<?php
/**
 * Plugin Name: Recurring Achievements Ltd
 * Description: Enable recurring achievements with an optional daily limit
 *
 * Version: 1.0
 * Author: Sennza
 * Author URI: http://www.sennza.com.au/
 *
 * Requires at least: 3.5.1
 * Tested up to: 3.6
 * License: GPLv3
 */

Sennza_CheevosLtd::bootstrap();

class Sennza_CheevosLtd {
	/**
	 * Do we have all our requirements?
	 * @var boolean
	 */
	protected static $has_requirements = false;

	/**
	 * Setup the actions and filters for the plugin
	 */
	public static function bootstrap() {
		add_action( 'plugins_loaded',         array( __CLASS__, 'check_requirements' ) );
		add_filter( 'cmb_meta_boxes',         array( __CLASS__, 'register_metabox' ) );

		add_filter( 'dpa_handle_event_maybe_unlock_achievement', array( __CLASS__, 'limit_daily_achievements' ), 10, 5 );
		add_filter( 'dpa_handle_event_maybe_unlock_achievement', array( __CLASS__, 'allow_unlimited_achievements' ), 999, 5 );
	}

	public static function check_requirements() {
		self::$has_requirements = class_exists( 'DPA_Achievements_Loader' );

		if ( ! self::$has_requirements )
			add_action( 'admin_notices', array( __CLASS__, 'print_requirement_warning' ) );

		// Load the metabox handler
		if ( ! function_exists( 'cmb_init' ) )
			require_once dirname( __FILE__ ) . '/metabox/custom-meta-boxes.php';
	}

	public static function print_requirement_warning() {
?>
		<div class="error">
			<p>
<?php
		_e( 'BP Group Level Achievements requires Achievements to be installed.', 'sennza_cheevosltd' );
		echo '<br />';
		printf(
			__( 'Please <a href="%s">download Achievements</a> to get started.', 'sennza_cheevosltd' ),
			'http://achievementsapp.com/'
		);
?>
			</p>
		</div>
<?php
	}

	public static function allow_unlimited_achievements($allow, $event_name, $func_args, $user_id, $args) {
		// We don't care if another filter has decided not to allow it
		if ( ! $allow )
			return $allow;

		global $post;
		$allow_multiple = $post->_sennza_cheevosltd_allow_multiple;

		// Does the achievement have a daily activation limit?
		if ( empty( $allow_multiple ) )
			return $allow;

		// Look in the progress posts and match against a post_parent which is the same as the current achievement.
		$progress = wp_filter_object_list( achievements()->progress_query->posts, array( 'post_parent' => dpa_get_the_achievement_ID() ) );
		$progress = array_shift( $progress );

		// If we haven't unlocked it once already, we don't care
		if ( empty( $progress ) || dpa_get_unlocked_status_id() !== $progress->post_status )
			return $allow;

		// Otherwise, let's change it back to locked
		$args = array(
			'ID' => $progress->ID,
			'post_status' => dpa_get_locked_status_id()
		);
		wp_update_post($args);

		// And ensure we update the existing object too
		$progress->post_status = dpa_get_locked_status_id();

		return $allow;
	}

	public static function limit_daily_achievements($allow, $event_name, $func_args, $user_id, $args) {
		// We don't care if another filter has decided not to allow it
		if ( ! $allow )
			return $allow;

		global $post;
		$daily_limit = (int) $post->_sennza_cheevosltd_daily_limit;

		// Does the achievement have a daily activation limit?
		if ( empty( $daily_limit ) )
			return $allow;

		$key = 'sennza_cheevosltd_achieved_' . dpa_get_the_achievement_ID();

		// Retrieve the number of people who have achieved this today
		$activated_today = get_user_meta( $user_id, $key, true );
		$expiration = (int) get_user_meta( $user_id, $key . '_expiration', true );

		// If we've passed the expiration, reset the number we've achieved
		if ( time() > $expiration )
			$activated_today = 0;

		// Have we hit the limit?
		if ($activated_today >= $daily_limit)
			return false;

		// Bump the number and store it
		$activated_today++;

		// Get when midnight in the local timezone
		$tz = get_option('timezone_string');
		if ( ! empty( $tz ) ) {
			date_default_timezone_set($tz);
		}
		$midnight_diff = strtotime('midnight tomorrow') - time();
		if ( ! empty( $tz ) ) {
			date_default_timezone_set('UTC');
		}

		update_user_meta( $user_id, $key, $activated_today );
		update_user_meta( $user_id, $key . '_expiration', time() + $midnight_diff );

		return $allow;
	}

	public static function register_metabox( $boxes ) {
		$boxes[] = array(
			'id' => 'sennza_cheevosltd',
			'title' => __( 'Recurring Achievements Ltd', 'sennza_cheevosltd' ),
			'pages' => dpa_get_achievement_post_type(),
			'context' => 'normal',
			'priority' => 'default',
			'fields' => array(
				array(
					'name' => __( 'Allow Multiple Unlocks?', 'sennza_cheevosltd' ),
					'desc' => __( 'Should we allow the user to achieve this more than once?', 'sennza_cheevosltd' ),
					'id' => '_sennza_cheevosltd_allow_multiple',
					'type' => 'checkbox'
				),
				array(
					'name' => __( 'Daily Limit:', 'sennza_cheevosltd' ),
					'desc' => __( 'Limit the times a user can achieve this per day (0 for no limit)', 'sennza_cheevosltd' ),
					'id' => '_sennza_cheevosltd_daily_limit',
					'type' => 'text_small',
				),
			),
		);
		return $boxes;
	}
}
