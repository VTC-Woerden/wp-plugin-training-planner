<?php
/**
 * Shortcode, block, REST, HTML renderer.
 *
 * @package VTC_Training_Planner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VTC_TP_Public {

	/** @var VTC_TP_DB */
	private $db;
	/** @var VTC_TP_Nevobo */
	private $nevobo;
	/** @var VTC_TP_Schedule */
	private $schedule;

	public function __construct( VTC_TP_DB $db, VTC_TP_Nevobo $nevobo, VTC_TP_Schedule $schedule ) {
		$this->db       = $db;
		$this->nevobo   = $nevobo;
		$this->schedule = $schedule;
		add_action( 'init', array( $this, 'register_shortcode' ) );
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function assets() {
		wp_register_style( 'vtc-tp-public', VTC_TP_URL . 'assets/public.css', array(), VTC_TP_VERSION );
	}

	public function register_shortcode() {
		add_shortcode( 'vtc_training_week', array( $this, 'shortcode' ) );
	}

	public function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'week' => '',
			),
			$atts,
			'vtc_training_week'
		);
		$week = $atts['week'] ? VTC_TP_Schedule::normalize_iso_week( $atts['week'] ) : VTC_TP_Schedule::current_iso_week();
		if ( ! $week ) {
			$week = VTC_TP_Schedule::current_iso_week();
		}
		wp_enqueue_style( 'vtc-tp-public' );
		$data = $this->schedule->get_merged_week( $week, $this->nevobo );
		return self::render_week_html( $data, false );
	}

	public function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_script(
			'vtc-tp-block',
			VTC_TP_URL . 'assets/block.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-i18n', 'wp-block-editor' ),
			VTC_TP_VERSION,
			true
		);
		register_block_type(
			'vtc-training-planner/week',
			array(
				'editor_script'   => 'vtc-tp-block',
				'render_callback' => array( $this, 'render_block' ),
				'attributes'      => array(
					'week' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	public function render_block( $attributes ) {
		$attrs = is_array( $attributes ) ? $attributes : array();
		$week  = ! empty( $attrs['week'] ) ? VTC_TP_Schedule::normalize_iso_week( $attrs['week'] ) : VTC_TP_Schedule::current_iso_week();
		if ( ! $week ) {
			$week = VTC_TP_Schedule::current_iso_week();
		}
		wp_enqueue_style( 'vtc-tp-public' );
		$data = $this->schedule->get_merged_week( $week, $this->nevobo );
		return self::render_week_html( $data, false );
	}

	public function register_rest() {
		register_rest_route(
			'vtc-tp/v1',
			'/week/(?P<week>[0-9]{4}-[Ww][0-9]{1,2})',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_week' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function rest_week( WP_REST_Request $req ) {
		$week = VTC_TP_Schedule::normalize_iso_week( $req['week'] );
		if ( ! $week ) {
			return new WP_Error( 'bad_week', __( 'Ongeldige week', 'vtc-training-planner' ), array( 'status' => 400 ) );
		}
		$data = $this->schedule->get_merged_week( $week, $this->nevobo );
		return rest_ensure_response(
			array(
				'iso_week'        => $data['iso_week'],
				'used_exceptions' => $data['used_exceptions'],
				'events'          => $data['events'],
			)
		);
	}

	/**
	 * @param array{events: array, iso_week: string, used_exceptions: bool} $data
	 */
	public static function render_week_html( array $data, $is_admin = false ) {
		$tz   = wp_timezone();
		$wrap = $is_admin ? 'div' : 'div';
		ob_start();
		echo '<' . $wrap . ' class="vtc-tp-week" data-iso-week="' . esc_attr( $data['iso_week'] ) . '">';
		echo '<h2 class="vtc-tp-week-title">' . esc_html( sprintf( __( 'Week %s', 'vtc-training-planner' ), $data['iso_week'] ) ) . '</h2>';
		if ( ! empty( $data['used_exceptions'] ) ) {
			echo '<p class="vtc-tp-week-note">' . esc_html__( 'Deze week gebruikt een uitzonderingsrooster.', 'vtc-training-planner' ) . '</p>';
		}
		if ( empty( $data['events'] ) ) {
			echo '<p class="vtc-tp-week-empty">' . esc_html__( 'Geen trainingen of wedstrijden in deze week.', 'vtc-training-planner' ) . '</p>';
		} else {
			echo '<ul class="vtc-tp-events">';
			foreach ( $data['events'] as $ev ) {
				$start = ( new DateTimeImmutable( '@' . (int) $ev['start_ts'] ) )->setTimezone( $tz );
				$end   = ( new DateTimeImmutable( '@' . (int) $ev['end_ts'] ) )->setTimezone( $tz );
				$cls   = 'vtc-tp-ev vtc-tp-ev--' . esc_attr( $ev['type'] );
				if ( ! empty( $ev['conflict'] ) ) {
					$cls .= ' vtc-tp-ev--conflict';
				}
				echo '<li class="' . $cls . '">';
				echo '<span class="vtc-tp-ev-time">' . esc_html( $start->format( 'D j M H:i' ) ) . '–' . esc_html( $end->format( 'H:i' ) ) . '</span> ';
				echo '<span class="vtc-tp-ev-title">' . esc_html( $ev['title'] ) . '</span>';
				echo ' <span class="vtc-tp-ev-badge">' . esc_html( $ev['subtitle'] ) . '</span>';
				$where = trim( $ev['location_label'] . ( $ev['field_label'] ? ', ' . $ev['field_label'] : '' ) );
				if ( $where ) {
					echo '<br /><span class="vtc-tp-ev-where">' . esc_html( $where ) . '</span>';
				}
				echo '</li>';
			}
			echo '</ul>';
		}
		echo '</' . $wrap . '>';
		return ob_get_clean();
	}
}
