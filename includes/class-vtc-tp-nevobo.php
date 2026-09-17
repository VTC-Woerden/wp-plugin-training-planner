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
		return strtolower( preg_replace( '/[^a-z0-9]/', '', (string) $nevobo_code ) );
	}

	/**
	 * Transient-key voor programma-cache.
	 *
	 * @param string $code Genormaliseerde code.
	 * @return string
	 */
	public static function cache_key_for_code( $code ) {
		return 'vtc_tp_nevobo_prog_' . $code;
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
			if ( false !== $cache && is_array( $cache ) ) {
				return $cache;
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
			if ( false !== $cache && is_array( $cache ) ) {
				$out['cached']     = true;
				$out['matches']    = $cache;
				$out['item_count'] = count( $cache );
				if ( 0 === $out['item_count'] ) {
					$out['error'] = __( 'Cache bevat 0 items (waarschijnlijk een eerdere mislukte parse). Vernieuw de feed.', 'vtc-training-planner' );
				}
				return $out;
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
}
