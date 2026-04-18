<?php
/**
 * Activation, dbDelta, schema upgrades (Team-app compatible fields).
 *
 * @package VTC_Training_Planner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VTC_TP_Activator {

	const DB_VERSION = '3';

	/**
	 * Run on plugin activation.
	 */
	public static function activate() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$p               = $wpdb->prefix;

		$tables = array(
			"CREATE TABLE {$p}vtc_tp_club (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) NOT NULL DEFAULT '',
				nevobo_code varchar(32) NOT NULL DEFAULT '',
				region varchar(191) NOT NULL DEFAULT '',
				logo_url varchar(500) DEFAULT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id)
			) $charset_collate;",
			"CREATE TABLE {$p}vtc_tp_blueprint (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) NOT NULL,
				kind tinyint(3) unsigned NOT NULL DEFAULT 0,
				parent_base_id bigint(20) unsigned DEFAULT NULL,
				editing_version_id bigint(20) unsigned DEFAULT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY kind (kind),
				KEY parent_base_id (parent_base_id)
			) $charset_collate;",
			"CREATE TABLE {$p}vtc_tp_blueprint_version (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				blueprint_id bigint(20) unsigned NOT NULL,
				label varchar(191) NOT NULL DEFAULT '',
				is_published tinyint(1) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY blueprint_id (blueprint_id),
				KEY bp_published (blueprint_id,is_published)
			) $charset_collate;",
			"CREATE TABLE {$p}vtc_tp_deviation_week (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				deviation_blueprint_id bigint(20) unsigned NOT NULL,
				iso_week varchar(12) NOT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY iso_week (iso_week),
				KEY deviation_blueprint_id (deviation_blueprint_id)
			) $charset_collate;",
			"CREATE TABLE {$p}vtc_tp_team (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				blueprint_id bigint(20) unsigned NOT NULL,
				display_name varchar(191) NOT NULL,
				nevobo_team_type varchar(64) NOT NULL DEFAULT '',
				nevobo_number int(11) NOT NULL DEFAULT 1,
				sort_order int(11) NOT NULL DEFAULT 0,
				trainings_per_week int(11) NOT NULL DEFAULT 2,
				min_training_minutes int(11) NOT NULL DEFAULT 90,
				max_training_minutes int(11) NOT NULL DEFAULT 90,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY blueprint_id (blueprint_id)
			) $charset_collate;",
			"CREATE TABLE {$p}vtc_tp_location (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				blueprint_id bigint(20) unsigned NOT NULL,
				name varchar(191) NOT NULL,
				nevobo_venue_name varchar(191) DEFAULT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY blueprint_id (blueprint_id)
			) $charset_collate;",
			"CREATE TABLE {$p}vtc_tp_venue (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				location_id bigint(20) unsigned NOT NULL,
				name varchar(191) NOT NULL,
				venue_type varchar(16) NOT NULL DEFAULT 'hall',
				nevobo_field_slug varchar(64) DEFAULT NULL,
				sort_order int(11) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY location_id (location_id)
			) $charset_collate;",
			"CREATE TABLE {$p}vtc_tp_venue_unavail (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				venue_id bigint(20) unsigned NOT NULL,
				day_of_week tinyint(3) unsigned NOT NULL,
				start_time varchar(8) NOT NULL,
				end_time varchar(8) NOT NULL,
				PRIMARY KEY  (id),
				KEY venue_id (venue_id)
			) $charset_collate;",
			"CREATE TABLE {$p}vtc_tp_slot_draft (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				blueprint_id bigint(20) unsigned NOT NULL,
				blueprint_version_id bigint(20) unsigned NOT NULL,
				team_id bigint(20) unsigned NOT NULL,
				venue_id bigint(20) unsigned NOT NULL,
				day_of_week tinyint(3) unsigned NOT NULL,
				start_time varchar(8) NOT NULL,
				end_time varchar(8) NOT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY blueprint_id (blueprint_id),
				KEY blueprint_version_id (blueprint_version_id),
				KEY team_id (team_id),
				KEY venue_id (venue_id)
			) $charset_collate;",
			"CREATE TABLE {$p}vtc_tp_slot_published (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				blueprint_id bigint(20) unsigned NOT NULL,
				blueprint_version_id bigint(20) unsigned NOT NULL,
				team_id bigint(20) unsigned NOT NULL,
				venue_id bigint(20) unsigned NOT NULL,
				day_of_week tinyint(3) unsigned NOT NULL,
				start_time varchar(8) NOT NULL,
				end_time varchar(8) NOT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY blueprint_id (blueprint_id),
				KEY blueprint_version_id (blueprint_version_id),
				KEY team_id (team_id),
				KEY venue_id (venue_id)
			) $charset_collate;",
			"CREATE TABLE {$p}vtc_tp_exception_week (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				blueprint_id bigint(20) unsigned NOT NULL,
				iso_week varchar(12) NOT NULL,
				label varchar(191) DEFAULT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY blueprint_week (blueprint_id,iso_week),
				KEY blueprint_id (blueprint_id)
			) $charset_collate;",
			"CREATE TABLE {$p}vtc_tp_exception_slot (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				exception_week_id bigint(20) unsigned NOT NULL,
				team_id bigint(20) unsigned NOT NULL,
				venue_id bigint(20) unsigned NOT NULL,
				day_of_week tinyint(3) unsigned NOT NULL,
				start_time varchar(8) NOT NULL,
				end_time varchar(8) NOT NULL,
				PRIMARY KEY  (id),
				KEY exception_week_id (exception_week_id),
				KEY team_id (team_id)
			) $charset_collate;",
		);

		foreach ( $tables as $sql ) {
			dbDelta( $sql );
		}

		$bp_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}vtc_tp_blueprint" );
		if ( 0 === $bp_count ) {
			$wpdb->insert(
				"{$p}vtc_tp_blueprint",
				array(
					'name' => __( 'Standaard', 'vtc-training-planner' ),
					'kind' => 0,
				),
				array( '%s', '%d' )
			);
			$new_bp = (int) $wpdb->insert_id;
			if ( $new_bp > 0 ) {
				self::insert_default_version_for_blueprint( $wpdb, $p, $new_bp );
			}
		}

		add_option( 'vtc_tp_cache_ttl', 1800 );
		add_option( 'vtc_tp_nevobo_code', '' );
		add_option( 'vtc_tp_matches_scope', 'home_halls' );

		self::upgrade_schema();
	}

	/**
	 * Migrations for existing installs (plugins_loaded + activate).
	 */
	public static function upgrade_schema() {
		global $wpdb;
		$p = $wpdb->prefix;

		// Los van db_version: oude dag-codering eenmalig omzetten.
		self::migrate_day_of_week_to_team_index( $p );

		$current = get_option( 'vtc_tp_db_version', '0' );
		if ( version_compare( (string) $current, self::DB_VERSION, '>=' ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$p}vtc_tp_club (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) NOT NULL DEFAULT '',
				nevobo_code varchar(32) NOT NULL DEFAULT '',
				region varchar(191) NOT NULL DEFAULT '',
				logo_url varchar(500) DEFAULT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id)
			) $charset_collate;"
		);

		$club_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}vtc_tp_club" );
		if ( 0 === $club_count ) {
			$legacy_code = sanitize_text_field( (string) get_option( 'vtc_tp_nevobo_code', '' ) );
			$wpdb->insert(
				"{$p}vtc_tp_club",
				array(
					'name'         => get_bloginfo( 'name' ),
					'nevobo_code'  => $legacy_code,
					'region'       => '',
					'logo_url'     => null,
				),
				array( '%s', '%s', '%s', '%s' )
			);
		} else {
			$row = $wpdb->get_row( "SELECT nevobo_code FROM {$p}vtc_tp_club WHERE id = 1" );
			if ( $row && $row->nevobo_code ) {
				update_option( 'vtc_tp_nevobo_code', $row->nevobo_code );
			}
		}

		$team_cols = array(
			'nevobo_team_type'     => 'ALTER TABLE %s ADD COLUMN nevobo_team_type varchar(64) NOT NULL DEFAULT \'\'',
			'nevobo_number'        => 'ALTER TABLE %s ADD COLUMN nevobo_number int(11) NOT NULL DEFAULT 1',
			'min_training_minutes' => 'ALTER TABLE %s ADD COLUMN min_training_minutes int(11) NOT NULL DEFAULT 90',
			'max_training_minutes' => 'ALTER TABLE %s ADD COLUMN max_training_minutes int(11) NOT NULL DEFAULT 90',
		);
		$t_table = "{$p}vtc_tp_team";
		foreach ( $team_cols as $col => $sql ) {
			if ( ! self::column_exists( $t_table, $col ) ) {
				$wpdb->query( sprintf( $sql, $t_table ) );
			}
		}

		$v_table = "{$p}vtc_tp_venue";
		if ( ! self::column_exists( $v_table, 'venue_type' ) ) {
			$wpdb->query( "ALTER TABLE {$v_table} ADD COLUMN venue_type varchar(16) NOT NULL DEFAULT 'hall'" );
		}
		$wpdb->query( "UPDATE {$v_table} SET venue_type = 'hall' WHERE venue_type IS NULL OR venue_type = ''" );

		if ( version_compare( (string) $current, '3', '<' ) ) {
			self::migrate_schema_v3( $p, $charset_collate );
		}

		update_option( 'vtc_tp_db_version', self::DB_VERSION );
	}

	/**
	 * Blauwdruk-versies, basis/afwijkend, week-claims (schema 3).
	 *
	 * @param string $charset_collate wpdb collate.
	 */
	private static function migrate_schema_v3( $p, $charset_collate ) {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE {$p}vtc_tp_blueprint_version (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				blueprint_id bigint(20) unsigned NOT NULL,
				label varchar(191) NOT NULL DEFAULT '',
				is_published tinyint(1) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY blueprint_id (blueprint_id),
				KEY bp_published (blueprint_id,is_published)
			) $charset_collate;"
		);
		dbDelta(
			"CREATE TABLE {$p}vtc_tp_deviation_week (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				deviation_blueprint_id bigint(20) unsigned NOT NULL,
				iso_week varchar(12) NOT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY iso_week (iso_week),
				KEY deviation_blueprint_id (deviation_blueprint_id)
			) $charset_collate;"
		);

		$bp_table = "{$p}vtc_tp_blueprint";
		if ( ! self::column_exists( $bp_table, 'kind' ) ) {
			$wpdb->query( "ALTER TABLE {$bp_table} ADD COLUMN kind tinyint(3) unsigned NOT NULL DEFAULT 0" );
		}
		if ( ! self::column_exists( $bp_table, 'parent_base_id' ) ) {
			$wpdb->query( "ALTER TABLE {$bp_table} ADD COLUMN parent_base_id bigint(20) unsigned DEFAULT NULL" );
		}
		if ( ! self::column_exists( $bp_table, 'editing_version_id' ) ) {
			$wpdb->query( "ALTER TABLE {$bp_table} ADD COLUMN editing_version_id bigint(20) unsigned DEFAULT NULL" );
		}
		$wpdb->query( "UPDATE {$bp_table} SET kind = 0 WHERE kind IS NULL" );

		$sd = "{$p}vtc_tp_slot_draft";
		$sp = "{$p}vtc_tp_slot_published";
		if ( ! self::column_exists( $sd, 'blueprint_version_id' ) ) {
			$wpdb->query( "ALTER TABLE {$sd} ADD COLUMN blueprint_version_id bigint(20) unsigned DEFAULT NULL" );
		}
		if ( ! self::column_exists( $sp, 'blueprint_version_id' ) ) {
			$wpdb->query( "ALTER TABLE {$sp} ADD COLUMN blueprint_version_id bigint(20) unsigned DEFAULT NULL" );
		}

		$bps = $wpdb->get_col( "SELECT id FROM {$bp_table} ORDER BY id ASC" );
		foreach ( $bps as $bid ) {
			$bid = (int) $bid;
			$vc   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}vtc_tp_blueprint_version WHERE blueprint_id = %d", $bid ) );
			if ( $vc < 1 ) {
				self::insert_default_version_for_blueprint( $wpdb, $p, $bid );
			}
			$vid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}vtc_tp_blueprint_version WHERE blueprint_id = %d AND is_published = 1 ORDER BY id ASC LIMIT 1", $bid ) );
			if ( ! $vid ) {
				$vid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}vtc_tp_blueprint_version WHERE blueprint_id = %d ORDER BY id ASC LIMIT 1", $bid ) );
				if ( $vid ) {
					$wpdb->update( "{$p}vtc_tp_blueprint_version", array( 'is_published' => 1 ), array( 'id' => $vid ), array( '%d' ), array( '%d' ) );
				}
			}
			if ( $vid ) {
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$sd} SET blueprint_version_id = %d WHERE blueprint_id = %d AND (blueprint_version_id IS NULL OR blueprint_version_id = 0)",
						$vid,
						$bid
					)
				);
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$sp} SET blueprint_version_id = %d WHERE blueprint_id = %d AND (blueprint_version_id IS NULL OR blueprint_version_id = 0)",
						$vid,
						$bid
					)
				);
				$wpdb->update( $bp_table, array( 'editing_version_id' => $vid ), array( 'id' => $bid ), array( '%d' ), array( '%d' ) );
			}
		}
	}

	/**
	 * Eerste gepubliceerde versie voor een blauwdruk (migratie / nieuwe site).
	 *
	 * @param wpdb   $wpdb wpdb.
	 * @param string $p    table prefix with underscore.
	 * @param int    $blueprint_id Blueprint id.
	 */
	private static function insert_default_version_for_blueprint( $wpdb, $p, $blueprint_id ) {
		$blueprint_id = (int) $blueprint_id;
		if ( $blueprint_id < 1 ) {
			return;
		}
		$wpdb->insert(
			"{$p}vtc_tp_blueprint_version",
			array(
				'blueprint_id'  => $blueprint_id,
				'label'         => __( 'Versie 1', 'vtc-training-planner' ),
				'is_published'  => 1,
			),
			array( '%d', '%s', '%d' )
		);
		$vid = (int) $wpdb->insert_id;
		if ( $vid > 0 ) {
			$wpdb->update(
				"{$p}vtc_tp_blueprint",
				array( 'editing_version_id' => $vid ),
				array( 'id' => $blueprint_id ),
				array( '%d' ),
				array( '%d' )
			);
		}
	}

	/**
	 * Eenmalig: oude plugin gebruikte ma=1 … zo=7; Team gebruikt ma=0 … zo=6.
	 */
	private static function migrate_day_of_week_to_team_index( $p ) {
		if ( get_option( 'vtc_tp_dow_is_team' ) === '1' ) {
			return;
		}
		global $wpdb;
		// Nog geen activatie / tabellen.
		$draft_t = "{$p}vtc_tp_slot_draft";
		$found   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $draft_t ) );
		if ( $found !== $draft_t && $found !== strtolower( $draft_t ) ) {
			return;
		}
		foreach ( array( 'vtc_tp_slot_draft', 'vtc_tp_slot_published', 'vtc_tp_exception_slot', 'vtc_tp_venue_unavail' ) as $tbl ) {
			$wpdb->query( "UPDATE {$p}{$tbl} SET day_of_week = day_of_week - 1 WHERE day_of_week BETWEEN 1 AND 7" );
		}
		update_option( 'vtc_tp_dow_is_team', '1' );
	}

	/**
	 * @param string $table Full table name with prefix.
	 */
	private static function column_exists( $table, $column ) {
		global $wpdb;
		$cols = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`" );
		if ( ! is_array( $cols ) ) {
			return false;
		}
		foreach ( $cols as $row ) {
			if ( isset( $row->Field ) && $row->Field === $column ) {
				return true;
			}
		}
		return false;
	}
}
