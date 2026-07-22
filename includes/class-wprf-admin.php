<?php
/**
 * Admin Panel Controller and View for Review Funnel Plugin.
 *
 * @package    Review_Funnel_Plugin
 * @subpackage Admin
 * @author     Senior WordPress Architect
 * @since      2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'WPRF_Admin' ) ) {

	/**
	 * Class WPRF_Admin
	 * Handles all backend administration, settings, and review moderations.
	 */
	class WPRF_Admin {

		/**
		 * Database table name for reviews.
		 *
		 * @var string
		 */
		private $table_name;

		/**
		 * WPRF_Admin Constructor.
		 */
		public function __construct() {
			global $wpdb;
			$this->table_name = $wpdb->prefix . 'universal_reviews';

			// Hook into admin menu lifecycle.
			add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		}

		/**
		 * Register the top-level admin menu page.
		 */
		public function register_admin_menu() {
			global $wpdb;
			
			// Database migration check for author_email and marketing_consent
			$column_exists  = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM {$this->table_name} LIKE %s", 'author_email' ) );
			$consent_exists = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM {$this->table_name} LIKE %s", 'marketing_consent' ) );
			if ( empty( $column_exists ) || empty( $consent_exists ) ) {
				require_once plugin_dir_path( __FILE__ ) . 'class-wprf-database.php';
				WPRF_Database::install_table();
			}

			add_menu_page(
				__( 'Review Funnel', 'review-funnel' ),
				__( 'Review Funnel', 'review-funnel' ),
				'manage_options',
				'review-funnel',
				array( $this, 'render_admin_page' ),
				'dashicons-star-filled',
				25
			);
		}

		/**
		 * Main render method for the admin dashboard.
		 */
		public function render_admin_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'review-funnel' ) );
			}

			global $wpdb;

			// Handle incoming POST/GET routing requests securely.
			$this->handle_admin_actions();

			// Build query dynamically based on GET filter parameters.
			$query = "SELECT * FROM {$this->table_name}";
			$where_clauses = array();
			$query_params = array();

			// 1. Filter by Start Date
			if ( ! empty( $_GET['filter_start_date'] ) ) {
				$where_clauses[] = "time >= %s";
				$query_params[]  = sanitize_text_field( $_GET['filter_start_date'] ) . ' 00:00:00';
			}

			// 2. Filter by End Date
			if ( ! empty( $_GET['filter_end_date'] ) ) {
				$where_clauses[] = "time <= %s";
				$query_params[]  = sanitize_text_field( $_GET['filter_end_date'] ) . ' 23:59:59';
			}

			// 3. Filter by ID (profile_id - search as comma-separated or matching)
			if ( ! empty( $_GET['filter_profile_id'] ) ) {
				$where_clauses[] = "profile_id LIKE %s";
				$query_params[]  = '%' . sanitize_text_field( $_GET['filter_profile_id'] ) . '%';
			}

			// 4. Filter by Author
			if ( ! empty( $_GET['filter_author'] ) ) {
				$where_clauses[] = "author_name LIKE %s";
				$query_params[]  = '%' . sanitize_text_field( $_GET['filter_author'] ) . '%';
			}

			// 5. Filter by Rating
			if ( isset( $_GET['filter_rating'] ) && $_GET['filter_rating'] !== '' ) {
				$where_clauses[] = "rating = %d";
				$query_params[]  = intval( $_GET['filter_rating'] );
			}

			// 6. Filter by Status
			if ( ! empty( $_GET['filter_status'] ) ) {
				$where_clauses[] = "status = %s";
				$query_params[]  = sanitize_text_field( $_GET['filter_status'] );
			}

			if ( ! empty( $where_clauses ) ) {
				$query .= " WHERE " . implode( " AND ", $where_clauses );
			}

			// Determine orderby and order parameters safely to prevent SQL injection.
			$allowed_orderby = array(
				'time'        => 'time',
				'profile_id'  => 'profile_id',
				'author_name' => 'author_name',
				'rating'      => 'rating',
			);
			
			$orderby = isset( $_GET['orderby'] ) && isset( $allowed_orderby[ $_GET['orderby'] ] ) ? $allowed_orderby[ $_GET['orderby'] ] : 'time';
			$order   = isset( $_GET['order'] ) && strtolower( $_GET['order'] ) === 'asc' ? 'ASC' : 'DESC';

			// 7. Calculate Pagination
			$per_page = isset( $_GET['per_page'] ) ? max( 10, min( 100, intval( $_GET['per_page'] ) ) ) : 20;
			$paged    = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;

			// Query total matching items count
			$count_query = "SELECT COUNT(*) FROM {$this->table_name}";
			if ( ! empty( $where_clauses ) ) {
				$count_query .= " WHERE " . implode( " AND ", $where_clauses );
			}
			if ( ! empty( $query_params ) ) {
				$total_items = intval( $wpdb->get_var( $wpdb->prepare( $count_query, $query_params ) ) );
			} else {
				$total_items = intval( $wpdb->get_var( $count_query ) );
			}

			$total_pages = ceil( $total_items / $per_page );
			$paged       = min( $paged, max( 1, $total_pages ) );
			$offset      = ( $paged - 1 ) * $per_page;

			$query .= " ORDER BY $orderby $order";
			$query .= " LIMIT %d OFFSET %d";

			$prepared_params = $query_params;
			$prepared_params[] = $per_page;
			$prepared_params[] = $offset;

			$reviews = $wpdb->get_results( $wpdb->prepare( $query, $prepared_params ) );

			// Sorting URLs construction helpers.
			$query_args = $_GET;
			$get_sort_url = function( $column ) use ( $query_args, $orderby, $order ) {
				$new_args = $query_args;
				$new_args['page'] = 'review-funnel';
				$new_args['orderby'] = $column;
				
				if ( $orderby === $column ) {
					$new_args['order'] = ( 'DESC' === $order ) ? 'asc' : 'desc';
				} else {
					$new_args['order'] = 'asc';
				}
				
				return add_query_arg( $new_args, admin_url( 'admin.php' ) );
			};
			
			$get_sort_icon = function( $column ) use ( $orderby, $order ) {
				if ( $orderby !== $column ) {
					return ' <span class="dashicons dashicons-sort" style="font-size:14px; vertical-align:middle; color:#bbb;"></span>';
				}
				return 'ASC' === $order
					? ' <span class="dashicons dashicons-arrow-up-alt2" style="font-size:14px; vertical-align:middle; color:#0073aa;"></span>'
					: ' <span class="dashicons dashicons-arrow-down-alt2" style="font-size:14px; vertical-align:middle; color:#0073aa;"></span>';
			};

			// Default customization settings fallbacks.
			$btn_color           = get_option( 'wprf_btn_color', '#007a78' );
			$btn_text_color      = get_option( 'wprf_btn_text_color', '#ffffff' );
			$slider_arrow_color  = get_option( 'wprf_slider_arrow_color', '#007a78' );
			$slider_dot_color    = get_option( 'wprf_slider_dot_color', '#007a78' );
			$slider_arrow_style  = get_option( 'wprf_slider_arrow_style', 'circle' );
			$notification_emails = get_option( 'wprf_notification_emails', get_option( 'admin_email' ) );
			$success_msg         = get_option( 'wprf_success_msg', 'Thank you for your review! Your feedback helps us grow.' );
			$google_redirect_msg = get_option( 'wprf_google_redirect_msg', 'Would you mind copying your review to our Google Maps profile to help others?' );

			// Google Place ID & API Key fallbacks.
			$api_key = get_option( 'wprf_google_api_key' );
			if ( empty( $api_key ) ) {
				$api_key = get_option( 'urf_google_api_key' );
			}
			$place_id = get_option( 'wprf_google_place_id' );
			if ( empty( $place_id ) ) {
				$place_id = get_option( 'urf_google_place_id' );
			}
			$direct_url = get_option( 'wprf_google_direct_url', '' );

			// GDPR options
			$enable_gdpr       = get_option( 'wprf_enable_gdpr', 'no' );
			$gdpr_policy_url   = get_option( 'wprf_gdpr_policy_url', '' );
			$gdpr_text         = get_option( 'wprf_gdpr_text', 'Wyrażam zgodę na przetwarzanie moich danych osobowych zgodnie z {privacy_policy} w celu opublikowania opinii.' );
			$enable_newsletter = get_option( 'wprf_enable_newsletter', 'no' );
			$newsletter_text   = get_option( 'wprf_newsletter_text', 'Chcę zapisać się na newsletter i wyrażam zgodę na przesyłanie informacji handlowych.' );

			// Cloudflare Turnstile spam protection options
			$enable_turnstile     = get_option( 'wprf_enable_turnstile', 'no' );
			$turnstile_site_key   = get_option( 'wprf_turnstile_site_key', '' );
			$turnstile_secret_key = get_option( 'wprf_turnstile_secret_key', '' );
			$disable_ip_rate_limit = get_option( 'wprf_disable_ip_rate_limit', 'no' );

			// Customization options (PRO)
			$enable_filters     = get_option( 'wprf_enable_filters', 'true' );
			$show_review_date   = get_option( 'wprf_show_review_date', 'yes' );
			$review_date_color  = get_option( 'wprf_review_date_color', '#718096' );
			$review_date_size   = get_option( 'wprf_review_date_size', '11px' );

			$no_reviews_color   = get_option( 'wprf_no_reviews_color', '#718096' );
			$no_reviews_size    = get_option( 'wprf_no_reviews_size', '15px' );
			$no_reviews_weight  = get_option( 'wprf_no_reviews_weight', 'normal' );
			$no_reviews_style   = get_option( 'wprf_no_reviews_style', 'italic' );

			$form_bg_color      = get_option( 'wprf_form_bg_color', '#fdfdfd' );
			$form_border_style  = get_option( 'wprf_form_border_style', 'solid' );
			$form_border_width  = get_option( 'wprf_form_border_width', '1px' );
			$form_border_color  = get_option( 'wprf_form_border_color', '#e2e8f0' );
			$form_border_radius = get_option( 'wprf_form_border_radius', '8px' );
			$form_padding       = get_option( 'wprf_form_padding', '25px' );
			$form_box_shadow    = get_option( 'wprf_form_box_shadow', '0 4px 6px -1px rgba(0,0,0,0.05)' );
			
			$field_bg_color     = get_option( 'wprf_field_bg_color', '#ffffff' );
			$field_border_color = get_option( 'wprf_field_border_color', '#cbd5e1' );
			$field_focus_color  = get_option( 'wprf_field_focus_color', '#007a78' );
			
			$star_size           = get_option( 'wprf_star_size', '36px' );
			$star_active_color   = get_option( 'wprf_star_active_color', '#f59e0b' );
			$star_inactive_color = get_option( 'wprf_star_inactive_color', '#cbd5e1' );
			
			$title_color        = get_option( 'wprf_title_color', '#2d3748' );
			$title_size         = get_option( 'wprf_title_size', '16px' );
			$label_color        = get_option( 'wprf_label_color', '#4a5568' );
			$label_size         = get_option( 'wprf_label_size', '13px' );

			// MailerLite Integration Options (PRO)
			$mailerlite_api_key      = get_option( 'wprf_mailerlite_api_key', '' );
			$mailerlite_satisfied    = get_option( 'wprf_mailerlite_satisfied_group', '' );
			$mailerlite_dissatisfied  = get_option( 'wprf_mailerlite_dissatisfied_group', '' );
			$mailerlite_groups        = get_option( 'wprf_mailerlite_groups', array() );

			// Retrieve unique profile IDs for autocompletion
			$raw_profile_ids = $wpdb->get_col( "SELECT DISTINCT profile_id FROM {$this->table_name}" );
			$unique_profile_ids = array();
			foreach ( $raw_profile_ids as $p_id ) {
				if ( empty( $p_id ) ) {
					continue;
				}
				$parts = explode( ',', $p_id );
				foreach ( $parts as $part ) {
					$trimmed = trim( $part );
					if ( ! empty( $trimmed ) && ! in_array( $trimmed, $unique_profile_ids, true ) ) {
						$unique_profile_ids[] = $trimmed;
					}
				}
			}
			sort( $unique_profile_ids );

			$custom_avatar_url = get_option( 'wprf_custom_avatar_url', '' );
			$default_layout    = get_option( 'wprf_default_layout', 'grid' );

			// --- MODIFICATION: FETCH TRANSLATIONS OPTIONS ---
			$translations = get_option( 'wprf_translations', array() );
			if ( ! is_array( $translations ) ) {
				$translations = array();
			}
			$t_rating_title  = isset( $translations['rating_title'] ) ? $translations['rating_title'] : 'How do you rate your experience with us?';
			$t_input_name    = isset( $translations['input_name'] ) ? $translations['input_name'] : 'Your Name / Nickname';
			$t_input_name_p  = isset( $translations['input_name_placeholder'] ) ? $translations['input_name_placeholder'] : 'e.g. John Doe';
			$t_input_email   = isset( $translations['input_email'] ) ? $translations['input_email'] : 'Your Email (optional)';
			$t_input_email_p = isset( $translations['input_email_placeholder'] ) ? $translations['input_email_placeholder'] : 'e.g. john@example.com';
			$t_input_text    = isset( $translations['input_text'] ) ? $translations['input_text'] : 'Your Feedback';
			$t_input_text_p  = isset( $translations['input_text_placeholder'] ) ? $translations['input_text_placeholder'] : 'Please share details about your visit...';
			$t_submit_btn    = isset( $translations['submit_btn'] ) ? $translations['submit_btn'] : 'Submit Review';
			$t_js_processing = isset( $translations['js_processing'] ) ? $translations['js_processing'] : 'Processing your feedback...';
			$t_js_star_alert = isset( $translations['js_star_alert'] ) ? $translations['js_star_alert'] : 'Please select a star rating.';
			$t_js_error     = isset( $translations['js_error'] ) ? $translations['js_error'] : 'An error occurred. Please try again.';
			$t_google_btn   = isset( $translations['google_btn'] ) ? $translations['google_btn'] : 'Go to Google Maps';
			$t_clipboard_msg = isset( $translations['clipboard_msg'] ) ? $translations['clipboard_msg'] : '(Your feedback text has been automatically copied to your clipboard!)';
			$t_copy_btn     = isset( $translations['copy_btn'] ) ? $translations['copy_btn'] : 'Copy Your Review';
			$t_js_copied    = isset( $translations['js_copied'] ) ? $translations['js_copied'] : 'Copied!';
			$t_review_single     = isset( $translations['review_single'] ) ? $translations['review_single'] : 'review';
			$t_review_plural_234 = isset( $translations['review_plural_234'] ) ? $translations['review_plural_234'] : 'reviews';
			$t_review_plural_5plus = isset( $translations['review_plural_5plus'] ) ? $translations['review_plural_5plus'] : 'reviews';
			$t_read_more         = isset( $translations['read_more'] ) ? $translations['read_more'] : 'read more';
			$t_read_less         = isset( $translations['read_less'] ) ? $translations['read_less'] : 'read less';
			$t_empty_reviews     = isset( $translations['empty_reviews_msg'] ) ? $translations['empty_reviews_msg'] : '{profile} does not have any reviews yet.';
			$t_filter_all        = isset( $translations['filter_all'] ) ? $translations['filter_all'] : 'All';

			wp_enqueue_media();
			?>
			<div class="wrap">
				<style>
				/* Kontener dla nowej struktury */
				.wprf-appearance-container {
					display: flex;
					gap: 24px;
					margin-top: 20px;
					align-items: flex-start;
				}

				/* Sidebar z menu */
				.wprf-appearance-sidebar {
					width: 240px;
					flex-shrink: 0;
					background: #ffffff;
					border: 1px solid #ccd0d4;
					border-radius: 8px;
					box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
					overflow: hidden;
				}

				.wprf-appearance-menu {
					list-style: none !important;
					margin: 0 !important;
					padding: 0 !important;
				}

				.wprf-appearance-menu-item {
					margin: 0 !important;
					border-bottom: 1px solid #edf2f7;
				}

				.wprf-appearance-menu-item:last-child {
					border-bottom: none;
				}

				.wprf-appearance-menu-item a {
					display: flex;
					align-items: center;
					gap: 12px;
					padding: 15px 20px;
					color: #4a5568;
					text-decoration: none;
					font-weight: 500;
					font-size: 13px;
					border-left: 4px solid transparent;
					transition: all 0.2s ease-in-out;
					box-shadow: none !important;
				}

				/* Stany interaktywne menu */
				.wprf-appearance-menu-item a:hover {
					background: #f8fafc;
					color: #2271b1;
				}

				.wprf-appearance-menu-item.active a {
					background: #f0f6fa;
					color: #2271b1;
					border-left-color: #2271b1;
					font-weight: 600;
				}

				.wprf-appearance-menu-item a .dashicons {
					color: #718096;
					font-size: 18px;
					width: 18px;
					height: 18px;
					transition: color 0.2s ease-in-out;
				}

				.wprf-appearance-menu-item.active a .dashicons,
				.wprf-appearance-menu-item a:hover .dashicons {
					color: #2271b1;
				}

				/* Kolumna z zawartością */
				.wprf-appearance-content {
					flex-grow: 1;
					min-width: 0;
				}

				.wprf-appearance-section-pane {
					display: none;
					animation: wprfFadeIn 0.25s ease-in-out;
				}

				/* Animacja przejścia */
				@keyframes wprfFadeIn {
					from {
						opacity: 0;
						transform: translateY(4px);
					}
					to {
						opacity: 1;
						transform: translateY(0);
					}
				}

				/* Stylowanie kart ustawień po prawej */
				.wprf-appearance-content .card {
					background: #ffffff;
					border: 1px solid #ccd0d4;
					border-radius: 8px;
					padding: 24px;
					margin-bottom: 20px;
					box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.02);
					max-width: 100% !important;
					box-sizing: border-box;
				}

				.wprf-appearance-content .card h2 {
					margin-top: 0;
					border-bottom: 1px solid #edf2f7;
					padding-bottom: 14px;
					margin-bottom: 20px;
					font-size: 18px;
					font-weight: 600;
					color: #1d2327;
				}
				</style>
				<h1><?php esc_html_e( 'Review Funnel & Google Maps Integration', 'review-funnel' ); ?></h1>
				
				<!-- WP NATIVE TABS NAVIGATION -->
				<h2 class="nav-tab-wrapper">
					<a href="#tab-reviews" class="nav-tab nav-tab-active" data-tab="tab-reviews" onclick="wprfSwitchTab('tab-reviews'); return false;"><?php esc_html_e( 'Reviews Management', 'review-funnel' ); ?></a>
					<a href="#tab-settings" class="nav-tab" data-tab="tab-settings" onclick="wprfSwitchTab('tab-settings'); return false;"><?php esc_html_e( 'Settings & Customization', 'review-funnel' ); ?></a>
					<a href="#tab-translations" class="nav-tab" data-tab="tab-translations" onclick="wprfSwitchTab('tab-translations'); return false;"><?php esc_html_e( 'Translations', 'review-funnel' ); ?></a>
					<a href="#tab-shortcodes" class="nav-tab" data-tab="tab-shortcodes" onclick="wprfSwitchTab('tab-shortcodes'); return false;"><?php esc_html_e( 'Shortcodes Guide', 'review-funnel' ); ?></a>
					<a href="#tab-support" class="nav-tab" data-tab="tab-support" onclick="wprfSwitchTab('tab-support'); return false;"><?php esc_html_e( 'Support & Help', 'review-funnel' ); ?></a>
				</h2>

				<!-- TAB 1: REVIEWS MANAGEMENT -->
				<div id="tab-reviews" class="tab-content-section" style="margin-top: 20px;">
					
					<!-- FILTERS SECTION -->
					<div style="clear: both; margin-bottom: 20px; background: #fff; padding: 15px; border-radius: 8px; border: 1px solid #ccd0d4; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02); max-width: 100%; box-sizing: border-box; display: block;">
						<form method="get" action="">
							<input type="hidden" name="page" value="review-funnel">
							
							<div style="display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end;">
								<!-- Date filters -->
								<div>
									<label style="display:block; font-weight:bold; margin-bottom:5px; font-size:12px; color:#4a5568;"><?php esc_html_e( 'From Date', 'review-funnel' ); ?>:</label>
									<input type="date" name="filter_start_date" value="<?php echo esc_attr( isset($_GET['filter_start_date']) ? $_GET['filter_start_date'] : '' ); ?>" style="border: 1px solid #cbd5e1; padding: 4px 8px; border-radius: 4px; background:#fff; font-size:13px; line-height: 1.5; height: 30px;">
								</div>
								<div>
									<label style="display:block; font-weight:bold; margin-bottom:5px; font-size:12px; color:#4a5568;"><?php esc_html_e( 'To Date', 'review-funnel' ); ?>:</label>
									<input type="date" name="filter_end_date" value="<?php echo esc_attr( isset($_GET['filter_end_date']) ? $_GET['filter_end_date'] : '' ); ?>" style="border: 1px solid #cbd5e1; padding: 4px 8px; border-radius: 4px; background:#fff; font-size:13px; line-height: 1.5; height: 30px;">
								</div>

								<!-- Profile ID filter -->
								<div>
									<label style="display:block; font-weight:bold; margin-bottom:5px; font-size:12px; color:#4a5568;"><?php esc_html_e( 'Profile ID', 'review-funnel' ); ?>:</label>
									<input type="text" name="filter_profile_id" placeholder="e.g. anna-dubaj" list="wprf-profile-ids" value="<?php echo esc_attr( isset($_GET['filter_profile_id']) ? $_GET['filter_profile_id'] : '' ); ?>" style="border: 1px solid #cbd5e1; padding: 4px 8px; border-radius: 4px; background:#fff; font-size:13px; height: 30px; width: 140px;">
									<datalist id="wprf-profile-ids">
										<?php foreach ( $unique_profile_ids as $p_id ) : ?>
											<option value="<?php echo esc_attr( $p_id ); ?>">
										<?php endforeach; ?>
									</datalist>
								</div>

								<!-- Author filter -->
								<div>
									<label style="display:block; font-weight:bold; margin-bottom:5px; font-size:12px; color:#4a5568;"><?php esc_html_e( 'Author', 'review-funnel' ); ?>:</label>
									<input type="text" name="filter_author" placeholder="e.g. John" value="<?php echo esc_attr( isset($_GET['filter_author']) ? $_GET['filter_author'] : '' ); ?>" style="border: 1px solid #cbd5e1; padding: 4px 8px; border-radius: 4px; background:#fff; font-size:13px; height: 30px; width: 140px;">
								</div>

								<!-- Rating filter -->
								<div>
									<label style="display:block; font-weight:bold; margin-bottom:5px; font-size:12px; color:#4a5568;"><?php esc_html_e( 'Rating', 'review-funnel' ); ?>:</label>
									<select name="filter_rating" style="border: 1px solid #cbd5e1; border-radius: 4px; background:#fff; font-size:13px; height: 30px; padding: 4px 8px; width: 110px;">
										<option value=""><?php esc_html_e( 'All', 'review-funnel' ); ?></option>
										<?php for ($i = 5; $i >= 1; $i--): ?>
											<option value="<?php echo $i; ?>" <?php selected( isset($_GET['filter_rating']) ? $_GET['filter_rating'] : '', $i ); ?>><?php echo esc_html($i . ' ★'); ?></option>
										<?php endfor; ?>
									</select>
								</div>

								<!-- Status filter -->
								<div>
									<label style="display:block; font-weight:bold; margin-bottom:5px; font-size:12px; color:#4a5568;"><?php esc_html_e( 'Status', 'review-funnel' ); ?>:</label>
									<select name="filter_status" style="border: 1px solid #cbd5e1; border-radius: 4px; background:#fff; font-size:13px; height: 30px; padding: 4px 8px; width: 120px;">
										<option value=""><?php esc_html_e( 'All', 'review-funnel' ); ?></option>
										<option value="approved" <?php selected( isset($_GET['filter_status']) ? $_GET['filter_status'] : '', 'approved' ); ?>><?php esc_html_e( 'Approved', 'review-funnel' ); ?></option>
										<option value="pending" <?php selected( isset($_GET['filter_status']) ? $_GET['filter_status'] : '', 'pending' ); ?>><?php esc_html_e( 'Pending', 'review-funnel' ); ?></option>
									</select>
								</div>

								<!-- Filter Actions -->
								<div style="display: flex; gap: 8px;">
									<button type="submit" class="button button-primary" style="height: 30px; line-height: 28px; padding: 0 12px; font-weight: bold;"><?php esc_html_e( 'Filter', 'review-funnel' ); ?></button>
									<a href="?page=review-funnel" class="button button-secondary" style="height: 30px; line-height: 28px; padding: 0 12px; display: inline-flex; align-items: center;"><?php esc_html_e( 'Reset', 'review-funnel' ); ?></a>
								</div>
							</div>
						</form>
					</div>

					<!-- Top Pagination Navigation Bar -->
					<div class="tablenav top" style="margin-bottom: 15px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px;">
						<!-- Bulk Actions Form -->
						<div style="display: flex; align-items: center;">
							<form method="post" action="?page=review-funnel" id="wprf-bulk-form" style="margin:0; display:flex; align-items:center; gap:8px;" onsubmit="return confirm('<?php esc_attr_e( 'Are you sure you want to delete all selected reviews?', 'review-funnel' ); ?>')">
								<?php wp_nonce_field( 'wprf_bulk_delete_action', 'wprf_bulk_delete_nonce' ); ?>
								<input type="hidden" name="wprf_admin_action" value="bulk_delete">
								<select name="bulk_action" style="height:32px;" required>
									<option value=""><?php esc_html_e( 'Bulk Actions', 'review-funnel' ); ?></option>
									<option value="delete"><?php esc_html_e( 'Delete Permanently', 'review-funnel' ); ?></option>
								</select>
								<button type="submit" class="button action"><?php esc_html_e( 'Apply', 'review-funnel' ); ?></button>
							</form>
						</div>

						<!-- Per Page Selector & Info -->
						<div style="display: flex; align-items: center; gap: 15px;">
							<form method="get" action="" style="margin:0; display:inline-flex; align-items:center; gap:8px;">
								<input type="hidden" name="page" value="review-funnel">
								<?php
								foreach ( $_GET as $key => $val ) {
									if ( in_array( $key, array( 'page', 'per_page', 'paged' ) ) ) continue;
									echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $val ) . '">';
								}
								?>
								<label for="wprf_per_page" style="font-size: 13px; color: #4a5568; font-weight: bold;"><?php esc_html_e( 'Show:', 'review-funnel' ); ?></label>
								<select id="wprf_per_page" name="per_page" onchange="this.form.submit()" style="height: 30px; border: 1px solid #cbd5e1; border-radius: 4px; padding: 0 8px; font-size: 13px; background: #fff; line-height: 28px; cursor: pointer;">
									<option value="10" <?php selected( $per_page, 10 ); ?>>10</option>
									<option value="20" <?php selected( $per_page, 20 ); ?>>20</option>
									<option value="50" <?php selected( $per_page, 50 ); ?>>50</option>
									<option value="100" <?php selected( $per_page, 100 ); ?>>100</option>
								</select>
							</form>

							<span style="font-size: 13px; color: #666;">
								<?php
								printf(
									/* translators: 1: total items count */
									esc_html( _n( '%d item', '%d items', $total_items, 'review-funnel' ) ),
									$total_items
								);
								?>
							</span>
						</div>

						<!-- Pagination Links -->
						<?php if ( $total_pages > 1 ) : ?>
							<div class="tablenav-pages" style="display: flex; align-items: center; gap: 4px;">
								<?php
								$pagination_args = array(
									'base'      => add_query_arg( 'paged', '%#%' ),
									'format'    => '',
									'total'     => $total_pages,
									'current'   => $paged,
									'show_all'  => false,
									'end_size'  => 1,
									'mid_size'  => 2,
									'prev_next' => true,
									'prev_text' => __( '&laquo;', 'review-funnel' ),
									'next_text' => __( '&raquo;', 'review-funnel' ),
									'type'      => 'plain',
								);
								echo paginate_links( $pagination_args );
								?>
							</div>
						<?php endif; ?>
					</div>

					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th style="width: 3%; text-align: center; vertical-align: middle;"><input type="checkbox" id="wprf-select-all-checkbox" style="margin:0;"></th>
								<th style="width: 12%;"><a href="<?php echo esc_url( $get_sort_url( 'time' ) ); ?>" style="text-decoration:none; color:#23282d; display:inline-block; font-weight:600;"><?php esc_html_e( 'Date & Source', 'review-funnel' ); ?><?php echo $get_sort_icon( 'time' ); ?></a></th>
								<th style="width: 22%;"><a href="<?php echo esc_url( $get_sort_url( 'profile_id' ) ); ?>" style="text-decoration:none; color:#23282d; display:inline-block; font-weight:600;"><?php esc_html_e( 'Assigned Profiles', 'review-funnel' ); ?><?php echo $get_sort_icon( 'profile_id' ); ?></a></th>
								<th style="width: 12%;"><a href="<?php echo esc_url( $get_sort_url( 'author_name' ) ); ?>" style="text-decoration:none; color:#23282d; display:inline-block; font-weight:600;"><?php esc_html_e( 'Author', 'review-funnel' ); ?><?php echo $get_sort_icon( 'author_name' ); ?></a></th>
								<th style="width: 8%;"><a href="<?php echo esc_url( $get_sort_url( 'rating' ) ); ?>" style="text-decoration:none; color:#23282d; display:inline-block; font-weight:600;"><?php esc_html_e( 'Rating', 'review-funnel' ); ?><?php echo $get_sort_icon( 'rating' ); ?></a></th>
								<th><?php esc_html_e( 'Review Content (Editable)', 'review-funnel' ); ?></th>
								<th style="width: 8%;"><?php esc_html_e( 'Status', 'review-funnel' ); ?></th>
								<th style="width: 12%;"><?php esc_html_e( 'Actions', 'review-funnel' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $reviews ) ) : ?>
								<tr><td colspan="8"><?php esc_html_e( 'No reviews found in the database.', 'review-funnel' ); ?></td></tr>
							<?php else : ?>
								<?php foreach ( $reviews as $rev ) : ?>
									<tr>
										<td style="text-align: center; vertical-align: middle;">
											<input type="checkbox" name="bulk_reviews[]" value="<?php echo esc_attr( $rev->id ); ?>" form="wprf-bulk-form" class="wprf-row-checkbox" style="margin:0;">
										</td>
										<td>
											<input type="text" name="edited_review_date" value="<?php echo esc_attr( date( 'Y-m-d H:i:s', strtotime( $rev->time ) ) ); ?>" form="form-rev-<?php echo esc_attr( $rev->id ); ?>" style="width: 100%; font-size: 11px; padding: 4px; border: 1px solid #cbd5e1; border-radius: 4px; box-sizing: border-box; margin-bottom: 5px;" required>
											<span style="font-size:11px; color:#666; font-weight:bold;">
												<?php echo $rev->google_review_id ? '🌐 Google Maps' : '📝 Web Form'; ?>
											</span>
										</td>
										<td>
											<form method="post" action="?page=review-funnel" id="form-rev-<?php echo esc_attr( $rev->id ); ?>" style="margin:0;">
												<?php wp_nonce_field( 'wprf_moderation_action', 'wprf_moderation_nonce' ); ?>
												<input type="hidden" name="review_id" value="<?php echo esc_attr( $rev->id ); ?>">
												<input type="hidden" name="wprf_admin_action" value="approve_and_save">
												
												<input type="text" name="assigned_profile_id" value="<?php echo esc_attr( $rev->profile_id ); ?>" placeholder="e.g. anna-dubaj, general" list="wprf-profile-ids" style="width:100%; margin-bottom:5px;" required>
												<small style="color:#777; font-size:10px;"><?php esc_html_e( 'Separate multiple IDs with commas.', 'review-funnel' ); ?></small>
											</form>
										</td>
										<td>
											<strong><?php echo esc_html( $rev->author_name ); ?></strong>
											<?php if ( ! empty( $rev->author_email ) ) : ?>
												<br><a href="mailto:<?php echo esc_attr( $rev->author_email ); ?>" style="font-size:11px; text-decoration:none; color:#0073aa;"><?php echo esc_html( $rev->author_email ); ?></a>
												<?php
												if ( isset( $rev->marketing_consent ) && 1 === intval( $rev->marketing_consent ) ) {
													echo '<br><span style="padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: bold; background: #e6fffa; color: #234e52; display: inline-block; margin-top: 4px;">✓ Newsletter Opt-in</span>';
												} else {
													echo '<br><span style="padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: bold; background: #f7fafc; color: #718096; display: inline-block; margin-top: 4px;">✗ No Consent</span>';
												}
												?>
											<?php endif; ?>
										</td>
										<td style="color:#ffcc00; font-size:16px;"><?php echo esc_html( str_repeat( '★', $rev->rating ) ); ?></td>
										<td>
											<textarea name="edited_review_text" form="form-rev-<?php echo esc_attr( $rev->id ); ?>" rows="3" style="width:100%; resize:vertical; box-sizing:border-box; padding:6px;"><?php echo esc_textarea( $rev->review_text ); ?></textarea>
										</td>
										<td>
											<select name="review_status" form="form-rev-<?php echo esc_attr( $rev->id ); ?>" style="width:100%; font-weight:bold; font-size:11px; padding:4px; border-radius:4px; border:1px solid #ccc; background: <?php echo 'approved' === $rev->status ? '#d4edda; color:#155724;' : '#fff3cd; color:#856404;'; ?>" onchange="this.style.background = (this.value === 'approved' ? '#d4edda' : '#fff3cd'); this.style.color = (this.value === 'approved' ? '#155724' : '#856404');">
												<option value="pending" <?php selected( $rev->status, 'pending' ); ?>><?php esc_html_e( 'Pending', 'review-funnel' ); ?></option>
												<option value="approved" <?php selected( $rev->status, 'approved' ); ?>><?php esc_html_e( 'Approved', 'review-funnel' ); ?></option>
											</select>
										</td>
										<td>
											<button type="submit" form="form-rev-<?php echo esc_attr( $rev->id ); ?>" class="button button-primary" style="width:100%; margin-bottom: 5px;">
												<?php esc_html_e( 'Save Changes', 'review-funnel' ); ?>
											</button>
											<?php
											$delete_url = wp_nonce_url( '?page=review-funnel&action=delete&id=' . $rev->id, 'wprf_delete_review_' . $rev->id, 'wprf_delete_nonce' );
											?>
											<a href="<?php echo esc_url( $delete_url ); ?>" class="button button-link-delete" style="color:#dc3545; text-decoration:none; display:block; text-align:center; margin-top:5px;" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to permanently delete this review?', 'review-funnel' ); ?>')"><?php esc_html_e( 'Delete', 'review-funnel' ); ?></a>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>

					<!-- Bottom Pagination Navigation Bar -->
					<?php if ( $total_pages > 1 ) : ?>
						<div class="tablenav bottom" style="margin-top: 15px; display: flex; align-items: center; justify-content: flex-end;">
							<div class="tablenav-pages" style="display: flex; align-items: center; gap: 4px;">
								<?php echo paginate_links( $pagination_args ); ?>
							</div>
						</div>
					<?php endif; ?>
				</div>

				<!-- TAB 2: SETTINGS & CUSTOMIZATION (Z DRUGIM POZIOMEM NAWIGACJI) -->
				<div id="tab-settings" class="tab-content-section" style="margin-top: 20px; display: none;">
					
					<!-- SECOND-LEVEL SUB-MENU BAR (Menu drugiego poziomu) -->
					<div class="wprf-subnav-bar" style="margin-bottom: 25px; background: #ffffff; padding: 12px 16px; border-radius: 8px; border: 1px solid #ccd0d4; box-shadow: 0 1px 3px rgba(0,0,0,0.04); display: flex; flex-wrap: wrap; gap: 8px;">
						<button type="button" class="button wprf-subnav-btn button-primary" data-subtab="subtab-google" onclick="wprfSwitchSubtab('subtab-google'); return false;" style="font-weight: 600;">
							<span class="dashicons dashicons-admin-links" style="vertical-align: middle; font-size: 16px; width: 16px; height: 16px; margin-right: 4px;"></span>
							<?php esc_html_e( 'Google API & Redirects', 'review-funnel' ); ?>
						</button>
						<button type="button" class="button wprf-subnav-btn button-secondary" data-subtab="subtab-privacy" onclick="wprfSwitchSubtab('subtab-privacy'); return false;" style="font-weight: 600;">
							<span class="dashicons dashicons-shield" style="vertical-align: middle; font-size: 16px; width: 16px; height: 16px; margin-right: 4px;"></span>
							<?php esc_html_e( 'GDPR & Privacy', 'review-funnel' ); ?>
						</button>
						<button type="button" class="button wprf-subnav-btn button-secondary" data-subtab="subtab-email" onclick="wprfSwitchSubtab('subtab-email'); return false;" style="font-weight: 600;">
							<span class="dashicons dashicons-email-alt" style="vertical-align: middle; font-size: 16px; width: 16px; height: 16px; margin-right: 4px;"></span>
							<?php esc_html_e( 'Email Notifications', 'review-funnel' ); ?>
						</button>
						<button type="button" class="button wprf-subnav-btn button-secondary" data-subtab="subtab-spam" onclick="wprfSwitchSubtab('subtab-spam'); return false;" style="font-weight: 600;">
							<span class="dashicons dashicons-lock" style="vertical-align: middle; font-size: 16px; width: 16px; height: 16px; margin-right: 4px;"></span>
							<?php esc_html_e( 'Spam Protection', 'review-funnel' ); ?>
						</button>
						<button type="button" class="button wprf-subnav-btn button-secondary" data-subtab="subtab-mailerlite" onclick="wprfSwitchSubtab('subtab-mailerlite'); return false;" style="font-weight: 600;">
							<span class="dashicons dashicons-groups" style="vertical-align: middle; font-size: 16px; width: 16px; height: 16px; margin-right: 4px;"></span>
							<?php esc_html_e( 'MailerLite Sync', 'review-funnel' ); ?>
						</button>
						<button type="button" class="button wprf-subnav-btn button-secondary" data-subtab="subtab-design" onclick="wprfSwitchSubtab('subtab-design'); return false;" style="font-weight: 600;">
							<span class="dashicons dashicons-art" style="vertical-align: middle; font-size: 16px; width: 16px; height: 16px; margin-right: 4px;"></span>
							<?php esc_html_e( 'Appearance & Layout PRO', 'review-funnel' ); ?>
						</button>
					</div>

					<form method="post" action="">
						<?php wp_nonce_field( 'wprf_settings_save_action', 'wprf_settings_save_nonce' ); ?>
						
						<!-- SUBTAB 1: GOOGLE API & REDIRECTS -->
						<div id="subtab-google" class="wprf-subtab-section">
						
						<div class="card" style="padding:20px; margin-bottom:20px; max-width:800px;">
							<h2><span class="dashicons dashicons-admin-links" style="vertical-align: middle;"></span> <?php esc_html_e( 'Google Profile & Redirect Configurations', 'review-funnel' ); ?></h2>
							<p class="description"><?php esc_html_e( 'Configure how satisfied users are redirected to Google to publish their reviews.', 'review-funnel' ); ?></p>
							<table class="form-table">
								<tr>
									<th scope="row"><label for="wprf_google_direct_url"><?php esc_html_e( 'Google Direct Review URL (Keyless Lejek)', 'review-funnel' ); ?></label></th>
									<td>
										<input type="text" id="wprf_google_direct_url" name="wprf_google_direct_url" value="<?php echo esc_attr( $direct_url ); ?>" class="regular-text">
										<p class="description"><?php esc_html_e( 'If filled, satisfied users (4-5 stars) will be redirected directly to this link. Bypasses Google Places API key & Place ID requirements. Perfect for pasting your Google Maps sharing link (e.g. https://share.google/... or https://maps.app.goo.gl/...).', 'review-funnel' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="wprf_google_api_key"><?php esc_html_e( 'Google API Key', 'review-funnel' ); ?></label></th>
									<td>
										<input type="text" id="wprf_google_api_key" name="wprf_google_api_key" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text">
										<p class="description"><?php esc_html_e( 'Required for automatic or manual synchronization of reviews from Google into WordPress.', 'review-funnel' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="wprf_google_place_id"><?php esc_html_e( 'Google Place ID', 'review-funnel' ); ?></label></th>
									<td>
										<input type="text" id="wprf_google_place_id" name="wprf_google_place_id" value="<?php echo esc_attr( $place_id ); ?>" class="regular-text">
										<p class="description"><?php esc_html_e( 'Google Place ID (e.g. ChIJ...) required for fetching/importing reviews via Google API. If no Direct Review URL is set above, the plugin will also use this ID to generate a redirect link. (Note: Do not paste a website link/URL here—use the field above for that).', 'review-funnel' ); ?></p>
									</td>
								</tr>
							</table>
							<button type="submit" name="fetch_google_reviews_manually" value="1" class="button button-secondary" style="margin-top:10px; background:#4285F4; color:#fff; border-color:#4285F4; font-weight:bold;">
								<?php esc_html_e( 'Fetch Latest Google Reviews Now', 'review-funnel' ); ?>
							</button>
						</div>
						<p class="submit" style="margin-top: 15px;">
							<button type="submit" name="save_wprf_settings" class="button button-primary button-large"><?php esc_html_e( 'Save Settings', 'review-funnel' ); ?></button>
						</p>
					</div><!-- /subtab-google -->

					<!-- SUBTAB 2: GDPR & PRIVACY -->
					<div id="subtab-privacy" class="wprf-subtab-section" style="display: none;">
						<div class="card" style="padding:20px; margin-bottom:20px; max-width:800px;">
							<h2><span class="dashicons dashicons-shield" style="vertical-align: middle;"></span> <?php esc_html_e( 'GDPR / Privacy Policy Consent Settings', 'review-funnel' ); ?></h2>
							<p class="description"><?php esc_html_e( 'Manage RODO / GDPR privacy policy checkboxes on your review submission funnel.', 'review-funnel' ); ?></p>
							<table class="form-table">
								<tr>
									<th scope="row"><label for="wprf_enable_gdpr"><?php esc_html_e( 'Enable GDPR Consent Checkbox', 'review-funnel' ); ?></label></th>
									<td><input type="checkbox" id="wprf_enable_gdpr" name="wprf_enable_gdpr" value="yes" <?php checked( $enable_gdpr, 'yes' ); ?>></td>
								</tr>
								<tr>
									<th scope="row"><label for="wprf_gdpr_policy_url"><?php esc_html_e( 'Privacy Policy URL', 'review-funnel' ); ?></label></th>
									<td>
										<input type="text" id="wprf_gdpr_policy_url" name="wprf_gdpr_policy_url" value="<?php echo esc_attr( $gdpr_policy_url ); ?>" class="regular-text">
										<p class="description"><?php esc_html_e( 'Link to your existing site privacy policy page.', 'review-funnel' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="wprf_gdpr_text"><?php esc_html_e( 'Consent Form Formula Text', 'review-funnel' ); ?></label></th>
									<td>
										<textarea id="wprf_gdpr_text" name="wprf_gdpr_text" rows="2" class="large-text"><?php echo esc_textarea( $gdpr_text ); ?></textarea>
										<p class="description"><?php esc_html_e( 'Use the {privacy_policy} tag to automatically output a linked privacy policy text.', 'review-funnel' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="wprf_enable_newsletter"><?php esc_html_e( 'Enable Newsletter Opt-in Checkbox', 'review-funnel' ); ?></label></th>
									<td><input type="checkbox" id="wprf_enable_newsletter" name="wprf_enable_newsletter" value="yes" <?php checked( $enable_newsletter, 'yes' ); ?>></td>
								</tr>
								<tr>
									<th scope="row"><label for="wprf_newsletter_text"><?php esc_html_e( 'Newsletter Consent Text', 'review-funnel' ); ?></label></th>
									<td>
										<textarea id="wprf_newsletter_text" name="wprf_newsletter_text" rows="2" class="large-text"><?php echo esc_textarea( $newsletter_text ); ?></textarea>
										<p class="description"><?php esc_html_e( 'Displayed only if the user inputs an optional email address.', 'review-funnel' ); ?></p>
									</td>
								</tr>
							</table>
						</div>
						<p class="submit" style="margin-top: 15px;">
							<button type="submit" name="save_wprf_settings" class="button button-primary button-large"><?php esc_html_e( 'Save Settings', 'review-funnel' ); ?></button>
						</p>
					</div><!-- /subtab-privacy -->

					<!-- SUBTAB 3: EMAIL NOTIFICATIONS -->
					<div id="subtab-email" class="wprf-subtab-section" style="display: none;">
						<div class="card" style="padding:20px; margin-bottom:20px; max-width:800px;">
							<h2><span class="dashicons dashicons-email-alt" style="vertical-align: middle;"></span> <?php esc_html_e( 'Email Notification Settings', 'review-funnel' ); ?></h2>
							<table class="form-table">
								<tr>
									<th scope="row"><label for="wprf_notification_emails"><?php esc_html_e( 'Recipient Email Addresses', 'review-funnel' ); ?></label></th>
									<td>
										<input type="text" id="wprf_notification_emails" name="wprf_notification_emails" value="<?php echo esc_attr( $notification_emails ); ?>" class="regular-text">
										<p class="description"><?php esc_html_e( 'Enter email addresses separated by commas (e.g. admin@example.com, manager@example.com) to receive notifications when new reviews are submitted.', 'review-funnel' ); ?></p>
									</td>
								</tr>
							</table>
						</div>
						<p class="submit" style="margin-top: 15px;">
							<button type="submit" name="save_wprf_settings" class="button button-primary button-large"><?php esc_html_e( 'Save Settings', 'review-funnel' ); ?></button>
						</p>
					</div><!-- /subtab-email -->

					<!-- SUBTAB 4: SPAM PROTECTION -->
					<div id="subtab-spam" class="wprf-subtab-section" style="display: none;">
						<div class="card" style="padding:20px; margin-bottom:20px; max-width:800px;">
							<h2><span class="dashicons dashicons-shield" style="vertical-align: middle;"></span> <?php esc_html_e( 'Spam Protection Settings (Cloudflare Turnstile)', 'review-funnel' ); ?></h2>
							<p class="description"><?php esc_html_e( 'Protect your review form from automated spambots using Cloudflare Turnstile (a secure, GDPR-compliant reCAPTCHA alternative).', 'review-funnel' ); ?></p>
							<table class="form-table">
								<tr>
									<th scope="row"><label for="wprf_enable_turnstile"><?php esc_html_e( 'Enable Cloudflare Turnstile', 'review-funnel' ); ?></label></th>
									<td>
										<input type="checkbox" id="wprf_enable_turnstile" name="wprf_enable_turnstile" value="yes" <?php checked( $enable_turnstile, 'yes' ); ?>>
										<span class="description"><?php esc_html_e( 'Enable Turnstile verification widget on the frontend form.', 'review-funnel' ); ?></span>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="wprf_turnstile_site_key"><?php esc_html_e( 'Turnstile Site Key', 'review-funnel' ); ?></label></th>
									<td>
										<input type="text" id="wprf_turnstile_site_key" name="wprf_turnstile_site_key" value="<?php echo esc_attr( $turnstile_site_key ); ?>" class="regular-text">
										<p class="description"><?php esc_html_e( 'Enter your Turnstile Site Key. To get one, register your site on Cloudflare > Turnstile.', 'review-funnel' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="wprf_turnstile_secret_key"><?php esc_html_e( 'Turnstile Secret Key', 'review-funnel' ); ?></label></th>
									<td>
										<input type="password" id="wprf_turnstile_secret_key" name="wprf_turnstile_secret_key" value="<?php echo esc_attr( $turnstile_secret_key ); ?>" class="regular-text">
										<p class="description"><?php esc_html_e( 'Enter your Turnstile Secret Key.', 'review-funnel' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="wprf_disable_ip_rate_limit"><?php esc_html_e( 'Disable IP Rate Limiting (Dev Mode)', 'review-funnel' ); ?></label></th>
									<td>
										<input type="checkbox" id="wprf_disable_ip_rate_limit" name="wprf_disable_ip_rate_limit" value="yes" <?php checked( $disable_ip_rate_limit, 'yes' ); ?>>
										<span class="description"><?php esc_html_e( 'Bypass the 1-hour IP submission limit. Use this for testing/development. Uncheck in production to prevent spam.', 'review-funnel' ); ?></span>
									</td>
								</tr>
							</table>
						</div>
						<p class="submit" style="margin-top: 15px;">
							<button type="submit" name="save_wprf_settings" class="button button-primary button-large"><?php esc_html_e( 'Save Settings', 'review-funnel' ); ?></button>
						</p>
					</div><!-- /subtab-spam -->

					<!-- SUBTAB 5: MAILERLITE SYNC -->
					<div id="subtab-mailerlite" class="wprf-subtab-section" style="display: none;">
						<div class="card" style="padding:20px; margin-bottom:20px; max-width:800px;">
							<h2><span class="dashicons dashicons-email-alt" style="vertical-align: middle;"></span> <?php esc_html_e( 'MailerLite Integration Settings (PRO)', 'review-funnel' ); ?></h2>
							<p class="description"><?php esc_html_e( 'Automatically subscribe clients who request email updates to your MailerLite lists based on rating satisfaction.', 'review-funnel' ); ?></p>
							<table class="form-table">
								<tr>
									<th scope="row"><label for="wprf_mailerlite_api_key"><?php esc_html_e( 'MailerLite API Key (v3)', 'review-funnel' ); ?></label></th>
									<td>
										<input type="password" id="wprf_mailerlite_api_key" name="wprf_mailerlite_api_key" value="<?php echo esc_attr( $mailerlite_api_key ); ?>" class="regular-text">
										<p class="description"><?php esc_html_e( 'Enter your MailerLite API v3 key. To generate one, go to MailerLite > Integrations > API.', 'review-funnel' ); ?></p>
									</td>
								</tr>
								<?php if ( ! empty( $mailerlite_api_key ) ) : ?>
									<tr>
										<th scope="row"><label for="wprf_mailerlite_satisfied_group"><?php esc_html_e( 'Satisfied Clients List (4-5 Stars)', 'review-funnel' ); ?></label></th>
										<td>
											<select id="wprf_mailerlite_satisfied_group" name="wprf_mailerlite_satisfied_group" style="min-width: 250px;">
												<option value=""><?php esc_html_e( '-- Select MailerLite Group --', 'review-funnel' ); ?></option>
												<?php foreach ( $mailerlite_groups as $grp ) : ?>
													<option value="<?php echo esc_attr( $grp['id'] ); ?>" <?php selected( $mailerlite_satisfied, $grp['id'] ); ?>><?php echo esc_html( $grp['name'] ); ?></option>
												<?php endforeach; ?>
											</select>
											<p class="description"><?php esc_html_e( 'Clients giving 4 or 5 stars will be subscribed to this group.', 'review-funnel' ); ?></p>
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="wprf_mailerlite_dissatisfied_group"><?php esc_html_e( 'Dissatisfied Clients List (1-3 Stars)', 'review-funnel' ); ?></label></th>
										<td>
											<select id="wprf_mailerlite_dissatisfied_group" name="wprf_mailerlite_dissatisfied_group" style="min-width: 250px;">
												<option value=""><?php esc_html_e( '-- Select MailerLite Group --', 'review-funnel' ); ?></option>
												<?php foreach ( $mailerlite_groups as $grp ) : ?>
													<option value="<?php echo esc_attr( $grp['id'] ); ?>" <?php selected( $mailerlite_dissatisfied, $grp['id'] ); ?>><?php echo esc_html( $grp['name'] ); ?></option>
												<?php endforeach; ?>
											</select>
											<p class="description"><?php esc_html_e( 'Clients giving 1, 2, or 3 stars will be subscribed to this group.', 'review-funnel' ); ?></p>
										</td>
									</tr>
									<tr>
										<th></th>
										<td>
											<button type="submit" name="sync_mailerlite_groups" value="1" class="button button-secondary">
												<?php esc_html_e( 'Refresh MailerLite Groups', 'review-funnel' ); ?>
											</button>
										</td>
									</tr>
								<?php else : ?>
									<tr>
										<th></th>
										<td>
											<div class="notice notice-info inline" style="margin: 0;"><p><?php esc_html_e( 'Save your MailerLite API Key to select mailing lists.', 'review-funnel' ); ?></p></div>
										</td>
									</tr>
								<?php endif; ?>
							</table>
						</div>
						<p class="submit" style="margin-top: 15px;">
							<button type="submit" name="save_wprf_settings" class="button button-primary button-large"><?php esc_html_e( 'Save Settings', 'review-funnel' ); ?></button>
						</p>
					</div><!-- /subtab-mailerlite -->

					<!-- SUBTAB 6: APPEARANCE & LAYOUT PRO -->
					<div id="subtab-design" class="wprf-subtab-section" style="display: none;">
						<div class="wprf-appearance-container">
							
							<!-- LEWA KOLUMNA: Pionowe menu podkategorii (Sidebar) -->
							<div class="wprf-appearance-sidebar">
								<ul class="wprf-appearance-menu">
									<li class="wprf-appearance-menu-item active" data-appearance-section="wprf-sec-list-layout">
										<a href="#">
											<span class="dashicons dashicons-layout"></span>
											<?php esc_html_e( 'Default Reviews List', 'review-funnel' ); ?>
										</a>
									</li>
									<li class="wprf-appearance-menu-item" data-appearance-section="wprf-sec-form-style">
										<a href="#">
											<span class="dashicons dashicons-art"></span>
											<?php esc_html_e( 'Form Style & Design', 'review-funnel' ); ?>
										</a>
									</li>
									<li class="wprf-appearance-menu-item" data-appearance-section="wprf-sec-avatar-icons">
										<a href="#">
											<span class="dashicons dashicons-admin-users"></span>
											<?php esc_html_e( 'Anonymous Avatar & Icons', 'review-funnel' ); ?>
										</a>
									</li>
									<li class="wprf-appearance-menu-item" data-appearance-section="wprf-sec-typography">
										<a href="#">
											<span class="dashicons dashicons-editor-textcolor"></span>
											<?php esc_html_e( 'Typography & Sizes', 'review-funnel' ); ?>
										</a>
									</li>
								</ul>
							</div>

							<!-- PRAWA KOLUMNA: Zawartość sekcji (Content) -->
							<div class="wprf-appearance-content">
								
								<!-- SEKCJA 1: Default Reviews List Layout -->
								<div id="wprf-sec-list-layout" class="wprf-appearance-section-pane" style="display: block;">
									<div class="card" style="padding:20px; margin-bottom:20px; max-width:800px;">
										<h2><span class="dashicons dashicons-layout" style="vertical-align: middle;"></span> <?php esc_html_e( 'Default Reviews List Layout (PRO)', 'review-funnel' ); ?></h2>
										<p class="description"><?php esc_html_e( 'Select the default display layout for reviews lists rendered by the [review_list] shortcode.', 'review-funnel' ); ?></p>
										<table class="form-table">
											<tr>
												<th scope="row"><label for="wprf_default_layout"><?php esc_html_e( 'Default Display Layout', 'review-funnel' ); ?></label></th>
												<td>
													<select id="wprf_default_layout" name="wprf_default_layout" style="min-width: 280px;">
														<option value="grid" <?php selected( $default_layout, 'grid' ); ?>><?php esc_html_e( 'Grid Cards (Siatka kart)', 'review-funnel' ); ?></option>
														<option value="slider" <?php selected( $default_layout, 'slider' ); ?>><?php esc_html_e( 'Touch Slider / Carousel (Przesuwany slider)', 'review-funnel' ); ?></option>
														<option value="masonry" <?php selected( $default_layout, 'masonry' ); ?>><?php esc_html_e( 'Masonry Grid (Dynamiczna siatka)', 'review-funnel' ); ?></option>
													</select>
													<p class="description"><?php esc_html_e( 'Choose between Grid, Slider (Carousel), or Masonry layouts. You can also override this on individual pages using layout="slider" or layout="grid" in the shortcode.', 'review-funnel' ); ?></p>
												</td>
											</tr>
											<tr>
												<th scope="row"><label for="wprf_enable_filters"><?php esc_html_e( 'Enable Rating Filter Buttons', 'review-funnel' ); ?></label></th>
												<td>
													<label>
														<input type="checkbox" id="wprf_enable_filters" name="wprf_enable_filters" value="true" <?php checked( $enable_filters, 'true' ); ?>>
														<?php esc_html_e( 'Display rating filter buttons (Wszystkie, 5 ★, 4 ★, 3 ★, 2 ★, 1 ★) above Grid and Masonry review lists by default.', 'review-funnel' ); ?>
													</label>
												</td>
											</tr>
										</table>
									</div>
								</div>

								<!-- SEKCJA 2: Form Style & Design -->
								<div id="wprf-sec-form-style" class="wprf-appearance-section-pane" style="display: none;">
									<div class="card" style="padding:20px; margin-bottom:20px; max-width:800px;">
										<h2><span class="dashicons dashicons-art" style="vertical-align: middle;"></span> <?php esc_html_e( 'Form Style & Design Settings', 'review-funnel' ); ?></h2>
										<table class="form-table">
											<tr>
												<th scope="row"><label for="wprf_btn_color"><?php esc_html_e( 'Button Background Color', 'review-funnel' ); ?></label></th>
												<td>
													<?php
													$picker_btn_val = ( strpos( $btn_color, '#' ) === 0 && strlen( $btn_color ) <= 7 ) ? $btn_color : '#007a78';
													?>
													<input type="color" id="wprf_btn_color_picker" value="<?php echo esc_attr( $picker_btn_val ); ?>" style="vertical-align: middle; margin-right: 5px; height: 30px; width: 40px; padding: 0; border: 1px solid #ccc; cursor: pointer;">
													<input type="text" id="wprf_btn_color" name="wprf_btn_color" value="<?php echo esc_attr( $btn_color ); ?>" class="regular-text" style="vertical-align: middle;">
													<button type="button" class="button wprf-open-gradient-generator" data-target="wprf_btn_color" style="vertical-align: middle; margin-left: 5px;">🎨 <?php esc_html_e( 'Gradient Generator', 'review-funnel' ); ?></button>
													<p class="description"><?php esc_html_e( 'Supports hex color (e.g. #007a78) or CSS gradients (e.g. linear-gradient(135deg, #007a78 0%, #00a4a2 100%)). Use the color picker to select solid colors easily.', 'review-funnel' ); ?></p>
												</td>
											</tr>
											<tr>
												<th scope="row"><label for="wprf_btn_text_color"><?php esc_html_e( 'Button Text Color', 'review-funnel' ); ?></label></th>
												<td><input type="color" id="wprf_btn_text_color" name="wprf_btn_text_color" value="<?php echo esc_attr( $btn_text_color ); ?>"></td>
											</tr>
											<tr>
												<th scope="row"><label for="wprf_slider_arrow_color"><?php esc_html_e( 'Slider Arrows Color', 'review-funnel' ); ?></label></th>
												<td><input type="color" id="wprf_slider_arrow_color" name="wprf_slider_arrow_color" value="<?php echo esc_attr( $slider_arrow_color ); ?>"></td>
											</tr>
											<tr>
												<th scope="row"><label for="wprf_slider_arrow_style"><?php esc_html_e( 'Slider Arrows Style', 'review-funnel' ); ?></label></th>
												<td>
													<select id="wprf_slider_arrow_style" name="wprf_slider_arrow_style">
														<option value="circle" <?php selected( $slider_arrow_style, 'circle' ); ?>><?php esc_html_e( 'Circle Border & Background', 'review-funnel' ); ?></option>
														<option value="clean" <?php selected( $slider_arrow_style, 'clean' ); ?>><?php esc_html_e( 'Clean (No Circle)', 'review-funnel' ); ?></option>
													</select>
												</td>
											</tr>

											<!-- Advanced Container Settings (PRO) -->
											<tr>
												<td colspan="2"><hr style="border:0; border-top:1px solid #eee; margin:10px 0;"><h3><?php esc_html_e( 'Advanced Container Settings (PRO)', 'review-funnel' ); ?></h3></td>
											</tr>
											<tr>
												<th scope="row"><label for="wprf_form_bg_color"><?php esc_html_e( 'Container Background', 'review-funnel' ); ?></label></th>
												<td>
													<?php
													$picker_val = ( strpos( $form_bg_color, '#' ) === 0 && strlen( $form_bg_color ) <= 7 ) ? $form_bg_color : '#ffffff';
													?>
													<input type="color" id="wprf_form_bg_color_picker" value="<?php echo esc_attr( $picker_val ); ?>" style="vertical-align: middle; margin-right: 5px; height: 30px; width: 40px; padding: 0; border: 1px solid #ccc; cursor: pointer;">
													<input type="text" id="wprf_form_bg_color" name="wprf_form_bg_color" value="<?php echo esc_attr( $form_bg_color ); ?>" class="regular-text" style="vertical-align: middle;">
													<button type="button" class="button wprf-open-gradient-generator" data-target="wprf_form_bg_color" style="vertical-align: middle; margin-left: 5px;">🎨 <?php esc_html_e( 'Gradient Generator', 'review-funnel' ); ?></button>
													<p class="description"><?php esc_html_e( 'Supports hex color (e.g. #fdfdfd) or CSS gradients (e.g. linear-gradient(135deg, #ffffff 0%, #f7fafc 100%)). Use the color picker to select solid colors easily.', 'review-funnel' ); ?></p>
												</td>
											</tr>
											<tr>
												<th scope="row"><label for="wprf_form_border_style"><?php esc_html_e( 'Border Style', 'review-funnel' ); ?></label></th>
												<td>
													<select id="wprf_form_border_style" name="wprf_form_border_style">
														<option value="none" <?php selected( $form_border_style, 'none' ); ?>><?php esc_html_e( 'None', 'review-funnel' ); ?></option>
														<option value="solid" <?php selected( $form_border_style, 'solid' ); ?>><?php esc_html_e( 'Solid', 'review-funnel' ); ?></option>
														<option value="dashed" <?php selected( $form_border_style, 'dashed' ); ?>><?php esc_html_e( 'Dashed', 'review-funnel' ); ?></option>
														<option value="dotted" <?php selected( $form_border_style, 'dotted' ); ?>><?php esc_html_e( 'Dotted', 'review-funnel' ); ?></option>
														<option value="double" <?php selected( $form_border_style, 'double' ); ?>><?php esc_html_e( 'Double', 'review-funnel' ); ?></option>
													</select>
												</td>
											</tr>
											<tr>
												<th scope="row"><label for="wprf_form_border_width"><?php esc_html_e( 'Border Width', 'review-funnel' ); ?></label></th>
												<td>
													<input type="text" id="wprf_form_border_width" name="wprf_form_border_width" value="<?php echo esc_attr( $form_border_width ); ?>" class="small-text" placeholder="1px">
												</td>
											</tr>
											<tr>
												<th scope="row"><label for="wprf_form_border_color"><?php esc_html_e( 'Border Color', 'review-funnel' ); ?></label></th>
												<td><input type="color" id="wprf_form_border_color" name="wprf_form_border_color" value="<?php echo esc_attr( $form_border_color ); ?>"></td>
											</tr>
											<tr>
												<th scope="row"><label for="wprf_form_border_radius"><?php esc_html_e( 'Border Radius (Zaokrąglenie)', 'review-funnel' ); ?></label></th>
												<td>
													<input type="text" id="wprf_form_border_radius" name="wprf_form_border_radius" value="<?php echo esc_attr( $form_border_radius ); ?>" class="small-text" placeholder="8px">
												</td>
											</tr>
											<tr>
												<th scope="row"><label for="wprf_form_padding"><?php esc_html_e( 'Container Padding', 'review-funnel' ); ?></label></th>
												<td>
													<input type="text" id="wprf_form_padding" name="wprf_form_padding" value="<?php echo esc_attr( $form_padding ); ?>" class="small-text" placeholder="25px">
												</td>
											</tr>
											<tr>
												<th scope="row"><label for="wprf_form_box_shadow"><?php esc_html_e( 'Container Shadow (box-shadow)', 'review-funnel' ); ?></label></th>
												<td>
													<input type="text" id="wprf_form_box_shadow" name="wprf_form_box_shadow" value="<?php echo esc_attr( $form_box_shadow ); ?>" class="regular-text">
													<p class="description"><?php esc_html_e( 'CSS box-shadow property value (e.g. 0 4px 6px -1px rgba(0,0,0,0.05)).', 'review-funnel' ); ?></p>
												</td>
											</tr>

											<!-- Form Fields Settings (PRO) -->
											<tr>
												<td colspan="2"><hr style="border:0; border-top:1px solid #eee; margin:10px 0;"><h3><?php esc_html_e( 'Form Fields Settings (PRO)', 'review-funnel' ); ?></h3></td>
											</tr>
											<tr>
												<th scope="row"><label for="wprf_field_bg_color"><?php esc_html_e( 'Field Background Color', 'review-funnel' ); ?></label></th>
												<td><input type="color" id="wprf_field_bg_color" name="wprf_field_bg_color" value="<?php echo esc_attr( $field_bg_color ); ?>"></td>
											</tr>
											<tr>
												<th scope="row"><label for="wprf_field_border_color"><?php esc_html_e( 'Field Border Color', 'review-funnel' ); ?></label></th>
												<td><input type="color" id="wprf_field_border_color" name="wprf_field_border_color" value="<?php echo esc_attr( $field_border_color ); ?>"></td>
											</tr>
											<tr>
												<th scope="row"><label for="wprf_field_focus_color"><?php esc_html_e( 'Field Focus Border Color', 'review-funnel' ); ?></label></th>
												<td><input type="color" id="wprf_field_focus_color" name="wprf_field_focus_color" value="<?php echo esc_attr( $field_focus_color ); ?>"></td>
											</tr>

											<!-- Star Rating Customization (PRO) -->
											<tr>
												<td colspan="2"><hr style="border:0; border-top:1px solid #eee; margin:10px 0;"><h3><?php esc_html_e( 'Star Rating Customization (PRO)', 'review-funnel' ); ?></h3></td>
											</tr>
											<tr>
												<th scope="row"><label for="wprf_star_size"><?php esc_html_e( 'Star Rating Font Size', 'review-funnel' ); ?></label></th>
												<td>
													<input type="text" id="wprf_star_size" name="wprf_star_size" value="<?php echo esc_attr( $star_size ); ?>" class="small-text" placeholder="36px">
												</td>
											</tr>
											<tr>
												<th scope="row"><label for="wprf_star_active_color"><?php esc_html_e( 'Active Star Color', 'review-funnel' ); ?></label></th>
												<td><input type="color" id="wprf_star_active_color" name="wprf_star_active_color" value="<?php echo esc_attr( $star_active_color ); ?>"></td>
											</tr>
											<tr>
												<th scope="row"><label for="wprf_star_inactive_color"><?php esc_html_e( 'Inactive Star Color', 'review-funnel' ); ?></label></th>
												<td><input type="color" id="wprf_star_inactive_color" name="wprf_star_inactive_color" value="<?php echo esc_attr( $star_inactive_color ); ?>"></td>
											</tr>
										</table>
									</div>
								</div>

								<!-- SEKCJA 3: Anonymous Avatar & Icons -->
								<div id="wprf-sec-avatar-icons" class="wprf-appearance-section-pane" style="display: none;">
									<div class="card" style="padding:20px; margin-bottom:20px; max-width:800px;">
										<h2><span class="dashicons dashicons-admin-users" style="vertical-align: middle;"></span> <?php esc_html_e( 'Anonymous Avatar Customization (PRO)', 'review-funnel' ); ?></h2>
										<table class="form-table">
											<tr>
												<th scope="row"><label for="wprf_custom_avatar_url"><?php esc_html_e( 'Custom Default Avatar Icon', 'review-funnel' ); ?></label></th>
												<td>
													<input type="text" id="wprf_custom_avatar_url" name="wprf_custom_avatar_url" value="<?php echo esc_attr( $custom_avatar_url ); ?>" class="regular-text" style="vertical-align: middle;">
													<button type="button" id="wprf_upload_avatar_btn" class="button button-secondary" style="vertical-align: middle; margin-left: 5px;">🖼️ <?php esc_html_e( 'Upload / Select Image', 'review-funnel' ); ?></button>
													<p class="description"><?php esc_html_e( 'Upload or select a custom anonymous avatar icon (PNG, SVG, JPG) from your computer to use for anonymous reviews instead of the neutral user silhouette.', 'review-funnel' ); ?></p>
													<?php if ( ! empty( $custom_avatar_url ) ) : ?>
														<div style="margin-top: 8px;"><img src="<?php echo esc_url( $custom_avatar_url ); ?>" style="width: 42px; height: 42px; border-radius: 50%; object-fit: cover; border: 1px solid #cbd5e1;"></div>
													<?php endif; ?>
												</td>
											</tr>
										</table>
									</div>
								</div>

								<!-- SEKCJA 4: Typography & Sizes -->
								<div id="wprf-sec-typography" class="wprf-appearance-section-pane" style="display: none;">
									<!-- Typography Colors & Sizes (PRO) -->
									<div class="card" style="padding:20px; margin-bottom:20px; max-width:800px;">
										<h2><span class="dashicons dashicons-editor-textcolor" style="vertical-align: middle;"></span> <?php esc_html_e( 'Typography Colors & Sizes (PRO)', 'review-funnel' ); ?></h2>
										<table class="form-table">
											<tr>
												<th scope="row"><label for="wprf_title_color"><?php esc_html_e( 'Step Title Color', 'review-funnel' ); ?></label></th>
												<td><input type="color" id="wprf_title_color" name="wprf_title_color" value="<?php echo esc_attr( $title_color ); ?>"></td>
											</tr>
											<tr>
												<th scope="row"><label for="wprf_title_size"><?php esc_html_e( 'Step Title Font Size', 'review-funnel' ); ?></label></th>
												<td>
													<input type="text" id="wprf_title_size" name="wprf_title_size" value="<?php echo esc_attr( $title_size ); ?>" class="small-text" placeholder="16px">
												</td>
											</tr>
											<tr>
												<th scope="row"><label for="wprf_label_color"><?php esc_html_e( 'Input Label Color', 'review-funnel' ); ?></label></th>
												<td><input type="color" id="wprf_label_color" name="wprf_label_color" value="<?php echo esc_attr( $label_color ); ?>"></td>
											</tr>
											<tr>
												<th scope="row"><label for="wprf_label_size"><?php esc_html_e( 'Input Label Font Size', 'review-funnel' ); ?></label></th>
												<td>
													<input type="text" id="wprf_label_size" name="wprf_label_size" value="<?php echo esc_attr( $label_size ); ?>" class="small-text" placeholder="13px">
												</td>
											</tr>
										</table>
									</div>

									<!-- Review List Date Settings (PRO) -->
									<div class="card" style="padding:20px; margin-bottom:20px; max-width:800px;">
										<h2><span class="dashicons dashicons-calendar-alt" style="vertical-align: middle;"></span> <?php esc_html_e( 'Review List Date Settings (PRO)', 'review-funnel' ); ?></h2>
										<table class="form-table">
											<tr>
												<th scope="row"><label for="wprf_show_review_date"><?php esc_html_e( 'Show Review Date on Frontend', 'review-funnel' ); ?></label></th>
												<td>
													<input type="checkbox" id="wprf_show_review_date" name="wprf_show_review_date" value="yes" <?php checked( $show_review_date, 'yes' ); ?>>
													<span class="description"><?php esc_html_e( 'Check this to display the date when reviews are listed on the front-end (format: dd.mm.yyyy).', 'review-funnel' ); ?></span>
												</td>
											</tr>
											<tr>
												<th scope="row"><label for="wprf_review_date_color"><?php esc_html_e( 'Review Date Text Color', 'review-funnel' ); ?></label></th>
												<td><input type="color" id="wprf_review_date_color" name="wprf_review_date_color" value="<?php echo esc_attr( $review_date_color ); ?>"></td>
											</tr>
											<tr>
												<th scope="row"><label for="wprf_review_date_size"><?php esc_html_e( 'Review Date Font Size', 'review-funnel' ); ?></label></th>
												<td>
													<input type="text" id="wprf_review_date_size" name="wprf_review_date_size" value="<?php echo esc_attr( $review_date_size ); ?>" class="small-text" placeholder="11px">
												</td>
											</tr>
										</table>
									</div>

									<!-- Empty Reviews Message Customization (PRO) -->
									<div class="card" style="padding:20px; margin-bottom:20px; max-width:800px;">
										<h2><span class="dashicons dashicons-editor-quote" style="vertical-align: middle;"></span> <?php esc_html_e( 'Empty Reviews Message Customization (PRO)', 'review-funnel' ); ?></h2>
										<table class="form-table">
											<tr>
												<th scope="row"><label for="wprf_no_reviews_color"><?php esc_html_e( 'Text Color', 'review-funnel' ); ?></label></th>
												<td><input type="color" id="wprf_no_reviews_color" name="wprf_no_reviews_color" value="<?php echo esc_attr( $no_reviews_color ); ?>"></td>
											</tr>
											<tr>
												<th scope="row"><label for="wprf_no_reviews_size"><?php esc_html_e( 'Font Size', 'review-funnel' ); ?></label></th>
												<td>
													<input type="text" id="wprf_no_reviews_size" name="wprf_no_reviews_size" value="<?php echo esc_attr( $no_reviews_size ); ?>" class="small-text" placeholder="15px">
												</td>
											</tr>
											<tr>
												<th scope="row"><label for="wprf_no_reviews_weight"><?php esc_html_e( 'Font Weight', 'review-funnel' ); ?></label></th>
												<td>
													<select id="wprf_no_reviews_weight" name="wprf_no_reviews_weight">
														<option value="normal" <?php selected( $no_reviews_weight, 'normal' ); ?>>Normal</option>
														<option value="bold" <?php selected( $no_reviews_weight, 'bold' ); ?>>Bold</option>
														<option value="500" <?php selected( $no_reviews_weight, '500' ); ?>>Medium (500)</option>
														<option value="600" <?php selected( $no_reviews_weight, '600' ); ?>>Semi-Bold (600)</option>
														<option value="700" <?php selected( $no_reviews_weight, '700' ); ?>>Bold (700)</option>
													</select>
												</td>
											</tr>
											<tr>
												<th scope="row"><label for="wprf_no_reviews_style"><?php esc_html_e( 'Font Style', 'review-funnel' ); ?></label></th>
												<td>
													<select id="wprf_no_reviews_style" name="wprf_no_reviews_style">
														<option value="italic" <?php selected( $no_reviews_style, 'italic' ); ?>>Italic</option>
														<option value="normal" <?php selected( $no_reviews_style, 'normal' ); ?>>Normal</option>
													</select>
												</td>
											</tr>
										</table>
									</div>

									<!-- Funnel Notifications & Messages -->
									<div class="card" style="padding:20px; margin-bottom:20px; max-width:800px;">
										<h2><span class="dashicons dashicons-testimonial" style="vertical-align: middle;"></span> <?php esc_html_e( 'Funnel Notifications & Messages', 'review-funnel' ); ?></h2>
										<table class="form-table">
											<tr>
												<th scope="row"><label for="wprf_success_msg"><?php esc_html_e( 'Standard Success Message (1-3 Stars)', 'review-funnel' ); ?></label></th>
												<td><textarea id="wprf_success_msg" name="wprf_success_msg" rows="3" class="large-text"><?php echo esc_textarea( $success_msg ); ?></textarea></td>
											</tr>
											<tr>
												<th scope="row"><label for="wprf_google_redirect_msg"><?php esc_html_e( 'Google Prompt Message (4-5 Stars)', 'review-funnel' ); ?></label></th>
												<td><textarea id="wprf_google_redirect_msg" name="wprf_google_redirect_msg" rows="3" class="large-text"><?php echo esc_textarea( $google_redirect_msg ); ?></textarea></td>
											</tr>
										</table>
									</div>
								</div>

								<!-- Przycisk zapisu podpięty pod cały formularz -->
								<p class="submit" style="margin-top: 15px;">
									<button type="submit" name="save_wprf_settings" class="button button-primary button-large"><?php esc_html_e( 'Save Settings', 'review-funnel' ); ?></button>
								</p>
							</div>
						</div>
					</div><!-- /subtab-design -->

					</form>
				</div><!-- /tab-settings -->

				<!-- --- MODIFICATION: TAB 3: TRANSLATIONS & LOCALIZATION --- -->
				<div id="tab-translations" class="tab-content-section" style="margin-top: 20px; display: none;">
					<form method="post" action="">
						<?php wp_nonce_field( 'wprf_translations_save_action', 'wprf_translations_save_nonce' ); ?>
						
						<div class="card" style="padding:20px; margin-bottom:20px; max-width:800px;">
							<h2><span class="dashicons dashicons-translation" style="vertical-align: middle;"></span> <?php esc_html_e( 'Frontend Form Translations', 'review-funnel' ); ?></h2>
							<p class="description"><?php esc_html_e( 'Customize or translate all visible text strings on the patient review funnel form.', 'review-funnel' ); ?></p>
							
							<table class="form-table">
								<tr>
									<th scope="row"><label for="wprf_t_rating_title"><?php esc_html_e( 'Rating Step Title', 'review-funnel' ); ?></label></th>
									<td><input type="text" id="wprf_t_rating_title" name="wprf_t[rating_title]" value="<?php echo esc_attr( $t_rating_title ); ?>" class="large-text"></td>
								</tr>
								<tr>
									<th scope="row"><label for="wprf_t_input_name"><?php esc_html_e( 'Name Field Label', 'review-funnel' ); ?></label></th>
									<td><input type="text" id="wprf_t_input_name" name="wprf_t[input_name]" value="<?php echo esc_attr( $t_input_name ); ?>" class="large-text"></td>
								</tr>
								<tr>
									<th scope="row"><label for="wprf_t_input_name_p"><?php esc_html_e( 'Name Field Placeholder', 'review-funnel' ); ?></label></th>
									<td><input type="text" id="wprf_t_input_name_p" name="wprf_t[input_name_placeholder]" value="<?php echo esc_attr( $t_input_name_p ); ?>" class="large-text"></td>
								</tr>
								<tr>
									<th scope="row"><label for="wprf_t_input_email"><?php esc_html_e( 'Email Field Label', 'review-funnel' ); ?></label></th>
									<td><input type="text" id="wprf_t_input_email" name="wprf_t[input_email]" value="<?php echo esc_attr( $t_input_email ); ?>" class="large-text"></td>
								</tr>
								<tr>
									<th scope="row"><label for="wprf_t_input_email_p"><?php esc_html_e( 'Email Field Placeholder', 'review-funnel' ); ?></label></th>
									<td><input type="text" id="wprf_t_input_email_p" name="wprf_t[input_email_placeholder]" value="<?php echo esc_attr( $t_input_email_p ); ?>" class="large-text"></td>
								</tr>
								<tr>
									<th scope="row"><label for="wprf_t_input_text"><?php esc_html_e( 'Feedback Field Label', 'review-funnel' ); ?></label></th>
									<td><input type="text" id="wprf_t_input_text" name="wprf_t[input_text]" value="<?php echo esc_attr( $t_input_text ); ?>" class="large-text"></td>
								</tr>
								<tr>
									<th scope="row"><label for="wprf_t_input_text_p"><?php esc_html_e( 'Feedback Field Placeholder', 'review-funnel' ); ?></label></th>
									<td><input type="text" id="wprf_t_input_text_p" name="wprf_t[input_text_placeholder]" value="<?php echo esc_attr( $t_input_text_p ); ?>" class="large-text"></td>
								</tr>
								<tr>
									<th scope="row"><label for="wprf_t_submit_btn"><?php esc_html_e( 'Submit Button Text', 'review-funnel' ); ?></label></th>
									<td><input type="text" id="wprf_t_submit_btn" name="wprf_t[submit_btn]" value="<?php echo esc_attr( $t_submit_btn ); ?>" class="large-text"></td>
								</tr>
							</table>
						</div>

						<div class="card" style="padding:20px; margin-bottom:20px; max-width:800px;">
							<h2><span class="dashicons dashicons-code-standards" style="vertical-align: middle;"></span> <?php esc_html_e( 'System Messages & Actions', 'review-funnel' ); ?></h2>
							<table class="form-table">
								<tr>
									<th scope="row"><label for="wprf_t_js_processing"><?php esc_html_e( 'Processing State Text', 'review-funnel' ); ?></label></th>
									<td><input type="text" id="wprf_t_js_processing" name="wprf_t[js_processing]" value="<?php echo esc_attr( $t_js_processing ); ?>" class="large-text"></td>
								</tr>
								<tr>
									<th scope="row"><label for="wprf_t_js_star_alert"><?php esc_html_e( 'Empty Rating Alert', 'review-funnel' ); ?></label></th>
									<td><input type="text" id="wprf_t_js_star_alert" name="wprf_t[js_star_alert]" value="<?php echo esc_attr( $t_js_star_alert ); ?>" class="large-text"></td>
								</tr>
								<tr>
									<th scope="row"><label for="wprf_t_js_error"><?php esc_html_e( 'Generic System Error Message', 'review-funnel' ); ?></label></th>
									<td><input type="text" id="wprf_t_js_error" name="wprf_t[js_error]" value="<?php echo esc_attr( $t_js_error ); ?>" class="large-text"></td>
								</tr>
								<tr>
									<th scope="row"><label for="wprf_t_google_btn"><?php esc_html_e( 'Google Redirect Button Text', 'review-funnel' ); ?></label></th>
									<td><input type="text" id="wprf_t_google_btn" name="wprf_t[google_btn]" value="<?php echo esc_attr( $t_google_btn ); ?>" class="large-text"></td>
								</tr>
								<tr>
									<th scope="row"><label for="wprf_t_clipboard_msg"><?php esc_html_e( 'Clipboard Copy Subtext Notice', 'review-funnel' ); ?></label></th>
									<td><input type="text" id="wprf_t_clipboard_msg" name="wprf_t[clipboard_msg]" value="<?php echo esc_attr( $t_clipboard_msg ); ?>" class="large-text"></td>
								</tr>
								<tr>
									<th scope="row"><label for="wprf_t_copy_btn"><?php esc_html_e( 'Copy Button Text', 'review-funnel' ); ?></label></th>
									<td><input type="text" id="wprf_t_copy_btn" name="wprf_t[copy_btn]" value="<?php echo esc_attr( $t_copy_btn ); ?>" class="large-text"></td>
								</tr>
								<tr>
									<th scope="row"><label for="wprf_t_js_copied"><?php esc_html_e( 'Copied Confirmation Text', 'review-funnel' ); ?></label></th>
									<td><input type="text" id="wprf_t_js_copied" name="wprf_t[js_copied]" value="<?php echo esc_attr( $t_js_copied ); ?>" class="large-text"></td>
								</tr>
								<tr>
									<th scope="row"><label for="wprf_t_review_single"><?php esc_html_e( 'Review Label (Single, e.g. "review" / "ocena")', 'review-funnel' ); ?></label></th>
									<td><input type="text" id="wprf_t_review_single" name="wprf_t[review_single]" value="<?php echo esc_attr( $t_review_single ); ?>" class="large-text"></td>
								</tr>
								<tr>
									<th scope="row"><label for="wprf_t_review_plural_234"><?php esc_html_e( 'Review Label (Plural 2,3,4, e.g. "reviews" / "oceny")', 'review-funnel' ); ?></label></th>
									<td><input type="text" id="wprf_t_review_plural_234" name="wprf_t[review_plural_234]" value="<?php echo esc_attr( $t_review_plural_234 ); ?>" class="large-text"></td>
								</tr>
								<tr>
									<th scope="row"><label for="wprf_t_review_plural_5plus"><?php esc_html_e( 'Review Label (Plural 5+ / 0, e.g. "reviews" / "ocen")', 'review-funnel' ); ?></label></th>
									<td><input type="text" id="wprf_t_review_plural_5plus" name="wprf_t[review_plural_5plus]" value="<?php echo esc_attr( $t_review_plural_5plus ); ?>" class="large-text"></td>
								</tr>
								<tr>
									<th scope="row"><label for="wprf_t_read_more"><?php esc_html_e( 'Read More Link Text', 'review-funnel' ); ?></label></th>
									<td><input type="text" id="wprf_t_read_more" name="wprf_t[read_more]" value="<?php echo esc_attr( $t_read_more ); ?>" class="large-text"></td>
								</tr>
								<tr>
									<th scope="row"><label for="wprf_t_read_less"><?php esc_html_e( 'Read Less Link Text', 'review-funnel' ); ?></label></th>
									<td><input type="text" id="wprf_t_read_less" name="wprf_t[read_less]" value="<?php echo esc_attr( $t_read_less ); ?>" class="large-text"></td>
								</tr>
								<tr>
									<th scope="row"><label for="wprf_t_empty_reviews_msg"><?php esc_html_e( 'Empty Reviews List Message', 'review-funnel' ); ?></label></th>
									<td>
										<input type="text" id="wprf_t_empty_reviews_msg" name="wprf_t[empty_reviews_msg]" value="<?php echo esc_attr( $t_empty_reviews ); ?>" class="large-text"><br>
										<small style="color: #666; font-style: italic;"><?php esc_html_e( 'Use {profile} to display the therapist name or profile ID.', 'review-funnel' ); ?></small>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="wprf_t_filter_all"><?php esc_html_e( 'Rating Filter "All" Button Text', 'review-funnel' ); ?></label></th>
									<td><input type="text" id="wprf_t_filter_all" name="wprf_t[filter_all]" value="<?php echo esc_attr( $t_filter_all ); ?>" class="large-text"></td>
								</tr>
							</table>
						</div>

						<p class="submit">
							<button type="submit" name="save_wprf_translations" class="button button-primary button-large"><?php esc_html_e( 'Save Translations', 'review-funnel' ); ?></button>
						</p>
					</form>
				</div>

				<!-- TAB 4: SHORTCODES GUIDE -->
				<div id="tab-shortcodes" class="tab-content-section" style="margin-top: 20px; display: none;">
					<div class="card" style="padding: 25px; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-radius: 4px; max-width:100%;">
						<h2><span class="dashicons dashicons-editor-code" style="vertical-align: middle;"></span> <?php esc_html_e( 'Plugin Deployment Shortcodes Documentation', 'review-funnel' ); ?></h2>
						<p><?php esc_html_e( 'Copy and paste these English shortcodes anywhere inside text modules or page builder code areas.', 'review-funnel' ); ?></p>
						
						<hr style="border:0; border-top:1px solid #eee; margin:20px 0;">
						
						<h3>1. Review Collection Funnel (Form)</h3>
						<p><?php esc_html_e( 'Use this shortcode to display the multi-step rating form. Any reviews submitted will be attached to the assigned profile ID.', 'review-funnel' ); ?></p>
						<p><strong><?php esc_html_e( 'Shortcode:', 'review-funnel' ); ?></strong> <code>[review_funnel]</code></p>
						<ul>
							<li><code>id</code> - <?php esc_html_e( 'Unique context string to identify the profile (e.g. id="john-smith").', 'review-funnel' ); ?></li>
							<li><strong><?php esc_html_e( 'Example:', 'review-funnel' ); ?></strong> <code>[review_funnel id="john-smith"]</code></li>
						</ul>
						
						<hr style="border:0; border-top:1px solid #eee; margin:20px 0;">
						
						<h3>2. Reviews List / Carousel Renderer</h3>
						<p><?php esc_html_e( 'Use this shortcode to display approved reviews on your frontend. You can toggle between a classic Grid card layout and an interactive Slider (Carousel) layout.', 'review-funnel' ); ?></p>
						<p><strong><?php esc_html_e( 'Shortcode:', 'review-funnel' ); ?></strong> <code>[review_list]</code></p>
						
						<table class="widefat" style="margin-top: 15px; margin-bottom: 20px; max-width: 100%;">
							<thead>
								<tr>
									<th style="width: 20%;">Attribute</th>
									<th style="width: 35%;">Values & Description</th>
									<th style="width: 45%;">Usage Examples</th>
								</tr>
							</thead>
							<tbody>
								<tr>
									<td><strong>id</strong></td>
									<td>Filter reviews by profile ID. Supports multiple comma-separated IDs (e.g. <code>id="john-smith, main"</code>). Leave empty to display all.</td>
									<td><code>[review_list id="john-smith"]</code></td>
								</tr>
								<tr>
									<td><strong>name</strong></td>
									<td>Custom profile/therapist name. Used in the empty reviews message when the profile has no reviews yet.</td>
									<td><code>[review_list id="john-smith" name="John Smith"]</code></td>
								</tr>
								<tr>
									<td><strong>count</strong></td>
									<td>Maximum number of reviews to load. Default is <code>6</code>.</td>
									<td><code>[review_list count="100"]</code></td>
								</tr>
								<tr>
									<td><strong>columns</strong></td>
									<td>Number of columns visible at once on desktop. Supported values: <code>1, 2, 3, 4</code>. Default is <code>3</code>.</td>
									<td><code>[review_list columns="3"]</code></td>
								</tr>
								<tr>
									<td><strong>layout</strong></td>
									<td>Layout representation type. Allowed values:<br>
										- <code>grid</code> (Default: displays cards in a responsive grid)<br>
										- <code>slider</code> (Displays cards in a touch-swipeable horizontal carousel)
									</td>
									<td><code>[review_list layout="slider"]</code></td>
								</tr>
								<tr>
									<td><strong>autoplay</strong></td>
									<td>Autoplay interval duration in milliseconds for the slider layout. Set to <code>0</code> to disable. Default is <code>0</code>.</td>
									<td><code>[review_list layout="slider" autoplay="4000"]</code> <small>(Scrolls every 4s)</small></td>
								</tr>
								<tr>
									<td><strong>arrows</strong></td>
									<td>Show left/right navigation arrows for the slider layout. Allowed values: <code>true</code> (default), <code>false</code>.</td>
									<td><code>[review_list layout="slider" arrows="false"]</code></td>
								</tr>
								<tr>
									<td><strong>dots</strong></td>
									<td>Show navigation/pagination bullets at the bottom for the slider layout. Allowed values: <code>true</code> (default), <code>false</code>.</td>
									<td><code>[review_list layout="slider" dots="true"]</code></td>
								</tr>
								<tr>
									<td><strong>show_date</strong></td>
									<td>Show review date inside review cards. Allowed values: <code>true</code> / <code>yes</code>, <code>false</code> / <code>no</code>. Overrides the global setting.</td>
									<td><code>[review_list show_date="false"]</code></td>
								</tr>
								<tr>
									<td><strong>filters</strong></td>
									<td>Enable or disable rating filter buttons (Wszystkie, 5 ★, 4 ★, 3 ★, 2 ★, 1 ★) above Grid and Masonry layouts. Allowed values: <code>true</code>, <code>false</code>.</td>
									<td><code>[review_list filters="true"]</code> or <code>[review_list filters="false"]</code></td>
								</tr>
								<tr>
									<td><strong>char_limit</strong></td>
									<td>Limit the visible review text length to a specific number of characters. Displays an interactive "read more" link for long reviews. Set to <code>0</code> to disable truncation. Default is <code>180</code>.</td>
									<td><code>[review_list char_limit="150"]</code></td>
								</tr>
							</tbody>
						</table>

						<p><strong><?php esc_html_e( 'Comprehensive Slider Example:', 'review-funnel' ); ?></strong><br>
						<code>[review_list id="john-smith, main" name="John Smith" layout="slider" columns="3" count="100" autoplay="4500" arrows="true" dots="true" show_date="true" char_limit="150"]</code></p>
						
						<hr style="border:0; border-top:1px solid #eee; margin:20px 0;">

						<h3>3. Average Rating Badge</h3>
						<p><?php esc_html_e( 'Use this shortcode to display only the average star rating and total count (e.g. for listing/bio headers), without actual review text.', 'review-funnel' ); ?></p>
						<p><strong><?php esc_html_e( 'Shortcode variations:', 'review-funnel' ); ?></strong> <code>[review_badge]</code> <?php esc_html_e( 'or', 'review-funnel' ); ?> <code>[average_rating]</code></p>
						<ul>
							<li><code>id</code> - <?php esc_html_e( 'Filter reviews by profile ID. Supports multiple comma-separated IDs (e.g. id="john-smith, main"). Leave empty to calculate average for all reviews.', 'review-funnel' ); ?></li>
							<li><code>star_color</code> - <?php esc_html_e( 'Hex color of stars (Default: #ffcc00).', 'review-funnel' ); ?></li>
							<li><code>text_color</code> - <?php esc_html_e( 'Hex color of text (Default: #007a78).', 'review-funnel' ); ?></li>
							<li><code>font_size</code> - <?php esc_html_e( 'Font size value (Default: 20px).', 'review-funnel' ); ?></li>
							<li><code>show_count</code> - <?php esc_html_e( 'Toggle reviews count visibility next to stars (true / false). Default is true.', 'review-funnel' ); ?></li>
							<li><strong><?php esc_html_e( 'Example:', 'review-funnel' ); ?></strong> <code>[average_rating id="john-smith" star_color="#ffbb00" font_size="16px" show_count="false"]</code></li>
						</ul>
						<p><em>* <?php esc_html_e( 'Note: Just like the reviews list, if the therapist has 0 reviews, this badge will automatically return nothing to hide the section cleanly.', 'review-funnel' ); ?></em></p>
					</div>
				</div><!-- /tab-shortcodes -->

				<!-- TAB 5: SUPPORT & HELP -->
				<div id="tab-support" class="tab-content-section" style="margin-top: 20px; display: none;">
					
					<!-- 1. SYSTEM STATUS & DIAGNOSTIC REPORT CARD -->
					<div class="card" style="padding: 25px; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-radius: 4px; max-width:800px; margin-bottom: 20px;">
						<h2><span class="dashicons dashicons-dashboard" style="vertical-align: middle;"></span> <?php esc_html_e( 'System Status & Diagnostics', 'review-funnel' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Technical details about your server environment and active plugin integrations.', 'review-funnel' ); ?></p>
						
						<table class="widefat striped" style="margin-top: 15px; max-width: 100%;">
							<tbody>
								<tr>
									<td><strong>Plugin Version</strong></td>
									<td><code>Review Funnel Plugin v1.0</code></td>
								</tr>
								<tr>
									<td><strong>WordPress & PHP Version</strong></td>
									<td>WordPress <?php echo esc_html( get_bloginfo( 'version' ) ); ?> | PHP <?php echo esc_html( phpversion() ); ?></td>
								</tr>
								<tr>
									<td><strong>Database Table</strong></td>
									<td>
										<?php
										$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$this->table_name}'" ) === $this->table_name;
										if ( $table_exists ) {
											$review_count = intval( $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_name}" ) );
											echo '<span style="color:green; font-weight:bold;">✔ Connected</span> (' . esc_html( $this->table_name ) . ' - ' . $review_count . ' reviews)';
										} else {
											echo '<span style="color:red; font-weight:bold;">✖ Table Missing!</span>';
										}
										?>
									</td>
								</tr>
								<tr>
									<td><strong>cURL & OpenSSL Support</strong></td>
									<td>
										<?php
										if ( function_exists( 'curl_version' ) && extension_loaded( 'openssl' ) ) {
											echo '<span style="color:green; font-weight:bold;">✔ Available</span> (Required for Google API & MailerLite API)';
										} else {
											echo '<span style="color:orange; font-weight:bold;">⚠ Limited</span> (cURL or OpenSSL extension missing)';
										}
										?>
									</td>
								</tr>
								<tr>
									<td><strong>Google Direct Review URL (Keyless Lejek)</strong></td>
									<td>
										<?php if ( ! empty( $direct_url ) ) : ?>
											<span style="color:green; font-weight:bold;">✔ Configured</span> (<code><?php echo esc_html( substr( $direct_url, 0, 45 ) ); ?>...</code>)
										<?php else : ?>
											<span style="color:#666;">Not set</span>
										<?php endif; ?>
									</td>
								</tr>
								<tr>
									<td><strong>Google Places API Key</strong></td>
									<td>
										<?php if ( ! empty( $api_key ) ) : ?>
											<span style="color:green; font-weight:bold;">✔ Configured</span> (<code><?php echo esc_html( substr( $api_key, 0, 6 ) . '...' ); ?></code>)
										<?php else : ?>
											<span style="color:#666;">Not set</span>
										<?php endif; ?>
									</td>
								</tr>
								<tr>
									<td><strong>Google Place ID</strong></td>
									<td>
										<?php if ( ! empty( $place_id ) ) : ?>
											<span style="color:green; font-weight:bold;">✔ Configured</span> (<code><?php echo esc_html( $place_id ); ?></code>)
										<?php else : ?>
											<span style="color:#666;">Not set</span>
										<?php endif; ?>
									</td>
								</tr>
								<tr>
									<td><strong>MailerLite Integration (v3)</strong></td>
									<td>
										<?php if ( ! empty( $mailerlite_api_key ) ) : ?>
											<span style="color:green; font-weight:bold;">✔ Configured</span> (API Key active)
										<?php else : ?>
											<span style="color:#666;">Disabled / Not set</span>
										<?php endif; ?>
									</td>
								</tr>
								<tr>
									<td><strong>Cloudflare Turnstile Protection</strong></td>
									<td>
										<?php if ( 'yes' === $enable_turnstile && ! empty( $turnstile_site_key ) ) : ?>
											<span style="color:green; font-weight:bold;">✔ Enabled</span>
										<?php else : ?>
											<span style="color:#666;">Disabled</span>
										<?php endif; ?>
									</td>
								</tr>
							</tbody>
						</table>
					</div>

					<!-- 1B. RECENT EMAIL LOGS CARD -->
					<div class="card" style="padding: 25px; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-radius: 4px; max-width:800px; margin-bottom: 20px;">
						<h2><span class="dashicons dashicons-list-view" style="vertical-align: middle;"></span> <?php esc_html_e( 'Plugin Email Delivery Logs', 'review-funnel' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Recent outgoing wp_mail() delivery attempts logged by Review Funnel Plugin.', 'review-funnel' ); ?></p>
						
						<table class="widefat striped" style="margin-top: 15px;">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Date & Time', 'review-funnel' ); ?></th>
									<th><?php esc_html_e( 'Recipient', 'review-funnel' ); ?></th>
									<th><?php esc_html_e( 'Subject', 'review-funnel' ); ?></th>
									<th><?php esc_html_e( 'Status / Result', 'review-funnel' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php
								$mail_logs = get_option( 'wprf_mail_logs', array() );
								if ( ! empty( $mail_logs ) && is_array( $mail_logs ) ) :
									foreach ( $mail_logs as $log ) :
								?>
									<tr>
										<td><code><?php echo esc_html( $log['timestamp'] ); ?></code></td>
										<td><?php echo esc_html( $log['recipient'] ); ?></td>
										<td><?php echo esc_html( $log['subject'] ); ?></td>
										<td>
											<?php if ( 'SUCCESS' === $log['status'] ) : ?>
												<span style="color:#00a32a; font-weight:bold;">✔ Sent (SUCCESS)</span>
											<?php else : ?>
												<span style="color:#d63638; font-weight:bold;">✖ Failed: <?php echo esc_html( $log['error'] ); ?></span>
											<?php endif; ?>
										</td>
									</tr>
								<?php
									endforeach;
								else :
								?>
									<tr>
										<td colspan="4" style="color:#666; font-style:italic;"><?php esc_html_e( 'No plugin email attempts logged yet.', 'review-funnel' ); ?></td>
									</tr>
								<?php endif; ?>
							</tbody>
						</table>
					</div>

					<!-- 2. STEP-BY-STEP GOOGLE SETUP GUIDE CARD -->
					<div class="card" style="padding: 25px; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-radius: 4px; max-width:800px; margin-bottom: 20px;">
						<h2><span class="dashicons dashicons-info" style="vertical-align: middle;"></span> <?php esc_html_e( 'How to Find Google API Key, Place ID & Direct Link', 'review-funnel' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Step-by-step instructions for linking your Google Business Profile to the Review Funnel plugin.', 'review-funnel' ); ?></p>
						
						<hr style="border:0; border-top:1px solid #eee; margin:15px 0;">

						<h3 style="margin-bottom: 5px;">1. Google Direct Review URL (Keyless Lejek - Easiest & Recommended)</h3>
						<p style="margin-top: 0; color: #444;">
							The Direct Review URL allows satisfied clients (4-5 stars) to be redirected straight to your Google Maps review pop-up without needing a Google API key.
						</p>
						<ol style="margin-left: 20px; line-height: 1.6;">
							<li>Go to <a href="https://www.google.com/maps" target="_blank" rel="noopener">Google Maps</a> and search for your business/clinic name.</li>
							<li>Click on your business profile, then click the <strong>"Ask for reviews"</strong> or <strong>"Share review form"</strong> button.</li>
							<li>Copy the short review link provided by Google (e.g., <code>https://g.page/r/.../review</code> or <code>https://maps.app.goo.gl/...</code>).</li>
							<li>Paste this link into <strong>Settings & Customization &gt; Google API &amp; Redirects &gt; Google Direct Review URL</strong>.</li>
						</ol>

						<hr style="border:0; border-top:1px solid #eee; margin:20px 0;">

						<h3 style="margin-bottom: 5px;">2. How to Find your Google Place ID</h3>
						<p style="margin-top: 0; color: #444;">
							The Google Place ID is a unique string that identifies your business location in Google Cloud.
						</p>
						<ol style="margin-left: 20px; line-height: 1.6;">
							<li>Open the official <a href="https://developers.google.com/maps/documentation/places/web-service/place-id" target="_blank" rel="noopener">Google Place ID Finder Tool</a>.</li>
							<li>In the search box on the map, type your business or clinic name and address.</li>
							<li>Click your business in the dropdown list.</li>
							<li>A pop-up will appear displaying your <strong>Place ID</strong> (an alphanumeric code starting with <code>ChIJ...</code>).</li>
							<li>Copy the code and paste it into <strong>Settings & Customization &gt; Google API &amp; Redirects &gt; Google Place ID</strong>.</li>
						</ol>

						<hr style="border:0; border-top:1px solid #eee; margin:20px 0;">

						<h3 style="margin-bottom: 5px;">3. How to Obtain a Google Places API Key</h3>
						<p style="margin-top: 0; color: #444;">
							Required if you want the plugin to automatically fetch and display existing Google Maps reviews on your website.
						</p>
						<ol style="margin-left: 20px; line-height: 1.6;">
							<li>Log in to the <a href="https://console.cloud.google.com/" target="_blank" rel="noopener">Google Cloud Console</a>.</li>
							<li>Create a new project or select an existing one.</li>
							<li>Navigate to <strong>APIs &amp; Services &gt; Library</strong> and enable <strong>Places API</strong>.</li>
							<li>Go to <strong>APIs &amp; Services &gt; Credentials</strong>, click <strong>Create Credentials</strong>, and select <strong>API key</strong>.</li>
							<li>Copy your newly created API key and paste it into <strong>Settings & Customization &gt; Google API &amp; Redirects &gt; Google API Key</strong>.</li>
						</ol>
					</div>

					<!-- 3. FAQ CARD -->
					<div class="card" style="padding: 25px; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-radius: 4px; max-width:800px; margin-bottom: 20px;">
						<h2><span class="dashicons dashicons-help" style="vertical-align: middle;"></span> <?php esc_html_e( 'Frequently Asked Questions (FAQ)', 'review-funnel' ); ?></h2>
						
						<p><strong><?php esc_html_e( 'Q: How does the Direct Google Link (Keyless Lejek) work?', 'review-funnel' ); ?></strong><br>
						<?php esc_html_e( 'A: If you provide a direct Google review link in Settings > Google API & Redirects, clients rating 4-5 stars will be sent directly to your Google Maps review form without requiring a Google Places API key.', 'review-funnel' ); ?></p>
						
						<p><strong><?php esc_html_e( 'Q: How do I display reviews on my site?', 'review-funnel' ); ?></strong><br>
						<?php esc_html_e( 'A: Use the [review_list] shortcode in any page or post. Check the Shortcodes Guide tab for all layout options (Grid, Touch Slider, Masonry).', 'review-funnel' ); ?></p>

						<p><strong><?php esc_html_e( 'Q: What happens to 1-3 star reviews?', 'review-funnel' ); ?></strong><br>
						<?php esc_html_e( 'A: Constructive feedback (1-3 stars) is captured internally in your WordPress database and emailed directly to your specified admin notification email addresses.', 'review-funnel' ); ?></p>
					</div>

					<!-- 4. CONTACT SUPPORT & SEND DIAGNOSTIC REPORT FORM -->
					<div class="card" style="padding: 25px; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-radius: 4px; max-width:800px;">
						<h2><span class="dashicons dashicons-email-alt" style="vertical-align: middle;"></span> <?php esc_html_e( 'Contact Support & Send Diagnostic Report', 'review-funnel' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Send a support ticket and full server diagnostic report directly to technical support.', 'review-funnel' ); ?></p>
						
						<form method="post" action="">
							<?php wp_nonce_field( 'wprf_support_send_action', 'wprf_support_send_nonce' ); ?>
							
							<table class="form-table">
								<tr>
									<th scope="row"><label for="wprf_support_recipient"><?php esc_html_e( 'Support Email Address', 'review-funnel' ); ?></label></th>
									<td>
										<input type="email" id="wprf_support_recipient" name="wprf_support_recipient" value="lechwyzewski@gmail.com" class="regular-text" required>
										<p class="description"><?php esc_html_e( 'Default support recipient email (default: lechwyzewski@gmail.com).', 'review-funnel' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="wprf_support_reply_to"><?php esc_html_e( 'Your Reply-to Email', 'review-funnel' ); ?></label></th>
									<td>
										<input type="email" id="wprf_support_reply_to" name="wprf_support_reply_to" value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" class="regular-text" required>
										<p class="description"><?php esc_html_e( 'Your email address so support can reply back to you.', 'review-funnel' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="wprf_support_message"><?php esc_html_e( 'Message / Description of Issue', 'review-funnel' ); ?></label></th>
									<td>
										<textarea id="wprf_support_message" name="wprf_support_message" rows="4" class="large-text" placeholder="<?php esc_attr_e( 'Describe your question or technical issue...', 'review-funnel' ); ?>"></textarea>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="wprf_include_diagnostics"><?php esc_html_e( 'Attach System Report', 'review-funnel' ); ?></label></th>
									<td>
										<label>
											<input type="checkbox" id="wprf_include_diagnostics" name="wprf_include_diagnostics" value="yes" checked>
											<?php esc_html_e( 'Include System Status & Server Diagnostic Info (PHP version, Database table, Google API status, etc.)', 'review-funnel' ); ?>
										</label>
									</td>
								</tr>
							</table>

							<textarea id="wprf_raw_diag_report" style="display:none;"><?php
$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$this->table_name}'" ) === $this->table_name;
$review_count = $table_exists ? intval( $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_name}" ) ) : 0;
echo "--- System Diagnostics Report ---\n";
echo "Website URL: " . esc_html( get_site_url() ) . "\n";
echo "Plugin Version: Review Funnel Plugin v1.0\n";
echo "WordPress Version: " . esc_html( get_bloginfo( 'version' ) ) . "\n";
echo "PHP Version: " . esc_html( phpversion() ) . "\n";
echo "Database Table: " . ( $table_exists ? "Connected (" . $review_count . " reviews)" : "Missing Table" ) . "\n";
echo "cURL Extension: " . ( function_exists( 'curl_version' ) ? "Available" : "Missing" ) . "\n";
echo "OpenSSL Extension: " . ( extension_loaded( 'openssl' ) ? "Available" : "Missing" ) . "\n";
echo "Google Direct URL: " . ( ! empty( $direct_url ) ? "Configured (" . esc_html( $direct_url ) . ")" : "Not set" ) . "\n";
echo "Google Places API Key: " . ( ! empty( $api_key ) ? "Configured (" . esc_html( substr( $api_key, 0, 6 ) ) . "...)" : "Not set" ) . "\n";
echo "Google Place ID: " . ( ! empty( $place_id ) ? "Configured (" . esc_html( $place_id ) . ")" : "Not set" ) . "\n";
echo "MailerLite API Key: " . ( ! empty( $mailerlite_api_key ) ? "Configured" : "Not set" ) . "\n";
echo "Cloudflare Turnstile: " . ( 'yes' === $enable_turnstile ? "Enabled" : "Disabled" ) . "\n";
?></textarea>

							<p class="submit" style="margin-top: 15px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
								<button type="submit" name="send_wprf_support_email" class="button button-primary button-large">
									<span class="dashicons dashicons-send" style="vertical-align: middle; margin-right: 4px;"></span>
									<?php esc_html_e( 'Send Support Request & Diagnostic Report', 'review-funnel' ); ?>
								</button>
								<button type="button" id="wprf_copy_diag_btn" class="button button-secondary button-large" style="font-weight: 600;">
									<span class="dashicons dashicons-clipboard" style="vertical-align: middle; margin-right: 4px;"></span>
									<?php esc_html_e( 'Copy Diagnostic Report to Clipboard', 'review-funnel' ); ?>
								</button>
								<a href="mailto:lechwyzewski@gmail.com?subject=Review%20Funnel%20Support%20Request" class="button button-secondary button-large" style="font-weight: 600; text-decoration: none;">
									<span class="dashicons dashicons-email" style="vertical-align: middle; margin-right: 4px;"></span>
									<?php esc_html_e( 'Open Direct Email App', 'review-funnel' ); ?>
								</a>
							</p>
						</form>
					</div>
				</div>

				<!-- GRADIENT GENERATOR MODAL -->
				<div id="wprf-gradient-modal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.6); z-index:999999; align-items:center; justify-content:center; padding:15px; box-sizing:border-box;">
					<div style="background:#ffffff; max-width:480px; width:100%; border-radius:12px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04); display:flex; flex-direction:column; overflow:hidden;">
						<!-- Header -->
						<div style="background:#f8fafc; padding:15px 20px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; box-sizing:border-box;">
							<h3 style="margin:0; font-size:16px; font-weight:700; color:#1e293b;"><?php esc_html_e( '🎨 CSS Gradient Generator (PRO)', 'review-funnel' ); ?></h3>
							<button type="button" id="wprf-close-gradient-btn" style="background:transparent; border:none; color:#94a3b8; font-size:24px; line-height:1; cursor:pointer; padding:0; outline:none;">&times;</button>
						</div>
						
						<!-- Body -->
						<div style="padding:20px; display:flex; flex-direction:column; gap:15px; box-sizing:border-box;">
							<!-- Live Preview -->
							<div>
								<label style="display:block; font-weight:600; font-size:12px; color:#475569; margin-bottom:8px;"><?php esc_html_e( 'Live Preview', 'review-funnel' ); ?></label>
								<div id="wprf-gradient-preview" style="height:100px; border-radius:8px; border:1px solid #cbd5e1; display:flex; align-items:center; justify-content:center; color:#ffffff; font-weight:bold; text-shadow:0 1px 3px rgba(0,0,0,0.4); box-shadow:inset 0 2px 4px rgba(0,0,0,0.06);">
									<?php esc_html_e( 'Preview Area', 'review-funnel' ); ?>
								</div>
							</div>

							<!-- Controls -->
							<div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
								<div>
									<label style="display:block; font-weight:600; font-size:12px; color:#475569; margin-bottom:5px;"><?php esc_html_e( 'Color Stop 1', 'review-funnel' ); ?></label>
									<div style="display:flex; gap:6px;">
										<input type="color" id="wprf-grad-c1" value="#667eea" style="width:40px; height:32px; padding:0; border:1px solid #cbd5e1; border-radius:4px; cursor:pointer;">
										<input type="text" id="wprf-grad-c1-hex" value="#667eea" style="width:75px; height:32px; font-size:12px; padding:4px; border:1px solid #cbd5e1; border-radius:4px; text-transform:uppercase; box-sizing:border-box;">
									</div>
								</div>
								<div>
									<label style="display:block; font-weight:600; font-size:12px; color:#475569; margin-bottom:5px;"><?php esc_html_e( 'Color Stop 2', 'review-funnel' ); ?></label>
									<div style="display:flex; gap:6px;">
										<input type="color" id="wprf-grad-c2" value="#764ba2" style="width:40px; height:32px; padding:0; border:1px solid #cbd5e1; border-radius:4px; cursor:pointer;">
										<input type="text" id="wprf-grad-c2-hex" value="#764ba2" style="width:75px; height:32px; font-size:12px; padding:4px; border:1px solid #cbd5e1; border-radius:4px; text-transform:uppercase; box-sizing:border-box;">
									</div>
								</div>
							</div>

							<!-- Angle control -->
							<div>
								<div style="display:flex; justify-content:space-between; margin-bottom:5px;">
									<label style="font-weight:600; font-size:12px; color:#475569;"><?php esc_html_e( 'Gradient Angle', 'review-funnel' ); ?></label>
									<span id="wprf-grad-angle-val" style="font-size:12px; font-weight:bold; color:#475569;">135°</span>
								</div>
								<input type="range" id="wprf-grad-angle" min="0" max="360" value="135" style="width:100%; margin:0; cursor:pointer; display:block;">
							</div>

							<!-- Presets -->
							<div>
								<label style="display:block; font-weight:600; font-size:12px; color:#475569; margin-bottom:8px;"><?php esc_html_e( 'Premium Presets', 'review-funnel' ); ?></label>
								<div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:8px;">
									<button type="button" class="wprf-grad-preset" data-g="linear-gradient(135deg, #f6d365 0%, #fda085 100%)" style="height:32px; border-radius:6px; border:1px solid #cbd5e1; cursor:pointer; background:linear-gradient(135deg, #f6d365 0%, #fda085 100%); outline:none;" title="Sunset Warmth"></button>
									<button type="button" class="wprf-grad-preset" data-g="linear-gradient(135deg, #0b3c5d 0%, #328cc1 100%)" style="height:32px; border-radius:6px; border:1px solid #cbd5e1; cursor:pointer; background:linear-gradient(135deg, #0b3c5d 0%, #328cc1 100%); outline:none;" title="Deep Ocean"></button>
									<button type="button" class="wprf-grad-preset" data-g="linear-gradient(135deg, #11998e 0%, #38ef7d 100%)" style="height:32px; border-radius:6px; border:1px solid #cbd5e1; cursor:pointer; background:linear-gradient(135deg, #11998e 0%, #38ef7d 100%); outline:none;" title="Emerald Glow"></button>
									<button type="button" class="wprf-grad-preset" data-g="linear-gradient(135deg, #667eea 0%, #764ba2 100%)" style="height:32px; border-radius:6px; border:1px solid #cbd5e1; cursor:pointer; background:linear-gradient(135deg, #667eea 0%, #764ba2 100%); outline:none;" title="Royal Plum"></button>
									<button type="button" class="wprf-grad-preset" data-g="linear-gradient(135deg, #fdfbfb 0%, #ebedee 100%)" style="height:32px; border-radius:6px; border:1px solid #cbd5e1; cursor:pointer; background:linear-gradient(135deg, #fdfbfb 0%, #ebedee 100%); outline:none;" title="Clean Glass"></button>
									<button type="button" class="wprf-grad-preset" data-g="linear-gradient(135deg, #2c3e50 0%, #3498db 100%)" style="height:32px; border-radius:6px; border:1px solid #cbd5e1; cursor:pointer; background:linear-gradient(135deg, #2c3e50 0%, #3498db 100%); outline:none;" title="Modern Slate"></button>
								</div>
							</div>
						</div>
						
						<!-- Footer -->
						<div style="background:#f8fafc; padding:12px 20px; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; gap:10px; box-sizing:border-box;">
							<button type="button" id="wprf-cancel-gradient-btn" class="button button-secondary"><?php esc_html_e( 'Cancel', 'review-funnel' ); ?></button>
							<button type="button" id="wprf-apply-gradient-btn" class="button button-primary"><?php esc_html_e( 'Apply Gradient', 'review-funnel' ); ?></button>
						</div>
					</div>
				</div>
			</div>

			<script>
			window.wprfSwitchTab = function(targetId) {
				var allMainTabIds = ['tab-reviews', 'tab-settings', 'tab-translations', 'tab-shortcodes', 'tab-support'];
				if (allMainTabIds.indexOf(targetId) === -1) {
					targetId = 'tab-reviews';
				}

				allMainTabIds.forEach(function(id) {
					var el = document.getElementById(id);
					if (el) {
						el.style.display = (id === targetId) ? 'block' : 'none';
					}
					var link = document.querySelector('.nav-tab-wrapper .nav-tab[data-tab="' + id + '"]');
					if (link) {
						if (id === targetId) {
							link.classList.add('nav-tab-active');
						} else {
							link.classList.remove('nav-tab-active');
						}
					}
				});

				try {
					sessionStorage.setItem('wprf_active_tab', targetId);
				} catch(e) {}
			};

			window.wprfSwitchSubtab = function(targetSubtab) {
				var allSubtabIds = ['subtab-google', 'subtab-privacy', 'subtab-email', 'subtab-spam', 'subtab-mailerlite', 'subtab-design'];
				if (allSubtabIds.indexOf(targetSubtab) === -1) {
					targetSubtab = 'subtab-google';
				}

				allSubtabIds.forEach(function(id) {
					var el = document.getElementById(id);
					if (el) {
						el.style.display = (id === targetSubtab) ? 'block' : 'none';
					}
					var btn = document.querySelector('.wprf-subnav-bar .wprf-subnav-btn[data-subtab="' + id + '"]');
					if (btn) {
						if (id === targetSubtab) {
							btn.classList.remove('button-secondary');
							btn.classList.add('button-primary');
						} else {
							btn.classList.remove('button-primary');
							btn.classList.add('button-secondary');
						}
					}
				});

				try {
					sessionStorage.setItem('wprf_active_subtab', targetSubtab);
				} catch(e) {}
			};

			document.addEventListener('DOMContentLoaded', function() {
				var allMainTabIds = ['tab-reviews', 'tab-settings', 'tab-translations', 'tab-shortcodes', 'tab-support'];
				var startTab = 'tab-reviews';
				if (window.location.hash) {
					var hash = window.location.hash.replace('#', '');
					if (allMainTabIds.indexOf(hash) !== -1) {
						startTab = hash;
					}
				} else {
					try {
						var savedTab = sessionStorage.getItem('wprf_active_tab');
						if (savedTab && allMainTabIds.indexOf(savedTab) !== -1) {
							startTab = savedTab;
						}
					} catch(e) {}
				}
				window.wprfSwitchTab(startTab);

				var allSubtabIds = ['subtab-google', 'subtab-privacy', 'subtab-email', 'subtab-spam', 'subtab-mailerlite', 'subtab-design'];
				var startSubtab = 'subtab-google';
				try {
					var savedSub = sessionStorage.getItem('wprf_active_subtab');
					if (savedSub && allSubtabIds.indexOf(savedSub) !== -1) {
						startSubtab = savedSub;
					}
				} catch(e) {}
				window.wprfSwitchSubtab(startSubtab);

				// Restructured Appearance Sub-navigation vertical sidebar switching
				const sidebarItems = document.querySelectorAll('.wprf-appearance-menu-item');
				const panes = document.querySelectorAll('.wprf-appearance-section-pane');

				sidebarItems.forEach(item => {
					item.addEventListener('click', function(e) {
						e.preventDefault();
						
						sidebarItems.forEach(i => i.classList.remove('active'));
						panes.forEach(pane => pane.style.display = 'none');
						
						this.classList.add('active');
						
						const targetSectionId = this.getAttribute('data-appearance-section');
						const targetPane = document.getElementById(targetSectionId);
						if (targetPane) {
							targetPane.style.display = 'block';
						}
						
						try {
							sessionStorage.setItem('wprf_active_appearance_section', targetSectionId);
						} catch(e) {}
					});
				});

				// Restore active appearance section from sessionStorage
				try {
					const savedSection = sessionStorage.getItem('wprf_active_appearance_section');
					if (savedSection) {
						const activeItem = document.querySelector(`.wprf-appearance-menu-item[data-appearance-section="${savedSection}"]`);
						if (activeItem) {
							sidebarItems.forEach(i => i.classList.remove('active'));
							panes.forEach(pane => pane.style.display = 'none');
							
							activeItem.classList.add('active');
							const targetPane = document.getElementById(savedSection);
							if (targetPane) {
								targetPane.style.display = 'block';
							}
						}
					}
				} catch(e) {}
				// Copy System Diagnostic Report to Clipboard
				const copyDiagBtn = document.getElementById('wprf_copy_diag_btn');
				if (copyDiagBtn) {
					copyDiagBtn.addEventListener('click', function(e) {
						e.preventDefault();
						const msg = document.getElementById('wprf_support_message') ? document.getElementById('wprf_support_message').value : '';
						const diagText = document.getElementById('wprf_raw_diag_report') ? document.getElementById('wprf_raw_diag_report').value : '';
						const fullText = "Review Funnel Support Request & Diagnostic Report\n==============================================\n" + (msg ? "User Message:\n" + msg + "\n\n" : "") + diagText;
						
						if (navigator.clipboard && navigator.clipboard.writeText) {
							navigator.clipboard.writeText(fullText).then(function() {
								copyDiagBtn.textContent = '✔ Copied to Clipboard!';
								setTimeout(function() {
									copyDiagBtn.innerHTML = '<span class="dashicons dashicons-clipboard" style="vertical-align: middle; margin-right: 4px;"></span> Copy Diagnostic Report to Clipboard';
								}, 2500);
							});
						} else {
							alert("Diagnostic Report:\n\n" + fullText);
						}
					});
				}

				// Media Uploader for Custom Anonymous Avatar Icon
				const uploadAvatarBtn = document.getElementById('wprf_upload_avatar_btn');
				const customAvatarInput = document.getElementById('wprf_custom_avatar_url');
				if (uploadAvatarBtn && customAvatarInput) {
					uploadAvatarBtn.addEventListener('click', function(e) {
						e.preventDefault();
						if (typeof wp !== 'undefined' && wp.media) {
							let frame = wp.media({
								title: 'Select Custom Anonymous Avatar Icon',
								button: { text: 'Use as Default Avatar' },
								multiple: false
							});
							frame.on('select', function() {
								let attachment = frame.state().get('selection').first().toJSON();
								customAvatarInput.value = attachment.url;
							});
							frame.open();
						}
					});
				}

				// Select All bulk checkboxes toggle
				const selectAll = document.getElementById('wprf-select-all-checkbox');
				const rowCheckboxes = document.querySelectorAll('.wprf-row-checkbox');
				if (selectAll) {
					selectAll.addEventListener('change', function() {
						rowCheckboxes.forEach(cb => {
							cb.checked = selectAll.checked;
						});
					});
				}

				// Link background color picker and text field
				const bgPicker = document.getElementById('wprf_form_bg_color_picker');
				const bgInput = document.getElementById('wprf_form_bg_color');
				if (bgPicker && bgInput) {
					bgPicker.addEventListener('input', function() {
						bgInput.value = this.value;
					});
					bgInput.addEventListener('input', function() {
						const val = this.value.trim();
						if (/^#[0-9A-F]{6}$/i.test(val)) {
							bgPicker.value = val;
						}
					});
				}

				// Link button background color picker and text field
				const btnBgPicker = document.getElementById('wprf_btn_color_picker');
				const btnBgInput = document.getElementById('wprf_btn_color');
				if (btnBgPicker && btnBgInput) {
					btnBgPicker.addEventListener('input', function() {
						btnBgInput.value = this.value;
					});
					btnBgInput.addEventListener('input', function() {
						const val = this.value.trim();
						if (/^#[0-9A-F]{6}$/i.test(val)) {
							btnBgPicker.value = val;
						}
					});
				}

				// --- Gradient Generator Modal logic ---
				const gradModal = document.getElementById('wprf-gradient-modal');
				const gradPreview = document.getElementById('wprf-gradient-preview');
				const gradC1 = document.getElementById('wprf-grad-c1');
				const gradC1Hex = document.getElementById('wprf-grad-c1-hex');
				const gradC2 = document.getElementById('wprf-grad-c2');
				const gradC2Hex = document.getElementById('wprf-grad-c2-hex');
				const gradAngle = document.getElementById('wprf-grad-angle');
				const gradAngleVal = document.getElementById('wprf-grad-angle-val');
				const applyGradBtn = document.getElementById('wprf-apply-gradient-btn');
				const cancelGradBtn = document.getElementById('wprf-cancel-gradient-btn');
				const closeGradBtn = document.getElementById('wprf-close-gradient-btn');
				
				let currentTargetInputId = '';

				function updateGradientPreview() {
					if (!gradC1 || !gradC2 || !gradAngle || !gradPreview || !gradAngleVal) {
						return;
					}
					const c1 = gradC1.value;
					const c2 = gradC2.value;
					const angle = gradAngle.value;
					const css = `linear-gradient(${angle}deg, ${c1} 0%, ${c2} 100%)`;
					gradPreview.style.background = css;
					gradAngleVal.textContent = `${angle}°`;
				}

				if (gradC1 && gradC1Hex) {
					gradC1.addEventListener('input', function() {
						gradC1Hex.value = this.value;
						updateGradientPreview();
					});
					gradC1Hex.addEventListener('input', function() {
						const val = this.value.trim();
						if (/^#[0-9A-F]{6}$/i.test(val)) {
							gradC1.value = val;
							updateGradientPreview();
						}
					});
				}

				if (gradC2 && gradC2Hex) {
					gradC2.addEventListener('input', function() {
						gradC2Hex.value = this.value;
						updateGradientPreview();
					});
					gradC2Hex.addEventListener('input', function() {
						const val = this.value.trim();
						if (/^#[0-9A-F]{6}$/i.test(val)) {
							gradC2.value = val;
							updateGradientPreview();
						}
					});
				}

				if (gradAngle) {
					gradAngle.addEventListener('input', updateGradientPreview);
				}

				document.querySelectorAll('.wprf-grad-preset').forEach(btn => {
					btn.addEventListener('click', function() {
						const g = this.getAttribute('data-g');
						const matches = g.match(/linear-gradient\((\d+)deg,\s*(#[a-f0-9]+)\s+0%,\s*(#[a-f0-9]+)\s+100%\)/i);
						if (matches && gradAngle && gradC1 && gradC1Hex && gradC2 && gradC2Hex) {
							gradAngle.value = matches[1];
							gradC1.value = matches[2];
							gradC1Hex.value = matches[2];
							gradC2.value = matches[3];
							gradC2Hex.value = matches[3];
							updateGradientPreview();
						}
					});
				});

				document.querySelectorAll('.wprf-open-gradient-generator').forEach(btn => {
					btn.addEventListener('click', function() {
						currentTargetInputId = this.getAttribute('data-target');
						const currentInput = document.getElementById(currentTargetInputId);
						if (currentInput) {
							const currentVal = currentInput.value.trim();
							const matches = currentVal.match(/linear-gradient\((\d+)deg,\s*(#[a-f0-9]+)\s+0%,\s*(#[a-f0-9]+)\s+100%\)/i);
							if (matches && gradAngle && gradC1 && gradC1Hex && gradC2 && gradC2Hex) {
								gradAngle.value = matches[1];
								gradC1.value = matches[2];
								gradC1Hex.value = matches[2];
								gradC2.value = matches[3];
								gradC2Hex.value = matches[3];
							} else if (/^#[0-9A-F]{6}$/i.test(currentVal) && gradC1 && gradC1Hex && gradC2 && gradC2Hex) {
								gradC1.value = currentVal;
								gradC1Hex.value = currentVal;
								gradC2.value = currentVal;
								gradC2Hex.value = currentVal;
							}
							updateGradientPreview();
							if (gradModal) {
								gradModal.style.display = 'flex';
							}
						}
					});
				});

				function closeGradientModal() {
					if (gradModal) {
						gradModal.style.display = 'none';
					}
					currentTargetInputId = '';
				}
				if (cancelGradBtn) { cancelGradBtn.addEventListener('click', closeGradientModal); }
				if (closeGradBtn) { closeGradBtn.addEventListener('click', closeGradientModal); }

				if (applyGradBtn) {
					applyGradBtn.addEventListener('click', function() {
						if (currentTargetInputId && gradC1 && gradC2 && gradAngle) {
							const c1 = gradC1.value;
							const c2 = gradC2.value;
							const angle = gradAngle.value;
							const css = `linear-gradient(${angle}deg, ${c1} 0%, ${c2} 100%)`;
							
							const targetInput = document.getElementById(currentTargetInputId);
							if (targetInput) {
								targetInput.value = css;
								targetInput.dispatchEvent(new Event('input'));
							}
						}
						closeGradientModal();
					});
				}
			});
			</script>
			<?php
		}

		/**
		 * Router for actions inside the admin scope.
		 */
		private function handle_admin_actions() {
			global $wpdb;

			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			// Action A: Save Settings and Interface Customizations.
			if ( isset( $_POST['save_wprf_settings'] ) ) {
				check_admin_referer( 'wprf_settings_save_action', 'wprf_settings_save_nonce' );

				update_option( 'wprf_google_api_key', sanitize_text_field( $_POST['wprf_google_api_key'] ) );
				update_option( 'wprf_google_place_id', sanitize_text_field( $_POST['wprf_google_place_id'] ) );
				update_option( 'wprf_google_direct_url', esc_url_raw( $_POST['wprf_google_direct_url'] ) );
				update_option( 'wprf_enable_gdpr', isset( $_POST['wprf_enable_gdpr'] ) ? 'yes' : 'no' );
				update_option( 'wprf_gdpr_policy_url', esc_url_raw( $_POST['wprf_gdpr_policy_url'] ) );
				update_option( 'wprf_gdpr_text', sanitize_textarea_field( $_POST['wprf_gdpr_text'] ) );
				update_option( 'wprf_enable_newsletter', isset( $_POST['wprf_enable_newsletter'] ) ? 'yes' : 'no' );
				update_option( 'wprf_newsletter_text', sanitize_textarea_field( $_POST['wprf_newsletter_text'] ) );

				// Cloudflare Turnstile spam protection settings save
				update_option( 'wprf_enable_turnstile', isset( $_POST['wprf_enable_turnstile'] ) ? 'yes' : 'no' );
				update_option( 'wprf_turnstile_site_key', sanitize_text_field( $_POST['wprf_turnstile_site_key'] ) );
				update_option( 'wprf_turnstile_secret_key', sanitize_text_field( $_POST['wprf_turnstile_secret_key'] ) );
				update_option( 'wprf_disable_ip_rate_limit', isset( $_POST['wprf_disable_ip_rate_limit'] ) ? 'yes' : 'no' );

				update_option( 'wprf_btn_color', sanitize_text_field( $_POST['wprf_btn_color'] ) );
				update_option( 'wprf_btn_text_color', sanitize_hex_color( $_POST['wprf_btn_text_color'] ) );
				update_option( 'wprf_slider_arrow_color', sanitize_hex_color( $_POST['wprf_slider_arrow_color'] ) );
				update_option( 'wprf_slider_dot_color', sanitize_hex_color( $_POST['wprf_slider_dot_color'] ) );
				update_option( 'wprf_slider_arrow_style', sanitize_text_field( $_POST['wprf_slider_arrow_style'] ) );
				update_option( 'wprf_notification_emails', sanitize_text_field( $_POST['wprf_notification_emails'] ) );
				update_option( 'wprf_success_msg', sanitize_textarea_field( $_POST['wprf_success_msg'] ) );
				update_option( 'wprf_google_redirect_msg', sanitize_textarea_field( $_POST['wprf_google_redirect_msg'] ) );

				// PRO custom styling options
				if ( isset( $_POST['wprf_default_layout'] ) ) {
					update_option( 'wprf_default_layout', sanitize_text_field( $_POST['wprf_default_layout'] ) );
				}
				update_option( 'wprf_enable_filters', isset( $_POST['wprf_enable_filters'] ) ? 'true' : 'false' );
				if ( isset( $_POST['wprf_custom_avatar_url'] ) ) {
					update_option( 'wprf_custom_avatar_url', esc_url_raw( $_POST['wprf_custom_avatar_url'] ) );
				}
				update_option( 'wprf_form_bg_color', sanitize_text_field( $_POST['wprf_form_bg_color'] ) );
				update_option( 'wprf_form_border_style', sanitize_text_field( $_POST['wprf_form_border_style'] ) );
				update_option( 'wprf_form_border_width', sanitize_text_field( $_POST['wprf_form_border_width'] ) );
				update_option( 'wprf_form_border_color', sanitize_hex_color( $_POST['wprf_form_border_color'] ) );
				update_option( 'wprf_form_border_radius', sanitize_text_field( $_POST['wprf_form_border_radius'] ) );
				update_option( 'wprf_form_padding', sanitize_text_field( $_POST['wprf_form_padding'] ) );
				update_option( 'wprf_form_box_shadow', sanitize_text_field( $_POST['wprf_form_box_shadow'] ) );
				
				update_option( 'wprf_field_bg_color', sanitize_hex_color( $_POST['wprf_field_bg_color'] ) );
				update_option( 'wprf_field_border_color', sanitize_hex_color( $_POST['wprf_field_border_color'] ) );
				update_option( 'wprf_field_focus_color', sanitize_hex_color( $_POST['wprf_field_focus_color'] ) );
				
				update_option( 'wprf_star_size', sanitize_text_field( $_POST['wprf_star_size'] ) );
				update_option( 'wprf_star_active_color', sanitize_hex_color( $_POST['wprf_star_active_color'] ) );
				update_option( 'wprf_star_inactive_color', sanitize_hex_color( $_POST['wprf_star_inactive_color'] ) );
				
				update_option( 'wprf_title_color', sanitize_hex_color( $_POST['wprf_title_color'] ) );
				update_option( 'wprf_title_size', sanitize_text_field( $_POST['wprf_title_size'] ) );
				update_option( 'wprf_label_color', sanitize_hex_color( $_POST['wprf_label_color'] ) );
				update_option( 'wprf_label_size', sanitize_text_field( $_POST['wprf_label_size'] ) );

				// Review date display settings save
				update_option( 'wprf_show_review_date', isset( $_POST['wprf_show_review_date'] ) ? 'yes' : 'no' );
				update_option( 'wprf_review_date_color', sanitize_hex_color( $_POST['wprf_review_date_color'] ) );
				update_option( 'wprf_review_date_size', sanitize_text_field( $_POST['wprf_review_date_size'] ) );

				// Empty reviews styling settings save
				update_option( 'wprf_no_reviews_color', sanitize_hex_color( $_POST['wprf_no_reviews_color'] ) );
				update_option( 'wprf_no_reviews_size', sanitize_text_field( $_POST['wprf_no_reviews_size'] ) );
				update_option( 'wprf_no_reviews_weight', sanitize_text_field( $_POST['wprf_no_reviews_weight'] ) );
				update_option( 'wprf_no_reviews_style', sanitize_text_field( $_POST['wprf_no_reviews_style'] ) );

				// MailerLite settings update
				update_option( 'wprf_mailerlite_api_key', sanitize_text_field( $_POST['wprf_mailerlite_api_key'] ) );
				if ( isset( $_POST['wprf_mailerlite_satisfied_group'] ) ) {
					update_option( 'wprf_mailerlite_satisfied_group', sanitize_text_field( $_POST['wprf_mailerlite_satisfied_group'] ) );
				}
				if ( isset( $_POST['wprf_mailerlite_dissatisfied_group'] ) ) {
					update_option( 'wprf_mailerlite_dissatisfied_group', sanitize_text_field( $_POST['wprf_mailerlite_dissatisfied_group'] ) );
				}

				if ( ! empty( $_POST['wprf_mailerlite_api_key'] ) ) {
					$ml_groups = self::fetch_mailerlite_groups( sanitize_text_field( $_POST['wprf_mailerlite_api_key'] ) );
					if ( ! empty( $ml_groups ) ) {
						update_option( 'wprf_mailerlite_groups', $ml_groups );
					}
				}

				echo '<div class="updated"><p>' . esc_html__( 'All settings and UI custom configurations successfully updated.', 'review-funnel' ) . '</p></div>';
			}

			// --- MODIFICATION: ACTION B: SAVE TRANSLATIONS TAB ARRAY ---
			if ( isset( $_POST['save_wprf_translations'] ) && isset( $_POST['wprf_t'] ) ) {
				check_admin_referer( 'wprf_translations_save_action', 'wprf_translations_save_nonce' );

				$raw_translations = $_POST['wprf_t'];
				$sanitized_translations = array();

				if ( is_array( $raw_translations ) ) {
					foreach ( $raw_translations as $key => $val ) {
						$sanitized_translations[ sanitize_key( $key ) ] = sanitize_text_field( $val );
					}
				}

				update_option( 'wprf_translations', $sanitized_translations );
				echo '<div class="updated"><p>' . esc_html__( 'Translations saved successfully and applied to the frontend form.', 'review-funnel' ) . '</p></div>';
			}

			// Action C: Manual Google Synchronizer Endpoint.
			if ( isset( $_POST['fetch_google_reviews_manually'] ) ) {
				check_admin_referer( 'wprf_settings_save_action', 'wprf_settings_save_nonce' );

				$imported = WPRF_Database::sync_google_reviews();
				if ( false === $imported ) {
					echo '<div class="error"><p>' . esc_html__( 'Error: Failed to sync reviews. Please check your API configurations or network connection.', 'review-funnel' ) . '</p></div>';
				} else {
					echo '<div class="updated"><p>' . sprintf( esc_html__( 'Synchronization Complete! Imported %d new pending Google reviews.', 'review-funnel' ), intval( $imported ) ) . '</p></div>';
				}
			}

			// Action F: Bulk Actions
			if ( isset( $_POST['wprf_admin_action'] ) && 'bulk_delete' === $_POST['wprf_admin_action'] ) {
				check_admin_referer( 'wprf_bulk_delete_action', 'wprf_bulk_delete_nonce' );

				if ( isset( $_POST['bulk_action'] ) && 'delete' === $_POST['bulk_action'] && ! empty( $_POST['bulk_reviews'] ) ) {
					$ids_to_delete = array_map( 'intval', $_POST['bulk_reviews'] );
					$ids_placeholder = implode( ',', array_fill( 0, count( $ids_to_delete ), '%d' ) );
					
					$wpdb->query( $wpdb->prepare(
						"DELETE FROM {$this->table_name} WHERE id IN ($ids_placeholder)",
						$ids_to_delete
					) );

					echo '<div class="updated"><p>' . sprintf( esc_html__( 'Successfully deleted %d reviews in bulk.', 'review-funnel' ), count( $ids_to_delete ) ) . '</p></div>';
				} else {
					echo '<div class="error"><p>' . esc_html__( 'No reviews selected or action invalid.', 'review-funnel' ) . '</p></div>';
				}
			}
			// Action H: Send Support & Diagnostic Report Email
			if ( isset( $_POST['send_wprf_support_email'] ) ) {
				check_admin_referer( 'wprf_support_send_action', 'wprf_support_send_nonce' );

				$recipient_email = ! empty( $_POST['wprf_support_recipient'] ) ? sanitize_email( $_POST['wprf_support_recipient'] ) : 'lechwyzewski@gmail.com';
				$user_email      = ! empty( $_POST['wprf_support_reply_to'] ) ? sanitize_email( $_POST['wprf_support_reply_to'] ) : get_option( 'admin_email' );
				$user_message    = ! empty( $_POST['wprf_support_message'] ) ? sanitize_textarea_field( $_POST['wprf_support_message'] ) : '';
				$include_diag    = isset( $_POST['wprf_include_diagnostics'] ) && 'yes' === $_POST['wprf_include_diagnostics'];

				if ( ! is_email( $recipient_email ) ) {
					$recipient_email = 'lechwyzewski@gmail.com';
				}

				$site_name = get_bloginfo( 'name' );
				$site_url  = get_site_url();
				$subject   = sprintf( '[Review Funnel Support] Diagnostic Report from %s (%s)', $site_name, $site_url );

				$body  = "Review Funnel Plugin - Support Request & System Status\n";
				$body .= "======================================================\n\n";
				$body .= "Website URL: " . $site_url . "\n";
				$body .= "Website Name: " . $site_name . "\n";
				$body .= "Admin Email / Reply-To: " . $user_email . "\n";
				$body .= "Submitted Date: " . date_i18n( 'Y-m-d H:i:s' ) . "\n\n";

				$body .= "--- User Message / Description ---\n";
				$body .= ! empty( $user_message ) ? $user_message . "\n\n" : "(No description provided)\n\n";

				if ( $include_diag ) {
					$table_name = $wpdb->prefix . 'review_funnel';
					$table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) === $table_name;
					$review_count = $table_exists ? intval( $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" ) ) : 0;

					$direct_url  = get_option( 'wprf_google_direct_url', '' );
					$api_key     = get_option( 'wprf_google_api_key', '' );
					$place_id    = get_option( 'wprf_google_place_id', '' );
					$ml_key      = get_option( 'wprf_mailerlite_api_key', '' );
					$turnstile   = get_option( 'wprf_enable_turnstile', 'no' );

					$body .= "--- System Diagnostics Report ---\n";
					$body .= "Plugin Version: Review Funnel Plugin v1.0\n";
					$body .= "WordPress Version: " . get_bloginfo( 'version' ) . "\n";
					$body .= "PHP Version: " . phpversion() . "\n";
					$body .= "Database Table: " . ( $table_exists ? "Connected (" . $review_count . " reviews)" : "Missing Table" ) . "\n";
					$body .= "cURL Extension: " . ( function_exists( 'curl_version' ) ? "Available" : "Missing" ) . "\n";
					$body .= "OpenSSL Extension: " . ( extension_loaded( 'openssl' ) ? "Available" : "Missing" ) . "\n";
					$body .= "Google Direct URL: " . ( ! empty( $direct_url ) ? "Configured (" . $direct_url . ")" : "Not set" ) . "\n";
					$body .= "Google Places API Key: " . ( ! empty( $api_key ) ? "Configured (" . substr( $api_key, 0, 6 ) . "...)" : "Not set" ) . "\n";
					$body .= "Google Place ID: " . ( ! empty( $place_id ) ? "Configured (" . $place_id . ")" : "Not set" ) . "\n";
					$body .= "MailerLite API Key: " . ( ! empty( $ml_key ) ? "Configured" : "Not set" ) . "\n";
					$body .= "Cloudflare Turnstile: " . ( 'yes' === $turnstile ? "Enabled" : "Disabled" ) . "\n";
				}

				$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
				if ( is_email( $user_email ) ) {
					$headers[] = 'Reply-To: ' . $user_email;
				}

				$mail_error_msg = '';
				$mail_failed_callback = function( $wp_error ) use ( &$mail_error_msg ) {
					if ( is_wp_error( $wp_error ) ) {
						$mail_error_msg = $wp_error->get_error_message();
					}
				};
				add_action( 'wp_mail_failed', $mail_failed_callback );

				$sent = wp_mail( $recipient_email, $subject, $body, $headers );

				remove_action( 'wp_mail_failed', $mail_failed_callback );

				self::log_mail_event( $recipient_email, $subject, $sent, $mail_error_msg );

				if ( $sent ) {
					echo '<div class="updated"><p>' . sprintf( esc_html__( 'Support ticket and system diagnostic report sent successfully to %s.', 'review-funnel' ), esc_html( $recipient_email ) ) . '</p></div>';
				} else {
					echo '<div class="error"><p>';
					echo esc_html__( 'Failed to send support email via server wp_mail().', 'review-funnel' );
					if ( ! empty( $mail_error_msg ) ) {
						echo ' <strong>' . esc_html__( 'Server Error Details:', 'review-funnel' ) . '</strong> ' . esc_html( $mail_error_msg );
					} else {
						echo ' ' . esc_html__( 'Please use the "Copy Diagnostic Report to Clipboard" button below to send it manually.', 'review-funnel' );
					}
					echo '</p></div>';
				}
			}

			// Action D: Review Moderation Update.
			if ( isset( $_POST['wprf_admin_action'] ) && isset( $_POST['review_id'] ) ) {
				check_admin_referer( 'wprf_moderation_action', 'wprf_moderation_nonce' );

				$review_id = intval( $_POST['review_id'] );
				$action    = sanitize_text_field( $_POST['wprf_admin_action'] );
				
				if ( 'approve_and_save' === $action ) {
					$updated_text     = sanitize_textarea_field( $_POST['edited_review_text'] );
					$assigned_profile = sanitize_text_field( $_POST['assigned_profile_id'] );
					$updated_date     = isset( $_POST['edited_review_date'] ) ? sanitize_text_field( $_POST['edited_review_date'] ) : '';

					if ( empty( $assigned_profile ) ) {
						$assigned_profile = 'general';
					}
					
					$review_status    = isset( $_POST['review_status'] ) ? sanitize_text_field( $_POST['review_status'] ) : 'pending';
					if ( ! in_array( $review_status, array( 'approved', 'pending' ), true ) ) {
						$review_status = 'pending';
					}

					$update_fields = array(
						'status'      => $review_status,
						'review_text' => $updated_text,
						'profile_id'  => $assigned_profile,
					);

					if ( ! empty( $updated_date ) ) {
						$time_parsed = strtotime( $updated_date );
						if ( $time_parsed ) {
							$update_fields['time'] = date( 'Y-m-d H:i:s', $time_parsed );
						}
					}

					$wpdb->update(
						$this->table_name, 
						$update_fields, 
						array( 'id' => $review_id )
					);
					echo '<div class="updated"><p>' . esc_html__( 'Review dataset modified and saved successfully.', 'review-funnel' ) . '</p></div>';
				}
			}

			// Action E: Delete Review.
			if ( isset( $_GET['action'] ) && 'delete' === $_GET['action'] && isset( $_GET['id'] ) ) {
				$review_id = intval( $_GET['id'] );
				check_admin_referer( 'wprf_delete_review_' . $review_id, 'wprf_delete_nonce' );

				$wpdb->delete( $this->table_name, array( 'id' => $review_id ) );
				echo '<div class="updated"><p>' . esc_html__( 'Review wiped out cleanly from data rows.', 'review-funnel' ) . '</p></div>';
			}
			// Action G: Sync MailerLite groups
			if ( isset( $_POST['sync_mailerlite_groups'] ) ) {
				check_admin_referer( 'wprf_settings_save_action', 'wprf_settings_save_nonce' );
				
				// Automatically save key first if sent in the POST request
				if ( isset( $_POST['wprf_mailerlite_api_key'] ) ) {
					update_option( 'wprf_mailerlite_api_key', sanitize_text_field( $_POST['wprf_mailerlite_api_key'] ) );
				}

				$key = get_option( 'wprf_mailerlite_api_key' );
				$ml_groups = self::fetch_mailerlite_groups( $key );
				if ( ! empty( $ml_groups ) ) {
					update_option( 'wprf_mailerlite_groups', $ml_groups );
					echo '<div class="updated"><p>' . esc_html__( 'MailerLite groups synced successfully.', 'review-funnel' ) . '</p></div>';
				} else {
					$error_msg = get_option( 'wprf_mailerlite_error', 'Failed to fetch groups from MailerLite.' );
					$trimmed_key = trim( $key );
					$key_len = strlen( $trimmed_key );
					$key_preview = $key_len > 6 ? substr( $trimmed_key, 0, 3 ) . '...' . substr( $trimmed_key, -3 ) : 'too_short';
					$detected_ver = ( 32 === $key_len && ctype_alnum( $trimmed_key ) ) ? 'Classic (v2)' : 'New (v3)';
					
					echo '<div class="error" style="padding: 12px; margin-bottom: 20px;">';
					echo '<p style="margin: 0 0 8px 0; font-size: 14px;"><strong>' . esc_html__( 'MailerLite Sync Diagnostics:', 'review-funnel' ) . '</strong></p>';
					echo '<ul style="margin: 0 0 12px 20px; list-style-type: disc;">';
					echo '<li>' . esc_html__( 'Detected API Version:', 'review-funnel' ) . ' <strong>' . esc_html( $detected_ver ) . '</strong></li>';
					echo '<li>' . esc_html__( 'Key Length:', 'review-funnel' ) . ' <strong>' . $key_len . ' characters</strong></li>';
					echo '<li>' . esc_html__( 'Key Preview:', 'review-funnel' ) . ' <code>' . esc_html( $key_preview ) . '</code></li>';
					echo '</ul>';
					echo '<p style="margin: 0; padding: 8px; background: #fff; border-left: 4px solid #d63638;"><strong>' . esc_html__( 'Error:', 'review-funnel' ) . '</strong> ' . esc_html( $error_msg ) . '</p>';
					echo '<p style="margin: 8px 0 0 0; font-size: 12px; color: #666;">' . esc_html__( 'Please verify that your API key is correct and your MailerLite account is active.', 'review-funnel' ) . '</p>';
					echo '</div>';
				}
			}
		}

		/**
		 * Fetch groups from MailerLite API (Supports both Classic v2 and New v3)
		 *
		 * @param string $api_key
		 * @return array
		 */
		public static function fetch_mailerlite_groups( $api_key ) {
			if ( empty( $api_key ) ) {
				return array();
			}

			// Clean the key
			$api_key = trim( $api_key );

			// Detect version: Classic keys are exactly 32 hex/alphanumeric characters
			$is_classic = ( 32 === strlen( $api_key ) && ctype_alnum( $api_key ) );

			if ( $is_classic ) {
				// MailerLite Classic (v2) API
				$url = 'https://api.mailerlite.com/api/v2/groups';
				$headers = array(
					'X-MailerLite-ApiKey' => $api_key,
					'Content-Type'        => 'application/json',
					'Accept'              => 'application/json',
					'User-Agent'          => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
				);
			} else {
				// MailerLite New (v3) API
				$url = 'https://connect.mailerlite.com/api/groups';
				$headers = array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'User-Agent'    => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
				);
			}

			// Try 1: Secure SSL verification (Cloudflare friendly)
			$args = array(
				'headers'   => $headers,
				'timeout'   => 15,
				'sslverify' => true,
			);

			$response = wp_remote_get( $url, $args );

			// Check if we got an SSL error (cURL error 60 or similar)
			$is_ssl_error = false;
			if ( is_wp_error( $response ) ) {
				$err_msg = $response->get_error_message();
				if ( false !== stripos( $err_msg, 'SSL' ) || false !== stripos( $err_msg, 'certificate' ) || false !== stripos( $err_msg, 'local issuer' ) ) {
					$is_ssl_error = true;
				}
			}

			// Try 2: Fallback with sslverify => false if Try 1 failed due to local server certificate configs
			if ( $is_ssl_error ) {
				$args['sslverify'] = false;
				$response = wp_remote_get( $url, $args );
			}

			if ( is_wp_error( $response ) ) {
				update_option( 'wprf_mailerlite_error', 'WP_Error: ' . $response->get_error_message() );
				return array();
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			if ( 200 !== $code ) {
				// Strip HTML tags and summarize if response is an HTML page
				if ( strpos( $body, '<html' ) !== false || strpos( $body, '<doctype' ) !== false ) {
					$clean_text = wp_strip_all_tags( $body );
					$clean_text = preg_replace( '/\s+/', ' ', $clean_text );
					$clean_text = trim( substr( $clean_text, 0, 150 ) );
					update_option( 'wprf_mailerlite_error', 'HTTP Code ' . $code . ' (HTML Response): ' . $clean_text . '...' );
				} else {
					update_option( 'wprf_mailerlite_error', 'HTTP Code ' . $code . ': ' . substr( $body, 0, 200 ) );
				}
				return array();
			}

			$data = json_decode( $body, true );
			$groups = array();

			if ( $is_classic ) {
				// Classic v2 returns a flat array of groups
				if ( is_array( $data ) ) {
					foreach ( $data as $group ) {
						if ( isset( $group['id'] ) && isset( $group['name'] ) ) {
							$groups[] = array(
								'id'   => sanitize_text_field( $group['id'] ),
								'name' => sanitize_text_field( $group['name'] ),
							);
						}
					}
					delete_option( 'wprf_mailerlite_error' );
					return $groups;
				}
			} else {
				// New v3 returns { data: [ ... ] }
				if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
					foreach ( $data['data'] as $group ) {
						$groups[] = array(
							'id'   => sanitize_text_field( $group['id'] ),
							'name' => sanitize_text_field( $group['name'] ),
						);
					}
					delete_option( 'wprf_mailerlite_error' );
					return $groups;
				}
			}

			update_option( 'wprf_mailerlite_error', 'Invalid response format: ' . substr( $body, 0, 200 ) );
			return array();
		}

		/**
		 * Helper to log outgoing plugin email delivery attempts.
		 */
		public static function log_mail_event( $recipient, $subject, $success, $error = '' ) {
			$logs = get_option( 'wprf_mail_logs', array() );
			if ( ! is_array( $logs ) ) {
				$logs = array();
			}

			array_unshift( $logs, array(
				'timestamp' => date_i18n( 'Y-m-d H:i:s' ),
				'recipient' => $recipient,
				'subject'   => $subject,
				'status'    => $success ? 'SUCCESS' : 'FAILED',
				'error'     => $error,
			) );

			// Keep last 15 log entries
			$logs = array_slice( $logs, 0, 15 );
			update_option( 'wprf_mail_logs', $logs );
		}
	}
}