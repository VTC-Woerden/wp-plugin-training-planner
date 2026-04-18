<?php
/**
 * ISO week helpers, effective slots per week, merged timeline.
 *
 * @package VTC_Training_Planner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VTC_TP_Schedule {

	/** @var VTC_TP_DB */
	private $db;

	public function __construct( VTC_TP_DB $db ) {
		$this->db = $db;
	}

	/**
	 * Normalize to YYYY-Www (e.g. 2026-W03).
	 *
	 * @param string $input e.g. 2026-W3, 2026_w03.
	 * @return string|null
	 */
	public static function normalize_iso_week( $input ) {
		$s = strtolower( trim( (string) $input ) );
		if ( preg_match( '/^(\d{4})[-_]?w(\d{1,2})$/', $s, $m ) ) {
			$y = (int) $m[1];
			$w = (int) $m[2];
			if ( $w < 1 || $w > 53 ) {
				return null;
			}
			return sprintf( '%d-W%02d', $y, $w );
		}
		return null;
	}

	/**
	 * Current ISO week in site timezone.
	 */
	public static function current_iso_week() {
		$tz = wp_timezone();
		$d  = new DateTimeImmutable( 'now', $tz );
		return $d->format( 'o' ) . '-W' . $d->format( 'W' );
	}

	/**
	 * Dagnamen zoals Team-app / training.js (0 = maandag … 6 = zondag).
	 *
	 * @return array<int, string>
	 */
	public static function team_day_names() {
		return array(
			0 => __( 'Maandag', 'vtc-training-planner' ),
			1 => __( 'Dinsdag', 'vtc-training-planner' ),
			2 => __( 'Woensdag', 'vtc-training-planner' ),
			3 => __( 'Donderdag', 'vtc-training-planner' ),
			4 => __( 'Vrijdag', 'vtc-training-planner' ),
			5 => __( 'Zaterdag', 'vtc-training-planner' ),
			6 => __( 'Zondag', 'vtc-training-planner' ),
		);
	}

	/**
	 * Valideer dagindex 0–6 (Team-app: maandag = 0).
	 *
	 * @param int $dow Raw DB value.
	 * @return int|null
	 */
	public static function normalize_day_of_week_team( $dow ) {
		$dow = (int) $dow;
		if ( $dow >= 0 && $dow <= 6 ) {
			return $dow;
		}
		return null;
	}

	/**
	 * Start (inclusive) and end (exclusive) Unix timestamps for ISO week, site TZ.
	 *
	 * @return array{0:int,1:int}|null
	 */
	public static function iso_week_range_utc_boundaries( $iso_week ) {
		$norm = self::normalize_iso_week( $iso_week );
		if ( ! $norm ) {
			return null;
		}
		if ( ! preg_match( '/^(\d{4})-W(\d{2})$/', $norm, $m ) ) {
			return null;
		}
		$y = (int) $m[1];
		$w = (int) $m[2];
		try {
			$tz = wp_timezone();
			$monday = new DateTimeImmutable( 'now', $tz );
			$monday = $monday->setISODate( $y, $w, 1 )->setTime( 0, 0, 0 );
			$sunday = $monday->modify( '+7 days' );
			return array( $monday->getTimestamp(), $sunday->getTimestamp() );
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * Effective weekly slots: afwijkende blauwdruk per week indien geclaimd, anders basis;
	 * uitzonderingsweek op die effectieve blauwdruk gaat voor op het weekpatroon.
	 *
	 * @return array<int, object>
	 */
	public function get_effective_slots_for_week( $iso_week ) {
		$norm = self::normalize_iso_week( $iso_week );
		if ( ! $norm ) {
			return array();
		}
		$bp_eff = $this->db->get_effective_blueprint_id_for_iso_week( $norm );
		$ex     = $this->db->get_exception_week( $bp_eff, $norm );
		if ( $ex ) {
			return $this->db->get_exception_slots( (int) $ex->id );
		}
		return $this->db->get_slots_published_or_draft( $bp_eff );
	}

	/**
	 * Expand slot rows to concrete events with start/end timestamps for the ISO week.
	 *
	 * @param array<int, object> $slots
	 * @return array<int, array<string, mixed>>
	 */
	public function expand_slots_to_events( $iso_week, array $slots, $blueprint_id_for_stamdata ) {
		$norm = self::normalize_iso_week( $iso_week );
		if ( ! $norm || ! preg_match( '/^(\d{4})-W(\d{2})$/', $norm, $m ) ) {
			return array();
		}
		$y = (int) $m[1];
		$w = (int) $m[2];
		$tz = wp_timezone();
		try {
			$monday = ( new DateTimeImmutable( 'now', $tz ) )->setISODate( $y, $w, 1 );
		} catch ( Exception $e ) {
			return array();
		}

		$bp_id = (int) $blueprint_id_for_stamdata;
		$teams = array();
		foreach ( $this->db->get_teams( $bp_id ) as $t ) {
			$teams[ (int) $t->id ] = $t;
		}
		$venues = array();
		foreach ( $this->db->get_venues_for_blueprint( $bp_id ) as $v ) {
			$venues[ (int) $v->id ] = $v;
		}

		$events = array();
		foreach ( $slots as $slot ) {
			$dow = self::normalize_day_of_week_team( $slot->day_of_week );
			if ( null === $dow ) {
				continue;
			}
			$day = $monday->modify( '+' . $dow . ' days' );
			$date = $day->format( 'Y-m-d' );
			$st   = $this->normalize_time( $slot->start_time );
			$en   = $this->normalize_time( $slot->end_time );
			if ( ! $st || ! $en ) {
				continue;
			}
			$start_dt = new DateTimeImmutable( $date . ' ' . $st, $tz );
			$end_dt   = new DateTimeImmutable( $date . ' ' . $en, $tz );
			$tid      = (int) $slot->team_id;
			$vid      = (int) $slot->venue_id;
			$team     = $teams[ $tid ] ?? null;
			$venue    = $venues[ $vid ] ?? null;
			$loc_label = $venue ? $venue->location_name : '';
			$vtype = ( $venue && isset( $venue->venue_type ) && 'field' === $venue->venue_type ) ? __( 'Buitenveld', 'vtc-training-planner' ) : __( 'Zaal', 'vtc-training-planner' );
			$events[] = array(
				'type'           => 'training',
				'start_ts'       => $start_dt->getTimestamp(),
				'end_ts'         => $end_dt->getTimestamp(),
				'title'          => $team ? $team->display_name : __( 'Team', 'vtc-training-planner' ),
				'subtitle'       => __( 'Training', 'vtc-training-planner' ) . ' · ' . $vtype,
				'venue_id'       => $vid,
				'location_label' => $loc_label,
				'field_label'    => $venue ? $venue->name : '',
				'hall_key'       => $loc_label ? strtolower( $loc_label ) : 'v:' . $vid,
			);
		}

		usort(
			$events,
			function ( $a, $b ) {
				return $a['start_ts'] <=> $b['start_ts'];
			}
		);

		return $events;
	}

	/**
	 * @param string $t HH:MM or HH:MM:SS
	 */
	private function normalize_time( $t ) {
		$t = trim( (string) $t );
		if ( preg_match( '/^(\d{1,2}):(\d{2})$/', $t, $m ) ) {
			return sprintf( '%02d:%02d:00', (int) $m[1], (int) $m[2] );
		}
		if ( preg_match( '/^(\d{1,2}):(\d{2}):(\d{2})$/', $t, $m ) ) {
			return sprintf( '%02d:%02d:%02d', (int) $m[1], (int) $m[2], (int) $m[3] );
		}
		return null;
	}

	/**
	 * Nevobo matches as timeline events (datetime from RSS item; end = +2h guess if unknown).
	 *
	 * @param array<int, array<string, mixed>> $matches
	 * @return array<int, array<string, mixed>>
	 */
	public function matches_to_events( array $matches ) {
		$tz     = wp_timezone();
		$events = array();
		foreach ( $matches as $m ) {
			$ts = isset( $m['datetime_ts'] ) ? (int) $m['datetime_ts'] : 0;
			if ( $ts <= 0 ) {
				continue;
			}
			$start_dt = ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz );
			$end_dt   = $start_dt->modify( '+2 hours' );
			$home     = isset( $m['home_team'] ) ? $m['home_team'] : '';
			$away     = isset( $m['away_team'] ) ? $m['away_team'] : '';
			$title    = trim( $home . ' — ' . $away );
			if ( '' === $title || '—' === $title ) {
				$title = isset( $m['title'] ) ? $m['title'] : __( 'Wedstrijd', 'vtc-training-planner' );
			}
			$vname = isset( $m['venue_name'] ) ? (string) $m['venue_name'] : '';
			$events[] = array(
				'type'           => 'match',
				'start_ts'       => $start_dt->getTimestamp(),
				'end_ts'         => $end_dt->getTimestamp(),
				'title'          => $title,
				'subtitle'       => __( 'Wedstrijd', 'vtc-training-planner' ),
				'venue_id'       => null,
				'location_label' => $vname,
				'field_label'    => '',
				'hall_key'       => $vname ? strtolower( $vname ) : 'm:' . md5( $title ),
			);
		}
		usort(
			$events,
			function ( $a, $b ) {
				return $a['start_ts'] <=> $b['start_ts'];
			}
		);
		return $events;
	}

	/**
	 * Merge training + match events and flag simple time overlaps per venue_id (trainings only) or location string.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function merge_and_flag_conflicts( array $training_events, array $match_events ) {
		$all = array_merge( $training_events, $match_events );
		usort(
			$all,
			function ( $a, $b ) {
				$c = $a['start_ts'] <=> $b['start_ts'];
				if ( 0 !== $c ) {
					return $c;
				}
				return strcmp( $a['type'], $b['type'] );
			}
		);

		$by_venue = array();
		foreach ( $all as $i => $ev ) {
			$key = isset( $ev['hall_key'] ) ? $ev['hall_key'] : 'x:' . $i;
			if ( ! isset( $by_venue[ $key ] ) ) {
				$by_venue[ $key ] = array();
			}
			$by_venue[ $key ][] = $i;
		}

		foreach ( $by_venue as $indices ) {
			$n = count( $indices );
			for ( $a = 0; $a < $n; $a++ ) {
				for ( $b = $a + 1; $b < $n; $b++ ) {
					$ia = $indices[ $a ];
					$ib = $indices[ $b ];
					$ea = $all[ $ia ];
					$eb = $all[ $ib ];
					if ( $ea['end_ts'] > $eb['start_ts'] && $eb['end_ts'] > $ea['start_ts'] ) {
						$all[ $ia ]['conflict'] = true;
						$all[ $ib ]['conflict'] = true;
					}
				}
			}
		}

		return $all;
	}

	/**
	 * Full pipeline for public/admin week view.
	 *
	 * @return array{events: array, iso_week: string, used_exceptions: bool}
	 */
	public function get_merged_week( $iso_week, VTC_TP_Nevobo $nevobo ) {
		$norm = self::normalize_iso_week( $iso_week ) ?: self::current_iso_week();

		$bp_eff = $this->db->get_effective_blueprint_id_for_iso_week( $norm );
		$slots  = $this->get_effective_slots_for_week( $norm );
		$ex     = $this->db->get_exception_week( $bp_eff, $norm );
		$train  = $this->expand_slots_to_events( $norm, $slots, $bp_eff );

		$code = $this->db->get_nevobo_code();
		$raw  = $nevobo->get_club_schedule_matches( $code );
		$week = $nevobo->filter_matches_in_iso_week( $raw, $norm );

		$scope   = get_option( 'vtc_tp_matches_scope', 'home_halls' );
		$bp_base = $this->db->get_base_blueprint_id();
		if ( 'home_halls' === $scope ) {
			$locs = $this->db->get_locations( $bp_base );
			$week = $nevobo->filter_home_hall_matches( $week, $locs );
		}

		$match_ev = $this->matches_to_events( $week );
		$events   = $this->merge_and_flag_conflicts( $train, $match_ev );

		return array(
			'events'                  => $events,
			'iso_week'                => $norm,
			'used_exceptions'         => (bool) $ex,
			'effective_blueprint_id'  => $bp_eff,
			'uses_deviation_blueprint'=> $bp_eff !== $bp_base,
		);
	}
}
