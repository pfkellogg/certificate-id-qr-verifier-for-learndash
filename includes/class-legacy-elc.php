<?php
/**
 * A drop-in replacement for the older eLearning Complete pair "Certificate Tracker for LearnDash" (elc-ctid-tracker)
 * and "Certificate Verifier for LearnDash" (elc-ctid-verifier).
 *
 * They saved every certificate ID a learner was issued in the user meta elc_certificate_ids (an array of
 * array( 'cid' => 'PREFIX-time-post-user' )), printed it with [elc_certificate_id prefix=".."], put it in a
 * Shortcodes Ultimate QR code ([su_qrcode]) as ?certificate_id=.., and verified it on a page with [elc_ssc_front_search].
 *
 * This keeps all of that working with the old plugins switched off:
 * - IDs already issued (and those issued from now on by the old shortcode) verify from that saved record.
 * - [elc_certificate_id] prints the same IDs as before, and keeps adding them to the record.
 * - [su_qrcode] on a certificate still gets ?certificate_id=.., so its codes keep working.
 * - [elc_ssc_front_search] (and the new [cp_ld_certificate_search]) is a search box; old QR links that open that page
 *   with ?certificate_id=.. go straight to the verification page.
 *
 * The saved record is never deleted, not even when this plugin is: it's the learners' certificate history.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CP_LD_Legacy_ELC {

	const META = 'elc_certificate_ids';

	public function __construct() {
		// After the plugins load: when the old plugins are still active, their shortcodes stay theirs.
		add_action( 'init', array( $this, 'register' ), 20 );
		add_action( 'template_redirect', array( $this, 'search_redirect' ), 2 );
		add_action( 'admin_notices', array( $this, 'old_plugins_notice' ) );
	}

	public function register() {
		if ( ! shortcode_exists( 'elc_certificate_id' ) ) {
			add_shortcode( 'elc_certificate_id', array( $this, 'id_shortcode' ) );
			add_filter( 'shortcode_atts_qrcode', array( $this, 'su_qrcode_atts' ) );
		}
		if ( ! shortcode_exists( 'elc_ssc_front_search' ) ) {
			add_shortcode( 'elc_ssc_front_search', array( $this, 'search_shortcode' ) );
		}
		add_shortcode( 'cp_ld_certificate_search', array( $this, 'search_shortcode' ) );
	}

	// -----------------------------------------------------------------------
	// Finding a certificate by its ID
	// -----------------------------------------------------------------------

	/**
	 * Looks a certificate ID up: first in the learner's saved record (IDs from the old plugin, or printed with
	 * [elc_certificate_id]), then as this plugin's own ID. IDs end in -post-user, whatever the prefix.
	 *
	 * @param string $raw The ID as typed or scanned.
	 * @return array|null array( user_id, post_id, timestamp, id, legacy ) or null when it isn't a real certificate.
	 */
	public static function find( $raw ) {
		$id = trim( sanitize_text_field( (string) $raw ) );
		if ( '' === $id || strlen( $id ) > 80 ) {
			return null;
		}
		$parts = array_reverse( explode( '-', $id ) );
		if ( count( $parts ) < 3 || ! ctype_digit( $parts[0] ) || ! ctype_digit( $parts[1] ) || ! ctype_digit( $parts[2] ) ) {
			return null;
		}
		$user_id = (int) $parts[0];
		$post_id = (int) $parts[1];
		if ( ! $user_id || ! $post_id || ! get_userdata( $user_id ) ) {
			return null;
		}
		$found = array(
			'user_id'   => $user_id,
			'post_id'   => $post_id,
			'timestamp' => (int) $parts[2],
		);

		$saved = get_user_meta( $user_id, self::META, true );
		if ( is_array( $saved ) ) {
			foreach ( $saved as $row ) {
				if ( is_array( $row ) && isset( $row['cid'] ) && 0 === strcasecmp( trim( (string) $row['cid'] ), $id ) ) {
					return $found + array(
						'id'     => (string) $row['cid'],
						'legacy' => true,
					);
				}
			}
		}

		$current = CP_LD_Certificate_Gate::certificate_id( $user_id, $post_id );
		if ( '' !== $current && 0 === strcasecmp( $current, $id ) ) {
			return $found + array(
				'id'     => $current,
				'legacy' => false,
			);
		}
		return null;
	}

	/**
	 * The verification page for a certificate ID.
	 */
	public static function verify_url_for_id( $id ) {
		return add_query_arg(
			array(
				'cp_ld_verify' => 1,
				'ctid'         => rawurlencode( $id ),
			),
			home_url( '/' )
		);
	}

	// -----------------------------------------------------------------------
	// [elc_certificate_id prefix=".."]: the same IDs the old Certificate Tracker printed
	// -----------------------------------------------------------------------

	/**
	 * The certificate being shown: its post (course, quiz or group) and completion time, as the old tracker found them.
	 *
	 * @return array|null array( post_id, timestamp )
	 */
	private static function current_certificate( $user_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- LearnDash's own certificate link (checked by LearnDash).
		if ( ! empty( $_REQUEST['quiz'] ) ) {
			$quiz_id   = absint( $_REQUEST['quiz'] );
			$quiz_time = isset( $_REQUEST['time'] ) ? absint( $_REQUEST['time'] ) : 0;
			$attempts  = get_user_meta( $user_id, '_sfwd-quizzes', true );
			$timestamp = 0;
			if ( is_array( $attempts ) ) {
				foreach ( $attempts as $a ) {
					if ( ! empty( $a['pass'] ) && isset( $a['quiz'] ) && (int) $a['quiz'] === $quiz_id
						&& ( ! $quiz_time || ( isset( $a['time'] ) && (int) $a['time'] === $quiz_time ) ) ) {
						$timestamp = isset( $a['completed'] ) ? (int) $a['completed'] : 0; // the last passed attempt
					}
				}
			}
			return array( $quiz_id, $timestamp );
		}
		if ( ! empty( $_REQUEST['course_id'] ) ) {
			$course_id = absint( $_REQUEST['course_id'] );
			return array( $course_id, (int) get_user_meta( $user_id, 'course_completed_' . $course_id, true ) );
		}
		if ( ! empty( $_REQUEST['group_id'] ) ) {
			$group_id = absint( $_REQUEST['group_id'] );
			return array( $group_id, (int) get_user_meta( $user_id, 'learndash_group_completed_' . $group_id, true ) );
		}
		// phpcs:enable
		return null;
	}

	/**
	 * The ID for the certificate being shown, with the old tracker's rules: the prefix keeps letters, digits and
	 * spaces, up to 8; no prefix means no leading dash. It's added to the learner's record the first time.
	 */
	public static function legacy_id( $prefix ) {
		if ( 'sfwd-certificates' !== get_post_type() ) {
			return '';
		}
		$user_id = get_current_user_id(); // LearnDash switches to the learner when an admin opens their certificate
		$current = $user_id ? self::current_certificate( $user_id ) : null;
		if ( ! $current || ! $current[1] ) {
			return '';
		}
		$prefix = substr( preg_replace( '/[^A-Za-z0-9 ]/', '', (string) $prefix ), 0, 8 );
		$id     = ( '' !== $prefix ? $prefix . '-' : '' ) . $current[1] . '-' . $current[0] . '-' . $user_id;

		$saved = get_user_meta( $user_id, self::META, true );
		$saved = is_array( $saved ) ? $saved : array();
		foreach ( $saved as $row ) {
			if ( is_array( $row ) && isset( $row['cid'] ) && $row['cid'] === $id ) {
				return $id;
			}
		}
		$saved[] = array( 'cid' => $id );
		update_user_meta( $user_id, self::META, $saved );
		return $id;
	}

	public function id_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'prefix' => '' ), $atts, 'elc_certificate_id' );
		return esc_html( self::legacy_id( $atts['prefix'] ) );
	}

	/**
	 * [su_qrcode] (Shortcodes Ultimate) on a certificate: add ?certificate_id=.. to its data and link, as the old tracker did.
	 */
	public function su_qrcode_atts( $out ) {
		if ( 'sfwd-certificates' !== get_post_type() ) {
			return $out;
		}
		$prefix  = '';
		$content = (string) get_post_field( 'post_content', get_the_ID(), 'raw' );
		if ( preg_match( '/\[elc_certificate_id\b([^\]]*)\]/', $content, $m ) ) {
			$a      = shortcode_parse_atts( $m[1] );
			$prefix = ( is_array( $a ) && isset( $a['prefix'] ) ) ? $a['prefix'] : '';
		}
		$id = self::legacy_id( $prefix );
		if ( '' === $id ) {
			return $out;
		}
		foreach ( array( 'data', 'link' ) as $key ) {
			if ( ! empty( $out[ $key ] ) ) {
				$out[ $key ] = add_query_arg( 'certificate_id', rawurlencode( $id ), $out[ $key ] );
			}
		}
		return $out;
	}

	// -----------------------------------------------------------------------
	// The search page: [elc_ssc_front_search] / [cp_ld_certificate_search]
	// -----------------------------------------------------------------------

	private static function page_has_search( $post ) {
		return $post && ( has_shortcode( $post->post_content, 'cp_ld_certificate_search' )
			|| ( has_shortcode( $post->post_content, 'elc_ssc_front_search' ) && ! function_exists( 'elc_ssc_front_search' ) ) );
	}

	/**
	 * The search page opened with ?certificate_id=.. (an old QR code, or the search box): a real certificate goes
	 * straight to its verification page.
	 */
	public function search_redirect() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public lookup.
		if ( ! isset( $_GET['certificate_id'] ) || ! is_singular() || ! self::page_has_search( get_post() ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public lookup.
		$found = self::find( sanitize_text_field( wp_unslash( $_GET['certificate_id'] ) ) );
		if ( $found ) {
			wp_safe_redirect( self::verify_url_for_id( $found['id'] ) );
			exit;
		}
	}

	/**
	 * The search box's look: the verification card's navy and gold, in a theme's own fonts.
	 */
	private static function search_style() {
		wp_register_style( 'cp-ld-cert-search', false, array(), CP_LD_VERSION );
		wp_enqueue_style( 'cp-ld-cert-search' );
		wp_add_inline_style(
			'cp-ld-cert-search',
			'.cp-ld-cert-search-wrap{max-width:560px;margin:1.5em 0;padding:24px 26px;border-radius:14px;background:#fff;border:1px solid #e3e8ef;border-top:5px solid #1f3a5f;box-shadow:0 6px 24px rgba(15,35,65,.08)}'
			. '.cp-ld-cert-search label{display:block;font-size:13px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#8a6420;margin-bottom:8px}'
			. '.cp-ld-cert-search-row{display:flex;gap:10px;flex-wrap:wrap}'
			. '.cp-ld-cert-search input[type=text]{flex:1 1 240px;min-width:0;font:600 17px/1.2 ui-monospace,Menlo,Consolas,monospace;letter-spacing:.03em;padding:12px 14px;border:2px solid #cfd8e3;border-radius:10px;background:#fbfcfe;color:#1a202c}'
			. '.cp-ld-cert-search input[type=text]:focus{outline:none;border-color:#1f3a5f;box-shadow:0 0 0 3px rgba(31,58,95,.15)}'
			. '.cp-ld-cert-search button{font-size:16px;font-weight:700;padding:12px 26px;border:0;border-radius:10px;background:#1f3a5f;color:#fff;cursor:pointer}'
			. '.cp-ld-cert-search button:hover,.cp-ld-cert-search button:focus{background:#8a6420}'
			. '.cp-ld-cert-search-help{margin:10px 0 0;font-size:14px;color:#64748b}'
			. '.cp-ld-cert-search-none{margin:14px 0 0;padding:10px 14px;border-radius:10px;background:#fef2f2;color:#7f1d1d;font-weight:600}'
		);
	}

	public function search_shortcode() {
		self::search_style();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public lookup.
		$asked = isset( $_GET['certificate_id'] ) ? sanitize_text_field( wp_unslash( $_GET['certificate_id'] ) ) : '';
		$html  = '<form class="cp-ld-cert-search" method="get" action="' . esc_url( get_permalink() ) . '">'
			. '<label for="cp-ld-cert-search-id">' . esc_html__( 'Certificate ID', 'aikellogg-certificate-id-qr-verifier-for-learndash' ) . '</label> '
			. '<div class="cp-ld-cert-search-row"><input type="text" id="cp-ld-cert-search-id" name="certificate_id" maxlength="80" size="28" value="' . esc_attr( $asked ) . '" placeholder="CERT-1795464733-6-1" autocomplete="off" required />'
			. '<button type="submit">' . esc_html__( 'Verify', 'aikellogg-certificate-id-qr-verifier-for-learndash' ) . '</button></div>'
			. '<p class="cp-ld-cert-search-help">' . esc_html__( 'Type the ID printed on the certificate, or scan its QR code.', 'aikellogg-certificate-id-qr-verifier-for-learndash' ) . '</p>'
			. '</form>';
		if ( '' !== $asked ) {
			// A real ID was sent on to its verification page before the page loaded, so this one wasn't found.
			$html .= '<p class="cp-ld-cert-search-none" role="status">' . esc_html__( 'No certificate was found with that ID. Check it and try again.', 'aikellogg-certificate-id-qr-verifier-for-learndash' ) . '</p>';
		}
		return '<div class="cp-ld-cert-search-wrap">' . $html . '</div>';
	}

	// -----------------------------------------------------------------------

	public function old_plugins_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		if ( ! function_exists( 'elc_certificate_id' ) && ! function_exists( 'elc_ssc_front_search' ) ) {
			return;
		}
		echo '<div class="notice notice-info"><p>' . esc_html__( 'AIKellogg Certificate ID & QR Verifier for LearnDash replaces the older Certificate Tracker and Certificate Verifier for LearnDash (eLearning Complete), which are still active. You can deactivate them: certificate IDs they issued, their shortcodes and their QR codes keep working.', 'aikellogg-certificate-id-qr-verifier-for-learndash' ) . '</p></div>';
	}
}
