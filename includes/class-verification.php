<?php
/**
 * Verification Page — public page that QR codes link to.
 * Shows the learner's photo, name, course, and completion date.
 *
 * URL format: {site}/?cp_ld_verify&uid={user_id}&cid={course_id}&sig={signature}
 *
 * @package CertificatesPlusLD
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CP_LD_Verification {

	public function __construct() {
		add_action( 'init',              array( $this, 'add_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render' ), 1 );
	}

	public function add_query_vars() {
		add_rewrite_rule(
			'^verify-certificate/?$',
			'index.php?cp_ld_verify=1',
			'top'
		);
		add_rewrite_tag( '%cp_ld_verify%', '([0-9]+)' );
	}

	/**
	 * If this is a verification request, render the page and exit.
	 */
	public function maybe_render() {
		$is_query_string = isset( $_GET['cp_ld_verify'] );
		$is_rewrite      = get_query_var( 'cp_ld_verify' );

		if ( ! $is_query_string && ! $is_rewrite ) {
			return;
		}

		nocache_headers();

		$user_id   = isset( $_GET['uid'] ) ? (int) $_GET['uid'] : 0;
		$course_id = isset( $_GET['cid'] ) ? (int) $_GET['cid'] : 0;
		$sig       = isset( $_GET['sig'] ) ? sanitize_text_field( wp_unslash( $_GET['sig'] ) ) : '';

		$valid          = false;
		$user           = null;
		$post           = null;       // The course, quiz, or group post object.
		$post_type      = '';         // 'course', 'quiz', or 'group'
		$completion     = array();    // Structured completion details for the template.

		if ( $user_id && self::verify_signature( $user_id, $course_id, $sig ) ) {
			$user  = get_userdata( $user_id );
			$valid = (bool) $user;

			if ( $valid && $course_id ) {
				$post      = get_post( $course_id );
				$raw_type  = get_post_type( $course_id );

				switch ( $raw_type ) {
					case 'sfwd-courses':
						$post_type  = 'course';
						$completion = self::course_completion( $user_id, $course_id );
						break;
					case 'sfwd-quiz':
						$post_type  = 'quiz';
						$completion = self::quiz_completion( $user_id, $course_id );
						break;
					case 'groups':
						$post_type  = 'group';
						$completion = self::group_completion( $user_id, $course_id );
						break;
				}

				// No completion data means course data was deleted.
				if ( empty( $completion['completed_date'] ) ) {
					$valid = false;
				}
			}
		}

		// Render.
		include CP_LD_DIR . 'templates/verify.php';
		exit;
	}

	// -----------------------------------------------------------------------
	// Completion detail builders
	// -----------------------------------------------------------------------

	/**
	 * Gather course completion details for the verification page.
	 *
	 * @param int $user_id
	 * @param int $course_id
	 * @return array
	 */
	private static function course_completion( $user_id, $course_id ) {
		$data = array( 'type' => 'course' );

		// Completion timestamp stored by LearnDash on course complete.
		$ts = get_user_meta( $user_id, 'course_completed_' . $course_id, true );
		$data['completed_date'] = $ts
			? date_i18n( get_option( 'date_format' ), (int) $ts )
			: '';
		$data['completed_time'] = $ts
			? date_i18n( get_option( 'time_format' ), (int) $ts )
			: '';

		// Course progress: steps completed / total.
		$progress = learndash_get_course_progress( $user_id, $course_id );
		if ( is_array( $progress ) ) {
			$data['steps_completed'] = isset( $progress['completed'] ) ? (int) $progress['completed'] : 0;
			$data['steps_total']     = isset( $progress['total'] )     ? (int) $progress['total']     : 0;
			$data['percent']         = $data['steps_total']
				? round( $data['steps_completed'] / $data['steps_total'] * 100 )
				: 0;
		}

		// Course categories.
		$terms = get_the_terms( $course_id, 'ld_course_category' );
		$data['categories'] = ( $terms && ! is_wp_error( $terms ) )
			? implode( ', ', wp_list_pluck( $terms, 'name' ) )
			: '';

		return $data;
	}

	/**
	 * Gather quiz completion details.
	 *
	 * @param int $user_id
	 * @param int $quiz_id
	 * @return array
	 */
	private static function quiz_completion( $user_id, $quiz_id ) {
		$data = array( 'type' => 'quiz' );

		// LearnDash stores all quiz attempts in a single user meta entry.
		$attempts = get_user_meta( $user_id, '_sfwd-quizzes', true );
		$last     = null;

		if ( is_array( $attempts ) ) {
			// Find the most recent passing attempt for this quiz.
			foreach ( array_reverse( $attempts ) as $attempt ) {
				if ( isset( $attempt['quiz'] ) && (int) $attempt['quiz'] === $quiz_id ) {
					if ( ! empty( $attempt['pass'] ) ) {
						$last = $attempt;
						break;
					}
				}
			}
			// No passing attempt — fall back to most recent attempt.
			if ( ! $last ) {
				foreach ( array_reverse( $attempts ) as $attempt ) {
					if ( isset( $attempt['quiz'] ) && (int) $attempt['quiz'] === $quiz_id ) {
						$last = $attempt;
						break;
					}
				}
			}
		}

		if ( $last ) {
			$ts = isset( $last['time'] ) ? (int) $last['time'] : 0;
			$data['completed_date'] = $ts ? date_i18n( get_option( 'date_format' ), $ts ) : '';
			$data['completed_time'] = $ts ? date_i18n( get_option( 'time_format' ), $ts ) : '';
			$data['score']          = isset( $last['points'] )       ? (int) $last['points']       : 0;
			$data['total_points']   = isset( $last['total_points'] ) ? (int) $last['total_points'] : 0;
			$data['percentage']     = isset( $last['percentage'] )   ? round( (float) $last['percentage'] ) : 0;
			$data['pass']           = ! empty( $last['pass'] );
			$data['attempts']       = count( array_filter( $attempts, function( $a ) use ( $quiz_id ) {
				return isset( $a['quiz'] ) && (int) $a['quiz'] === $quiz_id;
			} ) );
		} else {
			$data['completed_date'] = '';
			$data['completed_time'] = '';
			$data['score']          = 0;
			$data['total_points']   = 0;
			$data['percentage']     = 0;
			$data['pass']           = false;
			$data['attempts']       = 0;
		}

		return $data;
	}

	/**
	 * Gather group completion details.
	 *
	 * @param int $user_id
	 * @param int $group_id
	 * @return array
	 */
	private static function group_completion( $user_id, $group_id ) {
		$data = array( 'type' => 'group' );

		// Completion timestamp.
		$ts = get_user_meta( $user_id, 'learndash_group_completed_' . $group_id, true );
		$data['completed_date'] = $ts
			? date_i18n( get_option( 'date_format' ), (int) $ts )
			: '';
		$data['completed_time'] = $ts
			? date_i18n( get_option( 'time_format' ), (int) $ts )
			: '';

		// Courses in the group and which are completed.
		$courses = learndash_group_enrolled_courses( $group_id );
		$completed_courses = array();
		$pending_courses   = array();

		foreach ( $courses as $cid ) {
			$status = learndash_course_status( $cid, $user_id );
			if ( $status === 'completed' ) {
				$completed_courses[] = get_the_title( $cid );
			} else {
				$pending_courses[] = get_the_title( $cid );
			}
		}

		$data['total_courses']     = count( $courses );
		$data['completed_courses'] = $completed_courses;
		$data['pending_courses']   = $pending_courses;

		return $data;
	}

	// -----------------------------------------------------------------------
	// Token helpers
	// -----------------------------------------------------------------------

	/**
	 * Generate a deterministic HMAC signature for user+course.
	 * Does not require DB storage — regenerated on demand.
	 *
	 * @param int $user_id
	 * @param int $course_id
	 * @return string 20-char hex signature.
	 */
	public static function make_signature( $user_id, $course_id ) {
		return substr(
			hash_hmac( 'sha256', (int) $user_id . '|' . (int) $course_id, wp_salt( 'auth' ) ),
			0,
			20
		);
	}

	/**
	 * Verify a signature in constant time.
	 */
	public static function verify_signature( $user_id, $course_id, $sig ) {
		return hash_equals( self::make_signature( $user_id, $course_id ), $sig );
	}

	/**
	 * Build the public verification URL for a user+course pair.
	 *
	 * @param int $user_id
	 * @param int $course_id
	 * @return string
	 */
	public static function get_verify_url( $user_id, $course_id ) {
		return add_query_arg( array(
			'cp_ld_verify' => '1',
			'uid'           => (int) $user_id,
			'cid'           => (int) $course_id,
			'sig'           => self::make_signature( $user_id, $course_id ),
		), home_url( '/' ) );
	}
}
