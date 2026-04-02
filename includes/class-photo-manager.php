<?php
/**
 * Photo Manager — handles upload, storage, and retrieval of learner photos.
 *
 * Photos are stored as WordPress media attachments. The attachment ID is
 * saved as user meta `_cp_ld_photo_id`.
 *
 * @package CertificatesPlusLD
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CP_LD_Photo_Manager {

	const META_KEY     = '_cp_ld_photo_id';
	const LOG_META_KEY = '_cp_ld_photo_log';

	public function __construct() {
		// AJAX handlers — logged-in users only.
		add_action( 'wp_ajax_cp_ld_upload_photo',  array( $this, 'ajax_upload' ) );
		add_action( 'wp_ajax_cp_ld_delete_photo',  array( $this, 'ajax_delete' ) );

		// Enqueue frontend assets.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend' ) );
	}

	private static $GRAVATAR_PX = array(
		'thumbnail' => 96,
		'medium'    => 300,
		'large'     => 600,
		'full'      => 800,
	);

	public static function has_photo( $user_id ) {
		$id = (int) get_user_meta( $user_id, self::META_KEY, true );
		if ( $id > 0 && get_post( $id ) !== null ) {
			return true;
		}
		$user = get_userdata( $user_id );
		return $user && self::has_gravatar( $user->user_email );
	}

	public static function has_uploaded_photo( $user_id ) {
		$id = (int) get_user_meta( $user_id, self::META_KEY, true );
		return $id > 0 && get_post( $id ) !== null;
	}

	public static function get_photo_url( $user_id, $size = 'thumbnail' ) {
		$attachment_id = (int) get_user_meta( $user_id, self::META_KEY, true );
		if ( $attachment_id ) {
			$src = wp_get_attachment_image_src( $attachment_id, $size );
			if ( $src ) {
				return $src[0];
			}
		}
		$user = get_userdata( $user_id );
		if ( $user && self::has_gravatar( $user->user_email ) ) {
			$px = isset( self::$GRAVATAR_PX[ $size ] ) ? self::$GRAVATAR_PX[ $size ] : 96;
			return get_avatar_url( $user_id, array( 'size' => $px ) );
		}
		return false;
	}

	public static function get_gravatar_url( $user_id, $size = 'medium' ) {
		$user = get_userdata( $user_id );
		if ( ! $user || ! self::has_gravatar( $user->user_email ) ) {
			return false;
		}
		$px = isset( self::$GRAVATAR_PX[ $size ] ) ? self::$GRAVATAR_PX[ $size ] : 300;
		return get_avatar_url( $user_id, array( 'size' => $px ) );
	}

	public static function has_gravatar( $email ) {
		$cache_key = 'cp_ld_grav_' . md5( $email );
		$cached    = get_transient( $cache_key );
		if ( $cached !== false ) {
			return $cached === 'yes';
		}
		$hash     = md5( strtolower( trim( $email ) ) );
		$response = wp_remote_head(
			'https://www.gravatar.com/avatar/' . $hash . '?d=404',
			array( 'timeout' => 3 )
		);
		$has = ! is_wp_error( $response )
			&& (int) wp_remote_retrieve_response_code( $response ) === 200;
		set_transient( $cache_key, $has ? 'yes' : 'no', DAY_IN_SECONDS );
		return $has;
	}

	public static function get_photo_id( $user_id ) {
		return (int) get_user_meta( $user_id, self::META_KEY, true );
	}

	public function ajax_upload() {
		check_ajax_referer( 'cp_ld_photo_nonce', 'nonce' );
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'cp-ld' ) ) );
		}
		if ( empty( $_FILES['cp_ld_photo'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No file received.', 'cp-ld' ) ) );
		}
		$file     = $_FILES['cp_ld_photo'];
		$allowed  = array( 'image/jpeg', 'image/png', 'image/webp' );
		$finfo    = finfo_open( FILEINFO_MIME_TYPE );
		$mime     = finfo_file( $finfo, $file['tmp_name'] );
		finfo_close( $finfo );
		if ( ! in_array( $mime, $allowed, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Only JPEG, PNG, or WebP images are allowed.', 'cp-ld' ) ) );
		}
		if ( $file['size'] > 5 * MB_IN_BYTES ) {
			wp_send_json_error( array( 'message' => __( 'Photo must be under 5 MB.', 'cp-ld' ) ) );
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		// Snapshot old photo before deleting it.
		$old_id       = self::get_photo_id( $user_id );
		$old_snapshot = $old_id ? self::snapshot_for_log( $old_id ) : '';
		if ( $old_id ) {
			wp_delete_attachment( $old_id, true );
		}

		$attachment_id = media_handle_upload( 'cp_ld_photo', 0 );
		if ( is_wp_error( $attachment_id ) ) {
			wp_send_json_error( array( 'message' => $attachment_id->get_error_message() ) );
		}
		update_user_meta( $user_id, self::META_KEY, $attachment_id );

		// Snapshot the new photo for the log.
		$new_snapshot = self::snapshot_for_log( $attachment_id );
		self::log_change( $user_id, 'upload', $old_id, $attachment_id, 'self', $old_snapshot, $new_snapshot );

		$url = wp_get_attachment_image_src( $attachment_id, 'medium' );
		wp_send_json_success( array(
			'message'   => __( 'Photo saved successfully.', 'cp-ld' ),
			'photo_url' => $url ? $url[0] : '',
			'source'    => 'custom',
		) );
	}

	/**
	 * Remove custom upload and revert to Gravatar.
	 * Only allowed if the user has a Gravatar to fall back to.
	 */
	public function ajax_delete() {
		check_ajax_referer( 'cp_ld_photo_nonce', 'nonce' );
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error();
		}
		$user = get_userdata( $user_id );
		if ( ! $user || ! self::has_gravatar( $user->user_email ) ) {
			wp_send_json_error( array( 'message' => __( 'You must have a Gravatar to use this option.', 'cp-ld' ) ) );
		}
		$old_id = self::get_photo_id( $user_id );
		if ( $old_id ) {
			$old_snapshot = self::snapshot_for_log( $old_id );
			wp_delete_attachment( $old_id, true );
			delete_user_meta( $user_id, self::META_KEY );
			self::log_change( $user_id, 'revert_gravatar', $old_id, 0, 'self', $old_snapshot );
		}
		$gravatar_url = self::get_gravatar_url( $user_id );
		wp_send_json_success( array(
			'message'   => __( 'Switched to Gravatar.', 'cp-ld' ),
			'photo_url' => $gravatar_url,
			'source'    => 'gravatar',
		) );
	}

	// -----------------------------------------------------------------------
	// Photo change log
	// -----------------------------------------------------------------------

	public static function snapshot_for_log( $attachment_id ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return '';
		}
		$upload_dir = wp_upload_dir();
		$dir        = $upload_dir['basedir'] . '/cp-ld-photo-log';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$ext      = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		$filename = $attachment_id . '_' . time() . '.' . $ext;
		if ( copy( $file, $dir . '/' . $filename ) ) {
			return $filename;
		}
		return '';
	}

	public static function get_log_snapshot_url( $filename ) {
		if ( ! $filename ) {
			return '';
		}
		$upload_dir = wp_upload_dir();
		return $upload_dir['baseurl'] . '/cp-ld-photo-log/' . $filename;
	}

	public static function log_change( $user_id, $action, $old_id = 0, $new_id = 0, $changed_by = 'self', $old_snapshot = '', $new_snapshot = '' ) {
		$log = get_user_meta( $user_id, self::LOG_META_KEY, true );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$log[] = array(
			'action'       => $action,
			'old_id'       => (int) $old_id,
			'new_id'       => (int) $new_id,
			'changed_by'   => $changed_by,
			'old_snapshot' => $old_snapshot,
			'new_snapshot' => $new_snapshot,
			'ip'           => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
			'time'         => time(),
		);
		update_user_meta( $user_id, self::LOG_META_KEY, $log );
	}

	public static function clear_cert_snapshots( $user_id ) {
		$upload_dir = wp_upload_dir();
		$dir        = $upload_dir['basedir'] . '/cp-ld-cert-photos';
		if ( ! is_dir( $dir ) ) { return; }
		$pattern = $dir . '/' . (int) $user_id . '_*';
		foreach ( glob( $pattern ) as $file ) {
			unlink( $file );
		}
	}

	public static function get_log( $user_id ) {
		$log = get_user_meta( $user_id, self::LOG_META_KEY, true );
		return is_array( $log ) ? $log : array();
	}

	public function enqueue_frontend() {
		$user_id = get_current_user_id();
		wp_enqueue_style( 'cp-ld-frontend', CP_LD_URL . 'assets/css/frontend.css', array(), CP_LD_VERSION );
		wp_enqueue_script( 'cp-ld-frontend', CP_LD_URL . 'assets/js/frontend.js', array(), CP_LD_VERSION, true );
		wp_localize_script( 'cp-ld-frontend', 'cpLd', array(
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( 'cp_ld_photo_nonce' ),
			'hasPhoto'     => is_user_logged_in() && self::has_photo( $user_id ),
			'hasGravatar'  => is_user_logged_in() && $user_id && self::get_gravatar_url( $user_id ) ? true : false,
			'hasUpload'    => is_user_logged_in() && $user_id && self::has_uploaded_photo( $user_id ),
			'gravatarUrl'  => is_user_logged_in() && $user_id ? ( self::get_gravatar_url( $user_id ) ?: '' ) : '',
			'i18n'         => array(
				'uploading' => __( 'Uploading…', 'cp-ld' ),
				'switching' => __( 'Switching to Gravatar…', 'cp-ld' ),
			),
		) );
	}
}
