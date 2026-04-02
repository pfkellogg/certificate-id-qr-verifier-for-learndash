<?php
/**
 * Settings — plugin settings page under the WordPress Settings menu.
 *
 * @package CertificatesPlusLD
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CP_LD_Settings {

	const OPTION_BYPASS = 'cp_ld_bypass_photo';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'wp_ajax_cp_ld_save_bypass', array( $this, 'ajax_save_bypass' ) );
		add_action( 'wp_ajax_cp_ld_get_photo_logs', array( $this, 'ajax_get_photo_logs' ) );
		add_action( 'wp_ajax_cp_ld_delete_user_log', array( $this, 'ajax_delete_user_log' ) );
	}

	public function add_menu() {
		add_options_page(
			__( 'Certificate Photo Verifier', 'cp-ld' ),
			__( 'Cert Photo Verifier', 'cp-ld' ),
			'manage_options',
			'cp-ld-settings',
			array( $this, 'render_page' )
		);
	}

	public function register_settings() {
		register_setting( 'cp_ld_settings', self::OPTION_BYPASS, array(
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => false,
		) );
	}

	public static function is_photo_bypassed() {
		return (bool) get_option( self::OPTION_BYPASS, false );
	}

	public function ajax_save_bypass() {
		check_ajax_referer( 'cp_ld_bypass_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cp-ld' ) ) );
		}
		$value = isset( $_POST['bypass'] ) ? rest_sanitize_boolean( $_POST['bypass'] ) : false;
		update_option( self::OPTION_BYPASS, $value );
		wp_send_json_success();
	}

	/**
	 * AJAX — return paginated, sortable user photo change data.
	 */
	public function ajax_get_photo_logs() {
		check_ajax_referer( 'cp_ld_bypass_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cp-ld' ) ) );
		}

		$page     = isset( $_POST['page'] )     ? max( 1, (int) $_POST['page'] ) : 1;
		$per_page = isset( $_POST['per_page'] )  ? max( 1, min( 50, (int) $_POST['per_page'] ) ) : 10;
		$sort     = isset( $_POST['sort'] )      ? sanitize_text_field( $_POST['sort'] ) : 'recent';
		$search   = isset( $_POST['search'] )    ? sanitize_text_field( $_POST['search'] ) : '';

		// Get all user IDs that have a photo log.
		$meta_query_args = array(
			'meta_key'   => CP_LD_Photo_Manager::LOG_META_KEY,
			'meta_compare' => 'EXISTS',
			'fields'     => 'ID',
			'number'     => -1,
		);
		if ( $search ) {
			$meta_query_args['search']         = '*' . $search . '*';
			$meta_query_args['search_columns']  = array( 'user_login', 'user_email', 'display_name' );
		}
		$user_query = new WP_User_Query( $meta_query_args );
		$all_ids    = $user_query->get_results();

		// Build data array with log counts and latest change time for sorting.
		$users_data = array();
		foreach ( $all_ids as $uid ) {
			$log = CP_LD_Photo_Manager::get_log( (int) $uid );
			if ( empty( $log ) ) {
				continue;
			}
			$latest = 0;
			foreach ( $log as $entry ) {
				if ( $entry['time'] > $latest ) {
					$latest = $entry['time'];
				}
			}
			$users_data[] = array(
				'user_id'    => (int) $uid,
				'changes'    => count( $log ),
				'latest'     => $latest,
			);
		}

		// Sort.
		switch ( $sort ) {
			case 'changes_desc':
				usort( $users_data, function( $a, $b ) { return $b['changes'] - $a['changes']; } );
				break;
			case 'changes_asc':
				usort( $users_data, function( $a, $b ) { return $a['changes'] - $b['changes']; } );
				break;
			case 'name_asc':
				usort( $users_data, function( $a, $b ) {
					$ua = get_userdata( $a['user_id'] );
					$ub = get_userdata( $b['user_id'] );
					return strcasecmp( $ua ? $ua->display_name : '', $ub ? $ub->display_name : '' );
				} );
				break;
			case 'name_desc':
				usort( $users_data, function( $a, $b ) {
					$ua = get_userdata( $a['user_id'] );
					$ub = get_userdata( $b['user_id'] );
					return strcasecmp( $ub ? $ub->display_name : '', $ua ? $ua->display_name : '' );
				} );
				break;
			case 'oldest':
				usort( $users_data, function( $a, $b ) { return $a['latest'] - $b['latest']; } );
				break;
			default: // 'recent'
				usort( $users_data, function( $a, $b ) { return $b['latest'] - $a['latest']; } );
				break;
		}

		$total       = count( $users_data );
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$offset      = ( $page - 1 ) * $per_page;
		$paged       = array_slice( $users_data, $offset, $per_page );

		// Build response rows.
		$rows = array();
		$date_fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		foreach ( $paged as $item ) {
			$user = get_userdata( $item['user_id'] );
			if ( ! $user ) {
				continue;
			}
			$photo_url    = CP_LD_Photo_Manager::get_photo_url( $item['user_id'], 'thumbnail' );
			$gravatar_url = CP_LD_Photo_Manager::get_gravatar_url( $item['user_id'], 'thumbnail' );

			// Build log entries.
			$log     = CP_LD_Photo_Manager::get_log( $item['user_id'] );
			$entries = array();
			foreach ( array_reverse( $log ) as $entry ) {
				$old_snap = ! empty( $entry['old_snapshot'] )
					? CP_LD_Photo_Manager::get_log_snapshot_url( $entry['old_snapshot'] )
					: '';
				$new_snap = ! empty( $entry['new_snapshot'] )
					? CP_LD_Photo_Manager::get_log_snapshot_url( $entry['new_snapshot'] )
					: '';

				// Gravatar fallbacks.
				if ( ! $old_snap && $gravatar_url && $entry['action'] === 'upload' && empty( $entry['old_id'] ) ) {
					$old_snap = $gravatar_url;
				}
				if ( ! $new_snap && $gravatar_url && $entry['action'] === 'revert_gravatar' ) {
					$new_snap = $gravatar_url;
				}

				$action_label = '';
				switch ( $entry['action'] ) {
					case 'upload':          $action_label = __( 'Uploaded', 'cp-ld' ); break;
					case 'revert_gravatar': $action_label = __( 'Reverted to Gravatar', 'cp-ld' ); break;
					default:                $action_label = __( 'Deleted', 'cp-ld' ); break;
				}

				$entries[] = array(
					'date'       => date_i18n( $date_fmt, $entry['time'] ),
					'action'     => $action_label,
					'old_snap'   => $old_snap,
					'new_snap'   => $new_snap,
					'changed_by' => $entry['changed_by'] === 'admin' ? __( 'Admin', 'cp-ld' ) : __( 'Self', 'cp-ld' ),
					'ip'         => $entry['ip'],
				);
			}

			$rows[] = array(
				'user_id'      => $item['user_id'],
				'display_name' => $user->display_name,
				'email'        => $user->user_email,
				'photo_url'    => $photo_url ?: '',
				'changes'      => $item['changes'],
				'latest'       => date_i18n( $date_fmt, $item['latest'] ),
				'profile_url'  => get_edit_user_link( $item['user_id'] ) . '#cp-ld-photo',
				'entries'      => $entries,
			);
		}

		wp_send_json_success( array(
			'rows'        => $rows,
			'total'       => $total,
			'total_pages' => $total_pages,
			'page'        => $page,
		) );
	}

	/**
	 * AJAX — delete all photo change history for a user.
	 */
	public function ajax_delete_user_log() {
		check_ajax_referer( 'cp_ld_bypass_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cp-ld' ) ) );
		}

		$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid user.', 'cp-ld' ) ) );
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

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$bypass = self::is_photo_bypassed();
		$nonce  = wp_create_nonce( 'cp_ld_bypass_nonce' );
		?>
		<style>
			.cp-ld-settings { max-width: 900px; margin-top: 20px; }

			/* ---- Mode panel ---- */
			.cp-ld-mode {
				border-radius: 10px;
				padding: 20px 24px;
				margin-bottom: 28px;
				display: flex;
				align-items: center;
				gap: 16px;
				transition: background .35s, border-color .35s, box-shadow .35s;
			}
			.cp-ld-mode--required {
				background: linear-gradient(135deg, #ecfdf5 0%, #f0fdf4 100%);
				border: 2px solid #86efac;
				box-shadow: 0 2px 12px rgba(34,197,94,.10);
			}
			.cp-ld-mode--bypassed {
				background: linear-gradient(135deg, #fef9c3 0%, #fefce8 100%);
				border: 2px solid #fbbf24;
				box-shadow: 0 2px 12px rgba(251,191,36,.12);
			}
			.cp-ld-mode__icon {
				width: 48px; height: 48px;
				border-radius: 50%;
				display: flex; align-items: center; justify-content: center;
				font-size: 22px; flex-shrink: 0;
				transition: background .35s;
			}
			.cp-ld-mode--required .cp-ld-mode__icon { background: #d1fae5; }
			.cp-ld-mode--bypassed .cp-ld-mode__icon { background: #fef08a; }
			.cp-ld-mode__label {
				font-size: 11px; font-weight: 700; text-transform: uppercase;
				letter-spacing: .08em; margin-bottom: 2px; transition: color .35s;
			}
			.cp-ld-mode--required .cp-ld-mode__label { color: #166534; }
			.cp-ld-mode--bypassed .cp-ld-mode__label { color: #92400e; }
			.cp-ld-mode__title {
				font-size: 17px; font-weight: 700; line-height: 1.3; transition: color .35s;
			}
			.cp-ld-mode--required .cp-ld-mode__title { color: #14532d; }
			.cp-ld-mode--bypassed .cp-ld-mode__title { color: #78350f; }
			.cp-ld-mode__desc {
				font-size: 13px; margin-top: 3px; line-height: 1.5; transition: color .35s;
			}
			.cp-ld-mode--required .cp-ld-mode__desc { color: #15803d; }
			.cp-ld-mode--bypassed .cp-ld-mode__desc { color: #a16207; }

			/* ---- Saved toast ---- */
			.cp-ld-mode__saved {
				margin-left: auto; font-size: 12px; font-weight: 600;
				padding: 4px 12px; border-radius: 20px; white-space: nowrap;
				opacity: 0; transform: translateY(-4px);
				transition: opacity .25s, transform .25s;
			}
			.cp-ld-mode__saved--visible { opacity: 1; transform: translateY(0); }
			.cp-ld-mode--required .cp-ld-mode__saved { background: #d1fae5; color: #166534; }
			.cp-ld-mode--bypassed .cp-ld-mode__saved { background: #fef08a; color: #92400e; }

			/* ---- Checkbox row ---- */
			.cp-ld-option {
				background: #fff; border: 1px solid #e2e8f0;
				border-radius: 10px; padding: 20px 24px; margin-bottom: 20px;
			}
			.cp-ld-option label {
				font-size: 14px; font-weight: 500; cursor: pointer;
				display: flex; align-items: center; gap: 10px;
			}
			.cp-ld-option input[type="checkbox"] { width: 18px; height: 18px; accent-color: #dc2626; }

			/* ---- Warning ---- */
			.cp-ld-warning {
				border-radius: 8px; border: 2px solid #dc2626;
				background: linear-gradient(135deg, #fef2f2 0%, #fff5f5 100%);
				box-shadow: 0 4px 16px rgba(220,38,38,.10);
				overflow: hidden; margin-bottom: 24px;
				animation: cpLdSlideIn .25s ease-out;
			}
			@keyframes cpLdSlideIn {
				from { opacity: 0; transform: translateY(-8px); }
				to   { opacity: 1; transform: translateY(0); }
			}
			.cp-ld-warning__header {
				background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
				padding: 14px 20px; display: flex; align-items: center; gap: 10px; color: #fff;
			}
			.cp-ld-warning__header span { font-size: 22px; flex-shrink: 0; }
			.cp-ld-warning__header strong { font-size: 15px; }
			.cp-ld-warning__body { padding: 20px; }
			.cp-ld-warning__body > p { font-size: 14px; line-height: 1.6; color: #1a202c; margin: 0 0 16px; }
			.cp-ld-warning__detail {
				background: #fff; border: 1px solid #fecaca; border-radius: 6px; padding: 14px 16px;
			}
			.cp-ld-warning__detail p { font-size: 13px; color: #7f1d1d; margin: 0 0 10px; font-weight: 600; }
			.cp-ld-warning__detail ul {
				margin: 0; padding-left: 20px; font-size: 13px; color: #991b1b; line-height: 1.7;
			}
			.cp-ld-warning__actions { display: flex; gap: 10px; margin-top: 18px; }
			.cp-ld-warning__btn {
				padding: 9px 22px; border-radius: 6px; font-size: 13px;
				font-weight: 600; cursor: pointer; border: none; transition: opacity .2s;
			}
			.cp-ld-warning__btn:hover { opacity: .85; }
			.cp-ld-warning__btn--confirm { background: #dc2626; color: #fff; }
			.cp-ld-warning__btn--cancel { background: #fff; color: #7f1d1d; border: 1px solid #fecaca; }

			/* ---- Photo log section ---- */
			.cp-ld-logs {
				background: #fff; border: 1px solid #e2e8f0;
				border-radius: 10px; overflow: hidden; margin-top: 8px;
			}
			.cp-ld-logs__toggle {
				width: 100%; padding: 18px 24px;
				background: #fff; border: none; cursor: pointer;
				display: flex; align-items: center; gap: 12px;
				font-size: 15px; font-weight: 600; color: #1a202c;
				transition: background .15s;
			}
			.cp-ld-logs__toggle:hover { background: #f8fafc; }
			.cp-ld-logs__toggle-arrow {
				display: inline-block; transition: transform .2s; font-size: 12px; color: #64748b;
			}
			.cp-ld-logs__toggle-arrow--open { transform: rotate(90deg); }
			.cp-ld-logs__toggle-count {
				margin-left: auto; font-size: 12px; font-weight: 600;
				background: #e0f2fe; color: #0369a1; padding: 3px 10px; border-radius: 20px;
			}

			.cp-ld-logs__body { display: none; border-top: 1px solid #e2e8f0; }
			.cp-ld-logs__body--open { display: block; }

			/* Toolbar */
			.cp-ld-logs__toolbar {
				display: flex; align-items: center; gap: 12px;
				padding: 14px 24px; background: #f8fafc; border-bottom: 1px solid #e2e8f0;
				flex-wrap: wrap;
			}
			.cp-ld-logs__search {
				padding: 7px 12px; border: 1px solid #d1d5db; border-radius: 6px;
				font-size: 13px; min-width: 200px;
			}
			.cp-ld-logs__search:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59,130,246,.15); }
			.cp-ld-logs__sort {
				padding: 7px 12px; border: 1px solid #d1d5db; border-radius: 6px;
				font-size: 13px; background: #fff; cursor: pointer;
			}
			.cp-ld-logs__sort:focus { outline: none; border-color: #3b82f6; }
			.cp-ld-logs__loading {
				margin-left: auto; font-size: 12px; color: #94a3b8; font-style: italic;
			}

			/* Table */
			.cp-ld-logs table { width: 100%; border-collapse: collapse; }
			.cp-ld-logs table th {
				text-align: left; padding: 10px 16px; font-size: 11px; font-weight: 700;
				text-transform: uppercase; letter-spacing: .06em; color: #64748b;
				background: #f8fafc; border-bottom: 1px solid #e2e8f0;
			}
			.cp-ld-logs table td {
				padding: 12px 16px; font-size: 13px; color: #374151;
				border-bottom: 1px solid #f1f5f9; vertical-align: middle;
			}
			.cp-ld-logs table tr:last-child td { border-bottom: none; }
			.cp-ld-logs__user-row { cursor: pointer; transition: background .1s; }
			.cp-ld-logs__user-row:hover { background: #f8fafc; }
			.cp-ld-logs__user-cell {
				display: flex; align-items: center; gap: 12px;
			}
			.cp-ld-logs__avatar {
				width: 36px; height: 36px; border-radius: 50%; object-fit: cover;
				border: 2px solid #e2e8f0; flex-shrink: 0;
			}
			.cp-ld-logs__avatar-placeholder {
				width: 36px; height: 36px; border-radius: 50%; background: #e8edf2;
				display: flex; align-items: center; justify-content: center;
				font-size: 16px; flex-shrink: 0; color: #94a3b8;
			}
			.cp-ld-logs__username { font-weight: 600; color: #1a202c; }
			.cp-ld-logs__email { font-size: 12px; color: #94a3b8; }
			.cp-ld-logs__badge {
				display: inline-block; padding: 2px 9px; border-radius: 20px;
				font-size: 12px; font-weight: 700;
				background: #dbeafe; color: #1d4ed8;
			}
			.cp-ld-logs__expand-icon {
				font-size: 11px; color: #94a3b8; transition: transform .2s; display: inline-block;
			}
			.cp-ld-logs__expand-icon--open { transform: rotate(90deg); }

			/* Expanded sub-table */
			.cp-ld-logs__detail-row { display: none; }
			.cp-ld-logs__detail-row--open { display: table-row; }
			.cp-ld-logs__detail-cell { padding: 0 !important; background: #f8fafc; }
			.cp-ld-logs__subtable {
				width: 100%; border-collapse: collapse; margin: 0;
			}
			.cp-ld-logs__subtable th {
				text-align: left; padding: 8px 16px 8px 40px; font-size: 10px; font-weight: 700;
				text-transform: uppercase; letter-spacing: .06em; color: #94a3b8;
				background: #f1f5f9; border-bottom: 1px solid #e2e8f0;
			}
			.cp-ld-logs__subtable td {
				padding: 10px 16px 10px 40px; font-size: 12px; color: #475569;
				border-bottom: 1px solid #e8edf2; vertical-align: middle;
			}
			.cp-ld-logs__subtable tr:last-child td { border-bottom: none; }
			.cp-ld-logs__snap {
				width: 32px; height: 32px; border-radius: 50%; object-fit: cover;
				border: 2px solid #e2e8f0;
			}
			.cp-ld-logs__snap-none { color: #cbd5e1; font-size: 11px; }
			.cp-ld-logs__ip { font-family: "Courier New", Courier, monospace; font-size: 11px; color: #64748b; }
			.cp-ld-logs__profile-link {
				font-size: 12px; color: #2563eb; text-decoration: none; font-weight: 500;
			}
			.cp-ld-logs__profile-link:hover { text-decoration: underline; }

			/* Pagination */
			.cp-ld-logs__pagination {
				display: flex; align-items: center; justify-content: space-between;
				padding: 14px 24px; background: #f8fafc; border-top: 1px solid #e2e8f0;
				font-size: 13px; color: #64748b;
			}
			.cp-ld-logs__page-btns { display: flex; gap: 6px; }
			.cp-ld-logs__page-btn {
				padding: 6px 14px; border: 1px solid #d1d5db; border-radius: 6px;
				background: #fff; font-size: 13px; cursor: pointer;
				color: #374151; transition: background .15s, border-color .15s;
			}
			.cp-ld-logs__page-btn:hover { background: #f1f5f9; border-color: #9ca3af; }
			.cp-ld-logs__page-btn:disabled { opacity: .4; cursor: default; background: #fff; border-color: #d1d5db; }
			.cp-ld-logs__page-btn--active {
				background: #1e40af; color: #fff; border-color: #1e40af;
			}
			.cp-ld-logs__page-btn--active:hover { background: #1e3a8a; }

			/* Empty state */
			.cp-ld-logs__empty {
				padding: 40px 24px; text-align: center; color: #94a3b8; font-size: 14px;
			}

			/* Delete button */
			.cp-ld-logs__delete-btn {
				padding: 5px 12px; border-radius: 5px; font-size: 12px; font-weight: 600;
				cursor: pointer; border: 1px solid #fecaca; background: #fff;
				color: #dc2626; transition: background .15s, border-color .15s;
				white-space: nowrap;
			}
			.cp-ld-logs__delete-btn:hover { background: #fef2f2; border-color: #f87171; }

			/* Inline delete warning */
			.cp-ld-logs__delete-confirm {
				display: none;
			}
			.cp-ld-logs__delete-confirm--open {
				display: table-row;
			}
			.cp-ld-logs__delete-flash {
				margin: 0;
				padding: 16px 24px 16px 40px;
				background: linear-gradient(135deg, #fef2f2 0%, #fff5f5 100%);
				border-bottom: 2px solid #fecaca;
			}
			.cp-ld-logs__delete-flash-inner {
				display: flex; align-items: flex-start; gap: 12px;
			}
			.cp-ld-logs__delete-flash-icon {
				width: 36px; height: 36px; border-radius: 50%;
				background: #fee2e2; display: flex; align-items: center; justify-content: center;
				font-size: 18px; flex-shrink: 0;
			}
			.cp-ld-logs__delete-flash-text {
				font-size: 13px; line-height: 1.5; color: #7f1d1d;
			}
			.cp-ld-logs__delete-flash-text strong {
				display: block; font-size: 14px; color: #991b1b; margin-bottom: 4px;
			}
			.cp-ld-logs__delete-flash-actions {
				display: flex; gap: 8px; margin-top: 12px;
			}
			.cp-ld-logs__delete-flash-yes {
				padding: 7px 18px; border-radius: 6px; font-size: 12px; font-weight: 600;
				cursor: pointer; border: none; background: #dc2626; color: #fff;
				transition: opacity .15s;
			}
			.cp-ld-logs__delete-flash-yes:hover { opacity: .85; }
			.cp-ld-logs__delete-flash-no {
				padding: 7px 18px; border-radius: 6px; font-size: 12px; font-weight: 600;
				cursor: pointer; border: 1px solid #e2e8f0; background: #fff; color: #64748b;
				transition: background .15s;
			}
			.cp-ld-logs__delete-flash-no:hover { background: #f8fafc; }
		</style>

		<div class="wrap">
			<h1><?php esc_html_e( 'Certificate Photo Verifier Settings', 'cp-ld' ); ?></h1>

			<div class="cp-ld-settings">

				<!-- Current mode panel -->
				<div id="cp-ld-mode-panel" class="cp-ld-mode <?php echo $bypass ? 'cp-ld-mode--bypassed' : 'cp-ld-mode--required'; ?>">
					<div class="cp-ld-mode__icon">
						<span id="cp-ld-mode-icon"><?php echo $bypass ? '&#9888;' : '&#9989;'; ?></span>
					</div>
					<div>
						<div class="cp-ld-mode__label"><?php esc_html_e( 'Current Mode', 'cp-ld' ); ?></div>
						<div class="cp-ld-mode__title" id="cp-ld-mode-title">
							<?php echo $bypass
								? esc_html__( 'Photo Not Required', 'cp-ld' )
								: esc_html__( 'Photo Required', 'cp-ld' ); ?>
						</div>
						<div class="cp-ld-mode__desc" id="cp-ld-mode-desc">
							<?php echo $bypass
								? esc_html__( 'Learners can view certificates without a photo. The verification page does not display a photo.', 'cp-ld' )
								: esc_html__( 'Learners must have a photo on file before viewing any certificate. The verification page displays their photo.', 'cp-ld' ); ?>
						</div>
					</div>
					<div class="cp-ld-mode__saved" id="cp-ld-saved-toast"><?php esc_html_e( 'Saved', 'cp-ld' ); ?></div>
				</div>

				<!-- Checkbox -->
				<div class="cp-ld-option">
					<label for="cp_ld_bypass_photo">
						<input type="checkbox"
							id="cp_ld_bypass_photo"
							value="1"
							<?php checked( $bypass ); ?> />
						<?php esc_html_e( 'Bypass Photo Requirement', 'cp-ld' ); ?>
					</label>
				</div>

				<!-- Warning -->
				<div id="cp-ld-bypass-warning" style="display:none;">
					<div class="cp-ld-warning">
						<div class="cp-ld-warning__header">
							<span>&#9888;</span>
							<strong><?php esc_html_e( 'Are you sure you want to do this?', 'cp-ld' ); ?></strong>
						</div>
						<div class="cp-ld-warning__body">
							<p><?php esc_html_e( 'This will no longer require a user to have a photo when viewing a certificate and will not display a photo on the verification page landed on by the QR code.', 'cp-ld' ); ?></p>
							<div class="cp-ld-warning__detail">
								<p><?php esc_html_e( 'Specifically, this means:', 'cp-ld' ); ?></p>
								<ul>
									<li><?php esc_html_e( 'Users can view and download certificates without uploading a photo.', 'cp-ld' ); ?></li>
									<li><?php esc_html_e( 'The QR code verification page will not show a photo for the learner.', 'cp-ld' ); ?></li>
									<li><?php esc_html_e( 'Certificates generated while bypassed will have no photo snapshot on file.', 'cp-ld' ); ?></li>
								</ul>
							</div>
							<div class="cp-ld-warning__actions">
								<button type="button" class="cp-ld-warning__btn cp-ld-warning__btn--confirm" id="cp-ld-confirm-bypass">
									<?php esc_html_e( 'Yes, bypass photo requirement', 'cp-ld' ); ?>
								</button>
								<button type="button" class="cp-ld-warning__btn cp-ld-warning__btn--cancel" id="cp-ld-cancel-bypass">
									<?php esc_html_e( 'Cancel', 'cp-ld' ); ?>
								</button>
							</div>
						</div>
					</div>
				</div>

				<!-- User photo changes log -->
				<div class="cp-ld-logs" id="cp-ld-logs">
					<button type="button" class="cp-ld-logs__toggle" id="cp-ld-logs-toggle">
						<span class="cp-ld-logs__toggle-arrow" id="cp-ld-logs-arrow">&#9654;</span>
						<?php esc_html_e( 'View User Photo Changes', 'cp-ld' ); ?>
						<span class="cp-ld-logs__toggle-count" id="cp-ld-logs-count" style="display:none;"></span>
					</button>
					<div class="cp-ld-logs__body" id="cp-ld-logs-body">
						<div class="cp-ld-logs__toolbar">
							<input type="text" class="cp-ld-logs__search" id="cp-ld-logs-search"
								placeholder="<?php esc_attr_e( 'Search users...', 'cp-ld' ); ?>" />
							<select class="cp-ld-logs__sort" id="cp-ld-logs-sort">
								<option value="recent"><?php esc_html_e( 'Most Recent Change', 'cp-ld' ); ?></option>
								<option value="oldest"><?php esc_html_e( 'Oldest Change', 'cp-ld' ); ?></option>
								<option value="changes_desc"><?php esc_html_e( 'Most Changes', 'cp-ld' ); ?></option>
								<option value="changes_asc"><?php esc_html_e( 'Fewest Changes', 'cp-ld' ); ?></option>
								<option value="name_asc"><?php esc_html_e( 'Name A-Z', 'cp-ld' ); ?></option>
								<option value="name_desc"><?php esc_html_e( 'Name Z-A', 'cp-ld' ); ?></option>
							</select>
							<span class="cp-ld-logs__loading" id="cp-ld-logs-loading" style="display:none;"><?php esc_html_e( 'Loading...', 'cp-ld' ); ?></span>
						</div>
						<div id="cp-ld-logs-table-wrap"></div>
						<div id="cp-ld-logs-pagination" class="cp-ld-logs__pagination" style="display:none;"></div>
					</div>
				</div>

			</div>
		</div>

		<script>
		(function() {
			/* ---- Bypass toggle (unchanged) ---- */
			var checkbox   = document.getElementById('cp_ld_bypass_photo');
			var warning    = document.getElementById('cp-ld-bypass-warning');
			var confirmBtn = document.getElementById('cp-ld-confirm-bypass');
			var cancelBtn  = document.getElementById('cp-ld-cancel-bypass');
			var panel      = document.getElementById('cp-ld-mode-panel');
			var modeIcon   = document.getElementById('cp-ld-mode-icon');
			var modeTitle  = document.getElementById('cp-ld-mode-title');
			var modeDesc   = document.getElementById('cp-ld-mode-desc');
			var toast      = document.getElementById('cp-ld-saved-toast');

			var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var nonce   = <?php echo wp_json_encode( $nonce ); ?>;

			var modeText = {
				requiredTitle: <?php echo wp_json_encode( __( 'Photo Required', 'cp-ld' ) ); ?>,
				requiredDesc:  <?php echo wp_json_encode( __( 'Learners must have a photo on file before viewing any certificate. The verification page displays their photo.', 'cp-ld' ) ); ?>,
				bypassedTitle: <?php echo wp_json_encode( __( 'Photo Not Required', 'cp-ld' ) ); ?>,
				bypassedDesc:  <?php echo wp_json_encode( __( 'Learners can view certificates without a photo. The verification page does not display a photo.', 'cp-ld' ) ); ?>
			};
			var toastTimer = null;

			function showToast() {
				toast.classList.add('cp-ld-mode__saved--visible');
				if (toastTimer) clearTimeout(toastTimer);
				toastTimer = setTimeout(function() { toast.classList.remove('cp-ld-mode__saved--visible'); }, 2000);
			}
			function updatePanel(bypassed) {
				panel.className = 'cp-ld-mode ' + (bypassed ? 'cp-ld-mode--bypassed' : 'cp-ld-mode--required');
				modeIcon.innerHTML    = bypassed ? '&#9888;' : '&#9989;';
				modeTitle.textContent = bypassed ? modeText.bypassedTitle : modeText.requiredTitle;
				modeDesc.textContent  = bypassed ? modeText.bypassedDesc  : modeText.requiredDesc;
			}
			function saveBypass(value) {
				var xhr = new XMLHttpRequest();
				xhr.open('POST', ajaxUrl);
				xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
				xhr.onload = function() { if (xhr.status === 200) showToast(); };
				xhr.send('action=cp_ld_save_bypass&nonce=' + encodeURIComponent(nonce) + '&bypass=' + (value ? '1' : '0'));
			}
			checkbox.addEventListener('change', function() {
				if (checkbox.checked) { warning.style.display = ''; }
				else { warning.style.display = 'none'; updatePanel(false); saveBypass(false); }
			});
			confirmBtn.addEventListener('click', function() { warning.style.display = 'none'; updatePanel(true); saveBypass(true); });
			cancelBtn.addEventListener('click', function() { checkbox.checked = false; warning.style.display = 'none'; });

			/* ---- Photo logs table ---- */
			var logsToggle  = document.getElementById('cp-ld-logs-toggle');
			var logsArrow   = document.getElementById('cp-ld-logs-arrow');
			var logsBody    = document.getElementById('cp-ld-logs-body');
			var logsCount   = document.getElementById('cp-ld-logs-count');
			var logsSearch  = document.getElementById('cp-ld-logs-search');
			var logsSort    = document.getElementById('cp-ld-logs-sort');
			var logsLoading = document.getElementById('cp-ld-logs-loading');
			var logsWrap    = document.getElementById('cp-ld-logs-table-wrap');
			var logsPag     = document.getElementById('cp-ld-logs-pagination');

			var logsOpen    = false;
			var logsLoaded  = false;
			var currentPage = 1;
			var searchTimer = null;
			var expandedUsers = {};

			var i18n = {
				date:      <?php echo wp_json_encode( __( 'Date', 'cp-ld' ) ); ?>,
				user:      <?php echo wp_json_encode( __( 'User', 'cp-ld' ) ); ?>,
				changes:   <?php echo wp_json_encode( __( 'Changes', 'cp-ld' ) ); ?>,
				lastChange:<?php echo wp_json_encode( __( 'Last Change', 'cp-ld' ) ); ?>,
				action:    <?php echo wp_json_encode( __( 'Action', 'cp-ld' ) ); ?>,
				previous:  <?php echo wp_json_encode( __( 'Previous', 'cp-ld' ) ); ?>,
				newPhoto:  <?php echo wp_json_encode( __( 'New', 'cp-ld' ) ); ?>,
				by:        <?php echo wp_json_encode( __( 'By', 'cp-ld' ) ); ?>,
				ip:        <?php echo wp_json_encode( __( 'IP', 'cp-ld' ) ); ?>,
				profile:   <?php echo wp_json_encode( __( 'Profile', 'cp-ld' ) ); ?>,
				viewProfile: <?php echo wp_json_encode( __( 'View', 'cp-ld' ) ); ?>,
				prev:      <?php echo wp_json_encode( __( 'Previous', 'cp-ld' ) ); ?>,
				next:      <?php echo wp_json_encode( __( 'Next', 'cp-ld' ) ); ?>,
				showing:   <?php echo wp_json_encode( __( 'Showing %1$s of %2$s users', 'cp-ld' ) ); ?>,
				noResults: <?php echo wp_json_encode( __( 'No photo changes found.', 'cp-ld' ) ); ?>,
				usersWithChanges: <?php echo wp_json_encode( __( '%s users', 'cp-ld' ) ); ?>,
				dash:      '&mdash;',
				deleteBtn:     <?php echo wp_json_encode( __( 'Delete History', 'cp-ld' ) ); ?>,
				deleteTitle:   <?php echo wp_json_encode( __( 'Delete all photo change history?', 'cp-ld' ) ); ?>,
				deleteMsg:     <?php echo wp_json_encode( __( 'This will permanently delete all photo change log entries and their snapshot files for this user. This cannot be undone.', 'cp-ld' ) ); ?>,
				deleteConfirm: <?php echo wp_json_encode( __( 'Yes, delete history', 'cp-ld' ) ); ?>,
				deleteCancel:  <?php echo wp_json_encode( __( 'Cancel', 'cp-ld' ) ); ?>,
				deleting:      <?php echo wp_json_encode( __( 'Deleting...', 'cp-ld' ) ); ?>
			};

			logsToggle.addEventListener('click', function() {
				logsOpen = !logsOpen;
				logsBody.classList.toggle('cp-ld-logs__body--open', logsOpen);
				logsArrow.classList.toggle('cp-ld-logs__toggle-arrow--open', logsOpen);
				if (logsOpen && !logsLoaded) { logsLoaded = true; fetchLogs(); }
			});

			logsSort.addEventListener('change', function() { currentPage = 1; fetchLogs(); });
			logsSearch.addEventListener('input', function() {
				clearTimeout(searchTimer);
				searchTimer = setTimeout(function() { currentPage = 1; fetchLogs(); }, 350);
			});

			function fetchLogs() {
				logsLoading.style.display = '';
				var xhr = new XMLHttpRequest();
				xhr.open('POST', ajaxUrl);
				xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
				xhr.onload = function() {
					logsLoading.style.display = 'none';
					if (xhr.status !== 200) return;
					var resp;
					try { resp = JSON.parse(xhr.responseText); } catch(e) { return; }
					if (resp.success) renderLogs(resp.data);
				};
				xhr.send(
					'action=cp_ld_get_photo_logs'
					+ '&nonce=' + encodeURIComponent(nonce)
					+ '&page=' + currentPage
					+ '&per_page=10'
					+ '&sort=' + encodeURIComponent(logsSort.value)
					+ '&search=' + encodeURIComponent(logsSearch.value)
				);
			}

			function esc(str) {
				var d = document.createElement('div');
				d.textContent = str;
				return d.innerHTML;
			}

			function renderLogs(data) {
				// Update badge count.
				if (data.total > 0) {
					logsCount.style.display = '';
					logsCount.textContent = i18n.usersWithChanges.replace('%s', data.total);
				} else {
					logsCount.style.display = 'none';
				}

				if (!data.rows.length) {
					logsWrap.innerHTML = '<div class="cp-ld-logs__empty">' + esc(i18n.noResults) + '</div>';
					logsPag.style.display = 'none';
					return;
				}

				var html = '<table><thead><tr>'
					+ '<th style="width:30px;"></th>'
					+ '<th>' + esc(i18n.user) + '</th>'
					+ '<th style="text-align:center;">' + esc(i18n.changes) + '</th>'
					+ '<th>' + esc(i18n.lastChange) + '</th>'
					+ '<th></th>'
					+ '<th></th>'
					+ '</tr></thead><tbody>';

				for (var i = 0; i < data.rows.length; i++) {
					var r = data.rows[i];
					var uid = r.user_id;
					var isOpen = !!expandedUsers[uid];

					// User row
					html += '<tr class="cp-ld-logs__user-row" data-uid="' + uid + '">'
						+ '<td><span class="cp-ld-logs__expand-icon' + (isOpen ? ' cp-ld-logs__expand-icon--open' : '') + '">&#9654;</span></td>'
						+ '<td><div class="cp-ld-logs__user-cell">';
					if (r.photo_url) {
						html += '<img class="cp-ld-logs__avatar" src="' + esc(r.photo_url) + '" alt="" />';
					} else {
						html += '<div class="cp-ld-logs__avatar-placeholder">&#128100;</div>';
					}
					html += '<div><div class="cp-ld-logs__username">' + esc(r.display_name) + '</div>'
						+ '<div class="cp-ld-logs__email">' + esc(r.email) + '</div></div></div></td>'
						+ '<td style="text-align:center;"><span class="cp-ld-logs__badge">' + r.changes + '</span></td>'
						+ '<td>' + esc(r.latest) + '</td>'
						+ '<td><a href="' + esc(r.profile_url) + '" class="cp-ld-logs__profile-link" onclick="event.stopPropagation();">' + esc(i18n.viewProfile) + '</a></td>'
						+ '<td><button type="button" class="cp-ld-logs__delete-btn" data-delete-uid="' + uid + '" data-delete-name="' + esc(r.display_name) + '" onclick="event.stopPropagation();">' + esc(i18n.deleteBtn) + '</button></td>'
						+ '</tr>';

					// Delete confirm row
					html += '<tr class="cp-ld-logs__delete-confirm" data-confirm-uid="' + uid + '">'
						+ '<td colspan="6" class="cp-ld-logs__detail-cell">'
						+ '<div class="cp-ld-logs__delete-flash">'
						+ '<div class="cp-ld-logs__delete-flash-inner">'
						+ '<div class="cp-ld-logs__delete-flash-icon">&#9888;</div>'
						+ '<div class="cp-ld-logs__delete-flash-text">'
						+ '<strong>' + esc(i18n.deleteTitle) + '</strong>'
						+ esc(i18n.deleteMsg)
						+ '<div class="cp-ld-logs__delete-flash-actions">'
						+ '<button type="button" class="cp-ld-logs__delete-flash-yes" data-yes-uid="' + uid + '">' + esc(i18n.deleteConfirm) + '</button>'
						+ '<button type="button" class="cp-ld-logs__delete-flash-no" data-no-uid="' + uid + '">' + esc(i18n.deleteCancel) + '</button>'
						+ '</div></div></div></div></td></tr>';

					// Detail row (sub-table)
					html += '<tr class="cp-ld-logs__detail-row' + (isOpen ? ' cp-ld-logs__detail-row--open' : '') + '" data-detail-uid="' + uid + '">'
						+ '<td colspan="6" class="cp-ld-logs__detail-cell">'
						+ '<table class="cp-ld-logs__subtable"><thead><tr>'
						+ '<th>' + esc(i18n.date) + '</th>'
						+ '<th>' + esc(i18n.action) + '</th>'
						+ '<th>' + esc(i18n.previous) + '</th>'
						+ '<th>' + esc(i18n.newPhoto) + '</th>'
						+ '<th>' + esc(i18n.by) + '</th>'
						+ '<th>' + esc(i18n.ip) + '</th>'
						+ '</tr></thead><tbody>';

					for (var j = 0; j < r.entries.length; j++) {
						var e = r.entries[j];
						html += '<tr>'
							+ '<td>' + esc(e.date) + '</td>'
							+ '<td>' + esc(e.action) + '</td>'
							+ '<td>' + (e.old_snap ? '<img class="cp-ld-logs__snap" src="' + esc(e.old_snap) + '" alt="" />' : '<span class="cp-ld-logs__snap-none">' + i18n.dash + '</span>') + '</td>'
							+ '<td>' + (e.new_snap ? '<img class="cp-ld-logs__snap" src="' + esc(e.new_snap) + '" alt="" />' : '<span class="cp-ld-logs__snap-none">' + i18n.dash + '</span>') + '</td>'
							+ '<td>' + esc(e.changed_by) + '</td>'
							+ '<td><span class="cp-ld-logs__ip">' + esc(e.ip) + '</span></td>'
							+ '</tr>';
					}

					html += '</tbody></table></td></tr>';
				}

				html += '</tbody></table>';
				logsWrap.innerHTML = html;

				// Bind row expand clicks.
				var rows = logsWrap.querySelectorAll('.cp-ld-logs__user-row');
				rows.forEach(function(row) {
					row.addEventListener('click', function() {
						var uid = row.getAttribute('data-uid');
						var detail = logsWrap.querySelector('[data-detail-uid="' + uid + '"]');
						var arrow  = row.querySelector('.cp-ld-logs__expand-icon');
						var open   = detail.classList.toggle('cp-ld-logs__detail-row--open');
						arrow.classList.toggle('cp-ld-logs__expand-icon--open', open);
						expandedUsers[uid] = open;
					});
				});

				// Bind delete buttons.
				logsWrap.querySelectorAll('.cp-ld-logs__delete-btn').forEach(function(btn) {
					btn.addEventListener('click', function() {
						var uid = btn.getAttribute('data-delete-uid');
						// Close any other open confirm rows.
						logsWrap.querySelectorAll('.cp-ld-logs__delete-confirm--open').forEach(function(r) {
							r.classList.remove('cp-ld-logs__delete-confirm--open');
						});
						var confirmRow = logsWrap.querySelector('[data-confirm-uid="' + uid + '"]');
						confirmRow.classList.add('cp-ld-logs__delete-confirm--open');
					});
				});

				// Bind cancel buttons.
				logsWrap.querySelectorAll('.cp-ld-logs__delete-flash-no').forEach(function(btn) {
					btn.addEventListener('click', function() {
						var uid = btn.getAttribute('data-no-uid');
						var confirmRow = logsWrap.querySelector('[data-confirm-uid="' + uid + '"]');
						confirmRow.classList.remove('cp-ld-logs__delete-confirm--open');
					});
				});

				// Bind confirm delete buttons.
				logsWrap.querySelectorAll('.cp-ld-logs__delete-flash-yes').forEach(function(btn) {
					btn.addEventListener('click', function() {
						var uid = btn.getAttribute('data-yes-uid');
						btn.disabled = true;
						btn.textContent = i18n.deleting;
						var xhr = new XMLHttpRequest();
						xhr.open('POST', ajaxUrl);
						xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
						xhr.onload = function() {
							if (xhr.status === 200) {
								delete expandedUsers[uid];
								fetchLogs();
							} else {
								btn.disabled = false;
								btn.textContent = i18n.deleteConfirm;
							}
						};
						xhr.send(
							'action=cp_ld_delete_user_log'
							+ '&nonce=' + encodeURIComponent(nonce)
							+ '&user_id=' + encodeURIComponent(uid)
						);
					});
				});

				// Pagination.
				if (data.total_pages > 1) {
					logsPag.style.display = '';
					var start = (data.page - 1) * 10 + 1;
					var end   = Math.min(data.page * 10, data.total);
					var info  = i18n.showing.replace('%1$s', start + '–' + end).replace('%2$s', data.total);

					var pagHtml = '<span>' + esc(info) + '</span><div class="cp-ld-logs__page-btns">';

					pagHtml += '<button class="cp-ld-logs__page-btn" data-page="' + (data.page - 1) + '"'
						+ (data.page <= 1 ? ' disabled' : '') + '>&laquo; ' + esc(i18n.prev) + '</button>';

					var maxBtns = 7;
					var startPage = Math.max(1, data.page - 3);
					var endPage   = Math.min(data.total_pages, startPage + maxBtns - 1);
					if (endPage - startPage < maxBtns - 1) startPage = Math.max(1, endPage - maxBtns + 1);

					for (var p = startPage; p <= endPage; p++) {
						pagHtml += '<button class="cp-ld-logs__page-btn' + (p === data.page ? ' cp-ld-logs__page-btn--active' : '') + '" data-page="' + p + '">' + p + '</button>';
					}

					pagHtml += '<button class="cp-ld-logs__page-btn" data-page="' + (data.page + 1) + '"'
						+ (data.page >= data.total_pages ? ' disabled' : '') + '>' + esc(i18n.next) + ' &raquo;</button>';

					pagHtml += '</div>';
					logsPag.innerHTML = pagHtml;

					logsPag.querySelectorAll('.cp-ld-logs__page-btn').forEach(function(btn) {
						btn.addEventListener('click', function() {
							if (btn.disabled) return;
							currentPage = parseInt(btn.getAttribute('data-page'), 10);
							fetchLogs();
						});
					});
				} else {
					logsPag.style.display = 'none';
				}
			}
		})();
		</script>
		<?php
	}
}
