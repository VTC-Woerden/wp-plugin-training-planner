<?php
/**
 * Snapshot van actieve teams voor club ckl9x7n uit de Team-app (SQLite teams-tabel).
 * Alleen gebruikt bij lege vtc_tp_team voor de standaard-blauwdruk.
 *
 * @package VTC_Training_Planner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VTC_TP_Team_Seed {

	/**
	 * Rijen: display_name, nevobo_team_type, nevobo_number, trainings_per_week, min_min, max_min.
	 *
	 * @return array<int, array{0:string,1:string,2:int,3:int,4:int,5:int}>
	 */
	private static function rows() {
		return array(
			array( 'Quadrant Bouw VTC Woerden DS 1', 'dames', 1, 2, 120, 120 ),
			array( 'VTC Woerden DM 1', 'dames-master', 1, 1, 90, 90 ),
			array( 'VTC Woerden DR 1', 'dames-recreatief', 1, 1, 90, 90 ),
			array( 'VTC Woerden DR 2', 'dames-recreatief', 2, 0, 90, 90 ),
			array( 'VTC Woerden DR 3', 'dames-recreatief', 3, 1, 90, 90 ),
			array( 'VTC Woerden DR 4', 'dames-recreatief', 4, 1, 90, 90 ),
			array( 'VTC Woerden DR 5', 'dames-recreatief', 5, 1, 90, 90 ),
			array( 'VTC Woerden DR 6', '', 0, 1, 90, 90 ),
			array( 'VTC Woerden DS 2', '', 0, 2, 120, 120 ),
			array( 'VTC Woerden DS 3', '', 0, 2, 90, 90 ),
			array( 'VTC Woerden DS 4', '', 0, 2, 90, 90 ),
			array( 'VTC Woerden DS 5', '', 0, 2, 90, 90 ),
			array( 'VTC Woerden DS 6', '', 0, 2, 90, 90 ),
			array( 'VTC Woerden HR 1', 'heren-recreatief', 1, 0, 90, 90 ),
			array( 'VTC Woerden HS 1', '', 0, 2, 120, 120 ),
			array( 'VTC Woerden HS 2', '', 0, 2, 90, 90 ),
			array( 'VTC Woerden HS 3', '', 0, 1, 90, 90 ),
			array( 'VTC Woerden HS 4', '', 0, 1, 90, 90 ),
			array( 'VTC Woerden HS 5', '', 0, 1, 90, 90 ),
			array( 'VTC Woerden HS 6', '', 0, 1, 90, 90 ),
			array( 'VTC Woerden HS 7', '', 0, 1, 90, 90 ),
			array( 'VTC Woerden JA 1', '', 0, 2, 90, 90 ),
			array( 'VTC Woerden JB 1', '', 0, 2, 90, 90 ),
			array( 'VTC Woerden MA 1', '', 0, 2, 90, 90 ),
			array( 'VTC Woerden MA 2', 'meiden-a', 2, 2, 90, 90 ),
			array( 'VTC Woerden MA 3', 'meiden-a', 3, 2, 90, 90 ),
			array( 'VTC Woerden MB 1', 'meiden-b', 1, 2, 90, 90 ),
			array( 'VTC Woerden MB 2', 'meiden-b', 2, 2, 90, 90 ),
			array( 'VTC Woerden MB 3', '', 0, 2, 90, 90 ),
			array( 'VTC Woerden MC 1', '', 0, 2, 75, 90 ),
			array( 'VTC Woerden MC 2', '', 0, 2, 75, 90 ),
			array( 'VTC Woerden MC 3', '', 0, 2, 75, 90 ),
			array( 'VTC Woerden MC 4', '', 0, 1, 75, 90 ),
			array( 'VTC Woerden N5 1', '', 0, 4, 75, 75 ),
			array( 'VTC Woerden N5 2', '', 0, 4, 75, 75 ),
			array( 'VTC Woerden N5 3', '', 0, 4, 75, 75 ),
			array( 'VTC Woerden XR 1', '', 0, 0, 90, 90 ),
		);
	}

	/**
	 * Voegt alle rijen toe als er nog geen teams zijn voor deze blauwdruk.
	 *
	 * @param wpdb   $wpdb WordPress DB.
	 * @param int    $blueprint_id Blauwdruk-id.
	 * @return int Aantal toegevoegde teams (0 = al gevuld of niets gedaan).
	 */
	public static function insert_all_if_empty( $wpdb, $blueprint_id ) {
		$blueprint_id = (int) $blueprint_id;
		if ( $blueprint_id < 1 ) {
			return 0;
		}
		$p   = $wpdb->prefix;
		$cnt = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$p}vtc_tp_team WHERE blueprint_id = %d",
				$blueprint_id
			)
		);
		if ( $cnt > 0 ) {
			return 0;
		}
		$table = "{$p}vtc_tp_team";
		$so    = 0;
		$n     = 0;
		foreach ( self::rows() as $r ) {
			$wpdb->insert(
				$table,
				array(
					'blueprint_id'           => $blueprint_id,
					'display_name'           => $r[0],
					'nevobo_team_type'       => $r[1],
					'nevobo_number'          => (int) $r[2],
					'sort_order'             => $so++,
					'trainings_per_week'     => (int) $r[3],
					'min_training_minutes'   => (int) $r[4],
					'max_training_minutes'   => (int) $r[5],
				),
				array( '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d' )
			);
			$n++;
		}
		return $n;
	}
}
