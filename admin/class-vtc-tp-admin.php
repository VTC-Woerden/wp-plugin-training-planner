<?php
/**
 * wp-admin menus and forms.
 *
 * @package VTC_Training_Planner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VTC_TP_Admin {

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
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'handle_post' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'admin_body_class', array( $this, 'admin_body_class' ) );
	}

	public function admin_body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && strpos( (string) $screen->id, 'vtc-training-planner-visual' ) !== false ) {
			$classes .= ' vtc-tp-planner-screen';
		}
		return $classes;
	}

	public function assets( $hook ) {
		if ( false === strpos( $hook, 'vtc-training' ) ) {
			return;
		}
		wp_enqueue_style( 'vtc-tp-admin', VTC_TP_URL . 'assets/admin.css', array(), VTC_TP_VERSION );

		if ( false !== strpos( $hook, 'vtc-training-planner-visual' ) ) {
			wp_enqueue_style(
				'vtc-tp-planner-admin',
				VTC_TP_URL . 'assets/planner-admin.css',
				array(),
				VTC_TP_VERSION
			);
			wp_enqueue_script(
				'vtc-tp-planner-admin',
				VTC_TP_URL . 'assets/planner-admin.js',
				array(),
				VTC_TP_VERSION,
				true
			);
			wp_localize_script(
				'vtc-tp-planner-admin',
				'vtcTpPlanner',
				array(
					'rest'             => esc_url_raw( rest_url( trailingslashit( 'vtc-tp/v1' ) ) ),
					'nonce'            => wp_create_nonce( 'wp_rest' ),
					'stam'             => esc_url( admin_url( 'admin.php?page=vtc-training-stamdata' ) ),
					'baseBlueprintId'  => (int) $this->db->get_base_blueprint_id(),
					'currentIsoWeek'   => VTC_TP_Schedule::current_iso_week(),
					'i18n'   => array(
						'loadErr'    => __( 'Laden mislukt.', 'vtc-training-planner' ),
						'loadErrHint' => __( 'Wissel hierboven tussen blauwdruk en week, of klik op Herladen. Controleer of de REST API bereikbaar is en of er een blauwdruk bestaat.', 'vtc-training-planner' ),
						'saveErr'    => __( 'Opslaan mislukt.', 'vtc-training-planner' ),
						'published'  => __( 'Gepubliceerd: live rooster bijgewerkt.', 'vtc-training-planner' ),
						'deleted'    => __( 'Blok verwijderd.', 'vtc-training-planner' ),
						'noBp'       => __( 'Geen blauwdruk. Activeer de plugin opnieuw of neem contact op met de beheerder.', 'vtc-training-planner' ),
						'noVenues'   => __( 'Nog geen locaties/velden. Vul eerst stamdata in.', 'vtc-training-planner' ),
						'noTeams'    => __( 'Nog geen teams. Vul eerst stamdata in.', 'vtc-training-planner' ),
						'draftHint'  => __( 'Concept wijkt af van het gepubliceerde rooster.', 'vtc-training-planner' ),
						'publish'    => __( 'Publiceren (live)', 'vtc-training-planner' ),
						'saveDraft'  => __( 'Concept opslaan', 'vtc-training-planner' ),
						'savedAll'   => __( 'Alle wijzigingen opgeslagen.', 'vtc-training-planner' ),
						'saving'     => __( 'Bezig met opslaan…', 'vtc-training-planner' ),
						'dirtyBanner' => __( 'Je hebt niet-opgeslagen wijzigingen. Klik op “Concept opslaan” om ze naar de server te sturen.', 'vtc-training-planner' ),
						'reloadLose' => __( 'Niet-opgeslagen wijzigingen gaan verloren. Toch herladen?', 'vtc-training-planner' ),
						'publishConfirm' => __( 'Gepubliceerd rooster vervangen door dit concept?', 'vtc-training-planner' ),
						'publishSaveFirst' => __( 'Niet-opgeslagen wijzigingen worden eerst opgeslagen. Daarna het live rooster vervangen door dit concept?', 'vtc-training-planner' ),
						'reload'     => __( 'Herladen', 'vtc-training-planner' ),
						'helpDrag'   => __( 'Sleep een team naar een baan (veld) op de tijdas om een training te plannen. Sleep blokken horizontaal om te verplaatsen; sleep de linkerrand om te beginnen, de rechterrand om de duur te wijzigen. Met Ctrl (⌘ op Mac) klik je meerdere blokken aan en verschuif je ze samen in de tijd. Inhuurblokken bewerk je in modus “Zaal/veld (inhuur)”. Dubbelklik of × om te verwijderen. Wijzigingen blijven lokaal tot je op “Concept opslaan” drukt.', 'vtc-training-planner' ),
						'saved'      => __( 'Opgeslagen.', 'vtc-training-planner' ),
						'added'      => __( 'Training toegevoegd.', 'vtc-training-planner' ),
						'modeTeams'  => __( 'Teamrooster', 'vtc-training-planner' ),
						'modeInhuur' => __( 'Zaal/veld (inhuur)', 'vtc-training-planner' ),
						'inhuurHelp' => __( 'Sleep horizontaal op een baan om niet-beschikbare tijden te tekenen (zoals in de Team-app). Sleep verticaal over meerdere velden om dezelfde periode op alle geraakte banen te zetten. Korte klik = 15 min. Blokken: horizontaal slepen = verplaatsen, linker/rechterrand = begin/einde, dubbelklik of Delete = verwijderen. Druk op “Concept opslaan” om naar de server te schrijven.', 'vtc-training-planner' ),
						'inhuurBanner' => __( 'Modus: zaal/veld — teamblokken zijn verborgen; je tekent alleen beschikbaarheid (inhuur / geen huur).', 'vtc-training-planner' ),
						'unavailAdded' => __( 'Niet-beschikbaar toegevoegd.', 'vtc-training-planner' ),
						'unavailMulti' => __( 'Niet-beschikbaar toegevoegd op meerdere velden.', 'vtc-training-planner' ),
						'unavailDel' => __( 'Inhuurblok verwijderd.', 'vtc-training-planner' ),
						'noTeamsSidebar' => __( 'Nog geen teams in stamdata — alleen inhuur is hier zinvol, of voeg teams toe.', 'vtc-training-planner' ),
						'viewBlueprint' => __( 'Blauwdruk', 'vtc-training-planner' ),
						'viewWeek' => __( 'Week', 'vtc-training-planner' ),
						'weekIsoLabel' => __( 'ISO-week', 'vtc-training-planner' ),
						'weekHelp' => __( 'Kies een ISO-week. Zonder afwijkende week zie je het blauwdruk-rooster ter referentie (niet bewerkbaar). Met een afwijkende week overschrijf je dat rooster alleen voor die week op de site.', 'vtc-training-planner' ),
						'weekNoExceptionHint' => __( 'Geen afwijkende week voor deze week — onderstaand patroon komt uit de blauwdruk (concept/gepubliceerd). Klik op de knop om een afwijkende week te starten (kopie van dat patroon).', 'vtc-training-planner' ),
						'weekHasExceptionHint' => __( 'Deze week heeft een afwijkend rooster; na opslaan is dit zichtbaar in het weekoverzicht i.p.v. het blauwdruk-patroon.', 'vtc-training-planner' ),
						'createExceptionWeek' => __( 'Afwijkende week aanmaken', 'vtc-training-planner' ),
						'deleteExceptionWeek' => __( 'Afwijkende week verwijderen', 'vtc-training-planner' ),
						'deleteExceptionConfirm' => __( 'Afwijkende week en alle bijbehorende trainingen verwijderen? De blauwdruk geldt dan weer voor deze week.', 'vtc-training-planner' ),
						'exceptionCreated' => __( 'Afwijkende week aangemaakt. Je kunt nu aanpassen en opslaan.', 'vtc-training-planner' ),
						'exceptionDeleted' => __( 'Afwijkende week verwijderd.', 'vtc-training-planner' ),
						'exceptionExists' => __( 'Er bestaat al een afwijkende week voor deze ISO-week.', 'vtc-training-planner' ),
						'weekNavPrev' => __( 'Vorige week', 'vtc-training-planner' ),
						'weekNavNext' => __( 'Volgende week', 'vtc-training-planner' ),
						'existingExceptions' => __( 'Afwijkende weken', 'vtc-training-planner' ),
						'badWeek' => __( 'Ongeldige ISO-week.', 'vtc-training-planner' ),
						'blueprintLabel' => __( 'Blauwdruk', 'vtc-training-planner' ),
						'deviationActiveWeek' => __( 'Deze week: afwijkende blauwdruk (zonder aparte uitzonderingsweek). Wijzigingen gaan naar het weekpatroon van die blauwdruk.', 'vtc-training-planner' ),
						'versionLabel' => __( 'Versie (bewerken)', 'vtc-training-planner' ),
						'versionLive' => __( '(live)', 'vtc-training-planner' ),
						'newConceptVersion' => __( 'Nieuw concept van live', 'vtc-training-planner' ),
						'newConceptVersionPrompt' => __( 'Label voor de nieuwe conceptversie (optioneel):', 'vtc-training-planner' ),
						'versionSwitchErr' => __( 'Versie wisselen mislukt.', 'vtc-training-planner' ),
						'versionCreated' => __( 'Conceptversie aangemaakt.', 'vtc-training-planner' ),
						'teamOverviewTitle' => __( 'Team kiezen', 'vtc-training-planner' ),
						'teamOverviewHint' => __( 'Gebaseerd op “trainings per week” uit stamdata en het aantal geplande trainingen in dit overzicht.', 'vtc-training-planner' ),
						'teamOverviewShowAll' => __( 'Toon alle teams', 'vtc-training-planner' ),
						'teamOverviewEmpty' => __( 'Alle teams hebben hun aantal trainingen gepland (of geen verplicht aantal).', 'vtc-training-planner' ),
						'teamOverviewClose' => __( 'Sluiten', 'vtc-training-planner' ),
						'teamOverviewChipTitle' => __( 'Gepland / nodig', 'vtc-training-planner' ),
						'teamOverviewLaneHelp' => __( 'Klik op een lege baan: kies een team; de training komt op die tijd en plek. Of sleep een team vanuit de zijbalk.', 'vtc-training-planner' ),
					),
				)
			);
		}
	}

	public function menu() {
		$cap = 'manage_options';
		add_menu_page(
			__( 'Training', 'vtc-training-planner' ),
			__( 'Training', 'vtc-training-planner' ),
			$cap,
			'vtc-training-planner',
			array( $this, 'render_settings' ),
			'dashicons-calendar-alt',
			58
		);
		add_submenu_page( 'vtc-training-planner', __( 'Instellingen', 'vtc-training-planner' ), __( 'Instellingen', 'vtc-training-planner' ), $cap, 'vtc-training-planner', array( $this, 'render_settings' ) );
		add_submenu_page( 'vtc-training-planner', __( 'Blauwdrukken', 'vtc-training-planner' ), __( 'Blauwdrukken', 'vtc-training-planner' ), $cap, 'vtc-training-blueprints', array( $this, 'render_blueprints' ) );
		add_submenu_page( 'vtc-training-planner', __( 'Stamdata', 'vtc-training-planner' ), __( 'Stamdata', 'vtc-training-planner' ), $cap, 'vtc-training-stamdata', array( $this, 'render_stamdata' ) );
		add_submenu_page( 'vtc-training-planner', __( 'Rooster (visueel)', 'vtc-training-planner' ), __( 'Rooster (visueel)', 'vtc-training-planner' ), $cap, 'vtc-training-planner-visual', array( $this, 'render_planner_visual' ) );
		add_submenu_page( 'vtc-training-planner', __( 'Rooster (lijst)', 'vtc-training-planner' ), __( 'Rooster (lijst)', 'vtc-training-planner' ), $cap, 'vtc-training-rooster', array( $this, 'render_rooster' ) );
		add_submenu_page( 'vtc-training-planner', __( 'Uitzonderingsweken', 'vtc-training-planner' ), __( 'Uitzonderingsweken', 'vtc-training-planner' ), $cap, 'vtc-training-exceptions', array( $this, 'render_exceptions' ) );
		add_submenu_page( 'vtc-training-planner', __( 'Weekoverzicht', 'vtc-training-planner' ), __( 'Weekoverzicht', 'vtc-training-planner' ), $cap, 'vtc-training-week', array( $this, 'render_week_preview' ) );
	}

	private function bp() {
		if ( isset( $_REQUEST['blueprint_id'] ) ) {
			$bid = absint( $_REQUEST['blueprint_id'] );
			if ( $bid > 0 && $this->db->get_blueprint( $bid ) ) {
				return $bid;
			}
		}
		return $this->db->get_base_blueprint_id();
	}

	/**
	 * Dagselectie zoals Team-app: 0 = maandag … 6 = zondag.
	 */
	private function dow_select_html( $name, $selected = 0 ) {
		$names = VTC_TP_Schedule::team_day_names();
		$html  = '<select name="' . esc_attr( $name ) . '">';
		for ( $i = 0; $i <= 6; $i++ ) {
			$html .= '<option value="' . (int) $i . '"' . selected( (int) $selected, $i, false ) . '>' . esc_html( $names[ $i ] ) . ' (' . (int) $i . ')</option>';
		}
		$html .= '</select>';
		return $html;
	}

	/**
	 * @param int $dow 0–6
	 */
	private function dow_label( $dow ) {
		$names = VTC_TP_Schedule::team_day_names();
		$d     = (int) $dow;
		return isset( $names[ $d ] ) ? $names[ $d ] . ' (' . $d . ')' : (string) $dow;
	}

	/**
	 * @param string $type hall|field
	 */
	private function venue_type_label( $type ) {
		return 'field' === $type
			? __( 'Buiten', 'vtc-training-planner' )
			: __( 'Zaal', 'vtc-training-planner' );
	}

	public function handle_post() {
		if ( ! isset( $_POST['vtc_tp_action'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'vtc_tp_admin' ) ) {
			return;
		}

		global $wpdb;
		$p   = $wpdb->prefix;
		$bp  = $this->bp();
		$act = sanitize_text_field( wp_unslash( $_POST['vtc_tp_action'] ) );

		switch ( $act ) {
			case 'save_settings':
				update_option( 'vtc_tp_cache_ttl', max( 60, absint( $_POST['cache_ttl'] ?? 1800 ) ) );
				$scope = sanitize_text_field( wp_unslash( $_POST['matches_scope'] ?? 'home_halls' ) );
				update_option( 'vtc_tp_matches_scope', in_array( $scope, array( 'home_halls', 'all' ), true ) ? $scope : 'home_halls' );
				add_settings_error( 'vtc_tp', 'ok', __( 'Instellingen opgeslagen.', 'vtc-training-planner' ), 'success' );
				break;

			case 'save_club':
				$old_code = strtolower( preg_replace( '/[^a-z0-9]/', '', $this->db->get_nevobo_code() ) );
				$this->db->save_club(
					array(
						'name'         => wp_unslash( $_POST['club_name'] ?? '' ),
						'nevobo_code'  => wp_unslash( $_POST['club_nevobo_code'] ?? '' ),
						'region'       => wp_unslash( $_POST['club_region'] ?? '' ),
						'logo_url'     => wp_unslash( $_POST['club_logo_url'] ?? '' ),
					)
				);
				$new_code = strtolower( preg_replace( '/[^a-z0-9]/', '', $this->db->get_nevobo_code() ) );
				if ( $old_code ) {
					delete_transient( 'vtc_tp_nevobo_prog_' . $old_code );
				}
				if ( $new_code ) {
					delete_transient( 'vtc_tp_nevobo_prog_' . $new_code );
				}
				add_settings_error( 'vtc_tp', 'club', __( 'Verenigingsgegevens opgeslagen.', 'vtc-training-planner' ), 'success' );
				break;

			case 'seed_vtc_skeleton':
				if ( count( $this->db->get_locations( $bp ) ) > 0 ) {
					break;
				}
				$wpdb->insert(
					"{$p}vtc_tp_location",
					array(
						'blueprint_id'       => $bp,
						'name'               => 'Thijs van der Polshal',
						'nevobo_venue_name'  => 'Thijs van der Polshal',
					),
					array( '%d', '%s', '%s' )
				);
				$loc_thijs = (int) $wpdb->insert_id;
				$wpdb->insert(
					"{$p}vtc_tp_location",
					array(
						'blueprint_id'       => $bp,
						'name'               => 'Essenlaan',
						'nevobo_venue_name'  => null,
					),
					array( '%d', '%s', '%s' )
				);
				$loc_essen = (int) $wpdb->insert_id;
				$venues_thijs = array(
					array( 'Veld 1', 'hall', 'veld-1' ),
					array( 'Veld 2', 'hall', 'veld-2' ),
					array( 'Veld 3', 'hall', 'veld-3' ),
					array( 'Veld 4', 'hall', 'veld-4' ),
				);
				$so = 0;
				foreach ( $venues_thijs as $row ) {
					$wpdb->insert(
						"{$p}vtc_tp_venue",
						array(
							'location_id'        => $loc_thijs,
							'name'               => $row[0],
							'venue_type'         => $row[1],
							'nevobo_field_slug'  => $row[2],
							'sort_order'         => $so++,
						),
						array( '%d', '%s', '%s', '%s', '%d' )
					);
				}
				$wpdb->insert(
					"{$p}vtc_tp_venue",
					array(
						'location_id'        => $loc_essen,
						'name'               => 'Veld 1',
						'venue_type'         => 'field',
						'nevobo_field_slug'  => null,
						'sort_order'         => 0,
					),
					array( '%d', '%s', '%s', '%s', '%d' )
				);
				$wpdb->insert(
					"{$p}vtc_tp_venue",
					array(
						'location_id'        => $loc_essen,
						'name'               => 'Veld 2',
						'venue_type'         => 'field',
						'nevobo_field_slug'  => null,
						'sort_order'         => 1,
					),
					array( '%d', '%s', '%s', '%s', '%d' )
				);
				require_once VTC_TP_DIR . 'includes/class-vtc-tp-team-seed.php';
				$seeded_teams = VTC_TP_Team_Seed::insert_all_if_empty( $wpdb, $bp );
				$msg          = __( 'Voorbeeldlocaties (Thijs + Essenlaan) toegevoegd zoals in Team seed.', 'vtc-training-planner' );
				if ( $seeded_teams > 0 ) {
					$msg .= ' ' . sprintf(
						/* translators: %d: number of teams inserted */
						_n( '%d team uit de Team-app toegevoegd.', '%d teams uit de Team-app toegevoegd.', $seeded_teams, 'vtc-training-planner' ),
						$seeded_teams
					);
				}
				add_settings_error( 'vtc_tp', 'seed', $msg, 'success' );
				break;

			case 'seed_vtc_teams':
				require_once VTC_TP_DIR . 'includes/class-vtc-tp-team-seed.php';
				$n = VTC_TP_Team_Seed::insert_all_if_empty( $wpdb, $bp );
				if ( $n > 0 ) {
					add_settings_error(
						'vtc_tp',
						'seed_teams',
						sprintf(
							/* translators: %d: number of teams */
							_n( '%d team uit de Team-app toegevoegd.', '%d teams uit de Team-app toegevoegd.', $n, 'vtc-training-planner' ),
							$n
						),
						'success'
					);
				} else {
					add_settings_error(
						'vtc_tp',
						'seed_teams_skip',
						__( 'Teams niet toegevoegd: er staan al teams in de blauwdruk (of blauwdruk ontbreekt).', 'vtc-training-planner' ),
						'info'
					);
				}
				break;

			case 'add_team':
				$wpdb->insert(
					"{$p}vtc_tp_team",
					array(
						'blueprint_id'           => $bp,
						'display_name'           => sanitize_text_field( wp_unslash( $_POST['display_name'] ?? '' ) ),
						'nevobo_team_type'       => sanitize_text_field( wp_unslash( $_POST['nevobo_team_type'] ?? '' ) ),
						'nevobo_number'          => max( 1, absint( $_POST['nevobo_number'] ?? 1 ) ),
						'sort_order'             => absint( $_POST['sort_order'] ?? 0 ),
						'trainings_per_week'     => max( 0, absint( $_POST['trainings_per_week'] ?? 2 ) ),
						'min_training_minutes'   => max( 1, absint( $_POST['min_training_minutes'] ?? 90 ) ),
						'max_training_minutes'   => max( 1, absint( $_POST['max_training_minutes'] ?? 90 ) ),
					),
					array( '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d' )
				);
				break;

			case 'save_all_teams':
				$teams_post = isset( $_POST['teams'] ) && is_array( $_POST['teams'] ) ? wp_unslash( $_POST['teams'] ) : array();
				foreach ( $teams_post as $tid => $row ) {
					$tid = absint( $tid );
					if ( $tid < 1 ) {
						continue;
					}
					$wpdb->update(
						"{$p}vtc_tp_team",
						array(
							'display_name'           => sanitize_text_field( $row['display_name'] ?? '' ),
							'nevobo_team_type'         => sanitize_text_field( $row['nevobo_team_type'] ?? '' ),
							'nevobo_number'            => max( 1, absint( $row['nevobo_number'] ?? 1 ) ),
							'sort_order'               => absint( $row['sort_order'] ?? 0 ),
							'trainings_per_week'       => max( 0, absint( $row['trainings_per_week'] ?? 2 ) ),
							'min_training_minutes'     => max( 1, absint( $row['min_training_minutes'] ?? 90 ) ),
							'max_training_minutes'     => max( 1, absint( $row['max_training_minutes'] ?? 90 ) ),
						),
						array( 'id' => $tid ),
						array( '%s', '%s', '%d', '%d', '%d', '%d', '%d' ),
						array( '%d' )
					);
				}
				add_settings_error( 'vtc_tp', 'teams', __( 'Teams opgeslagen.', 'vtc-training-planner' ), 'success' );
				break;

			case 'delete_team':
				$tid = absint( $_POST['team_id'] ?? 0 );
				$wpdb->delete( "{$p}vtc_tp_slot_draft", array( 'team_id' => $tid ), array( '%d' ) );
				$wpdb->delete( "{$p}vtc_tp_slot_published", array( 'team_id' => $tid ), array( '%d' ) );
				$wpdb->delete( "{$p}vtc_tp_exception_slot", array( 'team_id' => $tid ), array( '%d' ) );
				$wpdb->delete( "{$p}vtc_tp_team", array( 'id' => $tid ), array( '%d' ) );
				break;

			case 'add_location':
				$wpdb->insert(
					"{$p}vtc_tp_location",
					array(
						'blueprint_id'       => $bp,
						'name'               => sanitize_text_field( wp_unslash( $_POST['loc_name'] ?? '' ) ),
						'nevobo_venue_name'  => sanitize_text_field( wp_unslash( $_POST['nevobo_venue_name'] ?? '' ) ) ?: null,
					),
					array( '%d', '%s', '%s' )
				);
				break;

			case 'delete_location':
				$lid = absint( $_POST['location_id'] ?? 0 );
				$vids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}vtc_tp_venue WHERE location_id = %d", $lid ) );
				foreach ( $vids as $vid ) {
					$vid = (int) $vid;
					$wpdb->delete( "{$p}vtc_tp_venue_unavail", array( 'venue_id' => $vid ), array( '%d' ) );
					$wpdb->delete( "{$p}vtc_tp_slot_draft", array( 'venue_id' => $vid ), array( '%d' ) );
					$wpdb->delete( "{$p}vtc_tp_slot_published", array( 'venue_id' => $vid ), array( '%d' ) );
					$wpdb->delete( "{$p}vtc_tp_exception_slot", array( 'venue_id' => $vid ), array( '%d' ) );
					$wpdb->delete( "{$p}vtc_tp_venue", array( 'id' => $vid ), array( '%d' ) );
				}
				$wpdb->delete( "{$p}vtc_tp_location", array( 'id' => $lid ), array( '%d' ) );
				break;

			case 'add_venue':
				$vtype = sanitize_text_field( wp_unslash( $_POST['venue_type'] ?? 'hall' ) );
				if ( ! in_array( $vtype, array( 'hall', 'field' ), true ) ) {
					$vtype = 'hall';
				}
				$wpdb->insert(
					"{$p}vtc_tp_venue",
					array(
						'location_id'        => absint( $_POST['location_id'] ?? 0 ),
						'name'               => sanitize_text_field( wp_unslash( $_POST['venue_name'] ?? '' ) ),
						'venue_type'         => $vtype,
						'nevobo_field_slug'  => sanitize_text_field( wp_unslash( $_POST['nevobo_field_slug'] ?? '' ) ) ?: null,
						'sort_order'         => absint( $_POST['venue_sort'] ?? 0 ),
					),
					array( '%d', '%s', '%s', '%s', '%d' )
				);
				break;

			case 'delete_venue':
				$vid = absint( $_POST['venue_id'] ?? 0 );
				$wpdb->delete( "{$p}vtc_tp_venue_unavail", array( 'venue_id' => $vid ), array( '%d' ) );
				$wpdb->delete( "{$p}vtc_tp_slot_draft", array( 'venue_id' => $vid ), array( '%d' ) );
				$wpdb->delete( "{$p}vtc_tp_slot_published", array( 'venue_id' => $vid ), array( '%d' ) );
				$wpdb->delete( "{$p}vtc_tp_exception_slot", array( 'venue_id' => $vid ), array( '%d' ) );
				$wpdb->delete( "{$p}vtc_tp_venue", array( 'id' => $vid ), array( '%d' ) );
				break;

			case 'add_unavail':
				$dow_u = min( 6, max( 0, absint( $_POST['dow'] ?? 0 ) ) );
				$wpdb->insert(
					"{$p}vtc_tp_venue_unavail",
					array(
						'venue_id'     => absint( $_POST['venue_id'] ?? 0 ),
						'day_of_week'  => $dow_u,
						'start_time'   => sanitize_text_field( wp_unslash( $_POST['t_start'] ?? '18:00' ) ),
						'end_time'     => sanitize_text_field( wp_unslash( $_POST['t_end'] ?? '23:00' ) ),
					),
					array( '%d', '%d', '%s', '%s' )
				);
				break;

			case 'delete_unavail':
				$wpdb->delete( "{$p}vtc_tp_venue_unavail", array( 'id' => absint( $_POST['unavail_id'] ?? 0 ) ), array( '%d' ) );
				break;

			case 'add_slot_draft':
				$dow_s = min( 6, max( 0, absint( $_POST['dow'] ?? 0 ) ) );
				$wpdb->insert(
					"{$p}vtc_tp_slot_draft",
					array(
						'blueprint_id' => $bp,
						'team_id'      => absint( $_POST['team_id'] ?? 0 ),
						'venue_id'     => absint( $_POST['venue_id'] ?? 0 ),
						'day_of_week'  => $dow_s,
						'start_time'   => sanitize_text_field( wp_unslash( $_POST['t_start'] ?? '19:00' ) ),
						'end_time'     => sanitize_text_field( wp_unslash( $_POST['t_end'] ?? '20:30' ) ),
					),
					array( '%d', '%d', '%d', '%d', '%s', '%s' )
				);
				break;

			case 'delete_slot_draft':
				$wpdb->delete( "{$p}vtc_tp_slot_draft", array( 'id' => absint( $_POST['slot_id'] ?? 0 ) ), array( '%d' ) );
				break;

			case 'publish_slots':
				$this->db->publish_slots( $bp );
				add_settings_error( 'vtc_tp', 'pub', __( 'Gepubliceerd: live rooster bijgewerkt.', 'vtc-training-planner' ), 'success' );
				break;

			case 'add_exception_week':
				$iw = VTC_TP_Schedule::normalize_iso_week( wp_unslash( $_POST['iso_week'] ?? '' ) );
				if ( $iw ) {
					$wpdb->insert(
						"{$p}vtc_tp_exception_week",
						array(
							'blueprint_id' => $bp,
							'iso_week'     => $iw,
							'label'        => sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) ) ?: null,
						),
						array( '%d', '%s', '%s' )
					);
				}
				break;

			case 'delete_exception_week':
				$eid = absint( $_POST['exception_week_id'] ?? 0 );
				$wpdb->delete( "{$p}vtc_tp_exception_slot", array( 'exception_week_id' => $eid ), array( '%d' ) );
				$wpdb->delete( "{$p}vtc_tp_exception_week", array( 'id' => $eid ), array( '%d' ) );
				break;

			case 'add_exception_slot':
				$dow_e = min( 6, max( 0, absint( $_POST['dow'] ?? 0 ) ) );
				$wpdb->insert(
					"{$p}vtc_tp_exception_slot",
					array(
						'exception_week_id' => absint( $_POST['exception_week_id'] ?? 0 ),
						'team_id'           => absint( $_POST['team_id'] ?? 0 ),
						'venue_id'          => absint( $_POST['venue_id'] ?? 0 ),
						'day_of_week'       => $dow_e,
						'start_time'        => sanitize_text_field( wp_unslash( $_POST['t_start'] ?? '19:00' ) ),
						'end_time'          => sanitize_text_field( wp_unslash( $_POST['t_end'] ?? '20:30' ) ),
					),
					array( '%d', '%d', '%d', '%d', '%s', '%s' )
				);
				break;

			case 'delete_exception_slot':
				$wpdb->delete( "{$p}vtc_tp_exception_slot", array( 'id' => absint( $_POST['slot_id'] ?? 0 ) ), array( '%d' ) );
				break;

			case 'add_deviation_blueprint':
				$n = sanitize_text_field( wp_unslash( $_POST['deviation_name'] ?? '' ) );
				if ( $n ) {
					$this->db->insert_deviation_blueprint( $n, $this->db->get_base_blueprint_id() );
					add_settings_error( 'vtc_tp', 'dev_bp', __( 'Afwijkende blauwdruk toegevoegd. Vul stamdata en rooster per blauwdruk in.', 'vtc-training-planner' ), 'success' );
				}
				break;

			case 'add_deviation_week_admin':
				$dw_bp = absint( $_POST['deviation_blueprint_id'] ?? 0 );
				$iw    = VTC_TP_Schedule::normalize_iso_week( wp_unslash( $_POST['iso_week'] ?? '' ) );
				if ( $iw ) {
					$r = $this->db->insert_deviation_week( $dw_bp, $iw );
					if ( null === $r ) {
						add_settings_error( 'vtc_tp', 'dw_bad', __( 'Ongeldige afwijkende blauwdruk of week.', 'vtc-training-planner' ), 'error' );
					} elseif ( 0 === $r ) {
						add_settings_error( 'vtc_tp', 'dw_dup', __( 'Deze week is al toegewezen aan een andere afwijkende blauwdruk.', 'vtc-training-planner' ), 'error' );
					} else {
						add_settings_error( 'vtc_tp', 'dw_ok', __( 'Week toegevoegd.', 'vtc-training-planner' ), 'success' );
					}
				}
				break;

			case 'delete_deviation_week_admin':
				$this->db->delete_deviation_week( absint( $_POST['deviation_week_id'] ?? 0 ) );
				add_settings_error( 'vtc_tp', 'dw_del', __( 'Toewijzing verwijderd.', 'vtc-training-planner' ), 'success' );
				break;
		}
	}

	public function render_planner_visual() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap vtc-tp-planner-wrap"><h1>' . esc_html__( 'Rooster — visueel (blauwdruk)', 'vtc-training-planner' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Zelfde lay-out als de Team-app: dagen onder elkaar, per dag velden als rijen en tijd horizontaal (08:00–22:00). Wijzigingen gaan naar het conceptrooster; publiceer om live te zetten.', 'vtc-training-planner' );
		echo ' <a href="' . esc_url( admin_url( 'admin.php?page=vtc-training-rooster' ) ) . '">' . esc_html__( 'Lijstweergave', 'vtc-training-planner' ) . '</a></p>';
		echo '<div id="vtc-tp-planner-root" class="vtc-tp-planner-root" aria-live="polite"></div></div>';
	}

	public function render_blueprints() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		settings_errors( 'vtc_tp' );
		$bps   = $this->db->list_blueprints();
		$dweek = $this->db->list_all_deviation_weeks();
		$base  = $this->db->get_base_blueprint_id();
		?>
		<div class="wrap vtc-tp-wrap">
			<h1><?php esc_html_e( 'Blauwdrukken', 'vtc-training-planner' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Er is één basis-blauwdruk (kind “basis”). Afwijkende blauwdrukken hebben eigen stamdata en rooster; per gepubliceerde versie één live patroon. Wijs ISO-weken toe — die overrulen de basis in het weekoverzicht.', 'vtc-training-planner' ); ?></p>

			<h2><?php esc_html_e( 'Blauwdrukken', 'vtc-training-planner' ); ?></h2>
			<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Naam', 'vtc-training-planner' ); ?></th><th><?php esc_html_e( 'Type', 'vtc-training-planner' ); ?></th><th><?php esc_html_e( 'Stamdata / rooster', 'vtc-training-planner' ); ?></th></tr></thead><tbody>
			<?php foreach ( $bps as $b ) : ?>
				<tr>
					<td><?php echo esc_html( $b->name ); ?></td>
					<td><?php echo (int) $b->kind === VTC_TP_DB::KIND_DEVIATION ? esc_html__( 'Afwijkend', 'vtc-training-planner' ) : esc_html__( 'Basis', 'vtc-training-planner' ); ?></td>
					<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=vtc-training-stamdata&blueprint_id=' . (int) $b->id ) ); ?>"><?php esc_html_e( 'Stamdata', 'vtc-training-planner' ); ?></a>
						· <a href="<?php echo esc_url( admin_url( 'admin.php?page=vtc-training-planner-visual' ) ); ?>#bp=<?php echo (int) $b->id; ?>"><?php esc_html_e( 'Rooster (visueel)', 'vtc-training-planner' ); ?></a></td>
				</tr>
			<?php endforeach; ?>
			</tbody></table>

			<h2><?php esc_html_e( 'Afwijkende blauwdruk toevoegen', 'vtc-training-planner' ); ?></h2>
			<form method="post" class="vtc-tp-inline-form">
				<?php wp_nonce_field( 'vtc_tp_admin' ); ?>
				<input type="hidden" name="vtc_tp_action" value="add_deviation_blueprint" />
				<input name="deviation_name" placeholder="<?php esc_attr_e( 'Naam (bijv. Eindexamen / andere zalen)', 'vtc-training-planner' ); ?>" class="regular-text" required />
				<?php submit_button( __( 'Toevoegen', 'vtc-training-planner' ), 'secondary', '', false ); ?>
			</form>

			<?php
			$dev_bps = array_filter(
				$bps,
				function ( $b ) {
					return (int) $b->kind === VTC_TP_DB::KIND_DEVIATION;
				}
			);
			?>
			<?php if ( count( $dev_bps ) > 0 ) : ?>
			<h2><?php esc_html_e( 'Week toewijzen aan afwijkende blauwdruk', 'vtc-training-planner' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Elke ISO-week kan maar bij één afwijkende blauwdruk horen.', 'vtc-training-planner' ); ?></p>
			<form method="post" class="vtc-tp-inline-form">
				<?php wp_nonce_field( 'vtc_tp_admin' ); ?>
				<input type="hidden" name="vtc_tp_action" value="add_deviation_week_admin" />
				<select name="deviation_blueprint_id" required>
					<?php foreach ( $dev_bps as $b ) : ?>
						<option value="<?php echo (int) $b->id; ?>"><?php echo esc_html( $b->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<input name="iso_week" placeholder="2026-W20" required pattern="[0-9]{4}-[Ww][0-9]{1,2}" />
				<?php submit_button( __( 'Week toewijzen', 'vtc-training-planner' ), 'primary', '', false ); ?>
			</form>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Toegewezen afwijkingsweken', 'vtc-training-planner' ); ?></h2>
			<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Week', 'vtc-training-planner' ); ?></th><th><?php esc_html_e( 'Blauwdruk', 'vtc-training-planner' ); ?></th><th></th></tr></thead><tbody>
			<?php foreach ( $dweek as $w ) : ?>
				<tr>
					<td><?php echo esc_html( $w->iso_week ); ?></td>
					<td><?php echo esc_html( $w->blueprint_name ); ?></td>
					<td>
						<form method="post" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Toewijzing verwijderen?', 'vtc-training-planner' ) ); ?>');">
							<?php wp_nonce_field( 'vtc_tp_admin' ); ?>
							<input type="hidden" name="vtc_tp_action" value="delete_deviation_week_admin" />
							<input type="hidden" name="deviation_week_id" value="<?php echo (int) $w->id; ?>" />
							<button class="button-link-delete"><?php esc_html_e( 'Verwijderen', 'vtc-training-planner' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody></table>
			<p class="description"><?php esc_html_e( 'Basis-blauwdruk-id voor koppelingen:', 'vtc-training-planner' ); ?> <code><?php echo (int) $base; ?></code></p>
		</div>
		<?php
	}

	public function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		settings_errors( 'vtc_tp' );
		$code  = $this->db->get_nevobo_code();
		$ttl   = (int) get_option( 'vtc_tp_cache_ttl', 1800 );
		$scope = get_option( 'vtc_tp_matches_scope', 'home_halls' );
		$test  = '';
		if ( $code ) {
			$m = $this->nevobo->get_club_schedule_matches( $code );
			$test = sprintf(
				/* translators: %d: number of matches in feed */
				_n( 'Feed bevat %d toekomstige/aankomende wedstrijd-item (na parse).', 'Feed bevat %d wedstrijd-items (na parse).', count( $m ), 'vtc-training-planner' ),
				count( $m )
			);
		}
		?>
		<div class="wrap vtc-tp-wrap">
			<h1><?php esc_html_e( 'Training — instellingen', 'vtc-training-planner' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Nevobo clubcode en verenigingsnaam staan onder Stamdata (zelfde model als de Team-app: tabel clubs).', 'vtc-training-planner' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'vtc_tp_admin' ); ?>
				<input type="hidden" name="vtc_tp_action" value="save_settings" />
				<table class="form-table">
					<tr>
						<th><label for="cache_ttl"><?php esc_html_e( 'RSS-cache (seconden)', 'vtc-training-planner' ); ?></label></th>
						<td><input name="cache_ttl" id="cache_ttl" type="number" min="60" step="60" value="<?php echo esc_attr( (string) $ttl ); ?>" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Wedstrijden in weekoverzicht', 'vtc-training-planner' ); ?></th>
						<td>
							<label><input type="radio" name="matches_scope" value="home_halls" <?php checked( $scope, 'home_halls' ); ?> /> <?php esc_html_e( 'Alleen in eigen zalen (match met locatienaam uit stamdata)', 'vtc-training-planner' ); ?></label><br />
							<label><input type="radio" name="matches_scope" value="all" <?php checked( $scope, 'all' ); ?> /> <?php esc_html_e( 'Alle clubwedstrijden in de week (thuis en uit)', 'vtc-training-planner' ); ?></label>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<?php if ( $test ) : ?>
				<p class="description"><?php echo esc_html( $test ); ?></p>
			<?php endif; ?>
			<hr />
			<p><?php esc_html_e( 'Frontend shortcode:', 'vtc-training-planner' ); ?> <code>[vtc_training_week]</code> <?php esc_html_e( 'of met week', 'vtc-training-planner' ); ?> <code>[vtc_training_week week="2026-W15"]</code></p>
		</div>
		<?php
	}

	public function render_stamdata() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		settings_errors( 'vtc_tp' );
		$bp    = $this->bp();
		$club  = $this->db->get_club();
		$teams = $this->db->get_teams( $bp );
		$locs  = $this->db->get_locations( $bp );
		$bp_row = $this->db->get_blueprint( $bp );
		$all_bp = $this->db->list_blueprints();
		?>
		<div class="wrap vtc-tp-wrap">
			<h1><?php esc_html_e( 'Stamdata', 'vtc-training-planner' ); ?><?php echo $bp_row ? ' — ' . esc_html( $bp_row->name ) : ''; ?></h1>
			<p class="vtc-tp-bp-switch">
				<?php esc_html_e( 'Blauwdruk:', 'vtc-training-planner' ); ?>
				<?php foreach ( $all_bp as $bx ) : ?>
					<?php
					$url = admin_url( 'admin.php?page=vtc-training-stamdata&blueprint_id=' . (int) $bx->id );
					if ( (int) $bx->id === (int) $bp ) {
						echo ' <strong>' . esc_html( $bx->name ) . '</strong>';
					} else {
						echo ' <a href="' . esc_url( $url ) . '">' . esc_html( $bx->name ) . '</a>';
					}
					?>
				<?php endforeach; ?>
				| <a href="<?php echo esc_url( admin_url( 'admin.php?page=vtc-training-blueprints' ) ); ?>"><?php esc_html_e( 'Blauwdrukken beheren', 'vtc-training-planner' ); ?></a>
			</p>
			<p class="description"><?php esc_html_e( 'Zelfde structuur als de Team-app: vereniging (clubs), teams met Nevobo-type/nummer, locaties met optionele Nevobo-zaalnaam, velden met type zaal/buiten en optionele veld-slug.', 'vtc-training-planner' ); ?></p>

			<h2><?php esc_html_e( 'Vereniging', 'vtc-training-planner' ); ?></h2>
			<form method="post" class="vtc-tp-club-form">
				<?php wp_nonce_field( 'vtc_tp_admin' ); ?>
				<input type="hidden" name="vtc_tp_action" value="save_club" />
				<table class="form-table">
					<tr>
						<th><label for="club_name"><?php esc_html_e( 'Clubnaam', 'vtc-training-planner' ); ?></label></th>
						<td><input name="club_name" id="club_name" type="text" class="regular-text" value="<?php echo esc_attr( $club->name ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="club_nevobo_code"><?php esc_html_e( 'Nevobo clubcode', 'vtc-training-planner' ); ?></label></th>
						<td><input name="club_nevobo_code" id="club_nevobo_code" type="text" class="regular-text" value="<?php echo esc_attr( $club->nevobo_code ); ?>" placeholder="bijv. ckl9x7n" /></td>
					</tr>
					<tr>
						<th><label for="club_region"><?php esc_html_e( 'Regio', 'vtc-training-planner' ); ?></label></th>
						<td><input name="club_region" id="club_region" type="text" class="regular-text" value="<?php echo esc_attr( $club->region ?? '' ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="club_logo_url"><?php esc_html_e( 'Logo-URL', 'vtc-training-planner' ); ?></label></th>
						<td><input name="club_logo_url" id="club_logo_url" type="url" class="large-text" value="<?php echo esc_attr( $club->logo_url ?? '' ); ?>" placeholder="https://..." /></td>
					</tr>
				</table>
				<?php submit_button( __( 'Vereniging opslaan', 'vtc-training-planner' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Teams', 'vtc-training-planner' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Komt overeen met teams in Team: display_name, nevobo_team_type (bv. dames-senioren, jongens-a), nevobo_number, trainings_per_week, min/max minuten.', 'vtc-training-planner' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'vtc_tp_admin' ); ?>
				<input type="hidden" name="vtc_tp_action" value="save_all_teams" />
				<table class="widefat striped vtc-tp-table-teams">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Weergavenaam', 'vtc-training-planner' ); ?></th>
							<th><?php esc_html_e( 'Nevobo team-type', 'vtc-training-planner' ); ?></th>
							<th><?php esc_html_e( 'Nr', 'vtc-training-planner' ); ?></th>
							<th><?php esc_html_e( 'Tr/wk', 'vtc-training-planner' ); ?></th>
							<th><?php esc_html_e( 'Min', 'vtc-training-planner' ); ?></th>
							<th><?php esc_html_e( 'Max', 'vtc-training-planner' ); ?></th>
							<th><?php esc_html_e( 'Sort', 'vtc-training-planner' ); ?></th>
							<th><?php esc_html_e( 'Actie', 'vtc-training-planner' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $teams as $t ) : ?>
						<tr>
							<td><input name="teams[<?php echo (int) $t->id; ?>][display_name]" value="<?php echo esc_attr( $t->display_name ); ?>" class="regular-text" required /></td>
							<td><input name="teams[<?php echo (int) $t->id; ?>][nevobo_team_type]" value="<?php echo esc_attr( $t->nevobo_team_type ?? '' ); ?>" class="regular-text" /></td>
							<td><input name="teams[<?php echo (int) $t->id; ?>][nevobo_number]" type="number" min="1" value="<?php echo (int) ( $t->nevobo_number ?? 1 ); ?>" style="width:3.5rem" /></td>
							<td><input name="teams[<?php echo (int) $t->id; ?>][trainings_per_week]" type="number" min="0" value="<?php echo (int) $t->trainings_per_week; ?>" style="width:3.5rem" /></td>
							<td><input name="teams[<?php echo (int) $t->id; ?>][min_training_minutes]" type="number" min="1" value="<?php echo (int) ( $t->min_training_minutes ?? 90 ); ?>" style="width:4rem" /></td>
							<td><input name="teams[<?php echo (int) $t->id; ?>][max_training_minutes]" type="number" min="1" value="<?php echo (int) ( $t->max_training_minutes ?? 90 ); ?>" style="width:4rem" /></td>
							<td><input name="teams[<?php echo (int) $t->id; ?>][sort_order]" type="number" value="<?php echo (int) $t->sort_order; ?>" style="width:3.5rem" /></td>
							<td>
								<form method="post" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Team verwijderen?', 'vtc-training-planner' ) ); ?>');">
									<?php wp_nonce_field( 'vtc_tp_admin' ); ?>
									<input type="hidden" name="vtc_tp_action" value="delete_team" />
									<input type="hidden" name="team_id" value="<?php echo (int) $t->id; ?>" />
									<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Verwijderen', 'vtc-training-planner' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( count( $teams ) > 0 ) : ?>
					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Alle teams opslaan', 'vtc-training-planner' ); ?></button></p>
				<?php endif; ?>
			</form>
			<h3><?php esc_html_e( 'Team toevoegen', 'vtc-training-planner' ); ?></h3>
			<form method="post" class="vtc-tp-inline-form vtc-tp-add-team">
				<?php wp_nonce_field( 'vtc_tp_admin' ); ?>
				<input type="hidden" name="vtc_tp_action" value="add_team" />
				<input name="display_name" placeholder="<?php esc_attr_e( 'Weergavenaam', 'vtc-training-planner' ); ?>" required class="regular-text" />
				<input name="nevobo_team_type" placeholder="<?php esc_attr_e( 'Nevobo type', 'vtc-training-planner' ); ?>" class="regular-text" />
				<input name="nevobo_number" type="number" min="1" value="1" style="width:3.5rem" title="<?php esc_attr_e( 'Nummer', 'vtc-training-planner' ); ?>" />
				<input name="trainings_per_week" type="number" min="0" value="2" style="width:3.5rem" title="<?php esc_attr_e( 'Trainings per week', 'vtc-training-planner' ); ?>" />
				<input name="min_training_minutes" type="number" min="1" value="90" style="width:4rem" />
				<input name="max_training_minutes" type="number" min="1" value="90" style="width:4rem" />
				<input name="sort_order" type="number" value="0" style="width:3.5rem" />
				<?php submit_button( __( 'Team toevoegen', 'vtc-training-planner' ), 'secondary', '', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Locaties en velden', 'vtc-training-planner' ); ?></h2>
			<?php if ( count( $locs ) === 0 ) : ?>
				<form method="post" style="margin-bottom:1rem">
					<?php wp_nonce_field( 'vtc_tp_admin' ); ?>
					<input type="hidden" name="vtc_tp_action" value="seed_vtc_skeleton" />
					<?php submit_button( __( 'Voorbeeld laden: locaties + velden + teams (zoals Team-app)', 'vtc-training-planner' ), 'secondary', '', false, array( 'onclick' => "return confirm('" . esc_js( __( 'Locaties, velden en (indien leeg) alle VTC-teams toevoegen?', 'vtc-training-planner' ) ) . "');" ) ); ?>
				</form>
			<?php elseif ( count( $teams ) === 0 ) : ?>
				<form method="post" style="margin-bottom:1rem">
					<?php wp_nonce_field( 'vtc_tp_admin' ); ?>
					<input type="hidden" name="vtc_tp_action" value="seed_vtc_teams" />
					<?php submit_button( __( 'Teams uit Team-app laden (VTC Woerden, actieve teams)', 'vtc-training-planner' ), 'secondary', '', false, array( 'onclick' => "return confirm('" . esc_js( __( 'Alle teams uit de Team-app-snapshot toevoegen?', 'vtc-training-planner' ) ) . "');" ) ); ?>
				</form>
			<?php endif; ?>
			<?php foreach ( $locs as $loc ) : ?>
				<?php $venues = $this->db->get_venues_by_location( (int) $loc->id ); ?>
				<h3><?php echo esc_html( $loc->name ); ?> <?php if ( $loc->nevobo_venue_name ) : ?><span class="description">(Nevobo: <?php echo esc_html( $loc->nevobo_venue_name ); ?>)</span><?php endif; ?></h3>
				<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Veld', 'vtc-training-planner' ); ?></th><th><?php esc_html_e( 'Type', 'vtc-training-planner' ); ?></th><th><?php esc_html_e( 'Nevobo slug', 'vtc-training-planner' ); ?></th><th></th></tr></thead><tbody>
				<?php foreach ( $venues as $v ) : ?>
					<tr>
						<td><?php echo esc_html( $v->name ); ?></td>
						<td><?php echo esc_html( $this->venue_type_label( $v->venue_type ?? 'hall' ) ); ?></td>
						<td><?php echo esc_html( (string) $v->nevobo_field_slug ); ?></td>
						<td>
							<form method="post" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Verwijderen?', 'vtc-training-planner' ) ); ?>');">
								<?php wp_nonce_field( 'vtc_tp_admin' ); ?>
								<input type="hidden" name="vtc_tp_action" value="delete_venue" />
								<input type="hidden" name="venue_id" value="<?php echo (int) $v->id; ?>" />
								<button class="button-link-delete"><?php esc_html_e( 'Verwijderen', 'vtc-training-planner' ); ?></button>
							</form>
						</td>
					</tr>
					<?php
					$unav = $this->db->get_venue_unavailability( (int) $v->id );
					foreach ( $unav as $u ) :
						?>
					<tr class="vtc-tp-subrow"><td colspan="4">
						<?php esc_html_e( 'Inhuur / niet beschikbaar', 'vtc-training-planner' ); ?>: <?php echo esc_html( $this->dow_label( $u->day_of_week ) ); ?> <?php echo esc_html( $u->start_time . '–' . $u->end_time ); ?>
						<form method="post" style="display:inline;margin-left:1rem">
							<?php wp_nonce_field( 'vtc_tp_admin' ); ?>
							<input type="hidden" name="vtc_tp_action" value="delete_unavail" />
							<input type="hidden" name="unavail_id" value="<?php echo (int) $u->id; ?>" />
							<button class="button-link-delete"><?php esc_html_e( 'Verwijder', 'vtc-training-planner' ); ?></button>
						</form>
					</td></tr>
					<?php endforeach; ?>
					<tr class="vtc-tp-subrow"><td colspan="4">
						<form method="post" class="vtc-tp-inline-form">
							<?php wp_nonce_field( 'vtc_tp_admin' ); ?>
							<input type="hidden" name="vtc_tp_action" value="add_unavail" />
							<input type="hidden" name="venue_id" value="<?php echo (int) $v->id; ?>" />
							<label><?php esc_html_e( 'Dag', 'vtc-training-planner' ); ?> <?php echo $this->dow_select_html( 'dow', 0 ); ?> <span class="description"><?php esc_html_e( '0 = maandag … 6 = zondag', 'vtc-training-planner' ); ?></span></label>
							<input name="t_start" value="08:00" size="6" /> – <input name="t_end" value="12:00" size="6" />
							<?php submit_button( __( 'Inhuur dit veld', 'vtc-training-planner' ), 'secondary', '', false ); ?>
						</form>
					</td></tr>
				<?php endforeach; ?>
				</tbody></table>
				<form method="post" class="vtc-tp-inline-form">
					<?php wp_nonce_field( 'vtc_tp_admin' ); ?>
					<input type="hidden" name="vtc_tp_action" value="add_venue" />
					<input type="hidden" name="location_id" value="<?php echo (int) $loc->id; ?>" />
					<input name="venue_name" placeholder="<?php esc_attr_e( 'Veldnaam', 'vtc-training-planner' ); ?>" required />
					<select name="venue_type" title="<?php esc_attr_e( 'Zaal of buiten', 'vtc-training-planner' ); ?>">
						<option value="hall"><?php esc_html_e( 'Zaal', 'vtc-training-planner' ); ?></option>
						<option value="field"><?php esc_html_e( 'Buiten', 'vtc-training-planner' ); ?></option>
					</select>
					<input name="nevobo_field_slug" placeholder="<?php esc_attr_e( 'Nevobo veld-slug', 'vtc-training-planner' ); ?>" class="regular-text" />
					<?php submit_button( __( 'Veld toevoegen', 'vtc-training-planner' ), 'secondary', '', false ); ?>
				</form>
				<form method="post" style="margin:1rem 0" onsubmit="return confirm('<?php echo esc_js( __( 'Hele locatie verwijderen?', 'vtc-training-planner' ) ); ?>');">
					<?php wp_nonce_field( 'vtc_tp_admin' ); ?>
					<input type="hidden" name="vtc_tp_action" value="delete_location" />
					<input type="hidden" name="location_id" value="<?php echo (int) $loc->id; ?>" />
					<?php submit_button( __( 'Locatie verwijderen', 'vtc-training-planner' ), 'delete', '', false ); ?>
				</form>
			<?php endforeach; ?>

			<h3><?php esc_html_e( 'Nieuwe locatie', 'vtc-training-planner' ); ?></h3>
			<form method="post" class="vtc-tp-inline-form">
				<?php wp_nonce_field( 'vtc_tp_admin' ); ?>
				<input type="hidden" name="vtc_tp_action" value="add_location" />
				<input name="loc_name" placeholder="<?php esc_attr_e( 'Locatie', 'vtc-training-planner' ); ?>" required />
				<input name="nevobo_venue_name" placeholder="<?php esc_attr_e( 'Nevobo zaalnaam (voor filter)', 'vtc-training-planner' ); ?>" class="regular-text" />
				<?php submit_button( __( 'Locatie toevoegen', 'vtc-training-planner' ), 'primary', '', false ); ?>
			</form>
		</div>
		<?php
	}

	public function render_rooster() {
		settings_errors( 'vtc_tp' );
		$bp     = $this->bp();
		$slots  = $this->db->get_slots_draft( $bp );
		$teams  = $this->db->get_teams( $bp );
		$venues = $this->db->get_venues_for_blueprint( $bp );
		$diff   = $this->db->draft_differs_from_published( $bp );
		$all_bp = $this->db->list_blueprints();
		?>
		<div class="wrap vtc-tp-wrap">
			<h1><?php esc_html_e( 'Rooster (lijst)', 'vtc-training-planner' ); ?></h1>
			<p class="vtc-tp-bp-switch"><?php esc_html_e( 'Blauwdruk:', 'vtc-training-planner' ); ?>
				<?php foreach ( $all_bp as $bx ) : ?>
					<?php
					$url = admin_url( 'admin.php?page=vtc-training-rooster&blueprint_id=' . (int) $bx->id );
					if ( (int) $bx->id === (int) $bp ) {
						echo ' <strong>' . esc_html( $bx->name ) . '</strong>';
					} else {
						echo ' <a href="' . esc_url( $url ) . '">' . esc_html( $bx->name ) . '</a>';
					}
					?>
				<?php endforeach; ?>
			</p>
			<p class="description">
				<?php
				echo esc_html__( 'Voor slepen en tijdlijn: gebruik', 'vtc-training-planner' );
				echo ' <a href="' . esc_url( admin_url( 'admin.php?page=vtc-training-planner-visual' ) ) . '">' . esc_html__( 'Rooster (visueel)', 'vtc-training-planner' ) . '</a>.';
				?>
			</p>
			<?php if ( $diff ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'Concept wijkt af van het gepubliceerde rooster. Publiceer om de site bij te werken.', 'vtc-training-planner' ); ?></p></div>
			<?php endif; ?>
			<form method="post" style="margin-bottom:1rem">
				<?php wp_nonce_field( 'vtc_tp_admin' ); ?>
				<input type="hidden" name="vtc_tp_action" value="publish_slots" />
				<input type="hidden" name="blueprint_id" value="<?php echo (int) $bp; ?>" />
				<?php submit_button( __( 'Publiceren (live)', 'vtc-training-planner' ), 'primary', '', false, array( 'onclick' => "return confirm('" . esc_js( __( 'Gepubliceerd rooster vervangen door concept?', 'vtc-training-planner' ) ) . "');" ) ); ?>
			</form>
			<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Team', 'vtc-training-planner' ); ?></th><th><?php esc_html_e( 'Veld', 'vtc-training-planner' ); ?></th><th><?php esc_html_e( 'Dag', 'vtc-training-planner' ); ?></th><th><?php esc_html_e( 'Tijd', 'vtc-training-planner' ); ?></th><th></th></tr></thead><tbody>
			<?php
			$team_map = array();
			foreach ( $teams as $t ) {
				$team_map[ $t->id ] = $t->display_name;
			}
			$venue_map = array();
			foreach ( $venues as $v ) {
				$venue_map[ $v->id ] = $v->location_name . ' — ' . $v->name;
			}
			foreach ( $slots as $s ) :
				?>
				<tr>
					<td><?php echo esc_html( $team_map[ $s->team_id ] ?? '#' . $s->team_id ); ?></td>
					<td><?php echo esc_html( $venue_map[ $s->venue_id ] ?? '#' . $s->venue_id ); ?></td>
					<td><?php echo esc_html( $this->dow_label( $s->day_of_week ) ); ?></td>
					<td><?php echo esc_html( $s->start_time . '–' . $s->end_time ); ?></td>
					<td>
						<form method="post" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Verwijderen?', 'vtc-training-planner' ) ); ?>');">
							<?php wp_nonce_field( 'vtc_tp_admin' ); ?>
							<input type="hidden" name="vtc_tp_action" value="delete_slot_draft" />
							<input type="hidden" name="slot_id" value="<?php echo (int) $s->id; ?>" />
							<button class="button-link-delete"><?php esc_html_e( 'Verwijderen', 'vtc-training-planner' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody></table>

			<h2><?php esc_html_e( 'Trainingsslot toevoegen (weekpatroon)', 'vtc-training-planner' ); ?></h2>
			<form method="post" class="vtc-tp-slot-form">
				<?php wp_nonce_field( 'vtc_tp_admin' ); ?>
				<input type="hidden" name="vtc_tp_action" value="add_slot_draft" />
				<select name="team_id" required><?php foreach ( $teams as $t ) : ?><option value="<?php echo (int) $t->id; ?>"><?php echo esc_html( $t->display_name ); ?></option><?php endforeach; ?></select>
				<select name="venue_id" required><?php foreach ( $venues as $v ) : ?><option value="<?php echo (int) $v->id; ?>"><?php echo esc_html( $v->location_name . ' — ' . $v->name ); ?></option><?php endforeach; ?></select>
				<?php echo $this->dow_select_html( 'dow', 0 ); ?>
				<input name="t_start" value="19:00" size="6" /> – <input name="t_end" value="20:30" size="6" />
				<?php submit_button( __( 'Toevoegen', 'vtc-training-planner' ), 'secondary', '', false ); ?>
			</form>
		</div>
		<?php
	}

	public function render_exceptions() {
		$bp  = $this->bp();
		$ews = $this->db->list_exception_weeks( $bp );
		$teams  = $this->db->get_teams( $bp );
		$venues = $this->db->get_venues_for_blueprint( $bp );
		$sel = isset( $_GET['ew'] ) ? absint( $_GET['ew'] ) : 0;
		?>
		<div class="wrap vtc-tp-wrap">
			<h1><?php esc_html_e( 'Uitzonderingsweken', 'vtc-training-planner' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Als voor een ISO-week een uitzondering bestaat, vervangt die de gepubliceerde weekpatronen voor die week.', 'vtc-training-planner' ); ?></p>

			<form method="post" class="vtc-tp-inline-form">
				<?php wp_nonce_field( 'vtc_tp_admin' ); ?>
				<input type="hidden" name="vtc_tp_action" value="add_exception_week" />
				<input name="iso_week" placeholder="2026-W12" required pattern="[0-9]{4}-[Ww][0-9]{1,2}" />
				<input name="label" placeholder="<?php esc_attr_e( 'Label (optioneel)', 'vtc-training-planner' ); ?>" class="regular-text" />
				<?php submit_button( __( 'Week toevoegen', 'vtc-training-planner' ), 'secondary', '', false ); ?>
			</form>

			<ul>
			<?php foreach ( $ews as $ew ) : ?>
				<li>
					<strong><a href="<?php echo esc_url( add_query_arg( 'ew', (int) $ew->id ) ); ?>"><?php echo esc_html( $ew->iso_week ); ?></a></strong>
					<?php echo $ew->label ? ' — ' . esc_html( $ew->label ) : ''; ?>
					<form method="post" style="display:inline;margin-left:0.5rem" onsubmit="return confirm('<?php echo esc_js( __( 'Verwijderen?', 'vtc-training-planner' ) ); ?>');">
						<?php wp_nonce_field( 'vtc_tp_admin' ); ?>
						<input type="hidden" name="vtc_tp_action" value="delete_exception_week" />
						<input type="hidden" name="exception_week_id" value="<?php echo (int) $ew->id; ?>" />
						<button class="button-link-delete"><?php esc_html_e( 'Verwijderen', 'vtc-training-planner' ); ?></button>
					</form>
				</li>
			<?php endforeach; ?>
			</ul>

			<?php
			if ( $sel ) {
				$ew = null;
				foreach ( $ews as $e ) {
					if ( (int) $e->id === $sel ) {
						$ew = $e;
						break;
					}
				}
				if ( $ew ) {
					$slots = $this->db->get_exception_slots( $sel );
					?>
					<h2><?php echo esc_html( $ew->iso_week ); ?></h2>
					<table class="widefat striped"><thead><tr><th>Team</th><th>Veld</th><th>Dag</th><th>Tijd</th><th></th></tr></thead><tbody>
					<?php
					$venue_map = array();
					foreach ( $venues as $v ) {
						$venue_map[ $v->id ] = $v->location_name . ' — ' . $v->name;
					}
					$team_map = array();
					foreach ( $teams as $t ) {
						$team_map[ $t->id ] = $t->display_name;
					}
					foreach ( $slots as $s ) :
						?>
						<tr>
							<td><?php echo esc_html( $team_map[ $s->team_id ] ?? '' ); ?></td>
							<td><?php echo esc_html( $venue_map[ $s->venue_id ] ?? '' ); ?></td>
							<td><?php echo esc_html( $this->dow_label( $s->day_of_week ) ); ?></td>
							<td><?php echo esc_html( $s->start_time . '–' . $s->end_time ); ?></td>
							<td>
								<form method="post" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Verwijderen?', 'vtc-training-planner' ) ); ?>');">
									<?php wp_nonce_field( 'vtc_tp_admin' ); ?>
									<input type="hidden" name="vtc_tp_action" value="delete_exception_slot" />
									<input type="hidden" name="slot_id" value="<?php echo (int) $s->id; ?>" />
									<button class="button-link-delete"><?php esc_html_e( 'Verwijderen', 'vtc-training-planner' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody></table>
					<form method="post" class="vtc-tp-slot-form">
						<?php wp_nonce_field( 'vtc_tp_admin' ); ?>
						<input type="hidden" name="vtc_tp_action" value="add_exception_slot" />
						<input type="hidden" name="exception_week_id" value="<?php echo (int) $sel; ?>" />
						<select name="team_id" required><?php foreach ( $teams as $t ) : ?><option value="<?php echo (int) $t->id; ?>"><?php echo esc_html( $t->display_name ); ?></option><?php endforeach; ?></select>
						<select name="venue_id" required><?php foreach ( $venues as $v ) : ?><option value="<?php echo (int) $v->id; ?>"><?php echo esc_html( $v->location_name . ' — ' . $v->name ); ?></option><?php endforeach; ?></select>
						<?php echo $this->dow_select_html( 'dow', 0 ); ?>
						<input name="t_start" value="19:00" size="6" /> – <input name="t_end" value="20:30" size="6" />
						<?php submit_button( __( 'Slot toevoegen', 'vtc-training-planner' ), 'secondary', '', false ); ?>
					</form>
					<?php
				}
			}
			?>
		</div>
		<?php
	}

	public function render_week_preview() {
		$w    = isset( $_GET['week'] ) ? sanitize_text_field( wp_unslash( $_GET['week'] ) ) : VTC_TP_Schedule::current_iso_week();
		$norm = VTC_TP_Schedule::normalize_iso_week( $w ) ?: VTC_TP_Schedule::current_iso_week();
		$data = $this->schedule->get_merged_week( $norm, $this->nevobo );
		?>
		<div class="wrap vtc-tp-wrap">
			<h1><?php esc_html_e( 'Weekoverzicht (preview)', 'vtc-training-planner' ); ?></h1>
			<form method="get" class="vtc-tp-week-nav">
				<input type="hidden" name="page" value="vtc-training-week" />
				<label>ISO-week <input name="week" value="<?php echo esc_attr( $data['iso_week'] ); ?>" /></label>
				<?php submit_button( __( 'Tonen', 'vtc-training-planner' ), 'secondary', '', false ); ?>
			</form>
			<hr />
			<?php echo VTC_TP_Public::render_week_html( $data, true ); ?>
		</div>
		<?php
	}
}
