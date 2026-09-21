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
	 * @return array{by_code: array<string, array{teller:string,scheidsrechter:string,team_thuis:string,team_uit:string,datum_ts:int}>, by_slot: array<string, array{teller:string,scheidsrechter:string,team_thuis:string,team_uit:string,datum_ts:int}>}
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

		$cache_key = 'vtc_tp_zaaltaken_' . $iso_week;
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
				"SELECT code, team_thuis, team_uit, datum, teller, scheidsrechter
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
			$item = array(
				'teller'         => isset( $row->teller ) ? trim( (string) $row->teller ) : '',
				'scheidsrechter' => isset( $row->scheidsrechter ) ? trim( (string) $row->scheidsrechter ) : '',
				'team_thuis'     => isset( $row->team_thuis ) ? trim( (string) $row->team_thuis ) : '',
				'team_uit'       => isset( $row->team_uit ) ? trim( (string) $row->team_uit ) : '',
				'datum_ts'       => $ts ? (int) $ts : 0,
			);
			if ( '' === $item['teller'] && '' === $item['scheidsrechter'] ) {
				// Geen toewijzing: toch indexeren zodat we “nog niet ingevuld” kunnen herkennen.
			}
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
	 * @param array<string, mixed>                                                                                                                                         $match
	 * @param array{by_code: array<string, array<string, mixed>>, by_slot: array<string, array<string, mixed>>} $index
	 * @return array{teller:string,scheidsrechter:string}|null
	 */
	public static function resolve_for_match( array $match, array $index ) {
		$code = '';
		if ( ! empty( $match['match_code'] ) ) {
			$code = strtoupper( trim( (string) $match['match_code'] ) );
		}
		if ( $code && isset( $index['by_code'][ $code ] ) ) {
			$row = $index['by_code'][ $code ];
			return array(
				'teller'         => (string) $row['teller'],
				'scheidsrechter' => (string) $row['scheidsrechter'],
			);
		}

		$ts = isset( $match['datetime_ts'] ) ? (int) $match['datetime_ts'] : 0;
		$home = isset( $match['home_team'] ) ? (string) $match['home_team'] : '';
		if ( $ts <= 0 || '' === $home ) {
			return null;
		}

		$slot = self::slot_key( $ts, $home );
		if ( isset( $index['by_slot'][ $slot ] ) ) {
			$row = $index['by_slot'][ $slot ];
			return array(
				'teller'         => (string) $row['teller'],
				'scheidsrechter' => (string) $row['scheidsrechter'],
			);
		}

		// Fuzzy: zelfde minuut + thuisteam-naam overlapt.
		$minute = $ts - ( $ts % 60 );
		$home_n = self::normalize_team( $home );
		foreach ( $index['by_slot'] as $row ) {
			$row_ts = isset( $row['datum_ts'] ) ? (int) $row['datum_ts'] : 0;
			if ( abs( $row_ts - $minute ) > 60 && abs( $row_ts - $ts ) > 60 ) {
				continue;
			}
			$thuis_n = self::normalize_team( isset( $row['team_thuis'] ) ? (string) $row['team_thuis'] : '' );
			if ( '' === $thuis_n || '' === $home_n ) {
				continue;
			}
			if ( $thuis_n === $home_n || false !== strpos( $home_n, $thuis_n ) || false !== strpos( $thuis_n, $home_n ) ) {
				return array(
					'teller'         => (string) $row['teller'],
					'scheidsrechter' => (string) $row['scheidsrechter'],
				);
			}
		}

		return null;
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
