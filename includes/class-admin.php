<?php
/**
 * Admin — user-profile photo field and frontend notice.
 *
 * @package CertificatesPlusLD
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CP_LD_Admin {

	public function __construct() {
		// Photo field on user profile (admin view).
		add_action( 'show_user_profile',        array( $this, 'render_photo_field' ) );
		add_action( 'edit_user_profile',        array( $this, 'render_photo_field' ) );
		add_action( 'personal_options_update',  array( $this, 'save_photo_field' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_photo_field' ) );

		// Frontend notice when photo is missing.
		add_action( 'wp_head', array( $this, 'maybe_notice' ) );
	}

	// -----------------------------------------------------------------------
	// User profile photo field
	// -----------------------------------------------------------------------

	public function render_photo_field( $user ) {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}
		$photo_url    = CP_LD_Photo_Manager::get_photo_url( $user->ID, 'medium' );
		$has_upload   = CP_LD_Photo_Manager::has_uploaded_photo( $user->ID );
		$gravatar_url = CP_LD_Photo_Manager::get_gravatar_url( $user->ID );
		$has_gravatar = (bool) $gravatar_url;
		$source       = $has_upload ? 'custom' : ( $has_gravatar ? 'gravatar' : 'none' );
		wp_nonce_field( 'cp_ld_profile_photo_' . $user->ID, 'cp_ld_profile_nonce' );
		?>
		<h3 id="cp-ld-photo"><?php esc_html_e( 'Certificate Photo', 'cp-ld' ); ?></h3>
		<table class="form-table">
			<tr>
				<th><?php esc_html_e( 'Photo', 'cp-ld' ); ?></th>
				<td>
					<?php if ( $photo_url ) : ?>
						<img id="cp-ld-profile-preview" src="<?php echo esc_url( $photo_url ); ?>"
							style="max-width:150px;display:block;margin-bottom:8px;" />
					<?php else : ?>
						<img id="cp-ld-profile-preview" src="" style="max-width:150px;display:none;margin-bottom:8px;" />
					<?php endif; ?>

					<?php if ( $has_gravatar ) : ?>
						<p style="margin-bottom:8px;">
							<label>
								<strong><?php esc_html_e( 'Photo source:', 'cp-ld' ); ?></strong>&nbsp;
								<select name="cp_ld_photo_source" style="min-width:180px;">
									<option value="gravatar" <?php selected( $source, 'gravatar' ); ?>><?php esc_html_e( 'Gravatar', 'cp-ld' ); ?></option>
									<option value="custom" <?php selected( $source, 'custom' ); ?>><?php esc_html_e( 'Custom Photo', 'cp-ld' ); ?></option>
								</select>
							</label>
						</p>
					<?php endif; ?>

					<input type="file" name="cp_ld_photo" id="cp-ld-profile-file"
						accept="image/jpeg,image/png,image/webp" />
					<p class="description"><?php esc_html_e( 'JPEG, PNG, or WebP. Max 5 MB. Upload a file to use a custom photo.', 'cp-ld' ); ?></p>
					<?php
					$log = CP_LD_Photo_Manager::get_log( $user->ID );
					if ( ! empty( $log ) ) :
						$log = array_reverse( $log );
					?>
						<div style="margin-top:16px;">
							<strong><?php esc_html_e( 'Photo Change Log', 'cp-ld' ); ?></strong>
							<span style="color:#64748b;font-size:12px;">(<?php echo esc_html( count( $log ) ); ?> <?php esc_html_e( 'total changes', 'cp-ld' ); ?>)</span>
							<table class="widefat striped" style="margin-top:8px;max-width:800px;">
								<thead>
									<tr>
										<th style="padding:10px 12px;"><?php esc_html_e( 'Date', 'cp-ld' ); ?></th>
										<th style="padding:10px 12px;"><?php esc_html_e( 'Action', 'cp-ld' ); ?></th>
										<th style="padding:10px 12px;"><?php esc_html_e( 'Previous', 'cp-ld' ); ?></th>
										<th style="padding:10px 12px;"><?php esc_html_e( 'New', 'cp-ld' ); ?></th>
										<th style="padding:10px 12px;"><?php esc_html_e( 'By', 'cp-ld' ); ?></th>
										<th style="padding:10px 12px;"><?php esc_html_e( 'IP', 'cp-ld' ); ?></th>
									</tr>
								</thead>
								<tbody>
								<?php foreach ( array_slice( $log, 0, 20 ) as $entry ) :
									$old_snap_url = ! empty( $entry['old_snapshot'] ) ? CP_LD_Photo_Manager::get_log_snapshot_url( $entry['old_snapshot'] ) : '';
									$new_snap_url = ! empty( $entry['new_snapshot'] ) ? CP_LD_Photo_Manager::get_log_snapshot_url( $entry['new_snapshot'] ) : '';
								?>
									<tr>
										<td style="padding:8px 12px;"><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $entry['time'] ) ); ?></td>
										<td style="padding:8px 12px;"><?php
										switch ( $entry['action'] ) {
											case 'upload':           esc_html_e( 'Uploaded', 'cp-ld' ); break;
											case 'revert_gravatar':  esc_html_e( 'Reverted to Gravatar', 'cp-ld' ); break;
											default:                 esc_html_e( 'Deleted', 'cp-ld' ); break;
										}
									?></td>
										<td style="padding:8px 12px;">
											<?php if ( $old_snap_url ) : ?>
												<img src="<?php echo esc_url( $old_snap_url ); ?>" style="width:40px;height:40px;border-radius:50%;object-fit:cover;border:2px solid #e2e8f0;" />
											<?php else : ?>
												<span style="color:#94a3b8;font-size:12px;">&mdash;</span>
											<?php endif; ?>
										</td>
										<td style="padding:8px 12px;">
											<?php if ( $new_snap_url ) : ?>
												<img src="<?php echo esc_url( $new_snap_url ); ?>" style="width:40px;height:40px;border-radius:50%;object-fit:cover;border:2px solid #e2e8f0;" />
											<?php else : ?>
												<span style="color:#94a3b8;font-size:12px;">&mdash;</span>
											<?php endif; ?>
										</td>
										<td style="padding:8px 12px;"><?php echo esc_html( $entry['changed_by'] === 'admin' ? __( 'Admin', 'cp-ld' ) : __( 'Self', 'cp-ld' ) ); ?></td>
										<td style="padding:8px 12px;"><code style="font-size:12px;"><?php echo esc_html( $entry['ip'] ); ?></code></td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
							<?php if ( count( $log ) > 20 ) : ?>
								<p style="color:#64748b;font-size:12px;margin-top:4px;">
									<?php printf( esc_html__( 'Showing 20 of %d entries.', 'cp-ld' ), count( $log ) ); ?>
								</p>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	public function save_photo_field( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		if ( ! isset( $_POST['cp_ld_profile_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cp_ld_profile_nonce'] ) ), 'cp_ld_profile_photo_' . $user_id ) ) {
			return;
		}

		// Revert to Gravatar if selected and user has one.
		if ( isset( $_POST['cp_ld_photo_source'] ) && $_POST['cp_ld_photo_source'] === 'gravatar' ) {
			$old = CP_LD_Photo_Manager::get_photo_id( $user_id );
			if ( $old ) {
				$user = get_userdata( $user_id );
				if ( $user && CP_LD_Photo_Manager::has_gravatar( $user->user_email ) ) {
					$old_snapshot = CP_LD_Photo_Manager::snapshot_for_log( $old );
					wp_delete_attachment( $old, true );
					delete_user_meta( $user_id, CP_LD_Photo_Manager::META_KEY );
					CP_LD_Photo_Manager::log_change( $user_id, 'revert_gravatar', $old, 0, 'admin', $old_snapshot );
				}
			}
		}

		// Upload new photo (replaces existing).
		if ( ! empty( $_FILES['cp_ld_photo']['size'] ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';

			$old = CP_LD_Photo_Manager::get_photo_id( $user_id );
			$old_snapshot = $old ? CP_LD_Photo_Manager::snapshot_for_log( $old ) : '';
			if ( $old ) {
				wp_delete_attachment( $old, true );
			}

			$id = media_handle_upload( 'cp_ld_photo', 0 );
			if ( ! is_wp_error( $id ) ) {
				update_user_meta( $user_id, CP_LD_Photo_Manager::META_KEY, $id );
				$new_snapshot = CP_LD_Photo_Manager::snapshot_for_log( $id );
				CP_LD_Photo_Manager::log_change( $user_id, 'upload', $old, $id, 'admin', $old_snapshot, $new_snapshot );
			}
		}
	}

	// -----------------------------------------------------------------------
	// Frontend notice
	// -----------------------------------------------------------------------

	public function maybe_notice() {
		if ( isset( $_GET['cp_ld_notice'] ) && $_GET['cp_ld_notice'] === 'photo_required' && is_user_logged_in() ) {
			echo '<script>document.addEventListener("DOMContentLoaded",function(){'
				. 'var n=document.createElement("div");'
				. 'n.className="cp-ld-notice-bar";'
				. 'n.innerHTML=' . wp_json_encode( '<strong>' . esc_html__( 'A certificate photo is required before you can download your certificate.', 'cp-ld' ) . '</strong>' ) . ';'
				. 'document.body.prepend(n);'
				. '});</script>';
		}
	}
}
