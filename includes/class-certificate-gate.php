<?php
/**
 * Certificate Gate — gates certificate access behind photo requirement,
 * injects photo and QR code into PDF certificates.
 *
 * @package CertificatesPlusLD
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CP_LD_Certificate_Gate {

	public function __construct() {
		add_filter( 'learndash_course_certificate_link',   array( $this, 'gate_link' ), 20, 3 );
		add_filter( 'learndash_group_certificate_link',    array( $this, 'gate_link' ), 20, 3 );
		add_filter( 'learndash_certificate_details_link',  array( $this, 'gate_quiz_link' ), 20, 4 );
		add_action( 'template_redirect', array( $this, 'block_direct_access' ), 5 );
		add_filter( 'learndash_certification_content_write_cell_args', array( $this, 'filter_cert_html' ), 10, 2 );
		add_action( 'learndash_certification_content_write_cell_after', array( $this, 'draw_on_pdf' ), 10, 2 );
		add_action( 'learndash_delete_user_data', array( $this, 'on_course_data_deleted' ) );
		add_shortcode( 'cp_ld_certificate', array( $this, 'certificate_shortcode' ) );
		add_shortcode( 'cp_ld_certificate_id', array( $this, 'certificate_id_shortcode' ) );
	}

	public function on_course_data_deleted( $user_id ) {
		CP_LD_Photo_Manager::clear_cert_snapshots( $user_id );
	}

	public function gate_link( $url, $post_id, $user_id ) {
		if ( empty( $url ) ) { return $url; }
		if ( CP_LD_Settings::is_photo_bypassed() ) { return $url; }
		if ( ! $user_id ) { $user_id = get_current_user_id(); }
		if ( ! CP_LD_Photo_Manager::has_photo( $user_id ) ) { return $this->upload_url( $url ); }
		return $url;
	}

	public function gate_quiz_link( $url, $certificate_post, $quiz_id, $user_id ) {
		return $this->gate_link( $url, $quiz_id, $user_id );
	}

	public function block_direct_access() {
		if ( empty( $_GET['cert-nonce'] ) ) { return; }
		if ( CP_LD_Settings::is_photo_bypassed() ) { return; }
		$user_id = isset( $_GET['user'] ) ? (int) $_GET['user'] : get_current_user_id();
		if ( ! CP_LD_Photo_Manager::has_photo( $user_id ) ) {
			wp_safe_redirect( $this->upload_url() );
			exit;
		}
	}

	public function filter_cert_html( $pdf_cell_args, $cert_args ) {
		$cert_id = isset( $cert_args['cert_id'] ) ? (int) $cert_args['cert_id'] : 0;
		if ( ! $cert_id ) {
			return $pdf_cell_args;
		}

		// Strip shortcode HTML — draw_on_pdf() positions these manually.
		$content = $pdf_cell_args['content'];
		$content = preg_replace( '/<div[^>]*class=["\'][^"\']*cp-ld-cert-extras[^"\']*["\'][^>]*>.*?<\/div>/isu', '', $content );
		$pdf_cell_args['content'] = $content;

		return $pdf_cell_args;
	}

	public function draw_on_pdf( $pdf, $cert_args ) {
		$user_id = isset( $cert_args['user_id'] ) ? (int) $cert_args['user_id'] : 0;
		$post_id = isset( $cert_args['post_id'] ) ? (int) $cert_args['post_id'] : 0;
		$cert_id = isset( $cert_args['cert_id'] ) ? (int) $cert_args['cert_id'] : 0;
		if ( ! $user_id ) { return; }

		$raw_content = '';
		if ( isset( $cert_args['cert_post'] ) && is_object( $cert_args['cert_post'] ) ) {
			$raw_content = $cert_args['cert_post']->post_content;
		} elseif ( $cert_id ) {
			$cert_post = get_post( $cert_id );
			if ( $cert_post ) { $raw_content = $cert_post->post_content; }
		}
		$sc = self::parse_template_overrides( $raw_content );

		$pdf->SetAutoPageBreak( false );
		while ( $pdf->getNumPages() > 1 ) { $pdf->deletePage( $pdf->getNumPages() ); }
		$pdf->setPage( 1 );

		$page_w = $pdf->GetPageWidth();
		$page_h = $pdf->GetPageHeight();
		$margin = 10;

		// ---- Learner photo ----
		$photo_size = self::opt( $sc, 'photo_size', 24 );
		$photo_x    = self::opt( $sc, 'photo_x',    $margin );
		$photo_y    = self::opt( $sc, 'photo_y',    $page_h - $margin - $photo_size );

		$photo_raw  = '';
		$photo_src  = '';

		// Use existing snapshot so the certificate keeps the original photo.
		$snapshot_file = self::get_snapshot_file( $user_id, $post_id );
		if ( $snapshot_file ) {
			$ext = strtolower( pathinfo( $snapshot_file, PATHINFO_EXTENSION ) );
			if ( $ext === 'webp' && function_exists( 'imagecreatefromwebp' ) ) {
				$im = @imagecreatefromwebp( $snapshot_file );
				if ( $im ) {
					ob_start(); imagepng( $im ); $photo_raw = (string) ob_get_clean(); imagedestroy( $im );
				}
			} else {
				$photo_src = $snapshot_file;
			}
		}

		// No snapshot yet — use current photo and create the snapshot.
		if ( ! $photo_src && ! $photo_raw ) {
			$photo_id   = CP_LD_Photo_Manager::get_photo_id( $user_id );
			$photo_file = $photo_id ? get_attached_file( $photo_id ) : '';
			if ( $photo_file && file_exists( $photo_file ) ) {
				$ext = strtolower( pathinfo( $photo_file, PATHINFO_EXTENSION ) );
				if ( $ext === 'webp' && function_exists( 'imagecreatefromwebp' ) ) {
					$im = @imagecreatefromwebp( $photo_file );
					if ( $im ) {
						ob_start(); imagepng( $im ); $photo_raw = (string) ob_get_clean(); imagedestroy( $im );
					}
				} else {
					$photo_src = $photo_file;
				}
			}
			if ( ! $photo_src && ! $photo_raw ) {
				$photo_url = CP_LD_Photo_Manager::get_photo_url( $user_id, 'medium' );
				if ( $photo_url ) {
					$resp = wp_remote_get( $photo_url, array( 'timeout' => 5 ) );
					if ( ! is_wp_error( $resp ) && 200 === (int) wp_remote_retrieve_response_code( $resp ) ) {
						$body = wp_remote_retrieve_body( $resp );
						$ct   = wp_remote_retrieve_header( $resp, 'content-type' );
						if ( strpos( $ct, 'webp' ) !== false && function_exists( 'imagecreatefromstring' ) ) {
							$im = @imagecreatefromstring( $body );
							if ( $im ) { ob_start(); imagepng( $im ); $body = (string) ob_get_clean(); imagedestroy( $im ); }
						}
						$photo_raw = $body;
					}
				}
			}
		}

		if ( $photo_src ) {
			$pdf->Image( $photo_src, $photo_x, $photo_y, $photo_size, $photo_size, '', '', '', true, 150 );
			if ( ! $snapshot_file ) {
				self::snapshot_photo( $user_id, $post_id, $photo_src );
			}
		} elseif ( $photo_raw ) {
			$pdf->Image( '@' . $photo_raw, $photo_x, $photo_y, $photo_size, $photo_size );
			if ( ! $snapshot_file ) {
				self::snapshot_photo_from_raw( $user_id, $post_id, $photo_raw );
			}
		}

		// ---- QR code ----
		$qr_size = self::opt( $sc, 'qr_size', 32 );
		$qr_x    = self::opt( $sc, 'qr_x',    $page_w - $margin - $qr_size );
		$qr_y    = self::opt( $sc, 'qr_y',    $page_h - $margin - $qr_size - 8 );

		$verify_url = CP_LD_Verification::get_verify_url( $user_id, $post_id );
		$qr_png     = CP_LD_QRCode::png_bytes( $verify_url, 300 );
		if ( $qr_png ) {
			$pdf->Image( '@' . $qr_png, $qr_x, $qr_y, $qr_size, $qr_size, 'PNG' );
			$pdf->SetFont( 'helvetica', '', 11 );
			$pdf->SetTextColor( 60, 60, 60 );
			$pdf->SetXY( $qr_x, $qr_y + $qr_size + 1 );
			$pdf->Cell( $qr_size, 8, __( 'Scan to verify', 'cp-ld' ), 0, 0, 'C' );
			if ( ! empty( $sc['show_url'] ) ) {
				$pdf->SetFont( 'helvetica', '', 9 );
				$pdf->SetTextColor( 60, 60, 60 );
				$pdf->SetXY( $qr_x, $qr_y + $qr_size + 7 );
				$pdf->Cell( $qr_size, 5, $verify_url, 0, 0, 'C' );
			}
		}
	}

	// -----------------------------------------------------------------------
	// Combined shortcode — renders both photo and QR in the browser
	// -----------------------------------------------------------------------

	public function certificate_shortcode( $atts ) {
		$atts = shortcode_atts( array(
			'photo_x'    => null,
			'photo_y'    => null,
			'photo_size' => null,
			'qr_x'      => null,
			'qr_y'      => null,
			'qr_size'    => null,
			'label'      => 1,
			'show_url'   => 0,
		), $atts );

		$user_id = isset( $_GET['user'] ) ? (int) $_GET['user'] : get_current_user_id();
		$post_id = self::detect_post_id();
		if ( ! $user_id ) { return ''; }

		// Photo — prefer the frozen snapshot so the certificate keeps the original photo.
		$photo_html = '';
		$photo_url  = self::get_snapshot_photo_url( $user_id, $post_id );
		if ( ! $photo_url ) {
			$photo_url = CP_LD_Photo_Manager::get_photo_url( $user_id, 'medium' );
		}
		if ( $photo_url ) {
			$photo_w    = ( null !== $atts['photo_size'] ) ? max( 40, (int) $atts['photo_size'] * 3 ) : 120;
			$photo_html = '<img src="' . esc_url( $photo_url ) . '" class="cp-ld-photo" style="border-radius:50%;max-width:' . $photo_w . 'px;" />';
		}

		// QR
		$qr_w       = ( null !== $atts['qr_size'] ) ? max( 60, (int) $atts['qr_size'] * 3 ) : 120;
		$verify_url = CP_LD_Verification::get_verify_url( $user_id, $post_id );
		$svg        = CP_LD_QRCode::svg( $verify_url, $qr_w );
		$label_html = '';
		if ( $atts['label'] ) {
			$label_html = '<p class="cp-ld-qr-label" style="margin:4px 0 0;font-size:11px;color:#64748b;">' . esc_html__( 'Scan to verify', 'cp-ld' ) . '</p>';
		}
		$url_html = '';
		if ( $atts['show_url'] ) {
			$url_html = '<p class="cp-ld-qr-url" style="margin:6px 0 0;font-size:12px;color:#64748b;word-break:break-all;">' . esc_html( $verify_url ) . '</p>';
		}
		$qr_html = '<div style="display:inline-block;text-align:center;">' . $svg . $label_html . $url_html . '</div>';

		return '<div class="cp-ld-cert-extras" style="display:flex;align-items:center;justify-content:space-between;gap:16px;">'
			. $photo_html . $qr_html
			. '</div>';
	}

	// -----------------------------------------------------------------------
	// Certificate ID shortcode — unique ID from prefix + timestamp + user ID
	// -----------------------------------------------------------------------

	public function certificate_id_shortcode( $atts ) {
		$atts = shortcode_atts( array(
			'prefix' => 'CERT',
			'x'      => null,
			'y'      => null,
			'size'   => 12,
		), $atts );

		$user_id = isset( $_GET['user'] ) ? (int) $_GET['user'] : get_current_user_id();
		$post_id = self::detect_post_id();
		if ( ! $user_id || ! $post_id ) { return ''; }

		$timestamp = self::get_completion_timestamp( $user_id, $post_id );
		if ( ! $timestamp ) { return ''; }

		$cert_id = strtoupper( sanitize_text_field( $atts['prefix'] ) )
			. '-' . $timestamp
			. '-' . $post_id
			. '-' . $user_id;

		$style = 'font-size:' . (int) $atts['size'] . 'mm;';
		if ( null !== $atts['x'] && null !== $atts['y'] ) {
			$style .= 'position:absolute;left:' . (int) $atts['x'] . 'mm;top:' . (int) $atts['y'] . 'mm;';
		}

		return '<span class="cp-ld-certificate-id" style="' . esc_attr( $style ) . '">' . esc_html( $cert_id ) . '</span>';
	}

	public static function get_completion_timestamp( $user_id, $post_id ) {
		$post_type = get_post_type( $post_id );

		if ( 'sfwd-courses' === $post_type ) {
			return (int) get_user_meta( $user_id, 'course_completed_' . $post_id, true );
		}

		if ( 'groups' === $post_type ) {
			return (int) get_user_meta( $user_id, 'learndash_group_completed_' . $post_id, true );
		}

		if ( 'sfwd-quiz' === $post_type ) {
			$attempts = get_user_meta( $user_id, '_sfwd-quizzes', true );
			if ( is_array( $attempts ) ) {
				foreach ( array_reverse( $attempts ) as $attempt ) {
					if ( isset( $attempt['quiz'] ) && (int) $attempt['quiz'] === $post_id && ! empty( $attempt['time'] ) ) {
						return (int) $attempt['time'];
					}
				}
			}
		}

		return 0;
	}

	// -----------------------------------------------------------------------
	// Photo snapshots — freeze the photo used on each certificate
	// -----------------------------------------------------------------------

	private static function snapshot_photo( $user_id, $post_id, $file_path ) {
		$dest = self::snapshot_path( $user_id, $post_id, pathinfo( $file_path, PATHINFO_EXTENSION ) );
		if ( ! $dest ) { return; }
		copy( $file_path, $dest );
	}

	private static function snapshot_photo_from_raw( $user_id, $post_id, $raw ) {
		$dest = self::snapshot_path( $user_id, $post_id, 'png' );
		if ( ! $dest ) { return; }
		file_put_contents( $dest, $raw );
	}

	private static function snapshot_path( $user_id, $post_id, $ext ) {
		$upload_dir = wp_upload_dir();
		$dir        = $upload_dir['basedir'] . '/cp-ld-cert-photos';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$ext = strtolower( $ext );
		if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'webp' ), true ) ) {
			$ext = 'png';
		}
		return $dir . '/' . (int) $user_id . '_' . (int) $post_id . '.' . $ext;
	}

	private static function get_snapshot_file( $user_id, $post_id ) {
		$upload_dir = wp_upload_dir();
		$dir        = $upload_dir['basedir'] . '/cp-ld-cert-photos';
		$base       = $dir . '/' . (int) $user_id . '_' . (int) $post_id;
		foreach ( array( 'jpg', 'jpeg', 'png', 'webp' ) as $ext ) {
			if ( file_exists( $base . '.' . $ext ) ) {
				return $base . '.' . $ext;
			}
		}
		return false;
	}

	public static function get_snapshot_photo_url( $user_id, $post_id ) {
		$upload_dir = wp_upload_dir();
		$dir        = $upload_dir['basedir'] . '/cp-ld-cert-photos';
		$base_url   = $upload_dir['baseurl'] . '/cp-ld-cert-photos';
		$base       = $dir . '/' . (int) $user_id . '_' . (int) $post_id;
		foreach ( array( 'jpg', 'jpeg', 'png', 'webp' ) as $ext ) {
			if ( file_exists( $base . '.' . $ext ) ) {
				return $base_url . '/' . (int) $user_id . '_' . (int) $post_id . '.' . $ext;
			}
		}
		return false;
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	private static function parse_template_overrides( $content ) {
		if ( ! $content ) { return array(); }
		$out = array();
		if ( preg_match( '/\[cp_ld_certificate\b([^\]]*)\]/', $content, $m ) ) {
			$atts = self::parse_sc_atts( $m[1] );
			$map  = array(
				'photo_x'    => 'photo_x',
				'photo_y'    => 'photo_y',
				'photo_size' => 'photo_size',
				'qr_x'      => 'qr_x',
				'qr_y'      => 'qr_y',
				'qr_size'   => 'qr_size',
				'show_url'  => 'show_url',
			);
			foreach ( $map as $attr => $key ) {
				if ( isset( $atts[ $attr ] ) && $atts[ $attr ] !== '' ) {
					$out[ $key ] = (float) $atts[ $attr ];
				}
			}
		}
		return $out;
	}

	private static function parse_sc_atts( $atts_str ) {
		$atts = array();
		if ( preg_match_all( '/(\w+)\s*=\s*["\']([^"\']*)["\']/', $atts_str, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				$atts[ strtolower( $m[1] ) ] = $m[2];
			}
		}
		return $atts;
	}

	private static function opt( $sc, $override_key, $auto ) {
		if ( isset( $sc[ $override_key ] ) && (float) $sc[ $override_key ] > 0 ) {
			return (float) $sc[ $override_key ];
		}
		return (float) $auto;
	}

	private static function detect_post_id() {
		if ( isset( $_GET['course_id'] ) && (int) $_GET['course_id'] ) { return (int) $_GET['course_id']; }
		if ( isset( $_GET['quiz'] ) && (int) $_GET['quiz'] ) { return (int) $_GET['quiz']; }
		if ( isset( $_GET['group_id'] ) && (int) $_GET['group_id'] ) { return (int) $_GET['group_id']; }
		return 0;
	}

	private function upload_url( $return_url = '' ) {
		global $wpdb;
		$page_id = (int) $wpdb->get_var(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type = 'page'
			   AND post_status = 'publish'
			   AND post_content LIKE '%<!-- wp:cp-ld/photo-upload%'
			 LIMIT 1"
		);
		if ( $page_id ) {
			$args = array( 'cp_ld_notice' => 'photo_required' );
			if ( $return_url ) {
				$args['cp_ld_return'] = rawurlencode( $return_url );
			}
			return add_query_arg( $args, get_permalink( $page_id ) );
		}
		return admin_url( 'profile.php' ) . '#cp-ld-photo';
	}
}
