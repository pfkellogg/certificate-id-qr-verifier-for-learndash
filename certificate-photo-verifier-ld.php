<?php
/**
 * Plugin Name: Certificate Photo Verifier For LearnDash
 * Description: Requires a photo to view LearnDash certificates and adds a scannable QR code for verification. Photo management is provided by a Gutenberg block. Supports Gravatar fallback, photo change logging, and snapshot-based verification pages.
 * Version:     1.1.0
 * Author:      Patrick Kellogg
 * License:     GPL-2.0-or-later
 * Text Domain: cp-ld
 *
 * @package CertificatesPlusLD
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CP_LD_VERSION', '1.1.0' );
define( 'CP_LD_DIR',     plugin_dir_path( __FILE__ ) );
define( 'CP_LD_URL',     plugin_dir_url( __FILE__ ) );

require_once CP_LD_DIR . 'includes/class-qrcode.php';
require_once CP_LD_DIR . 'includes/class-photo-manager.php';
require_once CP_LD_DIR . 'includes/class-certificate-gate.php';
require_once CP_LD_DIR . 'includes/class-verification.php';
require_once CP_LD_DIR . 'includes/class-admin.php';
require_once CP_LD_DIR . 'includes/class-photo-block.php';
require_once CP_LD_DIR . 'includes/class-settings.php';

new CP_LD_Photo_Manager();
new CP_LD_Certificate_Gate();
new CP_LD_Verification();
new CP_LD_Admin();
new CP_LD_Photo_Block();
new CP_LD_Settings();

register_activation_hook( __FILE__, 'cp_ld_activate' );
function cp_ld_activate() {
	flush_rewrite_rules();
}

register_deactivation_hook( __FILE__, 'cp_ld_deactivate' );
function cp_ld_deactivate() {
	flush_rewrite_rules();
}
