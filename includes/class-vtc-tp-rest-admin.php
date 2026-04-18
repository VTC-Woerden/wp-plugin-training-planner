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
				'args'                => array(
					'blueprint_id' => array(
						'required' => false,
						'type'     => 'integer',
					),
				),
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
		register_rest_route(
			'vtc-tp/v1',
			'/admin/blueprints',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_blueprints_rest' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_deviation_blueprint_rest' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);
		register_rest_route(
			'vtc-tp/v1',
			'/admin/blueprint-versions',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_draft_version_rest' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			'vtc-tp/v1',
			'/admin/blueprint-editing-version',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'set_editing_version_rest' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			'vtc-tp/v1',
			'/admin/deviation-weeks',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_deviation_week_rest' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			'vtc-tp/v1',
			'/admin/deviation-weeks/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_deviation_week_rest' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Actieve blauwdruk (query ?blueprint_id= of JSON body), anders basis-blauwdruk.
	 *
	 * @return int|null
	 */
	private function resolve_blueprint_id( WP_REST_Request $req ) {
		$params    = $req->get_json_params();
		$from_body = is_array( $params ) && isset( $params['blueprint_id'] ) ? (int) $params['blueprint_id'] : 0;
		$from_q    = (int) $req->get_param( 'blueprint_id' );
		foreach ( array( $from_body, $from_q ) as $cand ) {
			if ( $cand > 0 && $this->db->get_blueprint( $cand ) ) {
				return $cand;
			}
		}
		$id = $this->db->get_base_blueprint_id();
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
		$bp = $this->resolve_blueprint_id( $req );
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
				'id'                   => (int) $t->id,
				'display_name'         => $t->display_name,
				'trainings_per_week'   => (int) $t->trainings_per_week,
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

		$versions_out = array();
		foreach ( $this->db->list_versions_for_blueprint( $bp ) as $vr ) {
			$versions_out[] = array(
				'id'           => (int) $vr->id,
				'label'        => $vr->label,
				'is_published' => (int) $vr->is_published === 1,
			);
		}
		$brow = $this->db->get_blueprint( $bp );

		return rest_ensure_response(
			array(
				'planner_scope'           => 'blueprint',
				'blueprint_id'            => $bp,
				'blueprint_name'          => $brow ? $brow->name : '',
				'blueprint_kind'          => $brow ? (int) $brow->kind : 0,
				'parent_base_id'          => $brow && $brow->parent_base_id ? (int) $brow->parent_base_id : null,
				'editing_version_id'      => $brow && $brow->editing_version_id ? (int) $brow->editing_version_id : null,
				'published_version_id'    => $this->db->get_published_version_id_for_blueprint( $bp ),
				'versions'                => $versions_out,
				'deviation_weeks'         => $this->db->list_deviation_weeks_for_blueprint( $bp ),
				'day_names'               => VTC_TP_Schedule::team_day_names(),
				'teams'                   => $teams_out,
				'venues'                  => $venues_out,
				'slots'                   => $out_slots,
				'unavailability'          => $out_un,
				'draft_differs'           => $this->db->draft_differs_from_published( $bp ),
			)
		);
	}

	public function get_planner_week( WP_REST_Request $req ) {
		$base_bp = $this->db->get_base_blueprint_id();
		if ( ! $base_bp ) {
			return new WP_Error( 'no_blueprint', __( 'Geen blauwdruk gevonden.', 'vtc-training-planner' ), array( 'status' => 400 ) );
		}
		$norm = VTC_TP_Schedule::normalize_iso_week( (string) $req->get_param( 'iso_week' ) );
		if ( ! $norm ) {
			return new WP_Error( 'bad_week', __( 'Ongeldige ISO-week (gebruik bv. 2026-W12).', 'vtc-training-planner' ), array( 'status' => 400 ) );
		}

		$bp = $this->db->get_effective_blueprint_id_for_iso_week( $norm );

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
				'id'                   => (int) $t->id,
				'display_name'         => $t->display_name,
				'trainings_per_week'   => (int) $t->trainings_per_week,
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
		} elseif ( $bp !== $base_bp ) {
			// Afwijkende blauwdruk actief zonder handmatige uitzonderingsweek: toon live weekpatroon van deze blauwdruk.
			foreach ( $this->db->get_slots_published_or_draft( $bp ) as $s ) {
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

		$baseline_team_names = array();
		$baseline_venue_labels = array();
		if ( $bp !== $base_bp ) {
			foreach ( $this->db->get_teams( $base_bp ) as $t ) {
				$baseline_team_names[ (int) $t->id ] = $t->display_name;
			}
			foreach ( $this->db->get_venues_for_blueprint( $base_bp ) as $v ) {
				$baseline_venue_labels[ (int) $v->id ] = $v->location_name . ' — ' . $v->name;
			}
		}
		$baseline_slots = array();
		foreach ( $this->db->get_slots_published_or_draft( $base_bp ) as $s ) {
			$tid = (int) $s->team_id;
			$vid = (int) $s->venue_id;
			$baseline_slots[] = array(
				'id'           => (int) $s->id,
				'team_id'      => $tid,
				'venue_id'     => $vid,
				'day_of_week'  => (int) $s->day_of_week,
				'start_time'   => $s->start_time,
				'end_time'     => $s->end_time,
				'team_name'    => $bp === $base_bp ? ( $team_names[ $tid ] ?? ( '#' . $tid ) ) : ( $baseline_team_names[ $tid ] ?? ( '#' . $tid ) ),
				'venue_label'  => $bp === $base_bp ? ( $venue_labels[ $vid ] ?? ( '#' . $vid ) ) : ( $baseline_venue_labels[ $vid ] ?? ( '#' . $vid ) ),
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

		$dev_row = $this->db->get_deviation_week_row( $norm );

		return rest_ensure_response(
			array(
				'planner_scope'             => 'week',
				'blueprint_id'              => $bp,
				'base_blueprint_id'         => $base_bp,
				'iso_week'                  => $norm,
				'has_exception'             => $has_ex,
				'exception_week_id'         => $ex_id,
				'uses_deviation_blueprint'  => $bp !== $base_bp,
				'deviation_week_row_id'     => $dev_row ? (int) $dev_row->id : null,
				'day_names'                 => VTC_TP_Schedule::team_day_names(),
				'teams'                     => $teams_out,
				'venues'                    => $venues_out,
				'slots'                     => $out_slots,
				'baseline_slots'            => $baseline_slots,
				'unavailability'            => $out_un,
				'exception_weeks'           => $ew_list,
				'draft_differs'             => $this->db->draft_differs_from_published( $bp ),
			)
		);
	}

	public function create_exception_week( WP_REST_Request $req ) {
		$params = $req->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}
		$norm = VTC_TP_Schedule::normalize_iso_week( $params['iso_week'] ?? '' );
		if ( ! $norm ) {
			return new WP_Error( 'bad_week', __( 'Ongeldige ISO-week.', 'vtc-training-planner' ), array( 'status' => 400 ) );
		}
		$bp = $this->db->get_effective_blueprint_id_for_iso_week( $norm );
		if ( ! $bp ) {
			return new WP_Error( 'no_blueprint', __( 'Geen blauwdruk gevonden.', 'vtc-training-planner' ), array( 'status' => 400 ) );
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
		$eid = (int) $req['id'];
		$ew  = $this->db->get_exception_week_by_id( $eid );
		if ( ! $ew || ! $this->db->get_blueprint( (int) $ew->blueprint_id ) ) {
			return new WP_Error( 'not_found', __( 'Uitzonderingsweek niet gevonden.', 'vtc-training-planner' ), array( 'status' => 404 ) );
		}
		$this->db->delete_exception_week( $eid );
		return rest_ensure_response( array( 'deleted' => true, 'id' => $eid ) );
	}

	public function create_exception_slot( WP_REST_Request $req ) {
		$params = $req->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}
		$ewid = (int) ( $params['exception_week_id'] ?? 0 );
		$ew   = $this->db->get_exception_week_by_id( $ewid );
		if ( ! $ew || ! $this->db->get_blueprint( (int) $ew->blueprint_id ) ) {
			return new WP_Error( 'bad_week', __( 'Ongeldige uitzonderingsweek.', 'vtc-training-planner' ), array( 'status' => 400 ) );
		}
		$bp       = (int) $ew->blueprint_id;
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
		$sid  = (int) $req['id'];
		$meta = $this->db->get_exception_slot_with_blueprint( $sid );
		if ( ! $meta || ! $this->db->get_blueprint( (int) $meta->blueprint_id ) ) {
			return new WP_Error( 'not_found', __( 'Blok niet gevonden.', 'vtc-training-planner' ), array( 'status' => 404 ) );
		}
		$bp = (int) $meta->blueprint_id;
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
		$sid  = (int) $req['id'];
		$meta = $this->db->get_exception_slot_with_blueprint( $sid );
		if ( ! $meta || ! $this->db->get_blueprint( (int) $meta->blueprint_id ) ) {
			return new WP_Error( 'not_found', __( 'Blok niet gevonden.', 'vtc-training-planner' ), array( 'status' => 404 ) );
		}
		$this->db->delete_exception_slot( $sid );
		return rest_ensure_response( array( 'deleted' => true, 'id' => $sid ) );
	}

	public function create_slot( WP_REST_Request $req ) {
		$bp = $this->resolve_blueprint_id( $req );
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
		$bp  = $this->resolve_blueprint_id( $req );
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
		$bp  = $this->resolve_blueprint_id( $req );
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
		$bp = $this->resolve_blueprint_id( $req );
		if ( ! $bp ) {
			return new WP_Error( 'no_blueprint', __( 'Geen blauwdruk gevonden.', 'vtc-training-planner' ), array( 'status' => 400 ) );
		}
		$this->db->publish_slots( $bp );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public function create_unavailability( WP_REST_Request $req ) {
		$bp = $this->resolve_blueprint_id( $req );
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
		$bp  = $this->resolve_blueprint_id( $req );
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
		$bp  = $this->resolve_blueprint_id( $req );
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

	public function list_blueprints_rest( WP_REST_Request $req ) {
		unset( $req );
		$out = array();
		foreach ( $this->db->list_blueprints() as $b ) {
			$out[] = array(
				'id'                   => (int) $b->id,
				'name'                 => $b->name,
				'kind'                 => (int) $b->kind,
				'parent_base_id'       => $b->parent_base_id ? (int) $b->parent_base_id : null,
				'editing_version_id'   => $b->editing_version_id ? (int) $b->editing_version_id : null,
				'published_version_id' => $this->db->get_published_version_id_for_blueprint( (int) $b->id ),
				'versions'             => array_map(
					function ( $v ) {
						return array(
							'id'           => (int) $v->id,
							'label'        => $v->label,
							'is_published' => (int) $v->is_published === 1,
						);
					},
					$this->db->list_versions_for_blueprint( (int) $b->id )
				),
				'deviation_weeks'      => $this->db->list_deviation_weeks_for_blueprint( (int) $b->id ),
			);
		}
		return rest_ensure_response(
			array(
				'blueprints'        => $out,
				'base_blueprint_id' => $this->db->get_base_blueprint_id(),
			)
		);
	}

	public function create_deviation_blueprint_rest( WP_REST_Request $req ) {
		$params = $req->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}
		$name   = isset( $params['name'] ) ? sanitize_text_field( (string) $params['name'] ) : '';
		$parent = isset( $params['parent_base_id'] ) ? (int) $params['parent_base_id'] : $this->db->get_base_blueprint_id();
		if ( '' === $name ) {
			return new WP_Error( 'bad_name', __( 'Naam is verplicht.', 'vtc-training-planner' ), array( 'status' => 400 ) );
		}
		$bid = $this->db->insert_deviation_blueprint( $name, $parent );
		if ( ! $bid ) {
			return new WP_Error( 'create_fail', __( 'Afwijkende blauwdruk aanmaken mislukt.', 'vtc-training-planner' ), array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'blueprint_id' => (int) $bid ) );
	}

	public function create_draft_version_rest( WP_REST_Request $req ) {
		$params = $req->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}
		$bp    = (int) ( $params['blueprint_id'] ?? 0 );
		$label = isset( $params['label'] ) ? sanitize_text_field( (string) $params['label'] ) : __( 'Nieuw concept', 'vtc-training-planner' );
		if ( ! $this->db->get_blueprint( $bp ) ) {
			return new WP_Error( 'bad_bp', __( 'Ongeldige blauwdruk.', 'vtc-training-planner' ), array( 'status' => 400 ) );
		}
		$vid = $this->db->create_draft_version_from_published( $bp, $label );
		if ( ! $vid ) {
			return new WP_Error( 'version_fail', __( 'Conceptversie kon niet worden aangemaakt.', 'vtc-training-planner' ), array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'version_id' => (int) $vid ) );
	}

	public function set_editing_version_rest( WP_REST_Request $req ) {
		$params = $req->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}
		$bp  = (int) ( $params['blueprint_id'] ?? 0 );
		$vid = (int) ( $params['version_id'] ?? 0 );
		if ( ! $this->db->get_blueprint( $bp ) || ! $vid ) {
			return new WP_Error( 'bad_args', __( 'Ongeldige parameters.', 'vtc-training-planner' ), array( 'status' => 400 ) );
		}
		if ( ! $this->db->set_editing_version_id_for_blueprint( $bp, $vid ) ) {
			return new WP_Error( 'bad_version', __( 'Versie hoort niet bij deze blauwdruk.', 'vtc-training-planner' ), array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public function create_deviation_week_rest( WP_REST_Request $req ) {
		$params = $req->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}
		$dev_bp = (int) ( $params['deviation_blueprint_id'] ?? 0 );
		$iso    = isset( $params['iso_week'] ) ? (string) $params['iso_week'] : '';
		$new_id = $this->db->insert_deviation_week( $dev_bp, $iso );
		if ( null === $new_id ) {
			return new WP_Error( 'bad_request', __( 'Ongeldige afwijkende blauwdruk of ISO-week.', 'vtc-training-planner' ), array( 'status' => 400 ) );
		}
		if ( 0 === $new_id ) {
			return new WP_Error( 'week_claimed', __( 'Deze ISO-week is al toegewezen aan een andere afwijkende blauwdruk.', 'vtc-training-planner' ), array( 'status' => 409 ) );
		}
		$norm = VTC_TP_Schedule::normalize_iso_week( $iso );
		return rest_ensure_response( array( 'id' => (int) $new_id, 'iso_week' => $norm ) );
	}

	public function delete_deviation_week_rest( WP_REST_Request $req ) {
		$id = (int) $req['id'];
		global $wpdb;
		$p   = $wpdb->prefix;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}vtc_tp_deviation_week WHERE id = %d", $id ) );
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Niet gevonden.', 'vtc-training-planner' ), array( 'status' => 404 ) );
		}
		$this->db->delete_deviation_week( $id );
		return rest_ensure_response( array( 'deleted' => true, 'id' => $id ) );
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
