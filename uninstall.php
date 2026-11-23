<?php
/**
 * Certificate ID & QR Verifier for LearnDash: removes its setting and the user data it keeps when it is deleted.
 * Photos learners uploaded stay in the Media Library (they're the site's files).
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'cp_ld_bypass_photo' );
delete_metadata( 'user', 0, '_cp_ld_photo_id', '', true );
delete_metadata( 'user', 0, '_cp_ld_photo_log', '', true );
