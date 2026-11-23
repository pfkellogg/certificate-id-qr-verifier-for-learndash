<?php
/**
 * Photo Upload Gutenberg Block.
 *
 * Registers the cp-ld/photo-upload block which replaces the old
 * [cp_ld_photo_upload] shortcode.
 *
 * @package CertificatesPlusLD
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CP_LD_Photo_Block {

	public function __construct() {
		add_action( 'init', array( $this, 'register_block' ) );
	}

	public function register_block() {
		wp_register_script(
			'cp-ld-photo-upload-editor',
			CP_LD_URL . 'assets/js/photo-upload-editor.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor' ),
			CP_LD_VERSION,
			true
		);

		register_block_type( 'cp-ld/photo-upload', array(
			'editor_script'   => 'cp-ld-photo-upload-editor',
			'render_callback' => array( $this, 'render_block' ),
		) );
	}

	public function render_block( $attributes, $content ) {
		if ( ! is_user_logged_in() ) {
			return '<p class="cp-ld-notice">' . esc_html__( 'Please log in to manage your certificate photo.', 'certificate-id-qr-verifier-for-learndash' ) . '</p>';
		}
		ob_start();
		include CP_LD_DIR . 'templates/upload-form.php';
		return ob_get_clean();
	}
}
