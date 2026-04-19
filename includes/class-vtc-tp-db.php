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

	const KIND_BASE      = 0;
	const KIND_DEVIATION = 1;

	/**
	 * Basis-blauwdruk (kind=0), anders eerste blauwdruk (legacy).
	 *
	 * @return int
	 */
	public function get_base_blueprint_id() {
		global $wpdb;
		$p  = $wpdb->prefix;
		$id = (int) $wpdb->get_var( "SELECT id FROM {$p}vtc_tp_blueprint WHERE kind = " . self::KIND_BASE . ' ORDER BY id ASC LIMIT 1' );
		if ( $id > 0 ) {
			return $id;
		}
		return (int) $wpdb->get_var( "SELECT id FROM {$p}vtc_tp_blueprint ORDER BY id ASC LIMIT 1" );
	}

	/**
	 * @deprecated Gebruik get_base_blueprint_id().
	 * @return int
	 */
	public function get_default_blueprint_id() {
		return $this->get_base_blueprint_id();
	}

	/**
	 * @return array<int, object>
	 */
	public function list_blueprints() {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_results( "SELECT * FROM {$p}vtc_tp_blueprint ORDER BY kind ASC, id ASC" );
	}

	/**
	 * Welke blauwdruk geldt voor deze ISO-week (afwijkende week-claim wint van basis).
	 *
	 * @return int blueprint id
	 */
	public function get_effective_blueprint_id_for_iso_week( $iso_week ) {
		$norm = VTC_TP_Schedule::normalize_iso_week( (string) $iso_week );
		if ( ! $norm ) {
			return $this->get_base_blueprint_id();
		}
		global $wpdb;
		$p   = $wpdb->prefix;
		$dev = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT deviation_blueprint_id FROM {$p}vtc_tp_deviation_week WHERE iso_week = %s LIMIT 1",
				$norm
			)
		);
		if ( $dev > 0 ) {
			return $dev;
		}
		return $this->get_base_blueprint_id();
	}

	/**
	 * @return object|null { deviation_blueprint_id, iso_week }
	 */
	public function get_deviation_week_row( $iso_week ) {
		$norm = VTC_TP_Schedule::normalize_iso_week( (string) $iso_week );
		if ( ! $norm ) {
			return null;
		}
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$p}vtc_tp_deviation_week WHERE iso_week = %s LIMIT 1",
				$norm
			)
		);
	}

	/**
	 * @return array<int, object>
	 */
	public function list_deviation_weeks_for_blueprint( $deviation_blueprint_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$p}vtc_tp_deviation_week WHERE deviation_blueprint_id = %d ORDER BY iso_week ASC",
				(int) $deviation_blueprint_id
			)
		);
	}

	/**
	 * @return array<int, object> Alle afwijkingsweken (globaal uniek per week).
	 */
	public function list_all_deviation_weeks() {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_results(
			"SELECT w.*, b.name AS blueprint_name FROM {$p}vtc_tp_deviation_week w
			INNER JOIN {$p}vtc_tp_blueprint b ON b.id = w.deviation_blueprint_id
			ORDER BY w.iso_week ASC"
		);
	}

	/**
	 * @return int|null positief = nieuw id, 0 = week al geclaimd, null = geen geldige afwijkende blauwdruk/week
	 */
	public function insert_deviation_week( $deviation_blueprint_id, $iso_week ) {
		$bp = $this->get_blueprint( (int) $deviation_blueprint_id );
		if ( ! $bp || (int) $bp->kind !== self::KIND_DEVIATION ) {
			return null;
		}
		$norm = VTC_TP_Schedule::normalize_iso_week( (string) $iso_week );
		if ( ! $norm ) {
			return null;
		}
		global $wpdb;
		$p = $wpdb->prefix;
		$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}vtc_tp_deviation_week WHERE iso_week = %s LIMIT 1", $norm ) );
		if ( $exists > 0 ) {
			return 0;
		}
		$wpdb->insert(
			"{$p}vtc_tp_deviation_week",
			array(
				'deviation_blueprint_id' => (int) $deviation_blueprint_id,
				'iso_week'               => $norm,
			),
			array( '%d', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * @return bool
	 */
	public function delete_deviation_week( $id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return false !== $wpdb->delete( "{$p}vtc_tp_deviation_week", array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * @return int|false nieuwe blauwdruk-id
	 */
	public function insert_deviation_blueprint( $name, $parent_base_id ) {
		$base = (int) $parent_base_id;
		if ( $base !== $this->get_base_blueprint_id() ) {
			return false;
		}
		global $wpdb;
		$p = $wpdb->prefix;
		$wpdb->insert(
			"{$p}vtc_tp_blueprint",
			array(
				'name'            => sanitize_text_field( $name ),
				'kind'            => self::KIND_DEVIATION,
				'parent_base_id'  => $base,
			),
			array( '%s', '%d', '%d' )
		);
		$bid = (int) $wpdb->insert_id;
		if ( $bid < 1 ) {
			return false;
		}
		// Eerste versie: leeg gepubliceerd patroon.
		$wpdb->insert(
			"{$p}vtc_tp_blueprint_version",
			array(
				'blueprint_id' => $bid,
				'label'        => __( 'Versie 1', 'vtc-training-planner' ),
				'is_published' => 1,
			),
			array( '%d', '%s', '%d' )
		);
		$vid = (int) $wpdb->insert_id;
		if ( $vid > 0 ) {
			$wpdb->update(
				"{$p}vtc_tp_blueprint",
				array( 'editing_version_id' => $vid ),
				array( 'id' => $bid ),
				array( '%d' ),
				array( '%d' )
			);
		}
		return $bid;
	}

	/**
	 * Wijzigt de weergavenaam van een blauwdruk (basis of afwijkend).
	 *
	 * @param int    $blueprint_id Blauwdruk-id.
	 * @param string $name         Nieuwe naam (niet leeg na trim).
	 * @return bool
	 */
	public function update_blueprint_name( $blueprint_id, $name ) {
		$blueprint_id = (int) $blueprint_id;
		$name         = sanitize_text_field( $name );
		if ( $blueprint_id < 1 || '' === $name ) {
			return false;
		}
		if ( ! $this->get_blueprint( $blueprint_id ) ) {
			return false;
		}
		global $wpdb;
		$p  = $wpdb->prefix;
		$res = $wpdb->update(
			"{$p}vtc_tp_blueprint",
			array( 'name' => $name ),
			array( 'id' => $blueprint_id ),
			array( '%s' ),
			array( '%d' )
		);
		return false !== $res;
	}

	/**
	 * Verwijdert een afwijkende blauwdruk en alle gekoppelde data. De basis-blauwdruk kan niet worden verwijderd.
	 *
	 * @param int $blueprint_id Alleen kind = afwijkend.
	 * @return bool|string False bij mislukken; true bij succes.
	 */
	public function delete_deviation_blueprint_and_data( $blueprint_id ) {
		$blueprint_id = (int) $blueprint_id;
		if ( $blueprint_id < 1 ) {
			return false;
		}
		$bp = $this->get_blueprint( $blueprint_id );
		if ( ! $bp || (int) $bp->kind === self::KIND_BASE ) {
			return false;
		}
		global $wpdb;
		$p = $wpdb->prefix;

		$wpdb->delete( "{$p}vtc_tp_deviation_week", array( 'deviation_blueprint_id' => $blueprint_id ), array( '%d' ) );

		$vids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}vtc_tp_blueprint_version WHERE blueprint_id = %d", $blueprint_id ) );
		foreach ( $vids as $vid ) {
			$vid = (int) $vid;
			$wpdb->delete( "{$p}vtc_tp_slot_draft", array( 'blueprint_version_id' => $vid ), array( '%d' ) );
			$wpdb->delete( "{$p}vtc_tp_slot_published", array( 'blueprint_version_id' => $vid ), array( '%d' ) );
		}
		$wpdb->delete( "{$p}vtc_tp_slot_draft", array( 'blueprint_id' => $blueprint_id ), array( '%d' ) );
		$wpdb->delete( "{$p}vtc_tp_slot_published", array( 'blueprint_id' => $blueprint_id ), array( '%d' ) );
		$wpdb->delete( "{$p}vtc_tp_blueprint_version", array( 'blueprint_id' => $blueprint_id ), array( '%d' ) );

		$ew_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}vtc_tp_exception_week WHERE blueprint_id = %d", $blueprint_id ) );
		foreach ( $ew_ids as $ewid ) {
			$wpdb->delete( "{$p}vtc_tp_exception_slot", array( 'exception_week_id' => (int) $ewid ), array( '%d' ) );
		}
		$wpdb->delete( "{$p}vtc_tp_exception_week", array( 'blueprint_id' => $blueprint_id ), array( '%d' ) );

		$wpdb->delete( "{$p}vtc_tp_team", array( 'blueprint_id' => $blueprint_id ), array( '%d' ) );

		$loc_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}vtc_tp_location WHERE blueprint_id = %d", $blueprint_id ) );
		foreach ( $loc_ids as $lid ) {
			$lid = (int) $lid;
			$venue_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}vtc_tp_venue WHERE location_id = %d", $lid ) );
			foreach ( $venue_ids as $vid2 ) {
				$vid2 = (int) $vid2;
				$wpdb->delete( "{$p}vtc_tp_venue_unavail", array( 'venue_id' => $vid2 ), array( '%d' ) );
				$wpdb->delete( "{$p}vtc_tp_venue", array( 'id' => $vid2 ), array( '%d' ) );
			}
			$wpdb->delete( "{$p}vtc_tp_location", array( 'id' => $lid ), array( '%d' ) );
		}

		return false !== $wpdb->delete( "{$p}vtc_tp_blueprint", array( 'id' => $blueprint_id ), array( '%d' ) );
	}

	/**
	 * @return int|null
	 */
	public function get_published_version_id_for_blueprint( $blueprint_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$p}vtc_tp_blueprint_version WHERE blueprint_id = %d AND is_published = 1 ORDER BY id DESC LIMIT 1",
				(int) $blueprint_id
			)
		);
		return $id > 0 ? $id : null;
	}

	/**
	 * Concept bewerkt deze versie (fallback: gepubliceerde versie).
	 *
	 * @return int|null
	 */
	public function get_editing_version_id_for_blueprint( $blueprint_id ) {
		global $wpdb;
		$p   = $wpdb->prefix;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT editing_version_id FROM {$p}vtc_tp_blueprint WHERE id = %d", (int) $blueprint_id ) );
		if ( $row && ! empty( $row->editing_version_id ) ) {
			return (int) $row->editing_version_id;
		}
		return $this->get_published_version_id_for_blueprint( $blueprint_id );
	}

	/**
	 * @return bool
	 */
	public function set_editing_version_id_for_blueprint( $blueprint_id, $version_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		$ok = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$p}vtc_tp_blueprint_version WHERE id = %d AND blueprint_id = %d",
				(int) $version_id,
				(int) $blueprint_id
			)
		);
		if ( $ok < 1 ) {
			return false;
		}
		return false !== $wpdb->update(
			"{$p}vtc_tp_blueprint",
			array( 'editing_version_id' => (int) $version_id ),
			array( 'id' => (int) $blueprint_id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * @return array<int, object>
	 */
	public function list_versions_for_blueprint( $blueprint_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$p}vtc_tp_blueprint_version WHERE blueprint_id = %d ORDER BY is_published DESC, id DESC",
				(int) $blueprint_id
			)
		);
	}

	/**
	 * Wijzigt het label van een blauwdrukversie (concept of live).
	 *
	 * @param int    $version_id Versie-id.
	 * @param string $label      Nieuw label (niet leeg na trim).
	 * @return bool False bij ongeldige invoer of SQL-fout.
	 */
	public function update_blueprint_version_label( $version_id, $label ) {
		$version_id = (int) $version_id;
		$label      = sanitize_text_field( $label );
		if ( $version_id < 1 || '' === $label ) {
			return false;
		}
		global $wpdb;
		$p      = $wpdb->prefix;
		$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(1) FROM {$p}vtc_tp_blueprint_version WHERE id = %d", $version_id ) );
		if ( ! $exists ) {
			return false;
		}
		$res = $wpdb->update(
			"{$p}vtc_tp_blueprint_version",
			array( 'label' => $label ),
			array( 'id' => $version_id ),
			array( '%s' ),
			array( '%d' )
		);
		return false !== $res;
	}

	/**
	 * Nieuwe concept-versie: kopie van huidige gepubliceerde slots naar concept-rij.
	 *
	 * @return int|false nieuwe version id
	 */
	public function create_draft_version_from_published( $blueprint_id, $label ) {
		$pub_vid = $this->get_published_version_id_for_blueprint( $blueprint_id );
		if ( ! $pub_vid ) {
			return false;
		}
		global $wpdb;
		$p = $wpdb->prefix;
		$wpdb->insert(
			"{$p}vtc_tp_blueprint_version",
			array(
				'blueprint_id' => (int) $blueprint_id,
				'label'        => sanitize_text_field( $label ) ?: __( 'Concept', 'vtc-training-planner' ),
				'is_published' => 0,
			),
			array( '%d', '%s', '%d' )
		);
		$new_vid = (int) $wpdb->insert_id;
		if ( $new_vid < 1 ) {
			return false;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}vtc_tp_slot_published WHERE blueprint_version_id = %d", $pub_vid ) );
		foreach ( $rows as $r ) {
			$wpdb->insert(
				"{$p}vtc_tp_slot_draft",
				array(
					'blueprint_id'         => (int) $blueprint_id,
					'blueprint_version_id' => $new_vid,
					'team_id'              => (int) $r->team_id,
					'venue_id'             => (int) $r->venue_id,
					'day_of_week'          => (int) $r->day_of_week,
					'start_time'           => $r->start_time,
					'end_time'             => $r->end_time,
				),
				array( '%d', '%d', '%d', '%d', '%d', '%s', '%s' )
			);
		}
		$this->set_editing_version_id_for_blueprint( $blueprint_id, $new_vid );
		return $new_vid;
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
				"SELECT * FROM {$p}vtc_tp_team WHERE blueprint_id = %d ORDER BY display_name ASC",
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
	public function get_slots_draft( $blueprint_id, $version_id = null ) {
		global $wpdb;
		$p   = $wpdb->prefix;
		$vid = null === $version_id ? $this->get_editing_version_id_for_blueprint( $blueprint_id ) : (int) $version_id;
		if ( ! $vid ) {
			return array();
		}
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$p}vtc_tp_slot_draft WHERE blueprint_version_id = %d ORDER BY day_of_week, start_time",
				$vid
			)
		);
	}

	/**
	 * @return array<int, object>
	 */
	public function get_slots_published( $blueprint_id, $version_id = null ) {
		global $wpdb;
		$p   = $wpdb->prefix;
		$vid = null === $version_id ? $this->get_published_version_id_for_blueprint( $blueprint_id ) : (int) $version_id;
		if ( ! $vid ) {
			return array();
		}
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$p}vtc_tp_slot_published WHERE blueprint_version_id = %d ORDER BY day_of_week, start_time",
				$vid
			)
		);
	}

	/**
	 * Published rooster; als leeg, val terug op concept (zelfde semantiek als Team-app).
	 *
	 * @return array<int, object>
	 */
	public function get_slots_published_or_draft( $blueprint_id ) {
		$vp  = $this->get_published_version_id_for_blueprint( $blueprint_id );
		if ( ! $vp ) {
			return array();
		}
		$pub = $this->get_slots_published( $blueprint_id, $vp );
		if ( ! empty( $pub ) ) {
			return $pub;
		}
		return $this->get_slots_draft( $blueprint_id, $vp );
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
	 * Copy draft slots to published voor de actieve concept-versie; die wordt de enige gepubliceerde versie.
	 */
	public function publish_slots( $blueprint_id ) {
		global $wpdb;
		$p        = $wpdb->prefix;
		$edit_vid = $this->get_editing_version_id_for_blueprint( $blueprint_id );
		if ( ! $edit_vid ) {
			return;
		}
		$wpdb->delete( "{$p}vtc_tp_slot_published", array( 'blueprint_version_id' => $edit_vid ), array( '%d' ) );
		$draft = $this->get_slots_draft( $blueprint_id, $edit_vid );
		foreach ( $draft as $row ) {
			$wpdb->insert(
				"{$p}vtc_tp_slot_published",
				array(
					'blueprint_id'         => (int) $blueprint_id,
					'blueprint_version_id' => (int) $edit_vid,
					'team_id'              => (int) $row->team_id,
					'venue_id'             => (int) $row->venue_id,
					'day_of_week'          => (int) $row->day_of_week,
					'start_time'           => $row->start_time,
					'end_time'             => $row->end_time,
				),
				array( '%d', '%d', '%d', '%d', '%d', '%s', '%s' )
			);
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$p}vtc_tp_blueprint_version SET is_published = 0 WHERE blueprint_id = %d", (int) $blueprint_id ) );
		$wpdb->update(
			"{$p}vtc_tp_blueprint_version",
			array( 'is_published' => 1 ),
			array( 'id' => (int) $edit_vid ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Draft differs from published?
	 */
	public function draft_differs_from_published( $blueprint_id ) {
		$vid = $this->get_editing_version_id_for_blueprint( $blueprint_id );
		if ( ! $vid ) {
			return false;
		}
		$d = $this->get_slots_draft( $blueprint_id, $vid );
		$p = $this->get_slots_published( $blueprint_id, $vid );
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
		$p   = $wpdb->prefix;
		$vid = $this->get_editing_version_id_for_blueprint( (int) $blueprint_id );
		if ( ! $vid ) {
			return 0;
		}
		$wpdb->insert(
			"{$p}vtc_tp_slot_draft",
			array(
				'blueprint_id'         => (int) $blueprint_id,
				'blueprint_version_id' => (int) $vid,
				'team_id'              => (int) $team_id,
				'venue_id'             => (int) $venue_id,
				'day_of_week'          => min( 6, max( 0, (int) $day_of_week ) ),
				'start_time'           => $start_time,
				'end_time'             => $end_time,
			),
			array( '%d', '%d', '%d', '%d', '%d', '%s', '%s' )
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
