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

	/** @var VTC_TP_DB */
	private $db;

	public function __construct( VTC_TP_DB $db ) {
		$this->db = $db;
	}

	/**
	 * Parsed matches from club programma RSS (cached).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_club_schedule_matches( $nevobo_code ) {
		$code = strtolower( preg_replace( '/[^a-z0-9]/', '', (string) $nevobo_code ) );
		if ( '' === $code ) {
			return array();
		}

		$ttl   = max( 60, (int) get_option( 'vtc_tp_cache_ttl', 1800 ) );
		$cache = get_transient( 'vtc_tp_nevobo_prog_' . $code );
		if ( false !== $cache && is_array( $cache ) ) {
			return $cache;
		}

		$url  = self::BASE . '/vereniging/' . rawurlencode( $code ) . '/programma.rss';
		$body = $this->http_get_body( $url );
		if ( null === $body ) {
			return array();
		}

		$matches = $this->parse_rss_items( $body );
		set_transient( 'vtc_tp_nevobo_prog_' . $code, $matches, $ttl );

		return $matches;
	}

	/**
	 * @return string|null
	 */
	private function http_get_body( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 15,
				'user-agent' => 'VTC-Training-Planner/' . VTC_TP_VERSION . '; ' . home_url( '/' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return null;
		}
		return wp_remote_retrieve_body( $response );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function parse_rss_items( $xml_string ) {
		libxml_use_internal_errors( true );
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
		$ns    = $item->children( 'http://nevobo.nl/export/ns#' );
		$status = $ns && isset( $ns->status ) ? (string) $ns->status : 'onbekend';

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
