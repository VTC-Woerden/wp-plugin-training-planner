<?php
/**
 * Nevobo RSS fetch + parse (programma), with transients.
 *
 * @package VTC_Training_Planner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VTC_TP_Nevobo {

	const BASE = 'https://api.nevobo.nl/export';

	/** Namespace van <nevobo:status> in huidige RSS-feeds. */
	const NS_NEVOBO_RSS = 'https://www.api.nevobo.nl/rss/';

	/** @var VTC_TP_DB */
	private $db;

	public function __construct( VTC_TP_DB $db ) {
		$this->db = $db;
	}

	/**
	 * Genormaliseerde clubcode (alleen a-z0-9, lowercase).
	 *
	 * @param string $nevobo_code Ruwe code.
	 * @return string
	 */
	public static function normalize_club_code( $nevobo_code ) {
		// Eerst lowercase: anders stript [^a-z0-9] hoofdletters (CKL9X7N → 97).
		return preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $nevobo_code ) );
	}

	/**
	 * Transient-key voor programma-cache.
	 *
	 * @param string $code Genormaliseerde code.
	 * @return string
	 */
	public static function cache_key_for_code( $code ) {
		return 'vtc_tp_nevobo_prog_v2_' . $code;
	}

	/**
	 * Wis programma- + speelveld-transients voor een clubcode.
	 *
	 * @param string $nevobo_code Ruwe of genormaliseerde code.
	 */
	public static function clear_caches_for_code( $nevobo_code ) {
		$code = self::normalize_club_code( $nevobo_code );
		if ( '' === $code ) {
			return;
		}
		delete_transient( self::cache_key_for_code( $code ) );
		global $wpdb;
		// Speelveld-index per week: vtc_tp_nevobo_fields_{code}_{iso}.
		$like = $wpdb->esc_like( '_transient_vtc_tp_nevobo_fields_' ) . '%' . $wpdb->esc_like( $code . '_' ) . '%';
		$keys = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
		foreach ( $keys as $opt ) {
			$key = preg_replace( '/^_transient_/', '', (string) $opt );
			if ( $key ) {
				delete_transient( $key );
			}
		}
	}

	/**
	 * Parsed matches from club programma RSS (cached).
	 *
	 * @param string $nevobo_code Clubcode.
	 * @param bool   $force_refresh Cache overslaan.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_club_schedule_matches( $nevobo_code, $force_refresh = false ) {
		$code = self::normalize_club_code( $nevobo_code );
		if ( '' === $code ) {
			return array();
		}

		$cache_key = self::cache_key_for_code( $code );
		if ( ! $force_refresh ) {
			$cache = get_transient( $cache_key );
			// Lege array = oude/foute cache; opnieuw ophalen.
			if ( false !== $cache && is_array( $cache ) && ! empty( $cache ) ) {
				return $cache;
			}
			if ( false !== $cache && is_array( $cache ) && empty( $cache ) ) {
				delete_transient( $cache_key );
			}
		} else {
			delete_transient( $cache_key );
		}

		$probe = $this->fetch_and_parse( $code );
		$matches = isset( $probe['matches'] ) && is_array( $probe['matches'] ) ? $probe['matches'] : array();

		// Lege resultaten niet cachen: anders blijft een mislukte fetch/parse lang "0 items".
		if ( ! empty( $matches ) && empty( $probe['error'] ) ) {
			$ttl = max( 60, (int) get_option( 'vtc_tp_cache_ttl', 1800 ) );
			set_transient( $cache_key, $matches, $ttl );
		}

		return $matches;
	}

	/**
	 * Diagnose voor Instellingen: URL, HTTP, parse, cache.
	 *
	 * @param string $nevobo_code Clubcode.
	 * @param bool   $force_refresh True = opnieuw ophalen.
	 * @return array{code:string,url:string,cached:bool,http_code:int|null,item_count:int,error:string,matches:array}
	 */
	public function probe_club_feed( $nevobo_code, $force_refresh = false ) {
		$code = self::normalize_club_code( $nevobo_code );
		$url  = '' === $code ? '' : self::BASE . '/vereniging/' . rawurlencode( $code ) . '/programma.rss';
		$out  = array(
			'code'       => $code,
			'url'        => $url,
			'cached'     => false,
			'http_code'  => null,
			'item_count' => 0,
			'error'      => '',
			'matches'    => array(),
		);
		if ( '' === $code ) {
			$out['error'] = __( 'Geen Nevobo clubcode in Stamdata.', 'vtc-training-planner' );
			return $out;
		}

		$cache_key = self::cache_key_for_code( $code );
		if ( ! $force_refresh ) {
			$cache = get_transient( $cache_key );
			if ( false !== $cache && is_array( $cache ) && ! empty( $cache ) ) {
				$out['cached']     = true;
				$out['matches']    = $cache;
				$out['item_count'] = count( $cache );
				return $out;
			}
			if ( false !== $cache && is_array( $cache ) && empty( $cache ) ) {
				delete_transient( $cache_key );
			}
		} else {
			delete_transient( $cache_key );
		}

		$probe = $this->fetch_and_parse( $code );
		$out['http_code']  = $probe['http_code'];
		$out['error']      = $probe['error'];
		$out['matches']    = $probe['matches'];
		$out['item_count'] = count( $probe['matches'] );

		if ( ! empty( $probe['matches'] ) && empty( $probe['error'] ) ) {
			$ttl = max( 60, (int) get_option( 'vtc_tp_cache_ttl', 1800 ) );
			set_transient( $cache_key, $probe['matches'], $ttl );
		}

		return $out;
	}

	/**
	 * @param string $code Genormaliseerde clubcode.
	 * @return array{http_code:int|null,error:string,matches:array}
	 */
	private function fetch_and_parse( $code ) {
		$url = self::BASE . '/vereniging/' . rawurlencode( $code ) . '/programma.rss';
		$res = $this->http_get( $url );
		if ( null === $res['body'] ) {
			return array(
				'http_code' => $res['http_code'],
				'error'     => $res['error'] ? $res['error'] : __( 'Feed kon niet worden opgehaald.', 'vtc-training-planner' ),
				'matches'   => array(),
			);
		}

		$matches = $this->parse_rss_items( $res['body'] );
		$error   = '';
		if ( empty( $matches ) ) {
			$lib = libxml_get_errors();
			libxml_clear_errors();
			if ( ! empty( $lib ) ) {
				$error = __( 'XML-parsefout in RSS-feed.', 'vtc-training-planner' );
			} else {
				$error = __( 'Feed opgehaald, maar geen <item>-elementen gevonden.', 'vtc-training-planner' );
			}
		}

		return array(
			'http_code' => $res['http_code'],
			'error'     => $error,
			'matches'   => $matches,
		);
	}

	/**
	 * @param string $url Absolute URL.
	 * @return array{body:?string,http_code:int|null,error:string}
	 */
	private function http_get( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 20,
				'user-agent' => 'VTC-Training-Planner/' . VTC_TP_VERSION . '; ' . home_url( '/' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'body'      => null,
				'http_code' => null,
				'error'     => $response->get_error_message(),
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return array(
				'body'      => null,
				'http_code' => $code,
				'error'     => sprintf(
					/* translators: %d: HTTP status */
					__( 'HTTP-fout bij ophalen feed (%d).', 'vtc-training-planner' ),
					$code
				),
			);
		}
		$body = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body ) || '' === trim( $body ) ) {
			return array(
				'body'      => null,
				'http_code' => $code,
				'error'     => __( 'Lege response van Nevobo.', 'vtc-training-planner' ),
			);
		}
		return array(
			'body'      => $body,
			'http_code' => $code,
			'error'     => '',
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function parse_rss_items( $xml_string ) {
		libxml_use_internal_errors( true );
		libxml_clear_errors();
		$xml = simplexml_load_string( $xml_string, 'SimpleXMLElement', LIBXML_NOCDATA );
		if ( false === $xml ) {
			return array();
		}
		$items = array();
		if ( ! isset( $xml->channel->item ) ) {
			return $items;
		}
		foreach ( $xml->channel->item as $item ) {
			$items[] = $this->parse_match_item( $item );
		}
		return $items;
	}

	/**
	 * @param SimpleXMLElement $item RSS item.
	 * @return array<string, mixed>
	 */
	private function parse_match_item( $item ) {
		$title = isset( $item->title ) ? (string) $item->title : '';
		$desc  = isset( $item->description ) ? (string) $item->description : '';
		$link  = isset( $item->link ) ? (string) $item->link : '';
		$guid  = isset( $item->guid ) ? (string) $item->guid : '';

		$status = 'onbekend';
		$ns     = $item->children( self::NS_NEVOBO_RSS );
		if ( $ns && isset( $ns->status ) ) {
			$status = (string) $ns->status;
		} else {
			// Oudere namespace (legacy).
			$ns_old = $item->children( 'http://nevobo.nl/export/ns#' );
			if ( $ns_old && isset( $ns_old->status ) ) {
				$status = (string) $ns_old->status;
			}
		}

		$pub = isset( $item->pubDate ) ? strtotime( (string) $item->pubDate ) : false;
		$iso = isset( $item->children( 'http://www.w3.org/2005/Atom' )->updated )
			? strtotime( (string) $item->children( 'http://www.w3.org/2005/Atom' )->updated )
			: false;

		$dt = $iso ? $iso : ( $pub ? $pub : null );

		$match = array(
			'match_id'        => $guid ? preg_replace( '#.*/#', '', $guid ) : null,
			'link'            => $link ?: $guid,
			'title'           => $title,
			'datetime_ts'     => $dt,
			'status'          => $status,
			'home_team'       => null,
			'away_team'       => null,
			'venue_name'      => null,
			'venue_address'   => null,
			'raw_description' => $desc,
		);

		if ( $title ) {
			if ( preg_match( '/^\d+\s+\w+\s+\d+:\d+:\s*(.+)$/u', $title, $m ) ) {
				$teams_str = $m[1];
			} else {
				$teams_str = preg_replace( '/,\s*Uitslag:.+$/iu', '', $title );
				$teams_str = trim( $teams_str );
			}
			if ( preg_match( '/^(.+?)\s+-\s+(.+)$/', $teams_str, $tm ) ) {
				$match['home_team'] = trim( $tm[1] );
				$match['away_team'] = trim( $tm[2] );
			}
		}

		if ( $desc && preg_match( '/Speellocatie:\s*(.+)$/iu', $desc, $vm ) ) {
			$venue_full = trim( $vm[1] );
			$comma      = strpos( $venue_full, ',' );
			if ( false !== $comma && $comma > 0 ) {
				$match['venue_name']    = trim( substr( $venue_full, 0, $comma ) );
				$match['venue_address'] = trim( substr( $venue_full, $comma + 1 ) );
			} else {
				$match['venue_name'] = $venue_full;
			}
		}

		// Nevobo-code in description: "Wedstrijd: 3000XC2K1 ED" → XC2K1-ED.
		if ( $desc && preg_match( '/Wedstrijd:\s*\d*([A-Za-z0-9]+)\s+([A-Za-z0-9]{1,3})\b/u', $desc, $cm ) ) {
			$match['match_code'] = strtoupper( $cm[1] . '-' . $cm[2] );
		}

		return $match;
	}

	/**
	 * Matches whose datetime falls in the given ISO week (site timezone).
	 *
	 * @param array<int, array<string, mixed>> $matches Parsed matches.
	 * @return array<int, array<string, mixed>>
	 */
	public function filter_matches_in_iso_week( array $matches, $iso_week ) {
		$range = VTC_TP_Schedule::iso_week_range_utc_boundaries( $iso_week );
		if ( ! $range ) {
			return array();
		}
		list( $start, $end ) = $range;
		$out = array();
		foreach ( $matches as $m ) {
			$ts = isset( $m['datetime_ts'] ) ? (int) $m['datetime_ts'] : 0;
			if ( $ts >= $start && $ts < $end ) {
				$out[] = $m;
			}
		}
		return $out;
	}

	/**
	 * Keep matches at one of our halls (venue name matches location name or Nevobo venue name).
	 *
	 * @param array<int, array<string, mixed>> $matches
	 * @param array<int, object>               $locations From DB.
	 * @return array<int, array<string, mixed>>
	 */
	public function filter_home_hall_matches( array $matches, array $locations ) {
		if ( empty( $locations ) ) {
			return $matches;
		}
		$needles = array();
		foreach ( $locations as $loc ) {
			if ( ! empty( $loc->name ) ) {
				$needles[] = strtolower( $loc->name );
			}
			if ( ! empty( $loc->nevobo_venue_name ) ) {
				$needles[] = strtolower( $loc->nevobo_venue_name );
			}
		}
		$needles = array_unique( array_filter( $needles ) );
		if ( empty( $needles ) ) {
			return $matches;
		}
		$out = array();
		foreach ( $matches as $m ) {
			$vn = isset( $m['venue_name'] ) ? strtolower( (string) $m['venue_name'] ) : '';
			if ( '' === $vn ) {
				continue;
			}
			foreach ( $needles as $n ) {
				if ( $n && ( strpos( $vn, $n ) !== false || strpos( $n, $vn ) !== false ) ) {
					$out[] = $m;
					continue 2;
				}
			}
		}
		return $out;
	}

	/**
	 * Verrijk RSS-wedstrijden met speelveld-slug uit de Nevobo JSON-API (RSS heeft geen veld).
	 *
	 * @param array<int, array<string, mixed>> $matches RSS-matches (na weekfilter).
	 * @param string                           $nevobo_code Clubcode.
	 * @param string                           $iso_week ISO-week.
	 * @return array<int, array<string, mixed>>
	 */
	public function enrich_matches_with_speelveld( array $matches, $nevobo_code, $iso_week ) {
		if ( empty( $matches ) ) {
			return $matches;
		}
		$code = self::normalize_club_code( $nevobo_code );
		$norm = VTC_TP_Schedule::normalize_iso_week( (string) $iso_week );
		if ( '' === $code || ! $norm ) {
			return $matches;
		}

		$by_ts = $this->fetch_speelveld_index_for_week( $code, $norm );
		if ( empty( $by_ts ) ) {
			return $matches;
		}

		$used = array();
		foreach ( $matches as &$m ) {
			$ts = isset( $m['datetime_ts'] ) ? (int) $m['datetime_ts'] : 0;
			if ( $ts <= 0 ) {
				continue;
			}
			$candidates = isset( $by_ts[ $ts ] ) ? $by_ts[ $ts ] : array();
			if ( empty( $candidates ) ) {
				// Minuut-afronding (RSS vs API timezone/seconden).
				$minute     = $ts - ( $ts % 60 );
				$candidates = isset( $by_ts[ $minute ] ) ? $by_ts[ $minute ] : array();
			}
			if ( empty( $candidates ) ) {
				continue;
			}

			$vn    = isset( $m['venue_name'] ) ? strtolower( (string) $m['venue_name'] ) : '';
			$want  = ! empty( $m['match_code'] ) ? strtoupper( (string) $m['match_code'] ) : '';
			$pick  = null;
			$pool  = array();
			foreach ( $candidates as $c ) {
				$uid = isset( $c['uid'] ) ? (string) $c['uid'] : '';
				if ( $uid && isset( $used[ $uid ] ) ) {
					continue;
				}
				$pool[] = $c;
			}
			if ( empty( $pool ) ) {
				continue;
			}
			// Exacte wedstrijdcode (uit wedstrijd-planner) voorkomt verwarring bij gelijke starttijden.
			if ( $want ) {
				foreach ( $pool as $c ) {
					$cc = isset( $c['match_code'] ) ? strtoupper( (string) $c['match_code'] ) : '';
					if ( $cc && $cc === $want ) {
						$pick = $c;
						break;
					}
				}
			}
			if ( null === $pick && $vn ) {
				foreach ( $pool as $c ) {
					$hint = isset( $c['hall_hint'] ) ? strtolower( (string) $c['hall_hint'] ) : '';
					if ( $hint && ( false !== strpos( $vn, $hint ) || false !== strpos( $hint, $vn ) ) ) {
						$pick = $c;
						break;
					}
				}
			}
			if ( null === $pick ) {
				$pick = $pool[0];
			}
			if ( ! empty( $pick['uid'] ) ) {
				$used[ (string) $pick['uid'] ] = true;
			}
			// Planner-veld wint bij conflict (sporthal-Excel is leidend voor thuishal).
			$lock_field = ! empty( $m['field_from_planner'] ) && ! empty( $m['field_slug'] );
			if ( ! $lock_field && ! empty( $pick['field_slug'] ) ) {
				$m['field_slug'] = $pick['field_slug'];
			}
			if ( ! $lock_field && ! empty( $pick['field_label'] ) ) {
				$m['field_label'] = $pick['field_label'];
			}
			if ( isset( $pick['duration_min'] ) && (int) $pick['duration_min'] > 0 ) {
				$m['duration_min'] = (int) $pick['duration_min'];
			}
			if ( ! empty( $pick['poule'] ) ) {
				$m['poule'] = (string) $pick['poule'];
			}
			if ( ! empty( $pick['is_recreational'] ) ) {
				$m['is_recreational'] = true;
			}
			if ( ! empty( $pick['match_code'] ) && empty( $m['match_code'] ) ) {
				$m['match_code'] = (string) $pick['match_code'];
			}
		}
		unset( $m );

		return $matches;
	}

	/**
	 * @param string $code Genormaliseerde clubcode.
	 * @param string $iso_week Genormaliseerde ISO-week.
	 * @return array<int, array<int, array{field_slug:string,field_label:string,hall_hint:string}>>
	 */
	private function fetch_speelveld_index_for_week( $code, $iso_week ) {
		$range = VTC_TP_Schedule::iso_week_range_utc_boundaries( $iso_week );
		if ( ! $range ) {
			return array();
		}
		$tz = wp_timezone();
		$from = ( new DateTimeImmutable( '@' . (int) $range[0] ) )->setTimezone( $tz )->format( 'Y-m-d' );
		$to   = ( new DateTimeImmutable( '@' . ( (int) $range[1] - 1 ) ) )->setTimezone( $tz )->format( 'Y-m-d' );

		$cache_key = 'vtc_tp_nevobo_fields_v3_' . $code . '_' . $iso_week;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$vereniging = '/relatiebeheer/verenigingen/' . $code;
		$query      = array(
			'vereniging'    => $vereniging,
			'datum[after]'  => $from,
			'datum[before]' => $to,
			'itemsPerPage'  => 100,
		);
		$url  = 'https://api.nevobo.nl/competitie/wedstrijden?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		$by_ts = array();
		$guard = 0;
		while ( $url && $guard < 10 ) {
			++$guard;
			$payload = $this->http_get_json( $url );
			if ( null === $payload ) {
				break;
			}
			$members = array();
			if ( isset( $payload['hydra:member'] ) && is_array( $payload['hydra:member'] ) ) {
				$members = $payload['hydra:member'];
			}
			foreach ( $members as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$tijdstip = isset( $row['tijdstip'] ) ? (string) $row['tijdstip'] : '';
				$ts       = $tijdstip ? strtotime( $tijdstip ) : false;
				if ( ! $ts ) {
					continue;
				}
				$speelveld = isset( $row['speelveld'] ) ? (string) $row['speelveld'] : '';
				$slug      = '';
				$label     = '';
				if ( '' !== $speelveld ) {
					$slug  = strtolower( basename( untrailingslashit( $speelveld ) ) );
					$label = $slug;
					if ( preg_match( '/^veld-(.+)$/i', $slug, $mm ) ) {
						$label = sprintf(
							/* translators: %s: field code/number */
							__( 'Veld %s', 'vtc-training-planner' ),
							strtoupper( (string) $mm[1] )
						);
					}
				}
				$hall = '';
				if ( ! empty( $row['speelzaal'] ) ) {
					$parts = explode( '/', trim( (string) $row['speelzaal'], '/' ) );
					$hall  = str_replace( '-', ' ', (string) end( $parts ) );
				}
				$poule = isset( $row['poule'] ) ? (string) $row['poule'] : '';
				$lengte = isset( $row['lengte'] ) ? (int) $row['lengte'] : 0;
				$mcode  = isset( $row['code'] ) ? strtoupper( trim( (string) $row['code'] ) ) : '';
				$uid    = ( '' !== $speelveld )
					? strtolower( untrailingslashit( $speelveld ) ) . '|' . (int) $ts
					: ( 'm:' . ( isset( $row['uuid'] ) ? (string) $row['uuid'] : md5( $tijdstip . '|' . $poule ) ) );
				$entry  = array(
					'uid'             => $uid,
					'field_slug'      => $slug,
					'field_label'     => $label,
					'hall_hint'       => $hall,
					'duration_min'    => $lengte > 0 ? $lengte : 0,
					'poule'           => $poule,
					'is_recreational' => self::poule_is_recreational( $poule ),
					'match_code'      => $mcode,
				);
				$by_ts[ (int) $ts ][] = $entry;
				// Ook op minuut voor losse seconden-mismatch.
				$minute = (int) $ts - ( (int) $ts % 60 );
				if ( $minute !== (int) $ts ) {
					$by_ts[ $minute ][] = $entry;
				}
			}
			$next = '';
			if ( isset( $payload['hydra:view']['hydra:next'] ) ) {
				$next = (string) $payload['hydra:view']['hydra:next'];
			}
			if ( $next && 0 === strpos( $next, '/' ) ) {
				$url = 'https://api.nevobo.nl' . $next;
			} elseif ( $next && 0 === strpos( $next, 'http' ) ) {
				$url = $next;
			} else {
				$url = '';
			}
		}

		if ( ! empty( $by_ts ) ) {
			$ttl = max( 60, (int) get_option( 'vtc_tp_cache_ttl', 1800 ) );
			set_transient( $cache_key, $by_ts, $ttl );
		}

		return $by_ts;
	}

	/**
	 * Nevobo-poule in recreantencompetitie / -toernooi / mastercompetitie.
	 *
	 * @param string $poule Poule IRI/pad.
	 */
	public static function poule_is_recreational( $poule ) {
		$p = strtolower( (string) $poule );
		return ( '' !== $p && false !== strpos( $p, 'recreanten' ) );
	}

	/**
	 * @param string $url Absolute URL.
	 * @return array<string, mixed>|null
	 */
	private function http_get_json( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 20,
				'headers'    => array(
					'Accept' => 'application/ld+json, application/json',
				),
				'user-agent' => 'VTC-Training-Planner/' . VTC_TP_VERSION . '; ' . home_url( '/' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return null;
		}
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		return is_array( $data ) ? $data : null;
	}
}
