<?php
/**
 * Approval numbers by date, and expiration dates.
 *
 * A course (or quiz, or group) approved by a state board or accrediting body gets a new approval number each period
 * (say April 1 to March 31). A certificate must show the number that was valid when the learner completed it: learners
 * who finished last year keep last year's number. Each course gets a small table of periods (from, to, number), and
 * [cp_ld_approval_number] prints the right one. The verification page shows it too.
 *
 * [cp_ld_certificate_expires days="365"] (or years="3") prints when the certificate expires.
 *
 * [cp_ld_certificate_status start=".." end=".."] prints one of three texts, depending on when the learner completed:
 * before start → expired="..", from start up to (not including) end → valid="..", from end on → not_active="..".
 * A valid certificate becomes expired once the end has passed (when it's viewed or verified after the end).
 * The verification page reads start and end from the same shortcode in the course's certificate template.
 * Only start and end change when a period ends. They take a Unix timestamp or a date ("2026-12-31 23:59:59") read in
 * the site's time zone (Settings → General). step=".." uses that lesson's or topic's completion instead of the course's.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CP_LD_Approvals {

	const META = '_cp_ld_approval_numbers';

	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_box' ) );
		add_action( 'save_post', array( $this, 'save_box' ), 10, 2 );
		add_shortcode( 'cp_ld_approval_number', array( $this, 'approval_shortcode' ) );
		add_shortcode( 'cp_ld_certificate_expires', array( $this, 'expires_shortcode' ) );
		add_shortcode( 'cp_ld_certificate_status', array( $this, 'status_shortcode' ) );
	}

	private static function post_types() {
		return array( 'sfwd-courses', 'sfwd-quiz', 'groups' );
	}

	// -----------------------------------------------------------------------
	// The numbers
	// -----------------------------------------------------------------------

	/**
	 * The periods of a course, oldest first.
	 *
	 * @return array[] array( array( 'from' => 'Y-m-d', 'to' => 'Y-m-d' or '', 'number' => '...' ), ... )
	 */
	public static function periods( $post_id ) {
		$rows = get_post_meta( $post_id, self::META, true );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The approval number that was valid at a moment (the completion time), in the site's time zone. Both dates count
	 * as inside the period. When periods overlap, the one that starts last wins.
	 *
	 * @return string '' when no period covers it.
	 */
	public static function number_at( $post_id, $timestamp ) {
		$timestamp = (int) $timestamp;
		if ( ! $timestamp ) {
			return '';
		}
		$day    = wp_date( 'Y-m-d', $timestamp );
		$number = '';
		foreach ( self::periods( $post_id ) as $p ) {
			if ( '' !== $p['from'] && $day < $p['from'] ) {
				continue;
			}
			if ( '' !== $p['to'] && $day > $p['to'] ) {
				continue;
			}
			$number = $p['number'];
		}
		return $number;
	}

	// -----------------------------------------------------------------------
	// Shortcodes (in the certificate)
	// -----------------------------------------------------------------------

	public function approval_shortcode( $atts ) {
		$atts    = shortcode_atts(
			array(
				'before'  => '', // e.g. "Approval #: "
				'default' => '', // when no period covers the completion date
			),
			$atts,
			'cp_ld_approval_number'
		);
		$context = CP_LD_Certificate_Gate::certificate_context();
		if ( ! $context ) {
			return '';
		}
		$number = self::number_at( $context['post_id'], CP_LD_Certificate_Gate::get_completion_timestamp( $context['user_id'], $context['post_id'] ) );
		$number = '' !== $number ? $number : $atts['default'];
		return '' !== $number ? esc_html( $atts['before'] . $number ) : '';
	}

	public function expires_shortcode( $atts ) {
		$atts    = shortcode_atts(
			array(
				'days'   => '',
				'years'  => '',
				'format' => get_option( 'date_format' ),
			),
			$atts,
			'cp_ld_certificate_expires'
		);
		$context = CP_LD_Certificate_Gate::certificate_context();
		if ( ! $context ) {
			return '';
		}
		$completed = CP_LD_Certificate_Gate::get_completion_timestamp( $context['user_id'], $context['post_id'] );
		if ( ! $completed ) {
			return '';
		}
		$date = ( new DateTimeImmutable( '@' . $completed ) )->setTimezone( wp_timezone() );
		if ( (int) $atts['years'] > 0 ) {
			$date = $date->modify( '+' . (int) $atts['years'] . ' years' );
		} elseif ( (int) $atts['days'] > 0 ) {
			$date = $date->modify( '+' . (int) $atts['days'] . ' days' );
		} else {
			return '';
		}
		return esc_html( wp_date( $atts['format'], $date->getTimestamp() ) );
	}

	/**
	 * A moment from a shortcode attribute: digits are a Unix timestamp; anything else is a date and time in the
	 * site's time zone.
	 *
	 * @return int|null null when it's empty or not a date.
	 */
	public static function moment( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		if ( preg_match( '/^-?\d+$/', $value ) ) {
			return (int) $value;
		}
		try {
			return ( new DateTimeImmutable( $value, wp_timezone() ) )->getTimestamp();
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * When the learner completed a lesson or topic (LearnDash's activity log), or 0.
	 */
	public static function step_completion( $user_id, $step_id ) {
		if ( ! function_exists( 'learndash_get_user_activity' ) ) {
			return 0;
		}
		$type = str_replace( array( 'sfwd-lessons', 'sfwd-topic' ), array( 'lesson', 'topic' ), (string) get_post_type( $step_id ) );
		if ( ! in_array( $type, array( 'lesson', 'topic' ), true ) ) {
			return 0;
		}
		$args = array(
			'user_id'       => (int) $user_id,
			'post_id'       => (int) $step_id,
			'activity_type' => $type,
		);
		if ( function_exists( 'learndash_get_course_id' ) ) {
			$args['course_id'] = (int) learndash_get_course_id( $step_id );
		}
		$activity = learndash_get_user_activity( $args );
		return ( $activity && ! empty( $activity->activity_completed ) ) ? (int) $activity->activity_completed : 0;
	}

	/**
	 * expired, valid or not_active, for a completion time, a period, and now.
	 */
	public static function status_for( $completed, $start, $end, $now = null ) {
		$now = null === $now ? time() : (int) $now;
		if ( null !== $start && $completed < $start ) {
			return 'expired';    // completed before the period
		}
		if ( null !== $end && $completed >= $end ) {
			return 'not_active'; // completed after the period
		}
		if ( null !== $end && $now >= $end ) {
			return 'expired';    // completed in the period, which has ended
		}
		return 'valid';
	}

	/**
	 * The status of a learner's certificate for a course, quiz or group, from the [cp_ld_certificate_status] in its
	 * certificate template.
	 *
	 * @param int      $completed The completion time to use (an old certificate ID's own), or null for the current one.
	 * @return array|null array( 'status', 'start', 'end' ) or null when the template has no status shortcode.
	 */
	public static function status_of( $user_id, $post_id, $completed = null ) {
		$template = function_exists( 'learndash_get_setting' ) ? (int) learndash_get_setting( $post_id, 'certificate' ) : 0;
		$content  = $template ? (string) get_post_field( 'post_content', $template ) : '';
		if ( ! $content || ! preg_match( '/\[cp_ld_certificate_status\b([^\]]*)\]/', $content, $m ) ) {
			return null;
		}
		$atts = shortcode_parse_atts( $m[1] );
		$atts = is_array( $atts ) ? $atts : array();
		if ( null === $completed ) {
			$completed = ! empty( $atts['step'] )
				? self::step_completion( $user_id, absint( $atts['step'] ) )
				: CP_LD_Certificate_Gate::get_completion_timestamp( $user_id, $post_id );
		}
		if ( ! $completed ) {
			return null;
		}
		$start = self::moment( isset( $atts['start'] ) ? $atts['start'] : '' );
		$end   = self::moment( isset( $atts['end'] ) ? $atts['end'] : '' );
		return array(
			'status' => self::status_for( (int) $completed, $start, $end ),
			'start'  => $start,
			'end'    => $end,
		);
	}

	public function status_shortcode( $atts ) {
		$atts    = shortcode_atts(
			array(
				'start'      => '',
				'end'        => '',
				'expired'    => __( 'Expired', 'aikellogg-certificate-id-qr-verifier-for-learndash' ),
				'valid'      => __( 'Valid', 'aikellogg-certificate-id-qr-verifier-for-learndash' ),
				'not_active' => __( 'Not active', 'aikellogg-certificate-id-qr-verifier-for-learndash' ),
				'step'       => '', // a lesson or topic ID: its completion instead of the course's
			),
			$atts,
			'cp_ld_certificate_status'
		);
		$context = CP_LD_Certificate_Gate::certificate_context();
		if ( ! $context ) {
			return '';
		}
		$completed = absint( $atts['step'] )
			? self::step_completion( $context['user_id'], absint( $atts['step'] ) )
			: CP_LD_Certificate_Gate::get_completion_timestamp( $context['user_id'], $context['post_id'] );
		if ( ! $completed ) {
			return '';
		}
		$status = self::status_for( $completed, self::moment( $atts['start'] ), self::moment( $atts['end'] ) );
		return esc_html( $atts[ $status ] );
	}

	// -----------------------------------------------------------------------
	// The table on the course edit screen
	// -----------------------------------------------------------------------

	public function add_box() {
		add_meta_box(
			'cp-ld-approval-numbers',
			__( 'Approval numbers by date', 'aikellogg-certificate-id-qr-verifier-for-learndash' ),
			array( $this, 'render_box' ),
			self::post_types(),
			'normal',
			'default'
		);
	}

	public function render_box( $post ) {
		wp_nonce_field( 'cp_ld_approvals_' . $post->ID, 'cp_ld_approvals_nonce' );
		$rows   = self::periods( $post->ID );
		$rows[] = array(
			'from'   => '',
			'to'     => '',
			'number' => '',
		); // an empty row to fill in
		echo '<p>' . esc_html__( 'The certificate shows the number whose dates include the day the learner completed. Leave "To" empty for the current period.', 'aikellogg-certificate-id-qr-verifier-for-learndash' ) . '</p>';
		echo '<table class="widefat striped" id="cp-ld-approvals"><thead><tr><th>' . esc_html__( 'From', 'aikellogg-certificate-id-qr-verifier-for-learndash' ) . '</th><th>' . esc_html__( 'To', 'aikellogg-certificate-id-qr-verifier-for-learndash' ) . '</th><th>' . esc_html__( 'Approval number', 'aikellogg-certificate-id-qr-verifier-for-learndash' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><td><input type="date" name="cp_ld_approval_from[]" value="' . esc_attr( $r['from'] ) . '" /></td>'
				. '<td><input type="date" name="cp_ld_approval_to[]" value="' . esc_attr( $r['to'] ) . '" /></td>'
				. '<td><input type="text" class="regular-text" name="cp_ld_approval_number[]" value="' . esc_attr( $r['number'] ) . '" /></td></tr>';
		}
		echo '</tbody></table>';
		echo '<p><button type="button" class="button" onclick="var b=document.querySelector(\'#cp-ld-approvals tbody\'),r=b.rows[b.rows.length-1].cloneNode(true);r.querySelectorAll(\'input\').forEach(function(i){i.value=\'\';});b.appendChild(r);">' . esc_html__( 'Add a row', 'aikellogg-certificate-id-qr-verifier-for-learndash' ) . '</button></p>';
		echo '<p class="description">' . esc_html__( 'On the certificate: [cp_ld_approval_number before="Approval #: "]. To remove a row, empty its number.', 'aikellogg-certificate-id-qr-verifier-for-learndash' ) . '</p>';
	}

	public function save_box( $post_id, $post ) {
		if ( ! in_array( $post->post_type, self::post_types(), true ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		if ( ! isset( $_POST['cp_ld_approvals_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cp_ld_approvals_nonce'] ) ), 'cp_ld_approvals_' . $post_id )
			|| ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$from   = isset( $_POST['cp_ld_approval_from'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['cp_ld_approval_from'] ) ) : array();
		$to     = isset( $_POST['cp_ld_approval_to'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['cp_ld_approval_to'] ) ) : array();
		$number = isset( $_POST['cp_ld_approval_number'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['cp_ld_approval_number'] ) ) : array();
		$rows   = array();
		foreach ( $number as $i => $n ) {
			$n = trim( $n );
			if ( '' === $n ) {
				continue;
			}
			$f      = isset( $from[ $i ] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from[ $i ] ) ? $from[ $i ] : '';
			$t      = isset( $to[ $i ] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to[ $i ] ) ? $to[ $i ] : '';
			$rows[] = array(
				'from'   => $f,
				'to'     => $t,
				'number' => $n,
			);
		}
		usort(
			$rows,
			function ( $a, $b ) {
				return strcmp( $a['from'], $b['from'] );
			}
		);
		if ( $rows ) {
			update_post_meta( $post_id, self::META, $rows );
		} else {
			delete_post_meta( $post_id, self::META );
		}
	}
}
