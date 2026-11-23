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

	// -----------------------------------------------------------------------
	// The table on the course edit screen
	// -----------------------------------------------------------------------

	public function add_box() {
		add_meta_box(
			'cp-ld-approval-numbers',
			__( 'Approval numbers by date', 'certificate-id-qr-verifier-for-learndash' ),
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
		echo '<p>' . esc_html__( 'The certificate shows the number whose dates include the day the learner completed. Leave "To" empty for the current period.', 'certificate-id-qr-verifier-for-learndash' ) . '</p>';
		echo '<table class="widefat striped" id="cp-ld-approvals"><thead><tr><th>' . esc_html__( 'From', 'certificate-id-qr-verifier-for-learndash' ) . '</th><th>' . esc_html__( 'To', 'certificate-id-qr-verifier-for-learndash' ) . '</th><th>' . esc_html__( 'Approval number', 'certificate-id-qr-verifier-for-learndash' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><td><input type="date" name="cp_ld_approval_from[]" value="' . esc_attr( $r['from'] ) . '" /></td>'
				. '<td><input type="date" name="cp_ld_approval_to[]" value="' . esc_attr( $r['to'] ) . '" /></td>'
				. '<td><input type="text" class="regular-text" name="cp_ld_approval_number[]" value="' . esc_attr( $r['number'] ) . '" /></td></tr>';
		}
		echo '</tbody></table>';
		echo '<p><button type="button" class="button" onclick="var b=document.querySelector(\'#cp-ld-approvals tbody\'),r=b.rows[b.rows.length-1].cloneNode(true);r.querySelectorAll(\'input\').forEach(function(i){i.value=\'\';});b.appendChild(r);">' . esc_html__( 'Add a row', 'certificate-id-qr-verifier-for-learndash' ) . '</button></p>';
		echo '<p class="description">' . esc_html__( 'On the certificate: [cp_ld_approval_number before="Approval #: "]. To remove a row, empty its number.', 'certificate-id-qr-verifier-for-learndash' ) . '</p>';
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
