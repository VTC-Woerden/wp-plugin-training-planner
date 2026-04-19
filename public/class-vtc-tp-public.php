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
		add_action( 'init', array( $this, 'register_public_style' ), 5 );
		add_action( 'init', array( $this, 'register_week_nav_script' ), 6 );
		add_action( 'init', array( $this, 'register_shortcode' ), 8 );
		add_action( 'init', array( $this, 'register_block' ), 10 );
		add_action( 'rest_api_init', array( $this, 'register_rest' ) );
	}

	public function register_public_style() {
		wp_register_style( 'vtc-tp-public', VTC_TP_URL . 'assets/public.css', array(), VTC_TP_VERSION );
	}

	public function register_week_nav_script() {
		wp_register_script(
			'vtc-tp-week-nav',
			VTC_TP_URL . 'assets/week-nav.js',
			array(),
			VTC_TP_VERSION,
			true
		);
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
		$week_attr = isset( $atts['week'] ) ? trim( (string) $atts['week'] ) : '';
		$lock_week  = ( '' !== $week_attr );
		if ( $lock_week ) {
			$week = VTC_TP_Schedule::normalize_iso_week( $week_attr ) ?: VTC_TP_Schedule::current_iso_week_public_calendar();
		} else {
			$get = isset( $_GET['vtc_tp_week'] ) ? sanitize_text_field( wp_unslash( $_GET['vtc_tp_week'] ) ) : '';
			$week = VTC_TP_Schedule::normalize_iso_week( $get ) ?: VTC_TP_Schedule::current_iso_week_public_calendar();
		}
		if ( ! $week ) {
			$week = VTC_TP_Schedule::current_iso_week_public_calendar();
		}
		wp_enqueue_style( 'vtc-tp-public' );
		$data = $this->schedule->get_merged_week( $week, $this->nevobo );
		$data = $this->with_public_week_event_bridge( $data );
		return self::render_week_html( $data, false, self::week_nav_config( $data['iso_week'], $lock_week ) );
	}

	public function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_script(
			'vtc-tp-block',
			VTC_TP_URL . 'assets/block.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-i18n', 'wp-block-editor', 'wp-server-side-render' ),
			VTC_TP_VERSION,
			true
		);
		register_block_type(
			'vtc-training-planner/week',
			array(
				'editor_script'   => 'vtc-tp-block',
				'editor_style'    => 'vtc-tp-public',
				'render_callback' => array( $this, 'render_block' ),
				'style'           => 'vtc-tp-public',
				'attributes'      => array(
					'week' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	public function render_block( $attributes, $content = '', $block = null ) {
		$attrs     = is_array( $attributes ) ? $attributes : array();
		$week_attr = isset( $attrs['week'] ) ? trim( (string) $attrs['week'] ) : '';
		$lock_week = ( '' !== $week_attr );
		if ( $lock_week ) {
			$week = VTC_TP_Schedule::normalize_iso_week( $week_attr ) ?: VTC_TP_Schedule::current_iso_week_public_calendar();
		} else {
			$get = isset( $_GET['vtc_tp_week'] ) ? sanitize_text_field( wp_unslash( $_GET['vtc_tp_week'] ) ) : '';
			$week = VTC_TP_Schedule::normalize_iso_week( $get ) ?: VTC_TP_Schedule::current_iso_week_public_calendar();
		}
		if ( ! $week ) {
			$week = VTC_TP_Schedule::current_iso_week_public_calendar();
		}
		wp_enqueue_style( 'vtc-tp-public' );
		$data = $this->schedule->get_merged_week( $week, $this->nevobo );
		$data = $this->with_public_week_event_bridge( $data );

		return self::render_week_html( $data, false, self::week_nav_config( $data['iso_week'], $lock_week ) );
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
		register_rest_route(
			'vtc-tp/v1',
			'/week-html',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_week_html' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'week' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
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
				'iso_week'                 => $data['iso_week'],
				'used_exceptions'          => $data['used_exceptions'],
				'uses_deviation_blueprint' => ! empty( $data['uses_deviation_blueprint'] ),
				'effective_blueprint_id'   => isset( $data['effective_blueprint_id'] ) ? (int) $data['effective_blueprint_id'] : null,
				'events'                   => $data['events'],
			)
		);
	}

	/**
	 * HTML-fragment voor het weekrooster (AJAX), zonder toolbar/shell.
	 */
	public function rest_week_html( WP_REST_Request $req ) {
		$week = VTC_TP_Schedule::normalize_iso_week( (string) $req->get_param( 'week' ) );
		if ( ! $week ) {
			return new WP_Error( 'bad_week', __( 'Ongeldige week', 'vtc-training-planner' ), array( 'status' => 400 ) );
		}
		$data = $this->schedule->get_merged_week( $week, $this->nevobo );
		$data = $this->with_public_week_event_bridge( $data );
		$html = self::get_week_calendar_html( $data, false, true );
		$prev      = VTC_TP_Schedule::shift_iso_week( $data['iso_week'], -1 );
		$next      = VTC_TP_Schedule::shift_iso_week( $data['iso_week'], 1 );
		$week_lbl  = sprintf( __( 'Week %s', 'vtc-training-planner' ), $data['iso_week'] );
		return rest_ensure_response(
			array(
				'html'       => $html,
				'iso_week'   => $data['iso_week'],
				'week_label' => $week_lbl,
				'prev_iso'   => $prev ? $prev : '',
				'next_iso'   => $next ? $next : '',
			)
		);
	}

	/**
	 * Alleen het kalender-deel (één .vtc-tp-week), voor AJAX of volledige pagina.
	 *
	 * @param array{events: array, iso_week: string, used_exceptions: bool} $data
	 * @param bool                                                             $show_main_title Hoofdtitel "Week …" in de week-header (uit bij AJAX-toolbar).
	 * @param bool                                                             $public_week_layout Zondag vóór ISO-maandag t/m zaterdag (voorkant); false = ISO ma–zo (admin).
	 */
	public static function get_week_calendar_html( array $data, $show_main_title, $public_week_layout = false ) {
		$tz = wp_timezone();
		ob_start();
		echo '<div class="vtc-tp-week vtc-tp-week--visual" data-iso-week="' . esc_attr( $data['iso_week'] ) . '">';
		$has_notes = ! empty( $data['used_exceptions'] ) || ! empty( $data['uses_deviation_blueprint'] );
		if ( $show_main_title || $has_notes ) {
			echo '<header class="vtc-tp-week-header' . ( $show_main_title ? '' : ' vtc-tp-week-header--notes-only' ) . '">';
			if ( $show_main_title ) {
				echo '<h2 class="vtc-tp-week-title">' . esc_html( sprintf( __( 'Week %s', 'vtc-training-planner' ), $data['iso_week'] ) ) . '</h2>';
			}
			if ( ! empty( $data['used_exceptions'] ) ) {
				echo '<p class="vtc-tp-week-note">' . esc_html__( 'Deze week gebruikt een uitzonderingsrooster.', 'vtc-training-planner' ) . '</p>';
			}
			if ( ! empty( $data['uses_deviation_blueprint'] ) ) {
				echo '<p class="vtc-tp-week-note">' . esc_html__( 'Deze week volgt een afwijkende blauwdruk.', 'vtc-training-planner' ) . '</p>';
			}
			echo '</header>';
		}

		if ( empty( $data['events'] ) ) {
			echo '<p class="vtc-tp-week-empty">' . esc_html__( 'Geen trainingen of wedstrijden in deze week.', 'vtc-training-planner' ) . '</p>';
			echo '</div>';
			return ob_get_clean();
		}

		$by_date = self::week_events_grouped_by_date( $data['events'], $tz );
		$monday  = self::monday_of_iso_week_string( $data['iso_week'], $tz );
		if ( ! $monday ) {
			echo '<p class="vtc-tp-week-empty">' . esc_html__( 'Ongeldige week.', 'vtc-training-planner' ) . '</p></div>';
			return ob_get_clean();
		}

		if ( $public_week_layout ) {
			$anchor    = $monday->modify( '-1 day' );
			$day_names = VTC_TP_Schedule::public_calendar_day_names();
		} else {
			$anchor    = $monday;
			$day_names = VTC_TP_Schedule::team_day_names();
		}

		echo '<div class="vtc-tp-days">';

		for ( $i = 0; $i < 7; $i++ ) {
			$day_dt = $anchor->modify( '+' . $i . ' days' );
			$ymd    = $day_dt->format( 'Y-m-d' );
			$day_ts = $day_dt->getTimestamp();
			$evs    = isset( $by_date[ $ymd ] ) ? $by_date[ $ymd ] : array();

			// Publiek: kalender-zondag (slot 0) en -zaterdag (slot 6) verbergen bij geen items.
			// Admin (ISO): zaterdag (5) en zondag (6) verbergen bij geen items.
			if ( $public_week_layout ) {
				if ( empty( $evs ) && ( 0 === $i || 6 === $i ) ) {
					continue;
				}
			} elseif ( empty( $evs ) && ( 5 === $i || 6 === $i ) ) {
				continue;
			}

			echo '<section class="vtc-tp-day" data-dow="' . (int) $i . '" data-date="' . esc_attr( $ymd ) . '">';
			echo '<div class="vtc-tp-day-head">';
			echo '<div class="vtc-tp-day-head-main">';
			echo '<span class="vtc-tp-day-name">' . esc_html( $day_names[ $i ] ?? '' ) . '</span> ';
			echo '<time class="vtc-tp-day-date" datetime="' . esc_attr( $ymd ) . '">' . esc_html( date_i18n( 'j F Y', $day_ts ) ) . '</time>';
			echo '</div>';
			echo '</div>';

			if ( empty( $evs ) ) {
				echo '<p class="vtc-tp-day-empty">' . esc_html__( 'Geen trainingen of wedstrijden.', 'vtc-training-planner' ) . '</p>';
				echo '</section>';
				continue;
			}

			$win       = self::day_minute_window( $evs, $tz, $ymd );
			$t0        = $win['t0'];
			$t1        = $win['t1'];
			$span      = max( 1, $t1 - $t0 );
			$hours_css = $span / 60.0;

			$lanes = self::lanes_for_day_events( $evs );
			echo '<div class="vtc-tp-day-grid"><div class="vtc-tp-day-grid-inner" style="--vtc-tp-hours:' . esc_attr( (string) round( $hours_css, 4 ) ) . ';">';
			echo '<div class="vtc-tp-corner" aria-hidden="true"></div>';
			echo '<div class="vtc-tp-time-ruler" aria-hidden="true">';
			for ( $h = (int) floor( $t0 / 60 ); $h <= (int) ceil( $t1 / 60 ); $h++ ) {
				if ( $h < 0 || $h > 24 ) {
					continue;
				}
				$hm = $h * 60;
				if ( $hm < $t0 || $hm > $t1 ) {
					continue;
				}
				$left_pct = ( ( $hm - $t0 ) / $span ) * 100.0;
				echo '<span class="vtc-tp-time-tick" style="left:' . esc_attr( (string) round( $left_pct, 4 ) ) . '%"><span class="vtc-tp-time-tick-label">' . esc_html( sprintf( '%02d:00', $h % 24 ) ) . '</span></span>';
			}
			echo '</div>';

			foreach ( $lanes as $lane_label ) {
				$lane_esc = esc_html( $lane_label );
				echo '<div class="vtc-tp-lane-label">' . $lane_esc . '</div>';
				echo '<div class="vtc-tp-lane-body">';
				foreach ( $evs as $ev ) {
					if ( self::event_lane_label( $ev ) !== $lane_label ) {
						continue;
					}
					$start = ( new DateTimeImmutable( '@' . (int) $ev['start_ts'] ) )->setTimezone( $tz );
					$end   = ( new DateTimeImmutable( '@' . (int) $ev['end_ts'] ) )->setTimezone( $tz );
					$sm    = (int) $start->format( 'H' ) * 60 + (int) $start->format( 'i' );
					$em    = (int) $end->format( 'H' ) * 60 + (int) $end->format( 'i' );
					if ( $end->format( 'Y-m-d' ) !== $ymd ) {
						$em = 24 * 60;
					}
					$em        = max( $em, $sm + 15 );
					$sm        = max( $t0, min( $sm, $t1 ) );
					$em        = max( $sm + 15, min( $em, $t1 ) );
					$left_pct  = ( ( $sm - $t0 ) / $span ) * 100.0;
					$width_pct = ( ( $em - $sm ) / $span ) * 100.0;
					$left_pct  = max( 0, min( 100, $left_pct ) );
					$width_pct = max( 0.35, min( 100 - $left_pct, $width_pct ) );

					$cls = 'vtc-tp-block vtc-tp-block--' . sanitize_html_class( $ev['type'] );
					if ( ! empty( $ev['conflict'] ) ) {
						$cls .= ' vtc-tp-block--conflict';
					}
					$bg = self::event_block_color( $ev );
					echo '<div class="' . esc_attr( $cls ) . '" style="left:' . esc_attr( (string) round( $left_pct, 4 ) ) . '%;width:' . esc_attr( (string) round( $width_pct, 4 ) ) . '%;background:' . esc_attr( $bg ) . '">';
					echo '<span class="vtc-tp-block-title">' . esc_html( $ev['title'] ) . '</span>';
					echo '<span class="vtc-tp-block-times"><span class="vtc-tp-block-start">' . esc_html( $start->format( 'H:i' ) ) . '</span><span class="vtc-tp-block-sep" aria-hidden="true">–</span><span class="vtc-tp-block-end">' . esc_html( $end->format( 'H:i' ) ) . '</span></span>';
					echo '</div>';
				}
				echo '</div>';
			}

			echo '</div></div></section>';
		}

		echo '</div></div>';
		return ob_get_clean();
	}

	/**
	 * @param array{events: array, iso_week: string, used_exceptions: bool} $data
	 * @param array{enabled?:bool,prev_iso?:string,next_iso?:string}|null     $nav_config
	 */
	public static function render_week_html( array $data, $is_admin = false, $nav_config = null ) {
		$nav = ( ! $is_admin && is_array( $nav_config ) && ! empty( $nav_config['enabled'] ) )
			? $nav_config
			: array( 'enabled' => false );

		$ajax_nav = ! $is_admin && ! empty( $nav['enabled'] ) && ! empty( $nav['prev_iso'] ) && ! empty( $nav['next_iso'] );

		ob_start();
		if ( $ajax_nav ) {
			wp_enqueue_script( 'vtc-tp-week-nav' );
			wp_localize_script(
				'vtc-tp-week-nav',
				'vtcTpWeekNav',
				array(
					'url'    => untrailingslashit( rest_url( 'vtc-tp/v1/week-html' ) ),
					'errMsg' => __( 'Week laden mislukt.', 'vtc-training-planner' ),
				)
			);
		}

		echo '<div class="vtc-tp-week-shell"';
		if ( $ajax_nav ) {
			echo ' data-ajax-week="1" data-prev-iso="' . esc_attr( $nav['prev_iso'] ) . '" data-next-iso="' . esc_attr( $nav['next_iso'] ) . '" data-current-iso="' . esc_attr( $data['iso_week'] ) . '"';
		}
		echo '>';

		if ( $ajax_nav ) {
			echo '<div class="vtc-tp-week-header-row vtc-tp-week-toolbar">';
			echo '<button type="button" class="vtc-tp-week-nav-btn" data-nav-week="' . esc_attr( $nav['prev_iso'] ) . '" aria-label="' . esc_attr__( 'Vorige week', 'vtc-training-planner' ) . '"><span class="vtc-tp-week-nav-icon" aria-hidden="true">&#8592;</span></button>';
			echo '<div class="vtc-tp-week-title-wrap"><h2 class="vtc-tp-week-title vtc-tp-week-title--toolbar">' . esc_html( sprintf( __( 'Week %s', 'vtc-training-planner' ), $data['iso_week'] ) ) . '</h2></div>';
			echo '<button type="button" class="vtc-tp-week-nav-btn" data-nav-week="' . esc_attr( $nav['next_iso'] ) . '" aria-label="' . esc_attr__( 'Volgende week', 'vtc-training-planner' ) . '"><span class="vtc-tp-week-nav-icon" aria-hidden="true">&#8594;</span></button>';
			echo '</div>';
			echo '<div class="vtc-tp-week-ajax-inner">';
		}

		echo self::get_week_calendar_html( $data, ! $ajax_nav, ! $is_admin );

		if ( $ajax_nav ) {
			echo '</div>';
		}
		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * Voeg zondag vóór ISO-maandag toe (events uit vorige ISO-week) en herbereken overlapvlaggen.
	 *
	 * @param array{events: array, iso_week: string, used_exceptions?: bool, uses_deviation_blueprint?: bool, effective_blueprint_id?: int|null} $data
	 * @return array
	 */
	private function with_public_week_event_bridge( array $data ) {
		$data['events'] = $this->schedule->merge_lead_sunday_events(
			$data['iso_week'],
			isset( $data['events'] ) && is_array( $data['events'] ) ? $data['events'] : array(),
			$this->nevobo
		);
		return $data;
	}

	/**
	 * Weeknavigatie via AJAX (alleen als week niet vast in shortcode/blok staat).
	 *
	 * @return array{enabled:bool,prev_iso?:string,next_iso?:string}
	 */
	private static function week_nav_config( $iso_week, $week_locked ) {
		if ( $week_locked ) {
			return array( 'enabled' => false );
		}
		$prev = VTC_TP_Schedule::shift_iso_week( $iso_week, -1 );
		$next = VTC_TP_Schedule::shift_iso_week( $iso_week, 1 );
		if ( ! $prev || ! $next ) {
			return array( 'enabled' => false );
		}
		return array(
			'enabled'   => true,
			'prev_iso'  => $prev,
			'next_iso'  => $next,
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $events
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function week_events_grouped_by_date( array $events, DateTimeZone $tz ) {
		$out = array();
		foreach ( $events as $ev ) {
			$start = ( new DateTimeImmutable( '@' . (int) $ev['start_ts'] ) )->setTimezone( $tz );
			$key   = $start->format( 'Y-m-d' );
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = array();
			}
			$out[ $key ][] = $ev;
		}
		foreach ( array_keys( $out ) as $k ) {
			usort(
				$out[ $k ],
				function ( $a, $b ) {
					return ( (int) $a['start_ts'] ) <=> ( (int) $b['start_ts'] );
				}
			);
		}
		return $out;
	}

	/**
	 * @return DateTimeImmutable|null
	 */
	private static function monday_of_iso_week_string( $iso_week, DateTimeZone $tz ) {
		$norm = VTC_TP_Schedule::normalize_iso_week( (string) $iso_week );
		if ( ! $norm || ! preg_match( '/^(\d{4})-W(\d{2})$/', $norm, $m ) ) {
			return null;
		}
		try {
			$y = (int) $m[1];
			$w = (int) $m[2];
			return ( new DateTimeImmutable( 'now', $tz ) )->setISODate( $y, $w, 1 )->setTime( 0, 0, 0 );
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $events Same calendar day.
	 * @return array{t0:int,t1:int} Minutes from midnight.
	 */
	private static function day_minute_window( array $events, DateTimeZone $tz, $ymd ) {
		$min_sm = null;
		$max_em = null;
		foreach ( $events as $ev ) {
			$start = ( new DateTimeImmutable( '@' . (int) $ev['start_ts'] ) )->setTimezone( $tz );
			$end   = ( new DateTimeImmutable( '@' . (int) $ev['end_ts'] ) )->setTimezone( $tz );
			$sm    = (int) $start->format( 'H' ) * 60 + (int) $start->format( 'i' );
			$em    = (int) $end->format( 'H' ) * 60 + (int) $end->format( 'i' );
			if ( $end->format( 'Y-m-d' ) !== $ymd ) {
				$em = 24 * 60;
			}
			$em = max( $em, $sm + 15 );
			if ( null === $min_sm || $sm < $min_sm ) {
				$min_sm = $sm;
			}
			if ( null === $max_em || $em > $max_em ) {
				$max_em = $em;
			}
		}
		$pad = 30;
		$t0  = (int) ( floor( ( $min_sm - $pad ) / 15 ) * 15 );
		$t1  = (int) ( ceil( ( $max_em + $pad ) / 15 ) * 15 );
		$t0  = max( 0, $t0 );
		$t1  = min( 24 * 60 + 120, $t1 );
		if ( $t1 <= $t0 ) {
			$t1 = min( 24 * 60 + 60, $t0 + 120 );
		}
		if ( $t1 - $t0 < 90 ) {
			$c  = (int) round( ( $t0 + $t1 ) / 2 );
			$t0 = max( 0, $c - 45 );
			$t1 = min( 24 * 60 + 60, $t0 + 90 );
		}
		return array( 't0' => $t0, 't1' => $t1 );
	}

	/**
	 * @param array<int, array<string, mixed>> $events
	 * @return array<int, string>
	 */
	private static function lanes_for_day_events( array $events ) {
		$labels = array();
		foreach ( $events as $ev ) {
			$labels[ self::event_lane_label( $ev ) ] = true;
		}
		$keys = array_keys( $labels );
		usort( $keys, 'strnatcasecmp' );
		return $keys;
	}

	/**
	 * @param array<string, mixed> $ev
	 */
	private static function event_lane_label( array $ev ) {
		$loc = isset( $ev['location_label'] ) ? trim( (string) $ev['location_label'] ) : '';
		$fld = isset( $ev['field_label'] ) ? trim( (string) $ev['field_label'] ) : '';
		if ( $loc && $fld ) {
			return $loc . ' — ' . $fld;
		}
		if ( $loc ) {
			return $loc;
		}
		if ( $fld ) {
			return $fld;
		}
		return __( 'Locatie onbekend', 'vtc-training-planner' );
	}

	/**
	 * @param array<string, mixed> $ev
	 */
	private static function event_block_color( array $ev ) {
		if ( isset( $ev['type'] ) && 'match' === $ev['type'] ) {
			return '#2271b1';
		}
		$palette = array(
			'#e74c3c', '#3498db', '#2ecc71', '#f39c12', '#9b59b6',
			'#1abc9c', '#e67e22', '#2c3e50', '#e84393', '#00b894',
			'#6c5ce7', '#fd79a8', '#0984e3', '#d63031', '#00cec9',
		);
		$key = isset( $ev['title'] ) ? (string) $ev['title'] : 'x';
		$idx = abs( (int) crc32( $key ) ) % count( $palette );
		return $palette[ $idx ];
	}
}
