<?php
/**
 * Soft-read teller/scheidsrechter from VTC Wedstrijd Planner DB table.
 *
 * @package VTC_Training_Planner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VTC_TP_Zaaltaken {

	/**
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'wedstrijd_planner';
	}

	/**
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;
		$table = self::table_name();
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return ( $found === $table );
	}

	/**
	 * Opdrachten voor een ISO-week, geïndexeerd op wedstrijdcode (uppercase).
	 *
	 * @param string $iso_week Genormaliseerde ISO-week.
	 * @return array{by_code: array<string, array<string, mixed>>, by_slot: array<string, array<string, mixed>>}
	 */
	public static function assignments_for_iso_week( $iso_week ) {
		$empty = array(
			'by_code' => array(),
			'by_slot' => array(),
		);
		$range = VTC_TP_Schedule::iso_week_range_utc_boundaries( $iso_week );
		if ( ! $range || ! self::table_exists() ) {
			return $empty;
		}

		$cache_key = 'vtc_tp_zaaltaken_v3_' . $iso_week;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$table = self::table_name();
		$tz    = wp_timezone();
		$from  = ( new DateTimeImmutable( '@' . (int) $range[0] ) )->setTimezone( $tz )->format( 'Y-m-d H:i:s' );
		$to    = ( new DateTimeImmutable( '@' . (int) $range[1] ) )->setTimezone( $tz )->format( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is prefixed constant.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT code, team_thuis, team_uit, datum, veld, teller, scheidsrechter
				FROM {$table}
				WHERE datum >= %s AND datum < %s
				AND (actief IS NULL OR actief = 1)",
				$from,
				$to
			)
		);

		$out = $empty;
		if ( ! is_array( $rows ) ) {
			return $out;
		}
		foreach ( $rows as $row ) {
			$code = isset( $row->code ) ? strtoupper( trim( (string) $row->code ) ) : '';
			$ts   = ! empty( $row->datum ) ? strtotime( (string) $row->datum ) : false;
			$veld = isset( $row->veld ) ? trim( (string) $row->veld ) : '';
			$item = array(
				'code'           => $code,
				'veld'           => $veld,
				'field_slug'     => self::veld_to_field_slug( $veld ),
				'teller'         => isset( $row->teller ) ? trim( (string) $row->teller ) : '',
				'scheidsrechter' => isset( $row->scheidsrechter ) ? trim( (string) $row->scheidsrechter ) : '',
				'team_thuis'     => isset( $row->team_thuis ) ? trim( (string) $row->team_thuis ) : '',
				'team_uit'       => isset( $row->team_uit ) ? trim( (string) $row->team_uit ) : '',
				'datum_ts'       => $ts ? (int) $ts : 0,
			);
			if ( $code ) {
				$out['by_code'][ $code ] = $item;
			}
			if ( $item['datum_ts'] > 0 && $item['team_thuis'] ) {
				$slot = self::slot_key( $item['datum_ts'], $item['team_thuis'] );
				$out['by_slot'][ $slot ] = $item;
			}
		}

		set_transient( $cache_key, $out, max( 60, (int) get_option( 'vtc_tp_cache_ttl', 1800 ) ) );
		return $out;
	}

	/**
	 * Sporthal-Excel veld ("4", "H1") → stamdata-slug (veld-4 / veld-1; H1 = veld 1).
	 *
	 * @param string $veld
	 */
	public static function veld_to_field_slug( $veld ) {
		$v = strtolower( trim( (string) $veld ) );
		if ( '' === $v ) {
			return '';
		}
		if ( 0 === strpos( $v, 'veld-' ) ) {
			return self::normalize_field_slug( $v );
		}
		if ( 0 === strpos( $v, 'veld ' ) ) {
			$v = trim( substr( $v, 5 ) );
		}
		return self::normalize_field_slug( 'veld-' . $v );
	}

	/**
	 * Nevobo "veld-h1" (hoofdveld) = stamdata veld 1.
	 *
	 * @param string $slug
	 */
	public static function normalize_field_slug( $slug ) {
		$slug = strtolower( trim( (string) $slug ) );
		if ( '' === $slug ) {
			return '';
		}
		if ( 'veld-h1' === $slug || 'h1' === $slug ) {
			return 'veld-1';
		}
		if ( preg_match( '/^veld-h(\d+)$/', $slug, $m ) ) {
			return 'veld-' . $m[1];
		}
		return $slug;
	}

	/**
	 * @param array<string, mixed>                                                                                                                                         $match
	 * @param array{by_code: array<string, array<string, mixed>>, by_slot: array<string, array<string, mixed>>} $index
	 * @return array{teller:string,scheidsrechter:string,code:string,veld:string,field_slug:string}|null
	 */
	public static function resolve_for_match( array $match, array $index ) {
		$code = '';
		if ( ! empty( $match['match_code'] ) ) {
			$code = strtoupper( trim( (string) $match['match_code'] ) );
		}
		$row = null;
		if ( $code && isset( $index['by_code'][ $code ] ) ) {
			$row = $index['by_code'][ $code ];
		} else {
			$ts   = isset( $match['datetime_ts'] ) ? (int) $match['datetime_ts'] : 0;
			$home = isset( $match['home_team'] ) ? (string) $match['home_team'] : '';
			if ( $ts > 0 && '' !== $home ) {
				$slot = self::slot_key( $ts, $home );
				if ( isset( $index['by_slot'][ $slot ] ) ) {
					$row = $index['by_slot'][ $slot ];
				} else {
					$minute = $ts - ( $ts % 60 );
					$home_n = self::normalize_team( $home );
					foreach ( $index['by_slot'] as $cand ) {
						$row_ts = isset( $cand['datum_ts'] ) ? (int) $cand['datum_ts'] : 0;
						if ( abs( $row_ts - $minute ) > 60 && abs( $row_ts - $ts ) > 60 ) {
							continue;
						}
						$thuis_n = self::normalize_team( isset( $cand['team_thuis'] ) ? (string) $cand['team_thuis'] : '' );
						if ( '' === $thuis_n || '' === $home_n ) {
							continue;
						}
						if ( $thuis_n === $home_n || false !== strpos( $home_n, $thuis_n ) || false !== strpos( $thuis_n, $home_n ) ) {
							$row = $cand;
							break;
						}
					}
				}
			}
		}
		if ( ! $row ) {
			return null;
		}
		return array(
			'teller'         => (string) ( $row['teller'] ?? '' ),
			'scheidsrechter' => (string) ( $row['scheidsrechter'] ?? '' ),
			'code'           => (string) ( $row['code'] ?? '' ),
			'veld'           => (string) ( $row['veld'] ?? '' ),
			'field_slug'     => (string) ( $row['field_slug'] ?? '' ),
		);
	}

	/**
	 * Zet veld/code uit wedstrijd-planner op RSS-matches (betrouwbaarder bij gelijke starttijden).
	 *
	 * @param array<int, array<string, mixed>> $matches
	 * @param string                           $iso_week
	 * @return array<int, array<string, mixed>>
	 */
	public static function enrich_matches_with_planner_fields( array $matches, $iso_week ) {
		if ( empty( $matches ) ) {
			return $matches;
		}
		$index = self::assignments_for_iso_week( $iso_week );
		if ( empty( $index['by_code'] ) && empty( $index['by_slot'] ) ) {
			return $matches;
		}
		foreach ( $matches as &$m ) {
			$hit = self::resolve_for_match( $m, $index );
			if ( ! $hit ) {
				continue;
			}
			if ( ! empty( $hit['code'] ) ) {
				$m['match_code'] = $hit['code'];
			}
			if ( ! empty( $hit['field_slug'] ) ) {
				$m['field_slug']         = $hit['field_slug'];
				$m['field_label']        = self::field_slug_to_label( $hit['field_slug'] );
				$m['field_from_planner'] = true;
			}
		}
		unset( $m );
		return $matches;
	}

	/**
	 * @param string $slug
	 */
	public static function field_slug_to_label( $slug ) {
		$slug = self::normalize_field_slug( $slug );
		if ( preg_match( '/^veld-(.+)$/i', $slug, $mm ) ) {
			return sprintf(
				/* translators: %s: field code/number */
				__( 'Veld %s', 'vtc-training-planner' ),
				strtoupper( (string) $mm[1] )
			);
		}
		return $slug;
	}

	/**
	 * @param int    $ts
	 * @param string $team_thuis
	 */
	private static function slot_key( $ts, $team_thuis ) {
		$minute = (int) $ts - ( (int) $ts % 60 );
		return $minute . '|' . self::normalize_team( $team_thuis );
	}

	/**
	 * @param string $name
	 */
	private static function normalize_team( $name ) {
		$n = strtolower( trim( (string) $name ) );
		$n = preg_replace( '/\s+/', ' ', $n );
		return is_string( $n ) ? $n : '';
	}
}
