<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>
		<?php
		if ( $valid && $user ) {
			printf(
				/* translators: %s: the site's name */
				esc_html__( 'Certificate Verification — %s', 'certificate-id-qr-verifier-for-learndash' ),
				esc_html( $user->display_name )
			);
		} else {
			esc_html_e( 'Certificate Verification', 'certificate-id-qr-verifier-for-learndash' );
		}
		?>
	</title>
	<style>
		*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
		body {
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
			background: #f0f4f8;
			min-height: 100vh;
			display: flex;
			align-items: flex-start;
			justify-content: center;
			padding: 24px 16px 48px;
		}
		.vc { max-width: 540px; width: 100%; }

		/* ---- Header ---- */
		.vc-header {
			background: linear-gradient(135deg, #1a6da0 0%, #0d4d76 100%);
			border-radius: 12px 12px 0 0;
			padding: 28px 28px 20px;
			display: flex;
			align-items: center;
			gap: 16px;
			color: #fff;
		}
		.vc-header__logo { font-size: 28px; flex-shrink: 0; }
		.vc-header__site { font-size: 11px; opacity: .7; text-transform: uppercase; letter-spacing: .08em; }
		.vc-header__title { font-size: 18px; font-weight: 700; margin-top: 2px; }

		/* ---- Body ---- */
		.vc-body {
			background: #fff;
			border-radius: 0 0 12px 12px;
			box-shadow: 0 4px 24px rgba(0,0,0,.10);
			overflow: hidden;
		}

		/* ---- Identity band ---- */
		.vc-identity {
			display: flex;
			align-items: center;
			gap: 20px;
			padding: 24px 28px;
			border-bottom: 1px solid #e8edf2;
		}
		.vc-photo {
			width: 90px; height: 90px;
			border-radius: 50%;
			object-fit: cover;
			border: 3px solid #e2e8f0;
			box-shadow: 0 2px 10px rgba(0,0,0,.12);
			flex-shrink: 0;
		}
		.vc-no-photo {
			width: 90px; height: 90px;
			border-radius: 50%;
			background: #e8edf2;
			display: flex; align-items: center; justify-content: center;
			font-size: 36px; flex-shrink: 0;
		}
		.vc-identity__name  { font-size: 20px; font-weight: 700; color: #1a202c; }
		.vc-identity__email { font-size: 13px; color: #64748b; margin-top: 3px; }
		.vc-identity__since { font-size: 12px; color: #94a3b8; margin-top: 2px; }

		/* ---- Status badge ---- */
		.vc-status {
			display: flex; align-items: center; justify-content: center;
			gap: 10px; padding: 14px 28px;
			font-size: 15px; font-weight: 700;
			border-bottom: 1px solid #e8edf2;
		}
		.vc-status--valid   { background: #ecfdf5; color: #065f46; flex-wrap: wrap; }
		.vc-status--invalid { background: #fef2f2; color: #7f1d1d; }
		.vc-status--expired { background: #fffbeb; color: #78350f; flex-wrap: wrap; }
		.vc-status__note    { flex-basis: 100%; font-size: 13px; font-weight: 400; text-align: center; }
		.vc-status__icon    { font-size: 20px; }

		/* ---- Sections ---- */
		.vc-section { padding: 20px 28px; border-bottom: 1px solid #e8edf2; }
		.vc-section:last-child { border-bottom: none; }
		.vc-section__heading {
			font-size: 10px; font-weight: 700; text-transform: uppercase;
			letter-spacing: .10em; color: #94a3b8; margin-bottom: 12px;
		}

		/* ---- Row grid ---- */
		.vc-rows {}
		.vc-row {
			display: flex; justify-content: space-between; align-items: baseline;
			gap: 12px; padding: 7px 0;
			border-bottom: 1px solid #f1f5f9; font-size: 14px;
		}
		.vc-row:last-child { border-bottom: none; }
		.vc-row__label { color: #64748b; font-weight: 500; flex-shrink: 0; }
		.vc-row__value { color: #1a202c; font-weight: 600; text-align: right; }
		.vc-row__value--mono {
			font-family: "Courier New", Courier, monospace;
			font-size: 13px; letter-spacing: .03em;
		}

		/* ---- Score bar ---- */
		.vc-score-bar {
			height: 8px; border-radius: 4px;
			background: #e2e8f0; margin-top: 8px; overflow: hidden;
		}
		.vc-score-bar__fill {
			height: 100%; border-radius: 4px;
			background: linear-gradient(90deg, #22c55e, #16a34a);
			transition: width .4s ease;
		}
		.vc-score-bar__fill--fail { background: linear-gradient(90deg, #f87171, #dc2626); }

		/* ---- Pass / fail pill ---- */
		.vc-pill {
			display: inline-block; padding: 2px 10px; border-radius: 20px;
			font-size: 12px; font-weight: 700;
		}
		.vc-pill--pass { background: #d1fae5; color: #065f46; }
		.vc-pill--fail { background: #fee2e2; color: #7f1d1d; }

		/* ---- Course list ---- */
		.vc-course-list { list-style: none; margin-top: 8px; }
		.vc-course-list li {
			padding: 5px 0; font-size: 13px; color: #374151;
			border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; gap: 8px;
		}
		.vc-course-list li:last-child { border-bottom: none; }

		/* ---- Footer ---- */
		.vc-footer {
			text-align: center; font-size: 11px; color: #94a3b8;
			padding: 16px 28px 20px; background: #f8fafc;
			border-top: 1px solid #e8edf2; border-radius: 0 0 12px 12px;
		}
		.vc-footer a { color: #1a6da0; text-decoration: none; }
	</style>
</head>
<body>
<div class="vc">

	<!-- Header -->
	<div class="vc-header">
		<div class="vc-header__logo">🎓</div>
		<div>
			<div class="vc-header__site"><?php bloginfo( 'name' ); ?></div>
			<div class="vc-header__title"><?php esc_html_e( 'Certificate Verification', 'certificate-id-qr-verifier-for-learndash' ); ?></div>
		</div>
	</div>

	<div class="vc-body">

	<?php if ( $valid && $user ) :
		$bypass_photo = CP_LD_Settings::is_photo_bypassed();
		$photo_url    = '';
		if ( ! $bypass_photo ) {
			$photo_url = CP_LD_Certificate_Gate::get_snapshot_photo_url( $user->ID, $course_id );
			if ( ! $photo_url ) {
				$photo_url = CP_LD_Photo_Manager::get_photo_url( $user->ID, 'medium' );
			}
		}
	?>

		<!-- Identity -->
		<div class="vc-identity">
			<?php if ( $photo_url ) : ?>
				<img class="vc-photo"
					src="<?php echo esc_url( $photo_url ); ?>"
					alt="<?php echo esc_attr( $user->display_name ); ?>" />
			<?php elseif ( ! $bypass_photo ) : ?>
				<div class="vc-no-photo" aria-hidden="true">👤</div>
			<?php endif; ?>
			<div>
				<?php
				$full_name = trim( $user->first_name . ' ' . $user->last_name );
				?>
				<div class="vc-identity__name"><?php echo esc_html( $full_name ?: $user->display_name ); ?></div>
				<?php /* (no email: this page is public, anyone who sees the certificate can open it) */ ?>
				<div class="vc-identity__since">
					<?php
					printf(
						/* translators: %s: the date the learner joined */
						esc_html__( 'Member since %s', 'certificate-id-qr-verifier-for-learndash' ),
						esc_html( date_i18n( get_option( 'date_format' ), strtotime( $user->user_registered ) ) )
					);
					?>
				</div>
			</div>
		</div>

		<!-- Certificate ID -->
		<?php
		$cert_code = ! empty( $cert_code_from_id ) ? $cert_code_from_id : CP_LD_Certificate_Gate::certificate_id( $user_id, $course_id ); // (the same ID as on the certificate)
		if ( $cert_code ) : ?>
		<div style="text-align:center;padding:14px 28px;border-bottom:1px solid #e8edf2;font-family:'Courier New',Courier,monospace;font-size:15px;font-weight:700;color:#1a202c;letter-spacing:.03em;">
			<?php echo esc_html( $cert_code ); ?>
		</div>
		<?php endif; ?>

		<!-- Status -->
		<?php
		$vc_state = $cert_status ? $cert_status['status'] : 'valid';
		$vc_df    = get_option( 'date_format' );
		if ( 'valid' === $vc_state ) : ?>
		<div class="vc-status vc-status--valid">
			<span class="vc-status__icon">✅</span>
			<?php esc_html_e( 'Certificate Verified', 'certificate-id-qr-verifier-for-learndash' ); ?>
			<?php if ( $cert_status && null !== $cert_status['end'] ) : ?>
			<span class="vc-status__note"><?php
				/* translators: %s: the last day the certificate is valid */
				echo esc_html( sprintf( __( 'Valid until %s', 'certificate-id-qr-verifier-for-learndash' ), wp_date( $vc_df, $cert_status['end'] - 1 ) ) ); ?></span>
			<?php endif; ?>
		</div>
		<?php else : ?>
		<div class="vc-status vc-status--expired">
			<span class="vc-status__icon">⏰</span>
			<?php 'expired' === $vc_state ? esc_html_e( 'Certificate Expired', 'certificate-id-qr-verifier-for-learndash' ) : esc_html_e( 'Certificate Not Active', 'certificate-id-qr-verifier-for-learndash' ); ?>
			<span class="vc-status__note"><?php
			if ( 'expired' === $vc_state && null !== $cert_status['end'] && time() >= $cert_status['end'] ) {
				/* translators: %s: the last day the certificate was valid */
				echo esc_html( sprintf( __( 'A genuine certificate, valid until %s.', 'certificate-id-qr-verifier-for-learndash' ), wp_date( $vc_df, $cert_status['end'] - 1 ) ) );
			} elseif ( 'expired' === $vc_state ) {
				/* translators: %s: the first day of the current period */
				echo esc_html( sprintf( __( 'A genuine certificate, completed before the current period began (%s).', 'certificate-id-qr-verifier-for-learndash' ), wp_date( $vc_df, $cert_status['start'] ) ) );
			} else {
				/* translators: %s: the last day of the period */
				echo esc_html( sprintf( __( 'A genuine certificate, completed after its period ended (%s).', 'certificate-id-qr-verifier-for-learndash' ), wp_date( $vc_df, $cert_status['end'] - 1 ) ) );
			}
			?></span>
		</div>
		<?php endif; ?>

		<?php if ( $post ) : ?>
		<!-- Content details -->
		<div class="vc-section">
			<div class="vc-section__heading">
				<?php
				switch ( $post_type ) {
					case 'quiz':  esc_html_e( 'Quiz Details', 'certificate-id-qr-verifier-for-learndash' );   break;
					case 'group': esc_html_e( 'Group Details', 'certificate-id-qr-verifier-for-learndash' );  break;
					default:      esc_html_e( 'Course Details', 'certificate-id-qr-verifier-for-learndash' ); break;
				}
				?>
			</div>
			<div class="vc-rows">
				<div class="vc-row">
					<span class="vc-row__label">
						<?php
						switch ( $post_type ) {
							case 'quiz':  esc_html_e( 'Quiz', 'certificate-id-qr-verifier-for-learndash' );   break;
							case 'group': esc_html_e( 'Group', 'certificate-id-qr-verifier-for-learndash' );  break;
							default:      esc_html_e( 'Course', 'certificate-id-qr-verifier-for-learndash' ); break;
						}
						?>
					</span>
					<span class="vc-row__value"><?php echo esc_html( get_the_title( $post ) ); ?></span>
				</div>

				<?php
				$excerpt = get_the_excerpt( $post );
				if ( $excerpt ) : ?>
				<div class="vc-row">
					<span class="vc-row__label"><?php esc_html_e( 'Description', 'certificate-id-qr-verifier-for-learndash' ); ?></span>
					<span class="vc-row__value" style="font-weight:400;color:#4a5568;"><?php echo esc_html( wp_trim_words( $excerpt, 20 ) ); ?></span>
				</div>
				<?php endif; ?>

				<div class="vc-row">
					<span class="vc-row__label"><?php esc_html_e( 'Issued by', 'certificate-id-qr-verifier-for-learndash' ); ?></span>
					<span class="vc-row__value"><?php bloginfo( 'name' ); ?></span>
				</div>
			</div>
		</div>
		<?php endif; ?>

		<!-- Completion details -->
		<?php if ( ! empty( $completion ) ) : ?>
		<div class="vc-section">
			<div class="vc-section__heading"><?php esc_html_e( 'Completion Details', 'certificate-id-qr-verifier-for-learndash' ); ?></div>
			<div class="vc-rows">

				<?php if ( ! empty( $completion['completed_date'] ) ) : ?>
				<div class="vc-row">
					<span class="vc-row__label"><?php esc_html_e( 'Completed', 'certificate-id-qr-verifier-for-learndash' ); ?></span>
					<span class="vc-row__value">
						<?php echo esc_html( $completion['completed_date'] ); ?>
						<?php if ( ! empty( $completion['completed_time'] ) ) : ?>
							<span style="font-weight:400;color:#64748b;">&nbsp;<?php echo esc_html( $completion['completed_time'] ); ?></span>
						<?php endif; ?>
					</span>
				</div>
				<?php endif; ?>

				<?php if ( ! empty( $completion['approval_number'] ) ) : ?>
				<div class="vc-row">
					<span class="vc-row__label"><?php esc_html_e( 'Approval number', 'certificate-id-qr-verifier-for-learndash' ); ?></span>
					<span class="vc-row__value"><?php echo esc_html( $completion['approval_number'] ); ?></span>
				</div>
				<?php endif; ?>

				<?php if ( $post_type === 'course' ) : ?>

					<?php if ( isset( $completion['steps_total'] ) && $completion['steps_total'] ) : ?>
					<div class="vc-row">
						<span class="vc-row__label"><?php esc_html_e( 'Steps', 'certificate-id-qr-verifier-for-learndash' ); ?></span>
						<span class="vc-row__value">
							<?php echo esc_html( $completion['steps_completed'] . ' / ' . $completion['steps_total'] ); ?>
						</span>
					</div>
					<div class="vc-row" style="flex-direction:column;align-items:stretch;gap:4px;">
						<div style="display:flex;justify-content:space-between;">
							<span class="vc-row__label"><?php esc_html_e( 'Progress', 'certificate-id-qr-verifier-for-learndash' ); ?></span>
							<span class="vc-row__value"><?php echo esc_html( $completion['percent'] ); ?>%</span>
						</div>
						<div class="vc-score-bar">
							<div class="vc-score-bar__fill" style="width:<?php echo esc_attr( $completion['percent'] ); ?>%;"></div>
						</div>
					</div>
					<?php endif; ?>

					<?php if ( ! empty( $completion['categories'] ) ) : ?>
					<div class="vc-row">
						<span class="vc-row__label"><?php esc_html_e( 'Category', 'certificate-id-qr-verifier-for-learndash' ); ?></span>
						<span class="vc-row__value"><?php echo esc_html( $completion['categories'] ); ?></span>
					</div>
					<?php endif; ?>

				<?php elseif ( $post_type === 'quiz' ) : ?>

					<div class="vc-row">
						<span class="vc-row__label"><?php esc_html_e( 'Result', 'certificate-id-qr-verifier-for-learndash' ); ?></span>
						<span class="vc-row__value">
							<?php if ( $completion['pass'] ) : ?>
								<span class="vc-pill vc-pill--pass"><?php esc_html_e( 'PASSED', 'certificate-id-qr-verifier-for-learndash' ); ?></span>
							<?php else : ?>
								<span class="vc-pill vc-pill--fail"><?php esc_html_e( 'FAILED', 'certificate-id-qr-verifier-for-learndash' ); ?></span>
							<?php endif; ?>
						</span>
					</div>
					<div class="vc-row">
						<span class="vc-row__label"><?php esc_html_e( 'Score', 'certificate-id-qr-verifier-for-learndash' ); ?></span>
						<span class="vc-row__value">
							<?php echo esc_html( $completion['score'] . ' / ' . $completion['total_points'] ); ?>
							&nbsp;<span style="color:#64748b;font-weight:400;">(<?php echo esc_html( $completion['percentage'] ); ?>%)</span>
						</span>
					</div>
					<div class="vc-row" style="flex-direction:column;align-items:stretch;gap:4px;">
						<div style="display:flex;justify-content:space-between;">
							<span class="vc-row__label"><?php esc_html_e( 'Score', 'certificate-id-qr-verifier-for-learndash' ); ?></span>
							<span class="vc-row__value"><?php echo esc_html( $completion['percentage'] ); ?>%</span>
						</div>
						<div class="vc-score-bar">
							<div class="vc-score-bar__fill <?php echo $completion['pass'] ? '' : 'vc-score-bar__fill--fail'; ?>"
								style="width:<?php echo esc_attr( $completion['percentage'] ); ?>%;"></div>
						</div>
					</div>
					<?php if ( $completion['attempts'] ) : ?>
					<div class="vc-row">
						<span class="vc-row__label"><?php esc_html_e( 'Attempts', 'certificate-id-qr-verifier-for-learndash' ); ?></span>
						<span class="vc-row__value"><?php echo esc_html( $completion['attempts'] ); ?></span>
					</div>
					<?php endif; ?>

				<?php elseif ( $post_type === 'group' ) : ?>

					<div class="vc-row">
						<span class="vc-row__label"><?php esc_html_e( 'Courses', 'certificate-id-qr-verifier-for-learndash' ); ?></span>
						<span class="vc-row__value">
							<?php echo esc_html( count( $completion['completed_courses'] ) . ' / ' . $completion['total_courses'] ); ?>
							<?php esc_html_e( 'completed', 'certificate-id-qr-verifier-for-learndash' ); ?>
						</span>
					</div>
					<?php if ( ! empty( $completion['completed_courses'] ) ) : ?>
					<div style="padding: 6px 0;">
						<div class="vc-section__heading" style="margin-bottom:6px;"><?php esc_html_e( 'Completed courses', 'certificate-id-qr-verifier-for-learndash' ); ?></div>
						<ul class="vc-course-list">
							<?php foreach ( $completion['completed_courses'] as $c ) : ?>
								<li><span>✅</span><?php echo esc_html( $c ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
					<?php endif; ?>

				<?php endif; ?>

			</div>
		</div>
		<?php endif; ?>

	<?php else : ?>

		<!-- Invalid -->
		<div class="vc-identity" style="justify-content:center;">
			<div class="vc-no-photo" aria-hidden="true">❌</div>
		</div>
		<div class="vc-status vc-status--invalid">
			<span class="vc-status__icon">⚠️</span>
			<?php esc_html_e( 'Certificate could not be verified. This certificate does not exist or this QR code may be invalid.', 'certificate-id-qr-verifier-for-learndash' ); ?>
		</div>

	<?php endif; ?>

	</div><!-- .vc-body -->

	<div class="vc-footer">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php bloginfo( 'name' ); ?></a>
		&nbsp;·&nbsp;
		<?php printf(
			/* translators: %s: the date and time of this check */
			esc_html__( 'Verified on %s', 'certificate-id-qr-verifier-for-learndash' ),
			esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) )
		); ?>
	</div>

</div><!-- .vc -->
</body>
</html>
