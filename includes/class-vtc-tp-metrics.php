<?php
/**
 * Prometheus metrics, audit log, public view counters.
 *
 * @package VTC_Training_Planner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VTC_TP_Metrics {

	const OPTION_TOKEN          = 'vtc_tp_metrics_token';
	const OPTION_VIEW_COUNTERS  = 'vtc_tp_public_week_view_counters';
	const OPTION_SAVE_COUNTERS  = 'vtc_tp_save_action_counters';
	const OPTION_LAST_SAVES     = 'vtc_tp_last_save_unixtimes';
	const AUDIT_RETENTION_DAYS  = 90;
	const AUDIT_MAX_ROWS        = 5000;

	/** @var VTC_TP_DB */
	private $db;

	public function __construct( VTC_TP_DB $db ) {
		$this->db = $db;
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Generate and store a new metrics token.
	 *
	 * @return string
	 */
	public static function regenerate_token() {
		$token = wp_generate_password( 32, false, false );
		update_option( self::OPTION_TOKEN, $token, false );
		return $token;
	}

	/**
	 * @return string
	 */
	public static function get_token() {
		$token = (string) get_option( self::OPTION_TOKEN, '' );
		if ( '' === $token ) {
			$token = self::regenerate_token();
		}
		return $token;
	}

	/**
	 * @param WP_REST_Request $req Request.
	 * @return bool|WP_Error
	 */
	public function permission_check( WP_REST_Request $req ) {
		$expected = self::get_token();
		$got      = (string) $req->get_param( 'token' );
		if ( '' === $got ) {
			$auth = (string) $req->get_header( 'authorization' );
			if ( preg_match( '/^\s*Bearer\s+(\S+)\s*$/i', $auth, $m ) ) {
				$got = $m[1];
			}
		}
		if ( '' === $got || ! hash_equals( $expected, $got ) ) {
			return new WP_Error( 'forbidden', __( 'Ongeldig of ontbrekend metrics-token.', 'vtc-training-planner' ), array( 'status' => 401 ) );
		}
		return true;
	}

	public function register_routes() {
		register_rest_route(
			'vtc-tp/v1',
			'/metrics',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_prometheus' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);
		register_rest_route(
			'vtc-tp/v1',
			'/metrics/audit',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_audit' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'limit' => array(
						'required' => false,
						'type'     => 'integer',
						'default'  => 50,
					),
				),
			)
		);
	}

	/**
	 * Log a mutating admin/planner action.
	 *
	 * @param string               $action      Action key.
	 * @param array<string, mixed> $context     Optional: object_type, object_id, blueprint_id, meta.
	 */
	public static function audit( $action, array $context = array() ) {
		$action = sanitize_key( (string) $action );
		if ( '' === $action ) {
			return;
		}

		$user      = wp_get_current_user();
		$user_id   = ( $user && $user->ID ) ? (int) $user->ID : 0;
		$user_login = ( $user && $user->user_login ) ? (string) $user->user_login : 'anonymous';
		$label_user = self::prometheus_label_value( $user_login );

		$object_type  = isset( $context['object_type'] ) ? sanitize_key( (string) $context['object_type'] ) : '';
		$object_id    = isset( $context['object_id'] ) ? (int) $context['object_id'] : 0;
		$blueprint_id = isset( $context['blueprint_id'] ) ? (int) $context['blueprint_id'] : 0;
		$meta         = isset( $context['meta'] ) && is_array( $context['meta'] ) ? $context['meta'] : array();
		$meta_json    = $meta ? wp_json_encode( $meta ) : null;
		$ip           = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		global $wpdb;
		$p = $wpdb->prefix;
		$wpdb->insert(
			"{$p}vtc_tp_audit_log",
			array(
				'created_at'   => current_time( 'mysql', true ),
				'user_id'      => $user_id,
				'user_login'   => $user_login,
				'action'       => $action,
				'object_type'  => $object_type,
				'object_id'    => $object_id > 0 ? $object_id : null,
				'blueprint_id' => $blueprint_id > 0 ? $blueprint_id : null,
				'meta'         => $meta_json,
				'ip'           => substr( $ip, 0, 64 ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		self::bump_save_counter( $action, $label_user );
		self::set_last_save( $action, $label_user, time() );
		self::cleanup_audit_log();
	}

	/**
	 * Count a public week schedule view.
	 *
	 * @param string $source shortcode|block|rest|rest_html
	 */
	public static function record_public_week_view( $source ) {
		$source = sanitize_key( (string) $source );
		if ( ! in_array( $source, array( 'shortcode', 'block', 'rest', 'rest_html' ), true ) ) {
			$source = 'other';
		}
		$counters = get_option( self::OPTION_VIEW_COUNTERS, array() );
		if ( ! is_array( $counters ) ) {
			$counters = array();
		}
		if ( ! isset( $counters[ $source ] ) ) {
			$counters[ $source ] = 0;
		}
		$counters[ $source ] = (int) $counters[ $source ] + 1;
		update_option( self::OPTION_VIEW_COUNTERS, $counters, false );
	}

	/**
	 * @param string $action Action.
	 * @param string $user   Prometheus-safe user label.
	 */
	private static function bump_save_counter( $action, $user ) {
		$key      = $action . "\0" . $user;
		$counters = get_option( self::OPTION_SAVE_COUNTERS, array() );
		if ( ! is_array( $counters ) ) {
			$counters = array();
		}
		if ( ! isset( $counters[ $key ] ) ) {
			$counters[ $key ] = 0;
		}
		$counters[ $key ] = (int) $counters[ $key ] + 1;
		update_option( self::OPTION_SAVE_COUNTERS, $counters, false );
	}

	/**
	 * @param string $action Action.
	 * @param string $user   User label.
	 * @param int    $ts     Unix timestamp.
	 */
	private static function set_last_save( $action, $user, $ts ) {
		$key  = $action . "\0" . $user;
		$last = get_option( self::OPTION_LAST_SAVES, array() );
		if ( ! is_array( $last ) ) {
			$last = array();
		}
		$last[ $key ] = (int) $ts;
		update_option( self::OPTION_LAST_SAVES, $last, false );
	}

	private static function cleanup_audit_log() {
		global $wpdb;
		$p = $wpdb->prefix;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::AUDIT_RETENTION_DAYS * DAY_IN_SECONDS ) );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$p}vtc_tp_audit_log WHERE created_at < %s",
				$cutoff
			)
		);
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}vtc_tp_audit_log" );
		if ( $count > self::AUDIT_MAX_ROWS ) {
			$drop = $count - self::AUDIT_MAX_ROWS;
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$p}vtc_tp_audit_log ORDER BY id ASC LIMIT %d",
					$drop
				)
			);
		}
	}

	/**
	 * @param WP_REST_Request $req Request.
	 * @return void
	 */
	public function rest_prometheus( WP_REST_Request $req ) {
		nocache_headers();
		header( 'Content-Type: text/plain; version=0.0.4; charset=utf-8' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Prometheus text format.
		echo $this->render_prometheus_text();
		exit;
	}

	/**
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function rest_audit( WP_REST_Request $req ) {
		$limit = (int) $req->get_param( 'limit' );
		if ( $limit < 1 ) {
			$limit = 50;
		}
		if ( $limit > 200 ) {
			$limit = 200;
		}
		$rows = $this->list_recent_audit( $limit );
		return rest_ensure_response(
			array(
				'items' => $rows,
				'count' => count( $rows ),
			)
		);
	}

	/**
	 * @param int $limit Max rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_recent_audit( $limit = 50 ) {
		global $wpdb;
		$p    = $wpdb->prefix;
		$limit = max( 1, min( 200, (int) $limit ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, created_at, user_id, user_login, action, object_type, object_id, blueprint_id, meta, ip
				FROM {$p}vtc_tp_audit_log
				ORDER BY id DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$out = array();
		foreach ( $rows as $r ) {
			$meta = null;
			if ( ! empty( $r['meta'] ) ) {
				$decoded = json_decode( (string) $r['meta'], true );
				$meta    = is_array( $decoded ) ? $decoded : null;
			}
			$out[] = array(
				'id'           => (int) $r['id'],
				'created_at'   => (string) $r['created_at'],
				'user_id'      => (int) $r['user_id'],
				'user_login'   => (string) $r['user_login'],
				'action'       => (string) $r['action'],
				'object_type'  => (string) $r['object_type'],
				'object_id'    => null !== $r['object_id'] ? (int) $r['object_id'] : null,
				'blueprint_id' => null !== $r['blueprint_id'] ? (int) $r['blueprint_id'] : null,
				'meta'         => $meta,
				'ip'           => (string) $r['ip'],
			);
		}
		return $out;
	}

	/**
	 * @return string Prometheus exposition format.
	 */
	public function render_prometheus_text() {
		$lines = array();
		$bp    = (int) $this->db->get_base_blueprint_id();

		$teams   = $bp ? count( $this->db->get_teams( $bp ) ) : 0;
		$venues  = $bp ? count( $this->db->get_venues_for_blueprint( $bp ) ) : 0;
		$draft   = $bp ? count( $this->db->get_slots_draft( $bp ) ) : 0;
		$pub     = $bp ? count( $this->db->get_slots_published( $bp ) ) : 0;
		$ex_weeks = 0;
		if ( $bp ) {
			$ex_weeks = count( $this->db->list_exception_weeks( $bp ) );
		}
		$differs = ( $bp && $this->db->draft_differs_from_published( $bp ) ) ? 1 : 0;

		global $wpdb;
		$p          = $wpdb->prefix;
		$audit_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}vtc_tp_audit_log" );

		$nevobo_items = 0;
		$code         = strtolower( preg_replace( '/[^a-z0-9]/', '', (string) $this->db->get_nevobo_code() ) );
		if ( $code ) {
			$cache = get_transient( 'vtc_tp_nevobo_prog_' . $code );
			if ( is_array( $cache ) ) {
				$nevobo_items = count( $cache );
			}
		}

		$lines[] = '# HELP vtc_tp_teams Number of teams on the base blueprint.';
		$lines[] = '# TYPE vtc_tp_teams gauge';
		$lines[] = 'vtc_tp_teams ' . $teams;

		$lines[] = '# HELP vtc_tp_venues Number of venues on the base blueprint.';
		$lines[] = '# TYPE vtc_tp_venues gauge';
		$lines[] = 'vtc_tp_venues ' . $venues;

		$lines[] = '# HELP vtc_tp_draft_slots Draft training slots on the base blueprint.';
		$lines[] = '# TYPE vtc_tp_draft_slots gauge';
		$lines[] = 'vtc_tp_draft_slots ' . $draft;

		$lines[] = '# HELP vtc_tp_published_slots Published training slots on the base blueprint.';
		$lines[] = '# TYPE vtc_tp_published_slots gauge';
		$lines[] = 'vtc_tp_published_slots ' . $pub;

		$lines[] = '# HELP vtc_tp_exception_weeks Exception weeks for the base blueprint.';
		$lines[] = '# TYPE vtc_tp_exception_weeks gauge';
		$lines[] = 'vtc_tp_exception_weeks ' . $ex_weeks;

		$lines[] = '# HELP vtc_tp_draft_differs 1 if draft differs from published on the base blueprint.';
		$lines[] = '# TYPE vtc_tp_draft_differs gauge';
		$lines[] = 'vtc_tp_draft_differs ' . $differs;

		$lines[] = '# HELP vtc_tp_nevobo_feed_items Cached Nevobo programma items (0 if no cache).';
		$lines[] = '# TYPE vtc_tp_nevobo_feed_items gauge';
		$lines[] = 'vtc_tp_nevobo_feed_items ' . $nevobo_items;

		$lines[] = '# HELP vtc_tp_audit_log_rows Rows in the audit log table.';
		$lines[] = '# TYPE vtc_tp_audit_log_rows gauge';
		$lines[] = 'vtc_tp_audit_log_rows ' . $audit_rows;

		$view_counters = get_option( self::OPTION_VIEW_COUNTERS, array() );
		if ( ! is_array( $view_counters ) ) {
			$view_counters = array();
		}
		$lines[] = '# HELP vtc_tp_public_week_views_total Public week schedule views by source.';
		$lines[] = '# TYPE vtc_tp_public_week_views_total counter';
		if ( empty( $view_counters ) ) {
			$lines[] = 'vtc_tp_public_week_views_total{source="shortcode"} 0';
		} else {
			foreach ( $view_counters as $src => $n ) {
				$lines[] = 'vtc_tp_public_week_views_total{source="' . self::prometheus_label_value( (string) $src ) . '"} ' . (int) $n;
			}
		}

		$save_counters = get_option( self::OPTION_SAVE_COUNTERS, array() );
		if ( ! is_array( $save_counters ) ) {
			$save_counters = array();
		}
		$lines[] = '# HELP vtc_tp_save_actions_total Planner/admin save actions by action and user.';
		$lines[] = '# TYPE vtc_tp_save_actions_total counter';
		if ( empty( $save_counters ) ) {
			$lines[] = 'vtc_tp_save_actions_total{action="none",user="none"} 0';
		} else {
			foreach ( $save_counters as $key => $n ) {
				$parts = explode( "\0", (string) $key, 2 );
				$act   = isset( $parts[0] ) ? $parts[0] : 'unknown';
				$user  = isset( $parts[1] ) ? $parts[1] : 'unknown';
				$lines[] = 'vtc_tp_save_actions_total{action="' . self::prometheus_label_value( $act ) . '",user="' . self::prometheus_label_value( $user ) . '"} ' . (int) $n;
			}
		}

		$last_saves = get_option( self::OPTION_LAST_SAVES, array() );
		if ( ! is_array( $last_saves ) ) {
			$last_saves = array();
		}
		$lines[] = '# HELP vtc_tp_last_save_unixtime Unix timestamp of the last save per action and user.';
		$lines[] = '# TYPE vtc_tp_last_save_unixtime gauge';
		if ( empty( $last_saves ) ) {
			$lines[] = 'vtc_tp_last_save_unixtime{action="none",user="none"} 0';
		} else {
			foreach ( $last_saves as $key => $ts ) {
				$parts = explode( "\0", (string) $key, 2 );
				$act   = isset( $parts[0] ) ? $parts[0] : 'unknown';
				$user  = isset( $parts[1] ) ? $parts[1] : 'unknown';
				$lines[] = 'vtc_tp_last_save_unixtime{action="' . self::prometheus_label_value( $act ) . '",user="' . self::prometheus_label_value( $user ) . '"} ' . (int) $ts;
			}
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Escape a Prometheus label value.
	 *
	 * @param string $raw Raw value.
	 * @return string
	 */
	public static function prometheus_label_value( $raw ) {
		$s = (string) $raw;
		$s = str_replace( array( '\\', "\n", '"' ), array( '\\\\', '\\n', '\\"' ), $s );
		if ( '' === $s ) {
			return 'unknown';
		}
		return $s;
	}
}
