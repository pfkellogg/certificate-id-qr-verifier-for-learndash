<?php
/**
 * Frontend photo upload form.
 * Rendered by the cp-ld/photo-upload block.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$user_id      = get_current_user_id();
$photo_url    = CP_LD_Photo_Manager::get_photo_url( $user_id, 'medium' );
$has_upload   = CP_LD_Photo_Manager::has_uploaded_photo( $user_id );
$gravatar_url = CP_LD_Photo_Manager::get_gravatar_url( $user_id );
$has_gravatar = (bool) $gravatar_url;
$source       = $has_upload ? 'custom' : ( $has_gravatar ? 'gravatar' : 'none' );
?>
<div class="cp-ld-upload-wrap" id="cp-ld-upload-wrap">

	<?php if ( isset( $_GET['cp_ld_notice'] ) && $_GET['cp_ld_notice'] === 'photo_required' ) : ?>
		<div class="cp-ld-notice cp-ld-notice--warn">
			<?php esc_html_e( 'A certificate photo is required before you can download your certificate. Please upload one below.', 'certificate-id-qr-verifier-for-learndash' ); ?>
		</div>
	<?php endif; ?>

	<div class="cp-ld-card">
		<h2 class="cp-ld-card__title">
			<?php esc_html_e( 'Your Certificate Photo', 'certificate-id-qr-verifier-for-learndash' ); ?>
		</h2>
		<p class="cp-ld-card__desc">
			<?php esc_html_e( 'This photo will appear on your certificate and verification page when someone scans the QR code. Use a clear, recent headshot.', 'certificate-id-qr-verifier-for-learndash' ); ?>
		</p>

		<div class="cp-ld-photo-preview-wrap">
			<?php if ( $photo_url ) : ?>
				<img id="cp-ld-preview-img"
					src="<?php echo esc_url( $photo_url ); ?>"
					alt="<?php esc_attr_e( 'Your certificate photo', 'certificate-id-qr-verifier-for-learndash' ); ?>"
					class="cp-ld-photo-preview" />
			<?php else : ?>
				<div id="cp-ld-preview-placeholder" class="cp-ld-photo-placeholder">
					<span aria-hidden="true">👤</span>
					<span><?php esc_html_e( 'No photo yet', 'certificate-id-qr-verifier-for-learndash' ); ?></span>
				</div>
				<img id="cp-ld-preview-img"
					src=""
					alt=""
					class="cp-ld-photo-preview"
					style="display:none;" />
			<?php endif; ?>
		</div>

		<?php if ( $has_gravatar ) : ?>
			<div class="cp-ld-upload-controls" style="margin-bottom:12px;">
				<label for="cp-ld-source" style="font-weight:600;font-size:14px;margin-right:8px;">
					<?php esc_html_e( 'Photo source:', 'certificate-id-qr-verifier-for-learndash' ); ?>
				</label>
				<select id="cp-ld-source" style="min-width:180px;">
					<option value="gravatar" <?php selected( $source, 'gravatar' ); ?>>
						<?php esc_html_e( 'Gravatar', 'certificate-id-qr-verifier-for-learndash' ); ?>
					</option>
					<option value="custom" <?php selected( $source, 'custom' ); ?>>
						<?php esc_html_e( 'Custom Photo', 'certificate-id-qr-verifier-for-learndash' ); ?>
					</option>
				</select>
			</div>
		<?php endif; ?>

		<div class="cp-ld-upload-controls" id="cp-ld-upload-row" <?php if ( $has_gravatar && $source === 'gravatar' ) echo 'style="display:none;"'; ?>>
			<label class="cp-ld-btn cp-ld-btn--primary" for="cp-ld-file-input">
				<?php esc_html_e( 'Change Photo', 'certificate-id-qr-verifier-for-learndash' ); ?>
			</label>
			<input type="file"
				id="cp-ld-file-input"
				name="cp_ld_photo"
				accept="image/jpeg,image/png,image/webp"
				class="cp-ld-file-input" />
		</div>

		<p class="cp-ld-hint" id="cp-ld-hint" <?php if ( $has_gravatar && $source === 'gravatar' ) echo 'style="display:none;"'; ?>>
			<?php esc_html_e( 'Accepted: JPEG, PNG, WebP. Maximum size: 5 MB.', 'certificate-id-qr-verifier-for-learndash' ); ?>
		</p>

		<div id="cp-ld-upload-status" class="cp-ld-status" aria-live="polite"></div>

		<div id="cp-ld-continue-wrap" class="cp-ld-continue-wrap" style="display:none;">
			<a id="cp-ld-continue-btn" class="cp-ld-btn cp-ld-btn--continue" href="#">
				<?php esc_html_e( 'Continue to Certificate', 'certificate-id-qr-verifier-for-learndash' ); ?> &rarr;
			</a>
		</div>
	</div>
</div>
