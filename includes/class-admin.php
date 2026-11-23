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

		// Delete own log entries (admins only).
		add_action( 'wp_ajax_cp_ld_delete_log_entry',       array( $this, 'ajax_delete_log_entry' ) );
		add_action( 'wp_ajax_cp_ld_delete_all_log_entries',  array( $this, 'ajax_delete_all_log_entries' ) );

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
		<script>
		(function(){
			var f = document.getElementById('your-profile');
			if (f) f.enctype = 'multipart/form-data';
		})();
		</script>
		<h3 id="cp-ld-photo"><?php esc_html_e( 'Certificate Photo', 'certificate-id-qr-verifier-for-learndash' ); ?></h3>
		<table class="form-table">
			<tr>
				<th><?php esc_html_e( 'Photo', 'certificate-id-qr-verifier-for-learndash' ); ?></th>
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
								<strong><?php esc_html_e( 'Photo source:', 'certificate-id-qr-verifier-for-learndash' ); ?></strong>&nbsp;
								<select name="cp_ld_photo_source" style="min-width:180px;">
									<option value="gravatar" <?php selected( $source, 'gravatar' ); ?>><?php esc_html_e( 'Gravatar', 'certificate-id-qr-verifier-for-learndash' ); ?></option>
									<option value="custom" <?php selected( $source, 'custom' ); ?>><?php esc_html_e( 'Custom Photo', 'certificate-id-qr-verifier-for-learndash' ); ?></option>
								</select>
							</label>
						</p>
					<?php endif; ?>

					<div id="cp-ld-profile-upload-row">
						<input type="file" name="cp_ld_photo" id="cp-ld-profile-file"
							accept="image/jpeg,image/png,image/webp" />
						<p class="description"><?php esc_html_e( 'JPEG, PNG, or WebP. Max 5 MB. Upload a file to use a custom photo.', 'certificate-id-qr-verifier-for-learndash' ); ?></p>
					</div>
					<?php
					$log = CP_LD_Photo_Manager::get_log( $user->ID );
					$can_delete_log = current_user_can( 'manage_options' ) && $user->ID === get_current_user_id();
					if ( ! empty( $log ) ) :
						$log = array_reverse( $log );
					?>
						<div style="margin-top:16px;">
							<strong><?php esc_html_e( 'Photo Change Log', 'certificate-id-qr-verifier-for-learndash' ); ?></strong>
							<span style="color:#64748b;font-size:12px;">(<?php echo esc_html( count( $log ) ); ?> <?php esc_html_e( 'total changes', 'certificate-id-qr-verifier-for-learndash' ); ?>)</span>
							<table class="widefat striped" style="margin-top:8px;max-width:800px;">
								<thead>
									<tr>
										<th style="padding:10px 12px;"><?php esc_html_e( 'Date', 'certificate-id-qr-verifier-for-learndash' ); ?></th>
										<th style="padding:10px 12px;"><?php esc_html_e( 'Action', 'certificate-id-qr-verifier-for-learndash' ); ?></th>
										<th style="padding:10px 12px;"><?php esc_html_e( 'Previous', 'certificate-id-qr-verifier-for-learndash' ); ?></th>
										<th style="padding:10px 12px;"><?php esc_html_e( 'New', 'certificate-id-qr-verifier-for-learndash' ); ?></th>
										<th style="padding:10px 12px;"><?php esc_html_e( 'By', 'certificate-id-qr-verifier-for-learndash' ); ?></th>
										<th style="padding:10px 12px;"><?php esc_html_e( 'IP', 'certificate-id-qr-verifier-for-learndash' ); ?></th>
										<?php if ( $can_delete_log ) : ?>
											<th style="padding:10px 12px;"></th>
										<?php endif; ?>
									</tr>
								</thead>
								<tbody>
								<?php $gravatar_thumb = CP_LD_Photo_Manager::get_gravatar_url( $user->ID, 'thumbnail' ); ?>
								<?php foreach ( array_slice( $log, 0, 20 ) as $display_idx => $entry ) :
									$original_idx = count( $log ) - 1 - $display_idx;
									$old_snap_url = ! empty( $entry['old_snapshot'] ) ? CP_LD_Photo_Manager::get_log_snapshot_url( $entry['old_snapshot'] ) : '';
									$new_snap_url = ! empty( $entry['new_snapshot'] ) ? CP_LD_Photo_Manager::get_log_snapshot_url( $entry['new_snapshot'] ) : '';

									// Fall back to gravatar when no snapshot exists.
									if ( ! $old_snap_url && $gravatar_thumb && $entry['action'] === 'upload' && empty( $entry['old_id'] ) ) {
										$old_snap_url = $gravatar_thumb;
									}
									if ( ! $new_snap_url && $gravatar_thumb && $entry['action'] === 'revert_gravatar' ) {
										$new_snap_url = $gravatar_thumb;
									}
								?>
									<tr>
										<td style="padding:8px 12px;"><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $entry['time'] ) ); ?></td>
										<td style="padding:8px 12px;"><?php
										switch ( $entry['action'] ) {
											case 'upload':           esc_html_e( 'Uploaded', 'certificate-id-qr-verifier-for-learndash' ); break;
											case 'revert_gravatar':  esc_html_e( 'Reverted to Gravatar', 'certificate-id-qr-verifier-for-learndash' ); break;
											default:                 esc_html_e( 'Deleted', 'certificate-id-qr-verifier-for-learndash' ); break;
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
										<td style="padding:8px 12px;"><?php echo esc_html( $entry['changed_by'] === 'admin' ? __( 'Admin', 'certificate-id-qr-verifier-for-learndash' ) : __( 'Self', 'certificate-id-qr-verifier-for-learndash' ) ); ?></td>
										<td style="padding:8px 12px;"><code style="font-size:12px;"><?php echo esc_html( $entry['ip'] ); ?></code></td>
										<?php if ( $can_delete_log ) : ?>
											<td style="padding:8px 12px;">
												<button type="button" class="button-link cp-ld-delete-log-entry" data-index="<?php echo esc_attr( $original_idx ); ?>" style="color:#b91c1c;font-size:12px;"><?php esc_html_e( 'Delete', 'certificate-id-qr-verifier-for-learndash' ); ?></button>
											</td>
										<?php endif; ?>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
							<?php if ( count( $log ) > 20 ) : ?>
								<p style="color:#64748b;font-size:12px;margin-top:4px;">
									<?php printf( esc_html__( 'Showing 20 of %d entries.', 'certificate-id-qr-verifier-for-learndash' ), count( $log ) ); ?>
								</p>
							<?php endif; ?>
							<?php if ( $can_delete_log ) : ?>
								<p style="margin-top:8px;">
									<button type="button" id="cp-ld-delete-all-log" class="button" style="color:#b91c1c;border-color:#b91c1c;"><?php esc_html_e( 'Delete All Log Entries', 'certificate-id-qr-verifier-for-learndash' ); ?></button>
								</p>
							<?php endif; ?>
						</div>
					<?php endif; ?>

					<?php if ( $can_delete_log && ! empty( $log ) ) : ?>
					<script>
					(function(){
						var nonce = <?php echo wp_json_encode( wp_create_nonce( 'cp_ld_delete_log' ) ); ?>;
						var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
						document.querySelectorAll('.cp-ld-delete-log-entry').forEach(function(btn){
							btn.addEventListener('click', function(){
								if (!confirm(<?php echo wp_json_encode( __( 'Delete this log entry?', 'certificate-id-qr-verifier-for-learndash' ) ); ?>)) return;
								var row = btn.closest('tr');
								btn.disabled = true;
								btn.textContent = '…';
								var xhr = new XMLHttpRequest();
								xhr.open('POST', ajaxUrl);
								xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
								xhr.onload = function(){
									if (xhr.status === 200) { row.remove(); } else { btn.disabled = false; btn.textContent = <?php echo wp_json_encode( __( 'Delete', 'certificate-id-qr-verifier-for-learndash' ) ); ?>; }
								};
								xhr.send('action=cp_ld_delete_log_entry&nonce='+encodeURIComponent(nonce)+'&index='+encodeURIComponent(btn.dataset.index));
							});
						});
						var delAll = document.getElementById('cp-ld-delete-all-log');
						if (delAll) {
							delAll.addEventListener('click', function(){
								if (!confirm(<?php echo wp_json_encode( __( 'Delete ALL log entries? This cannot be undone.', 'certificate-id-qr-verifier-for-learndash' ) ); ?>)) return;
								delAll.disabled = true;
								delAll.textContent = '…';
								var xhr = new XMLHttpRequest();
								xhr.open('POST', ajaxUrl);
								xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
								xhr.onload = function(){
									if (xhr.status === 200) { delAll.closest('div').remove(); } else { delAll.disabled = false; delAll.textContent = <?php echo wp_json_encode( __( 'Delete All Log Entries', 'certificate-id-qr-verifier-for-learndash' ) ); ?>; }
								};
								xhr.send('action=cp_ld_delete_all_log_entries&nonce='+encodeURIComponent(nonce));
							});
						}
					})();
					</script>
					<?php endif; ?>

					<?php if ( $has_gravatar ) : ?>
					<script>
					(function(){
						var sel = document.querySelector('select[name="cp_ld_photo_source"]');
						if (!sel) return;
						var uploadRow = document.getElementById('cp-ld-profile-upload-row');
						var preview   = document.getElementById('cp-ld-profile-preview');
						var gravUrl   = <?php echo wp_json_encode( $gravatar_url ); ?>;
						var customUrl = <?php echo wp_json_encode( $photo_url ); ?>;

						function toggle() {
							if (sel.value === 'gravatar') {
								if (uploadRow) uploadRow.style.display = 'none';
								if (preview && gravUrl) { preview.src = gravUrl; preview.style.display = ''; }
							} else {
								if (uploadRow) uploadRow.style.display = '';
								if (preview && customUrl) { preview.src = customUrl; preview.style.display = ''; }
							}
						}
						toggle();
						sel.addEventListener('change', toggle);
					})();
					</script>
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

		// Upload new photo (replaces existing) — skip if Gravatar was chosen.
		$chose_gravatar = isset( $_POST['cp_ld_photo_source'] ) && $_POST['cp_ld_photo_source'] === 'gravatar';
		if ( ! $chose_gravatar && ! empty( $_FILES['cp_ld_photo']['size'] ) ) {
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
	// Delete own log entry (admins only)
	// -----------------------------------------------------------------------

	public function ajax_delete_log_entry() {
		check_ajax_referer( 'cp_ld_delete_log', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'certificate-id-qr-verifier-for-learndash' ) ) );
		}

		$index = isset( $_POST['index'] ) ? (int) $_POST['index'] : -1;
		$log   = CP_LD_Photo_Manager::get_log( $user_id );

		if ( $index < 0 || $index >= count( $log ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid entry.', 'certificate-id-qr-verifier-for-learndash' ) ) );
		}

		// Clean up snapshot files for the deleted entry.
		$entry = $log[ $index ];
		if ( ! empty( $entry['old_snapshot'] ) ) {
			$upload_dir = wp_upload_dir();
			$path = $upload_dir['basedir'] . '/cp-ld-photo-log/' . $entry['old_snapshot'];
			if ( file_exists( $path ) ) { unlink( $path ); }
		}
		if ( ! empty( $entry['new_snapshot'] ) ) {
			$upload_dir = isset( $upload_dir ) ? $upload_dir : wp_upload_dir();
			$path = $upload_dir['basedir'] . '/cp-ld-photo-log/' . $entry['new_snapshot'];
			if ( file_exists( $path ) ) { unlink( $path ); }
		}

		array_splice( $log, $index, 1 );
		update_user_meta( $user_id, CP_LD_Photo_Manager::LOG_META_KEY, $log );

		wp_send_json_success();
	}

	public function ajax_delete_all_log_entries() {
		check_ajax_referer( 'cp_ld_delete_log', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'certificate-id-qr-verifier-for-learndash' ) ) );
		}

		$log = CP_LD_Photo_Manager::get_log( $user_id );

		// Clean up all snapshot files.
		if ( ! empty( $log ) ) {
			$upload_dir = wp_upload_dir();
			$dir        = $upload_dir['basedir'] . '/cp-ld-photo-log';
			foreach ( $log as $entry ) {
				if ( ! empty( $entry['old_snapshot'] ) ) {
					$path = $dir . '/' . $entry['old_snapshot'];
					if ( file_exists( $path ) ) { unlink( $path ); }
				}
				if ( ! empty( $entry['new_snapshot'] ) ) {
					$path = $dir . '/' . $entry['new_snapshot'];
					if ( file_exists( $path ) ) { unlink( $path ); }
				}
			}
		}

		delete_user_meta( $user_id, CP_LD_Photo_Manager::LOG_META_KEY );
		wp_send_json_success();
	}

	// -----------------------------------------------------------------------
	// Frontend notice
	// -----------------------------------------------------------------------

	public function maybe_notice() {
		if ( isset( $_GET['cp_ld_notice'] ) && $_GET['cp_ld_notice'] === 'photo_required' && is_user_logged_in() ) {
			echo '<script>document.addEventListener("DOMContentLoaded",function(){'
				. 'var n=document.createElement("div");'
				. 'n.className="cp-ld-notice-bar";'
				. 'n.innerHTML=' . wp_json_encode( '<strong>' . esc_html__( 'A certificate photo is required before you can download your certificate.', 'certificate-id-qr-verifier-for-learndash' ) . '</strong>' ) . ';'
				. 'document.body.prepend(n);'
				. '});</script>';
		}
	}
}
