<?php
/**
 * Plugin Name:       Certificate ID & QR Verifier for LearnDash
 * Plugin URI:        https://aikellogg.com/#/certificate-verifier
 * Description:       Puts a unique certificate ID and a scannable QR code on LearnDash certificates. Anyone can scan the code to open a public page that confirms the certificate is genuine. Optional: require a learner photo on certificates.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Patrick Kellogg
 * Author URI:        https://aikellogg.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       certificate-id-qr-verifier-for-learndash
 *
 * LearnDash is a trademark of its owner. This plugin is independent: it is not made or endorsed by LearnDash.
 *
 * @package CertificatesPlusLD
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CP_LD_VERSION', '1.2.0' );
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
