<?php
/**
 * REST API for wp-admin visual planner (authenticated editors).
 *
 * @package VTC_Training_Planner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VTC_TP_Rest_Admin {

	/** @var VTC_TP_DB */
	private $db;

	public function __construct( VTC_TP_DB $db ) {
		$this->db = $db;
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			'vtc-tp/v1',
			'/admin/planner',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_planner' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			'vtc-tp/v1',
			'/admin/slots',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_slot' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			'vtc-tp/v1',
			'/admin/slots/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'patch_slot' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_slot' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);
		register_rest_route(
			'vtc-tp/v1',
			'/admin/publish',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'publish' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			'vtc-tp/v1',
			'/admin/unavailability',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_unavailability' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			'vtc-tp/v1',
			'/admin/unavailability/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'patch_unavailability' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_unavailability' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);
		register_rest_route(
			'vtc-tp/v1',
			'/admin/planner-week',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_planner_week' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'iso_week' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
		register_rest_route(
			'vtc-tp/v1',
			'/admin/exception-weeks',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_exception_week' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			'vtc-tp/v1',
			'/admin/exception-weeks/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_exception_week_rest' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			'vtc-tp/v1',
			'/admin/exception-slots',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_exception_slot' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			'vtc-tp/v1',
			'/admin/exception-slots/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'patch_exception_slot' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_exception_slot' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);
	}

	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	private function blueprint_id() {
		$id = $this->db->get_default_blueprint_id();
		return $id > 0 ? $id : null;
	}

	private function valid_team_ids( $bp ) {
		$ids = array();
		foreach ( $this->db->get_teams( $bp ) as $t ) {
			$ids[ (int) $t->id ] = true;
		}
		return $ids;
	}

	private function valid_venue_ids( $bp ) {
		$ids = array();
		foreach ( $this->db->get_venues_for_blueprint( $bp ) as $v ) {
			$ids[ (int) $v->id ] = true;
		}
		return $ids;
	}

	private function normalize_time( $s ) {
		$s = sanitize_text_field( (string) $s );
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', $s, $m ) ) {
			return null;
		}
		$h = min( 23, max( 0, (int) $m[1] ) );
		$i = min( 59, max( 0, (int) $m[2] ) );
		return sprintf( '%02d:%02d', $h, $i );
	}

	private function minutes( $hhmm ) {
		if ( ! preg_match( '/^(\d{2}):(\d{2})$/', $hhmm, $m ) ) {
			return 0;
		}
		return (int) $m[1] * 60 + (int) $m[2];
	}

	public function get_planner( WP_REST_Request $req ) {
		$bp = $this->blueprint_id();
		if ( ! $bp ) {
			return new WP_Error( 'no_blueprint', __( 'Geen blauwdruk gevonden.', 'vtc-training-planner' ), array( 'status' => 400 ) );
		}
		$teams  = $this->db->get_teams( $bp );
		$venues = $this->db->get_venues_for_blueprint( $bp );
		$slots  = $this->db->get_slots_draft( $bp );

		$team_names = array();
		foreach ( $teams as $t ) {
			$team_names[ (int) $t->id ] = $t->display_name;
		}
		$venue_labels = array();
		foreach ( $venues as $v ) {
			$venue_labels[ (int) $v->id ] = $v->location_name . ' — ' . $v->name;
		}

		$out_slots = array();
		foreach ( $slots as $s ) {
			$tid = (int) $s->team_id;
			$vid = (int) $s->venue_id;
			$out_slots[] = array(
				'id'           => (int) $s->id,
				'team_id'      => $tid,
				'venue_id'     => $vid,
				'day_of_week'  => (int) $s->day_of_week,
				'start_time'   => $s->start_time,
				'end_time'     => $s->end_time,
				'team_name'    => $team_names[ $tid ] ?? ( '#' . $tid ),
				'venue_label'  => $venue_labels[ $vid ] ?? ( '#' . $vid ),
			);
		}

		$teams_out = array();
		foreach ( $teams as $t ) {
			$teams_out[] = array(
				'id'           => (int) $t->id,
				'display_name' => $t->display_name,
			);
		}
		$venues_out = array();
		foreach ( $venues as $v ) {
			$venues_out[] = array(
				'id'             => (int) $v->id,
				'label'          => $v->location_name . ' — ' . $v->name,
				'location_name'  => $v->location_name,
				'name'           => $v->name,
			);
		}

		$un_rows = $this->db->get_unavailability_for_blueprint( $bp );
		$out_un  = array();
		foreach ( $un_rows as $u ) {
			$out_un[] = array(
				'id'            => (int) $u->id,
				'venue_id'      => (int) $u->venue_id,
				'day_of_week'   => (int) $u->day_of_week,
				'start_time'    => $u->start_time,
				'end_time'      => $u->end_time,
			);
		}

		return rest_ensure_response(
			array(
				'planner_scope'    => 'blueprint',
				'blueprint_id'     => $bp,
				'day_names'        => VTC_TP_Schedule::team_day_names(),
				'teams'            => $teams_out,
				'venues'           => $venues_out,
				'slots'            => $out_slots,
				'unavailability'   => $out_un,
				'draft_differs'    => $this->db->draft_differs_from_published( $bp ),
			)
		);
	}

	public function get_planner_week( WP_REST_Request $req ) {
		$bp = $this->blueprint_id();
		if ( ! $bp ) {
			return new WP_Error( 'no_blueprint', __( 'Geen blauwdruk gevonden.', 'vtc-training-planner' ), array( 'status' => 400 ) );
		}
		$norm = VTC_TP_Schedule::normalize_iso_week( (string) $req->get_param( 'iso_week' ) );
		if ( ! $norm ) {
			return new WP_Error( 'bad_week', __( 'Ongeldige ISO-week (gebruik bv. 2026-W12).', 'vtc-training-planner' ), array( 'status' => 400 ) );
		}

		$teams  = $this->db->get_teams( $bp );
		$venues = $this->db->get_venues_for_blueprint( $bp );

		$team_names = array();
		foreach ( $teams as $t ) {
			$team_names[ (int) $t->id ] = $t->display_name;
		}
		$venue_labels = array();
		foreach ( $venues as $v ) {
			$venue_labels[ (int) $v->id ] = $v->location_name . ' — ' . $v->name;
		}

		$teams_out = array();
		foreach ( $teams as $t ) {
			$teams_out[] = array(
				'id'           => (int) $t->id,
				'display_name' => $t->display_name,
			);
		}
		$venues_out = array();
		foreach ( $venues as $v ) {
			$venues_out[] = array(
				'id'            => (int) $v->id,
				'label'         => $v->location_name . ' — ' . $v->name,
				'location_name' => $v->location_name,
				'name'          => $v->name,
			);
		}

		$ex         = $this->db->get_exception_week( $bp, $norm );
		$out_slots  = array();
		$ex_id      = $ex ? (int) $ex->id : null;
		$has_ex     = (bool) $ex;
		if ( $ex ) {
			foreach ( $this->db->get_exception_slots( $ex_id ) as $s ) {
				$tid = (int) $s->team_id;
				$vid = (int) $s->venue_id;
				$out_slots[] = array(
					'id'           => (int) $s->id,
					'team_id'      => $tid,
					'venue_id'     => $vid,
					'day_of_week'  => (int) $s->day_of_week,
					'start_time'   => $s->start_time,
					'end_time'     => $s->end_time,
					'team_name'    => $team_names[ $tid ] ?? ( '#' . $tid ),
					'venue_label'  => $venue_labels[ $vid ] ?? ( '#' . $vid ),
				);
			}
		}

		$baseline_slots = array();
		foreach ( $this->db->get_slots_published_or_draft( $bp ) as $s ) {
			$tid = (int) $s->team_id;
			$vid = (int) $s->venue_id;
			$baseline_slots[] = array(
				'id'           => (int) $s->id,
				'team_id'      => $tid,
				'venue_id'     => $vid,
				'day_of_week'  => (int) $s->day_of_week,
				'start_time'   => $s->start_time,
				'end_time'     => $s->end_time,
				'team_name'    => $team_names[ $tid ] ?? ( '#' . $tid ),
				'venue_label'  => $venue_labels[ $vid ] ?? ( '#' . $vid ),
			);
		}

		$un_rows = $this->db->get_unavailability_for_blueprint( $bp );
		$out_un  = array();
		foreach ( $un_rows as $u ) {
			$out_un[] = array(
				'id'           => (int) $u->id,
				'venue_id'     => (int) $u->venue_id,
				'day_of_week'  => (int) $u->day_of_week,
				'start_time'   => $u->start_time,
				'end_time'     => $u->end_time,
			);
		}

		$ew_list = array();
		foreach ( $this->db->list_exception_weeks( $bp ) as $row ) {
			$ew_list[] = array(
				'id'       => (int) $row->id,
				'iso_week' => $row->iso_week,
			);
		}

		return rest_ensure_response(
			array(
				'planner_scope'      => 'week',
				'blueprint_id'       => $bp,
				'iso_week'           => $norm,
				'has_exception'      => $has_ex,
				'exception_week_id'  => $ex_id,
				'day_names'          => VTC_TP_Schedule::team_day_names(),
				'teams'              => $teams_out,
				'venues'             => $venues_out,
				'slots'              => $out_slots,
				'baseline_slots'     => $baseline_slots,
				'unavailability'     => $out_un,
				'exception_weeks'    => $ew_list,
				'draft_differs'      => false,
			)
		);
	}

	public function create_exception_week( WP_REST_Request $req ) {
		$bp = $this->blueprint_id();
		if ( ! $bp ) {
			return new WP_Error( 'no_blueprint', __( 'Geen blauwdruk gevonden.', 'vtc-training-planner' ), array( 'status' => 400 ) );
		}
		$params = $req->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}
		$norm = VTC_TP_Schedule::normalize_iso_week( $params['iso_week'] ?? '' );
		if ( ! $norm ) {
			return new WP_Error( 'bad_week', __( 'Ongeldige ISO-week.', 'vtc-training-planner' ), array( 'status' => 400 ) );
		}
		$new_id = $this->db->insert_exception_week( $bp, $norm );
		if ( ! $new_id ) {
			return new WP_Error( 'duplicate', __( 'Er bestaat al een afwijkende week voor deze ISO-week.', 'vtc-training-planner' ), array( 'status' => 409 ) );
		}
		$this->db->copy_slots_to_exception_week( $bp, $new_id );
		return rest_ensure_response(
			array(
				'ok'                => true,
				'exception_week_id' => $new_id,
				'iso_week'          => $norm,
			)
		);
	}

	public function delete_exception_week_rest( WP_REST_Request $req ) {
		$bp  = $this->blueprint_id();
		$eid = (int) $req['id'];
		if ( ! $bp ) {
			return new WP_Error( 'no_blueprint', __( 'Geen blauwdruk gevonden.', 'vtc-training-planner' ), array( 'status' => 400 ) );
		}
		$ew = $this->db->get_exception_week_by_id( $eid );
		if ( ! $ew || (int) $ew->blueprint_id !== $bp ) {
			return new WP_Error( 'not_found', __( 'Uitzonderingsweek niet gevonden.', 'vtc-training-planner' ), array( 'status' => 404 ) );
		}
		$this->db->delete_exception_week( $eid );
		return rest_ensure_response( array( 'deleted' => true, 'id' => $eid ) );
	}

	public function create_exception_slot( WP_REST_Request $req ) {
		$bp = $this->blueprint_id();
		if ( ! $bp ) {
			return new WP_Error( 'no_blueprint', __( 'Geen blauwdruk gevonden.', 'vtc-training-planner' ), array( 'status' => 400 ) );
		}
		$params = $req->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}
		$ewid = (int) ( $params['exception_week_id'] ?? 0 );
		$ew   = $this->db->get_exception_week_by_id( $ewid );
		if ( ! $ew || (int) $ew->blueprint_id !== $bp ) {
			return new WP_Error( 'bad_week', __( 'Ongeldige uitzonderingsweek.', 'vtc-training-planner' ), array( 'status' => 400 ) );
		}
		$team_ok  = $this->valid_team_ids( $bp );
		$venue_ok = $this->valid_venue_ids( $bp );
		$tid      = (int) ( $params['team_id'] ?? 0 );
		$vid      = (int) ( $params['venue_id'] ?? 0 );
		if ( ! isset( $team_ok[ $tid ] ) || ! isset( $venue_ok[ $vid ] ) ) {
			return new WP_Error( 'bad_ref', __( 'Ongeldig team of veld.', 'vtc-training-planner' ), array( 'status' => 400 ) );
		}
		$dow = min( 6, max( 0, (int) ( $params['day_of_week'] ?? 0 ) ) );
		$st  = $this->normalize_time( $params['start_time'] ?? '' );
		$en  = $this->normalize_time( $params['end_time'] ?? '' );
		if ( ! $st || ! $en || $this->minutes( $en ) <= $this->minutes( $st ) ) {
			return new WP_Error( 'bad_time', __( 'Ongeldige tijden.', 'vtc-training-planner' ), array( 'status' => 400 ) );
		}
		$new_id = $this->db->insert_exception_slot( $ewid, $tid, $vid, $dow, $st, $en );
		$row    = $this->db->get_exception_slot_row( $new_id );
		return $this->exception_slot_response( $row, $bp );
	}

	public function patch_exception_slot( WP_REST_Request $req ) {
		$bp  = $this->blueprint_id();
		$sid = (int) $req['id'];
		if ( ! $bp ) {
			return new WP_Error( 'no_blueprint', __( 'Geen blauwdruk gevonden.', 'vtc-training-planner' ), array( 'status' => 400 ) );
		}
		$meta = $this->db->get_exception_slot_with_blueprint( $sid );
		if ( ! $meta || (int) $meta->blueprint_id !== $bp ) {
			return new WP_Error( 'not_found', __( 'Blok niet gevonden.', 'vtc-training-planner' ), array( 'status' => 404 ) );
		}
		$params = $req->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}
		$fields = array();
		if ( isset( $params['team_id'] ) ) {
			$tid = (int) $params['team_id'];
			if ( ! isset( $this->valid_team_ids( $bp )[ $tid ] ) ) {
				return new WP_Error( 'bad_team', __( 'Ongeldig team.', 'vtc-training-planner' ), array( 'status' => 400 ) );
			}
			$fields['team_id'] = $tid;
		}
		if ( isset( $params['venue_id'] ) ) {
			$nvid = (int) $params['venue_id'];
			if ( ! isset( $this->valid_venue_ids( $bp )[ $nvid ] ) ) {
				return new WP_Error( 'bad_venue', __( 'Ongeldig veld.', 'vtc-training-planner' ), array( 'status' => 400 ) );
			}
			$fields['venue_id'] = $nvid;
		}
		if ( isset( $params['day_of_week'] ) ) {
			$fields['day_of_week'] = min( 6, max( 0, (int) $params['day_of_week'] ) );
		}
		if ( isset( $params['start_time'] ) ) {
			$t = $this->normalize_time( $params['start_time'] );
			if ( ! $t ) {
				return new WP_Error( 'bad_time', __( 'Ongeldige starttijd.', 'vtc-training-planner' ), array( 'status' => 400 ) );
			}
			$fields['start_time'] = $t;
		}
		if ( isset( $params['end_time'] ) ) {
			$t = $this->normalize_time( $params['end_time'] );
			if ( ! $t ) {
				return new WP_Error( 'bad_time', __( 'Ongeldige eindtijd.', 'vtc-training-planner' ), array( 'status' => 400 ) );
			}
			$fields['end_time'] = $t;
		}
		if ( isset( $fields['start_time'] ) || isset( $fields['end_time'] ) ) {
			$st = $fields['start_time'] ?? $meta->start_time;
			$en = $fields['end_time'] ?? $meta->end_time;
			if ( $this->minutes( $en ) <= $this->minutes( $st ) ) {
				return new WP_Error( 'bad_range', __( 'Eindtijd moet na starttijd liggen.', 'vtc-training-planner' ), array( 'status' => 400 ) );
			}
		}
		if ( empty( $fields ) ) {
			$row = $this->db->get_exception_slot_row( $sid );
			return $this->exception_slot_response( $row, $bp );
		}
		$this->db->update_exception_slot( $sid, $fields );
		$row = $this->db->get_exception_slot_row( $sid );
		return $this->exception_slot_response( $row, $bp );
	}

	public function delete_exception_slot( WP_REST_Request $req ) {
		$bp  = $this->blueprint_id();
		$sid = (int) $req['id'];
		if ( ! $bp ) {
			return new WP_Error( 'no_blueprint', __( 'Geen blauwdruk gevonden.', 'vtc-training-planner' ), array( 'status' => 400 ) );
		}
		$meta = $this->db->get_exception_slot_with_blueprint( $sid );
		if ( ! $meta || (int) $meta->blueprint_id !== $bp ) {
			return new WP_Error( 'not_found', __( 'Blok niet gevonden.', 'vtc-training-planner' ), array( 'status' => 404 ) );
		}
		$this->db->delete_exception_slot( $sid );
		return rest_ensure_response( array( 'deleted' => true, 'id' => $sid ) );
	}

	public function create_slot( WP_REST_Request $req ) {
		$bp = $this->blueprint_id();
		if ( ! $bp ) {
			return new WP_Error( 'no_blueprint', __( 'Geen blauwdruk gevonden.', 'vtc-training-planner' ), array( 'status' => 400 ) );
		}
		$params = $req->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}
		$team_ok  = $this->valid_team_ids( $bp );
		$venue_ok = $this->valid_venue_ids( $bp );
		$tid      = (int) ( $params['team_id'] ?? 0 );
		$vid      = (int) ( $params['venue_id'] ?? 0 );
		if ( ! isset( $team_ok[ $tid ] ) || ! isset( $venue_ok[ $vid ] ) ) {
			return new WP_Error( 'bad_ref', __( 'Ongeldig team of veld.', 'vtc-training-planner' ), array( 'status' => 400 ) );
		}
		$dow = min( 6, max( 0, (int) ( $params['day_of_week'] ?? 0 ) ) );
		$st  = $this->normalize_time( $params['start_time'] ?? '' );
		$en  = $this->normalize_time( $params['end_time'] ?? '' );
		if ( ! $st || ! $en || $this->minutes( $en ) <= $this->minutes( $st ) ) {
			return new WP_Error( 'bad_time', __( 'Ongeldige tijden.', 'vtc-training-planner' ), array( 'status' => 400 ) );
		}
		$new_id = $this->db->insert_slot_draft( $bp, $tid, $vid, $dow, $st, $en );
		$row    = $this->db->get_slot_draft( $new_id );
		if ( ! $row || (int) $row->blueprint_id !== $bp ) {
			return new WP_Error( 'insert_fail', __( 'Opslaan mislukt.', 'vtc-training-planner' ), array( 'status' => 500 ) );
		}
		return $this->slot_response( $row, $bp );
	}

	public function patch_slot( WP_REST_Request $req ) {
		$bp  = $this->blueprint_id();
		$sid = (int) $req['id'];
		if ( ! $bp ) {
			return new WP_Error( 'no_blueprint', __( 'Geen blauwdruk gevonden.', 'vtc-training-planner' ), array( 'status' => 400 ) );
		}
		$row = $this->db->get_slot_draft( $sid );
		if ( ! $row || (int) $row->blueprint_id !== $bp ) {
			return new WP_Error( 'not_found', __( 'Blok niet gevonden.', 'vtc-training-planner' ), array( 'status' => 404 ) );
		}
		$params = $req->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}
		$fields = array();
		if ( isset( $params['team_id'] ) ) {
			$tid = (int) $params['team_id'];
			if ( ! isset( $this->valid_team_ids( $bp )[ $tid ] ) ) {
				return new WP_Error( 'bad_team', __( 'Ongeldig team.', 'vtc-training-planner' ), array( 'status' => 400 ) );
			}
			$fields['team_id'] = $tid;
		}
		if ( isset( $params['venue_id'] ) ) {
			$vid = (int) $params['venue_id'];
			if ( ! isset( $this->valid_venue_ids( $bp )[ $vid ] ) ) {
				return new WP_Error( 'bad_venue', __( 'Ongeldig veld.', 'vtc-training-planner' ), array( 'status' => 400 ) );
			}
			$fields['venue_id'] = $vid;
		}
		if ( isset( $params['day_of_week'] ) ) {
			$fields['day_of_week'] = min( 6, max( 0, (int) $params['day_of_week'] ) );
		}
		if ( isset( $params['start_time'] ) ) {
			$t = $this->normalize_time( $params['start_time'] );
			if ( ! $t ) {
				return new WP_Error( 'bad_time', __( 'Ongeldige starttijd.', 'vtc-training-planner' ), array( 'status' => 400 ) );
			}
			$fields['start_time'] = $t;
		}
		if ( isset( $params['end_time'] ) ) {
			$t = $this->normalize_time( $params['end_time'] );
			if ( ! $t ) {
				return new WP_Error( 'bad_time', __( 'Ongeldige eindtijd.', 'vtc-training-planner' ), array( 'status' => 400 ) );
			}
			$fields['end_time'] = $t;
		}
		if ( isset( $fields['start_time'] ) || isset( $fields['end_time'] ) ) {
			$st = $fields['start_time'] ?? $row->start_time;
			$en = $fields['end_time'] ?? $row->end_time;
			if ( $this->minutes( $en ) <= $this->minutes( $st ) ) {
				return new WP_Error( 'bad_range', __( 'Eindtijd moet na starttijd liggen.', 'vtc-training-planner' ), array( 'status' => 400 ) );
			}
		}
		if ( empty( $fields ) ) {
			return $this->slot_response( $row, $bp );
		}
		$this->db->update_slot_draft( $sid, $fields );
		$row = $this->db->get_slot_draft( $sid );
		return $this->slot_response( $row, $bp );
	}

	public function delete_slot( WP_REST_Request $req ) {
		$bp  = $this->blueprint_id();
		$sid = (int) $req['id'];
		if ( ! $bp ) {
			return new WP_Error( 'no_blueprint', __( 'Geen blauwdruk gevonden.', 'vtc-training-planner' ), array( 'status' => 400 ) );
		}
		$row = $this->db->get_slot_draft( $sid );
		if ( ! $row || (int) $row->blueprint_id !== $bp ) {
			return new WP_Error( 'not_found', __( 'Blok niet gevonden.', 'vtc-training-planner' ), array( 'status' => 404 ) );
		}
		$this->db->delete_slot_draft( $sid );
		return rest_ensure_response( array( 'deleted' => true, 'id' => $sid ) );
	}

	public function publish( WP_REST_Request $req ) {
		$bp = $this->blueprint_id();
		if ( ! $bp ) {
			return new WP_Error( 'no_blueprint', __( 'Geen blauwdruk gevonden.', 'vtc-training-planner' ), array( 'status' => 400 ) );
		}
		$this->db->publish_slots( $bp );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public function create_unavailability( WP_REST_Request $req ) {
		$bp = $this->blueprint_id();
		if ( ! $bp ) {
			return new WP_Error( 'no_blueprint', __( 'Geen blauwdruk gevonden.', 'vtc-training-planner' ), array( 'status' => 400 ) );
		}
		$params = $req->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}
		$venue_ok = $this->valid_venue_ids( $bp );
		$vid      = (int) ( $params['venue_id'] ?? 0 );
		if ( ! isset( $venue_ok[ $vid ] ) ) {
			return new WP_Error( 'bad_venue', __( 'Ongeldig veld.', 'vtc-training-planner' ), array( 'status' => 400 ) );
		}
		$dow = min( 6, max( 0, (int) ( $params['day_of_week'] ?? 0 ) ) );
		$st  = $this->normalize_time( $params['start_time'] ?? '' );
		$en  = $this->normalize_time( $params['end_time'] ?? '' );
		if ( ! $st || ! $en || $this->minutes( $en ) <= $this->minutes( $st ) ) {
			return new WP_Error( 'bad_time', __( 'Ongeldige tijden.', 'vtc-training-planner' ), array( 'status' => 400 ) );
		}
		$new_id = $this->db->insert_venue_unavail( $vid, $dow, $st, $en );
		$row    = $this->db->get_venue_unavail_row( $new_id );
		return rest_ensure_response( array( 'unavailability' => $this->unavail_to_array( $row ) ) );
	}

	public function patch_unavailability( WP_REST_Request $req ) {
		$bp  = $this->blueprint_id();
		$uid = (int) $req['id'];
		if ( ! $bp ) {
			return new WP_Error( 'no_blueprint', __( 'Geen blauwdruk gevonden.', 'vtc-training-planner' ), array( 'status' => 400 ) );
		}
		$meta = $this->db->get_venue_unavail_with_blueprint( $uid );
		if ( ! $meta || (int) $meta->blueprint_id !== $bp ) {
			return new WP_Error( 'not_found', __( 'Blok niet gevonden.', 'vtc-training-planner' ), array( 'status' => 404 ) );
		}
		$params = $req->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}
		$fields = array();
		if ( isset( $params['venue_id'] ) ) {
			$nv = (int) $params['venue_id'];
			if ( ! isset( $this->valid_venue_ids( $bp )[ $nv ] ) ) {
				return new WP_Error( 'bad_venue', __( 'Ongeldig veld.', 'vtc-training-planner' ), array( 'status' => 400 ) );
			}
			$fields['venue_id'] = $nv;
		}
		if ( isset( $params['day_of_week'] ) ) {
			$fields['day_of_week'] = min( 6, max( 0, (int) $params['day_of_week'] ) );
		}
		if ( isset( $params['start_time'] ) ) {
			$t = $this->normalize_time( $params['start_time'] );
			if ( ! $t ) {
				return new WP_Error( 'bad_time', __( 'Ongeldige starttijd.', 'vtc-training-planner' ), array( 'status' => 400 ) );
			}
			$fields['start_time'] = $t;
		}
		if ( isset( $params['end_time'] ) ) {
			$t = $this->normalize_time( $params['end_time'] );
			if ( ! $t ) {
				return new WP_Error( 'bad_time', __( 'Ongeldige eindtijd.', 'vtc-training-planner' ), array( 'status' => 400 ) );
			}
			$fields['end_time'] = $t;
		}
		if ( isset( $fields['start_time'] ) || isset( $fields['end_time'] ) ) {
			$st = $fields['start_time'] ?? $meta->start_time;
			$en = $fields['end_time'] ?? $meta->end_time;
			if ( $this->minutes( $en ) <= $this->minutes( $st ) ) {
				return new WP_Error( 'bad_range', __( 'Eindtijd moet na starttijd liggen.', 'vtc-training-planner' ), array( 'status' => 400 ) );
			}
		}
		if ( empty( $fields ) ) {
			return rest_ensure_response( array( 'unavailability' => $this->unavail_to_array( $meta ) ) );
		}
		$this->db->update_venue_unavail( $uid, $fields );
		$row = $this->db->get_venue_unavail_row( $uid );
		return rest_ensure_response( array( 'unavailability' => $this->unavail_to_array( $row ) ) );
	}

	public function delete_unavailability( WP_REST_Request $req ) {
		$bp  = $this->blueprint_id();
		$uid = (int) $req['id'];
		if ( ! $bp ) {
			return new WP_Error( 'no_blueprint', __( 'Geen blauwdruk gevonden.', 'vtc-training-planner' ), array( 'status' => 400 ) );
		}
		$meta = $this->db->get_venue_unavail_with_blueprint( $uid );
		if ( ! $meta || (int) $meta->blueprint_id !== $bp ) {
			return new WP_Error( 'not_found', __( 'Blok niet gevonden.', 'vtc-training-planner' ), array( 'status' => 404 ) );
		}
		$this->db->delete_venue_unavail( $uid );
		return rest_ensure_response( array( 'deleted' => true, 'id' => $uid ) );
	}

	/**
	 * @param object|null $row
	 */
	private function unavail_to_array( $row ) {
		if ( ! $row ) {
			return null;
		}
		return array(
			'id'            => (int) $row->id,
			'venue_id'      => (int) $row->venue_id,
			'day_of_week'   => (int) $row->day_of_week,
			'start_time'    => $row->start_time,
			'end_time'      => $row->end_time,
		);
	}

	private function exception_slot_response( $row, $bp ) {
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Blok niet gevonden.', 'vtc-training-planner' ), array( 'status' => 404 ) );
		}
		$team_names = array();
		foreach ( $this->db->get_teams( $bp ) as $t ) {
			$team_names[ (int) $t->id ] = $t->display_name;
		}
		$venue_labels = array();
		foreach ( $this->db->get_venues_for_blueprint( $bp ) as $v ) {
			$venue_labels[ (int) $v->id ] = $v->location_name . ' — ' . $v->name;
		}
		$tid = (int) $row->team_id;
		$vid = (int) $row->venue_id;
		return rest_ensure_response(
			array(
				'slot' => array(
					'id'           => (int) $row->id,
					'team_id'      => $tid,
					'venue_id'     => $vid,
					'day_of_week'  => (int) $row->day_of_week,
					'start_time'   => $row->start_time,
					'end_time'     => $row->end_time,
					'team_name'    => $team_names[ $tid ] ?? '',
					'venue_label'  => $venue_labels[ $vid ] ?? '',
				),
			)
		);
	}

	private function slot_response( $row, $bp ) {
		$team_names = array();
		foreach ( $this->db->get_teams( $bp ) as $t ) {
			$team_names[ (int) $t->id ] = $t->display_name;
		}
		$venue_labels = array();
		foreach ( $this->db->get_venues_for_blueprint( $bp ) as $v ) {
			$venue_labels[ (int) $v->id ] = $v->location_name . ' — ' . $v->name;
		}
		$tid = (int) $row->team_id;
		$vid = (int) $row->venue_id;
		return rest_ensure_response(
			array(
				'slot'          => array(
					'id'           => (int) $row->id,
					'team_id'      => $tid,
					'venue_id'     => $vid,
					'day_of_week'  => (int) $row->day_of_week,
					'start_time'   => $row->start_time,
					'end_time'     => $row->end_time,
					'team_name'    => $team_names[ $tid ] ?? '',
					'venue_label'  => $venue_labels[ $vid ] ?? '',
				),
				'draft_differs' => $this->db->draft_differs_from_published( $bp ),
			)
		);
	}
}
