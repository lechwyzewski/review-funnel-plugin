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

			// --- MODIFICATION: FETCH TRANSLATIONS OPTIONS ---
			$translations = get_option( 'wprf_translations', array() );
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
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Review Funnel & Google Maps Integration', 'review-funnel' ); ?></h1>
				
				<!-- WP NATIVE TABS NAVIGATION -->
				<h2 class="nav-tab-wrapper">
					<a href="#tab-reviews" class="nav-tab nav-tab-active" data-tab="tab-reviews"><?php esc_html_e( 'Reviews Management', 'review-funnel' ); ?></a>
					<a href="#tab-settings" class="nav-tab" data-tab="tab-settings"><?php esc_html_e( 'Settings & Customization', 'review-funnel' ); ?></a>
					<a href="#tab-translations" class="nav-tab" data-tab="tab-translations"><?php esc_html_e( 'Translations', 'review-funnel' ); ?></a>
					<a href="#tab-shortcodes" class="nav-tab" data-tab="tab-shortcodes"><?php esc_html_e( 'Shortcodes Guide', 'review-funnel' ); ?></a>
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

				<!-- TAB 2: SETTINGS & CUSTOMIZATION -->
				<div id="tab-settings" class="tab-content-section" style="margin-top: 20px; display: none;">
					<form method="post" action="">
						<?php wp_nonce_field( 'wprf_settings_save_action', 'wprf_settings_save_nonce' ); ?>
						
						<div class="card" style="padding:20px; margin-bottom:20px; max-width:800px;">
							<h2><span class="dashicons dashicons-admin-links" style="vertical-align: middle;"></span> <?php esc_html_e( 'Google Profile & Redirect Configurations', 'review-funnel' ); ?></h2>
							<p class="description"><?php esc_html_e( 'Configure how satisfied users are redirected to Google to publish their reviews.', 'review-funnel' ); ?></p>
							<table class="form-table">
								<tr>
									<th scope="row"><label for="wprf_google_direct_url"><?php esc_html_e( 'Google Direct Review URL (Keyless Lejek)', 'review-funnel' ); ?></label></th>
									<td>
										<input type="text" id="wprf_google_direct_url" name="wprf_google_direct_url" value="<?php echo esc_attr( $direct_url ); ?>" class="regular-text">
										<p class="description"><?php esc_html_e( 'If filled, satisfied users (4-5 stars) will be redirected directly to this link. Bypasses Google Places API key & Place ID requirements. Useful for simple, keyless setups.', 'review-funnel' ); ?></p>
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
										<p class="description"><?php esc_html_e( 'Google Place ID used for Google API calls. You can also paste your Google Maps link (URL) here directly, and the plugin will automatically redirect clients to it.', 'review-funnel' ); ?></p>
									</td>
								</tr>
							</table>
							<button type="submit" name="fetch_google_reviews_manually" value="1" class="button button-secondary" style="margin-top:10px; background:#4285F4; color:#fff; border-color:#4285F4; font-weight:bold;">
								<?php esc_html_e( 'Fetch Latest Google Reviews Now', 'review-funnel' ); ?>
							</button>
						</div>

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
								<tr>
									<th scope="row"><label for="wprf_slider_dot_color"><?php esc_html_e( 'Slider Bullets Color', 'review-funnel' ); ?></label></th>
									<td><input type="color" id="wprf_slider_dot_color" name="wprf_slider_dot_color" value="<?php echo esc_attr( $slider_dot_color ); ?>"></td>
								</tr>

								<!-- PRO advanced customization properties -->
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
								
								<tr>
									<td colspan="2"><hr style="border:0; border-top:1px solid #eee; margin:10px 0;"><h3><?php esc_html_e( 'Typography Colors & Sizes (PRO)', 'review-funnel' ); ?></h3></td>
								</tr>
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
								
								<tr>
									<td colspan="2"><hr style="border:0; border-top:1px solid #eee; margin:10px 0;"><h3><?php esc_html_e( 'Review List Date Settings (PRO)', 'review-funnel' ); ?></h3></td>
								</tr>
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
								<tr>
									<td colspan="2"><hr style="border:0; border-top:1px solid #eee; margin:10px 0;"><h3><?php esc_html_e( 'Empty Reviews Message Customization (PRO)', 'review-funnel' ); ?></h3></td>
								</tr>
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

						<p class="submit">
							<button type="submit" name="save_wprf_settings" class="button button-primary button-large"><?php esc_html_e( 'Save All Settings', 'review-funnel' ); ?></button>
						</p>
					</form>
				</div>

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
			document.addEventListener('DOMContentLoaded', function() {
				const tabs = document.querySelectorAll('.nav-tab-wrapper .nav-tab');
				const contents = document.querySelectorAll('.tab-content-section');

				tabs.forEach(tab => {
					tab.addEventListener('click', function(e) {
						e.preventDefault();
						
						tabs.forEach(t => t.classList.remove('nav-tab-active'));
						contents.forEach(c => c.style.display = 'none');
						
						this.classList.add('nav-tab-active');
						
						const targetId = this.getAttribute('data-tab');
						const targetContent = document.getElementById(targetId);
						
						if (targetContent) {
							targetContent.style.display = 'block';
						}
					});
				});

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
					const c1 = gradC1.value;
					const c2 = gradC2.value;
					const angle = gradAngle.value;
					const css = `linear-gradient(${angle}deg, ${c1} 0%, ${c2} 100%)`;
					gradPreview.style.background = css;
					gradAngleVal.textContent = `${angle}°`;
				}

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
				gradAngle.addEventListener('input', updateGradientPreview);

				document.querySelectorAll('.wprf-grad-preset').forEach(btn => {
					btn.addEventListener('click', function() {
						const g = this.getAttribute('data-g');
						const matches = g.match(/linear-gradient\((\d+)deg,\s*(#[a-f0-9]+)\s+0%,\s*(#[a-f0-9]+)\s+100%\)/i);
						if (matches) {
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
							if (matches) {
								gradAngle.value = matches[1];
								gradC1.value = matches[2];
								gradC1Hex.value = matches[2];
								gradC2.value = matches[3];
								gradC2Hex.value = matches[3];
							} else if (/^#[0-9A-F]{6}$/i.test(currentVal)) {
								gradC1.value = currentVal;
								gradC1Hex.value = currentVal;
								gradC2.value = currentVal;
								gradC2Hex.value = currentVal;
							}
							updateGradientPreview();
							gradModal.style.display = 'flex';
						}
					});
				});

				function closeGradientModal() {
					gradModal.style.display = 'none';
					currentTargetInputId = '';
				}
				cancelGradBtn.addEventListener('click', closeGradientModal);
				closeGradBtn.addEventListener('click', closeGradientModal);

				applyGradBtn.addEventListener('click', function() {
					if (currentTargetInputId) {
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
	}
}