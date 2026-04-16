<?php
/**
 * Database helpers (wpdb).
 *
 * @package VTC_Training_Planner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VTC_TP_DB {

	/**
	 * First blueprint id (MVP: single blueprint).
	 *
	 * @return int
	 */
	public function get_default_blueprint_id() {
		global $wpdb;
		$p = $wpdb->prefix;
		$id = (int) $wpdb->get_var( "SELECT id FROM {$p}vtc_tp_blueprint ORDER BY id ASC LIMIT 1" );
		return $id;
	}

	/**
	 * @return object|null
	 */
	public function get_blueprint( $id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}vtc_tp_blueprint WHERE id = %d", $id ) );
	}

	/**
	 * Vereniging (één rij, id=1) — zelfde rol als `clubs` in Team-app.
	 *
	 * @return object{id:int,name:string,nevobo_code:string,region:string,logo_url:?string}
	 */
	public function get_club() {
		global $wpdb;
		$p   = $wpdb->prefix;
		$row = $wpdb->get_row( "SELECT * FROM {$p}vtc_tp_club WHERE id = 1 LIMIT 1" );
		if ( ! $row ) {
			return (object) array(
				'id'           => 1,
				'name'         => '',
				'nevobo_code'  => (string) get_option( 'vtc_tp_nevobo_code', '' ),
				'region'       => '',
				'logo_url'     => null,
			);
		}
		return $row;
	}

	/**
	 * Nevobo clubcode: stamdata, met fallback naar oude optie.
	 */
	public function get_nevobo_code() {
		$c = $this->get_club();
		if ( $c && ! empty( $c->nevobo_code ) ) {
			return (string) $c->nevobo_code;
		}
		return (string) get_option( 'vtc_tp_nevobo_code', '' );
	}

	/**
	 * @param array{name?:string,nevobo_code?:string,region?:string,logo_url?:string} $data
	 */
	public function save_club( array $data ) {
		global $wpdb;
		$p = $wpdb->prefix;

		$fields = array(
			'name'         => isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '',
			'nevobo_code'  => isset( $data['nevobo_code'] ) ? sanitize_text_field( $data['nevobo_code'] ) : '',
			'region'       => isset( $data['region'] ) ? sanitize_text_field( $data['region'] ) : '',
			'logo_url'     => ! empty( $data['logo_url'] ) ? esc_url_raw( $data['logo_url'] ) : null,
		);

		$exists = (int) $wpdb->get_var( "SELECT id FROM {$p}vtc_tp_club WHERE id = 1 LIMIT 1" );
		if ( $exists ) {
			$wpdb->update( "{$p}vtc_tp_club", $fields, array( 'id' => 1 ), array( '%s', '%s', '%s', '%s' ), array( '%d' ) );
		} else {
			$wpdb->insert(
				"{$p}vtc_tp_club",
				$fields,
				array( '%s', '%s', '%s', '%s' )
			);
		}

		update_option( 'vtc_tp_nevobo_code', $fields['nevobo_code'] );
	}

	/**
	 * @return array<int, object>
	 */
	public function get_teams( $blueprint_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$p}vtc_tp_team WHERE blueprint_id = %d ORDER BY sort_order ASC, display_name ASC",
				$blueprint_id
			)
		);
	}

	/**
	 * @return array<int, object>
	 */
	public function get_locations( $blueprint_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$p}vtc_tp_location WHERE blueprint_id = %d ORDER BY name ASC",
				$blueprint_id
			)
		);
	}

	/**
	 * @return array<int, object>
	 */
	public function get_venues_for_blueprint( $blueprint_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT v.*, l.name AS location_name, l.nevobo_venue_name
				FROM {$p}vtc_tp_venue v
				INNER JOIN {$p}vtc_tp_location l ON l.id = v.location_id
				WHERE l.blueprint_id = %d
				ORDER BY l.name ASC, v.sort_order ASC, v.name ASC",
				$blueprint_id
			)
		);
	}

	/**
	 * @return array<int, object>
	 */
	public function get_venues_by_location( $location_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$p}vtc_tp_venue WHERE location_id = %d ORDER BY sort_order ASC, name ASC",
				$location_id
			)
		);
	}

	/**
	 * @return array<int, object>
	 */
	public function get_venue_unavailability( $venue_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$p}vtc_tp_venue_unavail WHERE venue_id = %d ORDER BY day_of_week, start_time",
				$venue_id
			)
		);
	}

	/**
	 * Alle inhuur-/niet-beschikbaar-slots voor velden in deze blauwdruk (zwembanen per dag).
	 *
	 * @return array<int, object>
	 */
	public function get_unavailability_for_blueprint( $blueprint_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT u.id, u.venue_id, u.day_of_week, u.start_time, u.end_time
				FROM {$p}vtc_tp_venue_unavail u
				INNER JOIN {$p}vtc_tp_venue v ON v.id = u.venue_id
				INNER JOIN {$p}vtc_tp_location l ON l.id = v.location_id
				WHERE l.blueprint_id = %d
				ORDER BY u.day_of_week ASC, u.venue_id ASC, u.start_time ASC",
				$blueprint_id
			)
		);
	}

	/**
	 * @return object|null
	 */
	public function get_venue_unavail_row( $id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}vtc_tp_venue_unavail WHERE id = %d", $id ) );
	}

	/**
	 * Rij + blueprint_id van de locatie (autorisatie).
	 *
	 * @return object|null
	 */
	public function get_venue_unavail_with_blueprint( $id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT u.*, l.blueprint_id AS blueprint_id
				FROM {$p}vtc_tp_venue_unavail u
				INNER JOIN {$p}vtc_tp_venue v ON v.id = u.venue_id
				INNER JOIN {$p}vtc_tp_location l ON l.id = v.location_id
				WHERE u.id = %d",
				$id
			)
		);
	}

	/**
	 * @return int insert id
	 */
	public function insert_venue_unavail( $venue_id, $day_of_week, $start_time, $end_time ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$wpdb->insert(
			"{$p}vtc_tp_venue_unavail",
			array(
				'venue_id'     => (int) $venue_id,
				'day_of_week'  => min( 6, max( 0, (int) $day_of_week ) ),
				'start_time'   => $start_time,
				'end_time'     => $end_time,
			),
			array( '%d', '%d', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * @param array{venue_id?:int,day_of_week?:int,start_time?:string,end_time?:string} $fields
	 */
	public function update_venue_unavail( $id, array $fields ) {
		global $wpdb;
		$p     = $wpdb->prefix;
		$allow = array( 'venue_id', 'day_of_week', 'start_time', 'end_time' );
		$data  = array();
		$fmt   = array();
		foreach ( $allow as $k ) {
			if ( ! array_key_exists( $k, $fields ) ) {
				continue;
			}
			$data[ $k ] = $fields[ $k ];
			$fmt[]      = ( 'start_time' === $k || 'end_time' === $k ) ? '%s' : '%d';
		}
		if ( empty( $data ) ) {
			return false;
		}
		return false !== $wpdb->update( "{$p}vtc_tp_venue_unavail", $data, array( 'id' => (int) $id ), $fmt, array( '%d' ) );
	}

	/**
	 * @return int|false
	 */
	public function delete_venue_unavail( $id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->delete( "{$p}vtc_tp_venue_unavail", array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * @return array<int, object>
	 */
	public function get_slots_draft( $blueprint_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$p}vtc_tp_slot_draft WHERE blueprint_id = %d ORDER BY day_of_week, start_time",
				$blueprint_id
			)
		);
	}

	/**
	 * @return array<int, object>
	 */
	public function get_slots_published( $blueprint_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$p}vtc_tp_slot_published WHERE blueprint_id = %d ORDER BY day_of_week, start_time",
				$blueprint_id
			)
		);
	}

	/**
	 * Published rooster; als leeg, val terug op concept (zelfde semantiek als Team-app).
	 *
	 * @return array<int, object>
	 */
	public function get_slots_published_or_draft( $blueprint_id ) {
		$pub = $this->get_slots_published( $blueprint_id );
		if ( ! empty( $pub ) ) {
			return $pub;
		}
		return $this->get_slots_draft( $blueprint_id );
	}

	/**
	 * @return object|null
	 */
	public function get_exception_week( $blueprint_id, $iso_week ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$p}vtc_tp_exception_week WHERE blueprint_id = %d AND iso_week = %s",
				$blueprint_id,
				$iso_week
			)
		);
	}

	/**
	 * @return array<int, object>
	 */
	public function get_exception_slots( $exception_week_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$p}vtc_tp_exception_slot WHERE exception_week_id = %d ORDER BY day_of_week, start_time",
				$exception_week_id
			)
		);
	}

	/**
	 * @return array<int, object>
	 */
	public function list_exception_weeks( $blueprint_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$p}vtc_tp_exception_week WHERE blueprint_id = %d ORDER BY iso_week DESC",
				$blueprint_id
			)
		);
	}

	/**
	 * Copy draft slots to published (replace all published for blueprint).
	 */
	public function publish_slots( $blueprint_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$wpdb->delete( "{$p}vtc_tp_slot_published", array( 'blueprint_id' => $blueprint_id ), array( '%d' ) );
		$draft = $this->get_slots_draft( $blueprint_id );
		foreach ( $draft as $row ) {
			$wpdb->insert(
				"{$p}vtc_tp_slot_published",
				array(
					'blueprint_id' => (int) $row->blueprint_id,
					'team_id'      => (int) $row->team_id,
					'venue_id'     => (int) $row->venue_id,
					'day_of_week'  => (int) $row->day_of_week,
					'start_time'   => $row->start_time,
					'end_time'     => $row->end_time,
				),
				array( '%d', '%d', '%d', '%d', '%s', '%s' )
			);
		}
	}

	/**
	 * Draft differs from published?
	 */
	public function draft_differs_from_published( $blueprint_id ) {
		$d = $this->get_slots_draft( $blueprint_id );
		$p = $this->get_slots_published( $blueprint_id );
		if ( count( $d ) !== count( $p ) ) {
			return true;
		}
		$key = function ( $rows ) {
			$out = array();
			foreach ( $rows as $r ) {
				$out[] = implode(
					'|',
					array(
						$r->team_id,
						$r->venue_id,
						$r->day_of_week,
						$r->start_time,
						$r->end_time,
					)
				);
			}
			sort( $out );
			return $out;
		};
		return $key( $d ) !== $key( $p );
	}

	/**
	 * @return object|null
	 */
	public function get_slot_draft( $slot_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}vtc_tp_slot_draft WHERE id = %d", $slot_id ) );
	}

	/**
	 * @param array{team_id?:int,venue_id?:int,day_of_week?:int,start_time?:string,end_time?:string} $fields
	 * @return bool
	 */
	public function update_slot_draft( $slot_id, array $fields ) {
		global $wpdb;
		$p     = $wpdb->prefix;
		$allow = array( 'team_id', 'venue_id', 'day_of_week', 'start_time', 'end_time' );
		$data  = array();
		$fmt   = array();
		foreach ( $allow as $k ) {
			if ( ! array_key_exists( $k, $fields ) ) {
				continue;
			}
			$data[ $k ] = $fields[ $k ];
			if ( 'day_of_week' === $k || 'team_id' === $k || 'venue_id' === $k ) {
				$fmt[] = '%d';
			} else {
				$fmt[] = '%s';
			}
		}
		if ( empty( $data ) ) {
			return false;
		}
		return false !== $wpdb->update( "{$p}vtc_tp_slot_draft", $data, array( 'id' => (int) $slot_id ), $fmt, array( '%d' ) );
	}

	/**
	 * @return int insert id
	 */
	public function insert_slot_draft( $blueprint_id, $team_id, $venue_id, $day_of_week, $start_time, $end_time ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$wpdb->insert(
			"{$p}vtc_tp_slot_draft",
			array(
				'blueprint_id' => (int) $blueprint_id,
				'team_id'      => (int) $team_id,
				'venue_id'     => (int) $venue_id,
				'day_of_week'  => min( 6, max( 0, (int) $day_of_week ) ),
				'start_time'   => $start_time,
				'end_time'     => $end_time,
			),
			array( '%d', '%d', '%d', '%d', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * @return int|false rows deleted
	 */
	public function delete_slot_draft( $slot_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->delete( "{$p}vtc_tp_slot_draft", array( 'id' => (int) $slot_id ), array( '%d' ) );
	}

	/**
	 * @return object|null
	 */
	public function get_exception_week_by_id( $exception_week_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}vtc_tp_exception_week WHERE id = %d", (int) $exception_week_id ) );
	}

	/**
	 * Maak uitzonderingsweek (alleen als nog niet bestaat). Retourneert 0 bij duplicaat.
	 *
	 * @return int insert id of 0
	 */
	public function insert_exception_week( $blueprint_id, $iso_week ) {
		global $wpdb;
		$p = $wpdb->prefix;
		if ( $this->get_exception_week( $blueprint_id, $iso_week ) ) {
			return 0;
		}
		$wpdb->insert(
			"{$p}vtc_tp_exception_week",
			array(
				'blueprint_id' => (int) $blueprint_id,
				'iso_week'     => $iso_week,
			),
			array( '%d', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Kopieer gepubliceerd/concept-rooster naar uitzonderingsweek-slots.
	 */
	public function copy_slots_to_exception_week( $blueprint_id, $exception_week_id ) {
		$rows = $this->get_slots_published_or_draft( $blueprint_id );
		foreach ( $rows as $r ) {
			$this->insert_exception_slot(
				$exception_week_id,
				(int) $r->team_id,
				(int) $r->venue_id,
				(int) $r->day_of_week,
				(string) $r->start_time,
				(string) $r->end_time
			);
		}
	}

	/**
	 * @return bool
	 */
	public function delete_exception_week( $exception_week_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$wpdb->delete( "{$p}vtc_tp_exception_slot", array( 'exception_week_id' => (int) $exception_week_id ), array( '%d' ) );
		return false !== $wpdb->delete( "{$p}vtc_tp_exception_week", array( 'id' => (int) $exception_week_id ), array( '%d' ) );
	}

	/**
	 * @return object|null
	 */
	public function get_exception_slot_row( $slot_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}vtc_tp_exception_slot WHERE id = %d", (int) $slot_id ) );
	}

	/**
	 * Slot + exception_week.blueprint_id voor autorisatie.
	 *
	 * @return object|null { slot fields + blueprint_id }
	 */
	public function get_exception_slot_with_blueprint( $slot_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT s.*, w.blueprint_id, w.iso_week
				FROM {$p}vtc_tp_exception_slot s
				INNER JOIN {$p}vtc_tp_exception_week w ON w.id = s.exception_week_id
				WHERE s.id = %d",
				(int) $slot_id
			)
		);
	}

	/**
	 * @return int insert id
	 */
	public function insert_exception_slot( $exception_week_id, $team_id, $venue_id, $day_of_week, $start_time, $end_time ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$wpdb->insert(
			"{$p}vtc_tp_exception_slot",
			array(
				'exception_week_id' => (int) $exception_week_id,
				'team_id'           => (int) $team_id,
				'venue_id'          => (int) $venue_id,
				'day_of_week'       => min( 6, max( 0, (int) $day_of_week ) ),
				'start_time'        => $start_time,
				'end_time'          => $end_time,
			),
			array( '%d', '%d', '%d', '%d', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * @param array{team_id?:int,venue_id?:int,day_of_week?:int,start_time?:string,end_time?:string} $fields
	 * @return bool
	 */
	public function update_exception_slot( $slot_id, array $fields ) {
		global $wpdb;
		$p     = $wpdb->prefix;
		$allow = array( 'team_id', 'venue_id', 'day_of_week', 'start_time', 'end_time' );
		$data  = array();
		$fmt   = array();
		foreach ( $allow as $k ) {
			if ( ! array_key_exists( $k, $fields ) ) {
				continue;
			}
			$data[ $k ] = $fields[ $k ];
			if ( 'day_of_week' === $k || 'team_id' === $k || 'venue_id' === $k ) {
				$fmt[] = '%d';
			} else {
				$fmt[] = '%s';
			}
		}
		if ( empty( $data ) ) {
			return false;
		}
		return false !== $wpdb->update( "{$p}vtc_tp_exception_slot", $data, array( 'id' => (int) $slot_id ), $fmt, array( '%d' ) );
	}

	/**
	 * @return int|false
	 */
	public function delete_exception_slot( $slot_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->delete( "{$p}vtc_tp_exception_slot", array( 'id' => (int) $slot_id ), array( '%d' ) );
	}
}
