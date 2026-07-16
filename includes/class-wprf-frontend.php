<?php
/**
 * Frontend Layouts, Shortcodes, and AJAX Processor for Review Funnel Plugin.
 *
 * @package    Review_Funnel_Plugin
 * @subpackage Frontend
 * @author     Senior WordPress Architect
 * @since      1.7.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'WPRF_Frontend' ) ) {

	/**
	 * Class WPRF_Frontend
	 * Handles patient-facing review funnels and responsive grid layout outputs.
	 */
	class WPRF_Frontend {

		/**
		 * Database table name for reviews.
		 *
		 * @var string
		 */
		private $table_name;

		/**
		 * WPRF_Frontend Constructor.
		 */
		public function __construct() {
			global $wpdb;
			$this->table_name = $wpdb->prefix . 'universal_reviews';

			add_shortcode( 'review_funnel', array( $this, 'render_review_funnel' ) );
			add_shortcode( 'lejek_opinii', array( $this, 'render_review_funnel' ) );

			add_shortcode( 'review_list', array( $this, 'render_reviews_list' ) );
			add_shortcode( 'wyswietl_opinie', array( $this, 'render_reviews_list' ) );

			add_shortcode( 'review_badge', array( $this, 'render_review_badge' ) );
			add_shortcode( 'average_rating', array( $this, 'render_review_badge' ) );
			add_shortcode( 'srednia_ocen', array( $this, 'render_review_badge' ) );

			add_action( 'wp_ajax_wprf_submit_funnel', array( $this, 'handle_funnel_submission' ) );
			add_action( 'wp_ajax_nopriv_wprf_submit_funnel', array( $this, 'handle_funnel_submission' ) );

			add_action( 'wp_enqueue_scripts', array( $this, 'register_frontend_assets' ) );
		}

		/**
		 * Register frontend script and style assets.
		 */
		public function register_frontend_assets() {
			wp_register_style( 'wprf-frontend', plugins_url( 'assets/css/frontend.css', dirname( __FILE__ ) ), array(), '1.7.1' );
			wp_register_script( 'wprf-frontend', plugins_url( 'assets/js/frontend.js', dirname( __FILE__ ) ), array(), '1.7.2', true );
			wp_register_script( 'wprf-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, true );

			// Fetch translations and settings for localization
			$translations = get_option( 'wprf_translations', array() );
			$t_js_processing = isset( $translations['js_processing'] ) ? $translations['js_processing'] : 'Processing your feedback...';
			$t_js_star_alert = isset( $translations['js_star_alert'] ) ? $translations['js_star_alert'] : 'Please select a star rating.';
			$t_js_error      = isset( $translations['js_error'] ) ? $translations['js_error'] : 'An error occurred. Please try again.';
			$t_google_btn    = isset( $translations['google_btn'] ) ? $translations['google_btn'] : 'Go to Google Maps';
			$t_clipboard_msg = isset( $translations['clipboard_msg'] ) ? $translations['clipboard_msg'] : '(Your feedback text has been automatically copied to your clipboard!)';
			$t_copy_btn      = isset( $translations['copy_btn'] ) ? $translations['copy_btn'] : 'Copy Your Review';
			$t_js_copied     = isset( $translations['js_copied'] ) ? $translations['js_copied'] : 'Copied!';
			$t_read_more     = isset( $translations['read_more'] ) ? $translations['read_more'] : 'read more';
			$t_read_less     = isset( $translations['read_less'] ) ? $translations['read_less'] : 'read less';
			
			$btn_color       = get_option( 'wprf_btn_color', '#007a78' );
			$text_color      = get_option( 'wprf_btn_text_color', '#ffffff' );
			$slider_dot_color = get_option( 'wprf_slider_dot_color', '#007a78' );
			$gdpr_error      = __( 'Please accept the privacy policy to continue.', 'review-funnel' );

			wp_localize_script( 'wprf-frontend', 'wprf_frontend_vars', array(
				'ajax_url'         => admin_url( 'admin-ajax.php' ),
				't_js_processing'  => $t_js_processing,
				't_js_star_alert'  => $t_js_star_alert,
				't_js_error'       => $t_js_error,
				't_google_btn'     => $t_google_btn,
				't_clipboard_msg'  => $t_clipboard_msg,
				't_copy_btn'       => $t_copy_btn,
				't_js_copied'      => $t_js_copied,
				'btn_color'        => $btn_color,
				'text_color'       => $text_color,
				'slider_dot_color' => $slider_dot_color,
				't_gdpr_error'     => $gdpr_error,
				't_read_more'      => $t_read_more,
				't_read_less'      => $t_read_less,
			) );
		}

		/**
		 * 1. Shortcode Handler: [review_funnel id="..."]
		 */
		public function render_review_funnel( $atts ) {
			wp_enqueue_style( 'wprf-frontend' );
			wp_enqueue_script( 'wprf-frontend' );

			$enable_turnstile = get_option( 'wprf_enable_turnstile', 'no' );
			$turnstile_site_key = get_option( 'wprf_turnstile_site_key', '' );
			if ( 'yes' === $enable_turnstile && ! empty( $turnstile_site_key ) ) {
				wp_enqueue_script( 'wprf-turnstile' );
			}

			$a = shortcode_atts( array(
				'id' => 'general',
			), $atts );

			$profile_id = sanitize_text_field( $a['id'] );
			$btn_color  = get_option( 'wprf_btn_color', '#007a78' );
			$text_color = get_option( 'wprf_btn_text_color', '#ffffff' );

			$translations = get_option( 'wprf_translations', array() );
			$t_rating_title  = isset( $translations['rating_title'] ) ? $translations['rating_title'] : 'How do you rate your experience with us?';
			$t_input_name    = isset( $translations['input_name'] ) ? $translations['input_name'] : 'Your Name / Nickname';
			$t_input_name_p  = isset( $translations['input_name_placeholder'] ) ? $translations['input_name_placeholder'] : 'e.g. John Doe';
			$t_input_email   = isset( $translations['input_email'] ) ? $translations['input_email'] : 'Your Email (optional)';
			$t_input_email_p = isset( $translations['input_email_placeholder'] ) ? $translations['input_email_placeholder'] : 'e.g. john@example.com';
			$t_input_text    = isset( $translations['input_text'] ) ? $translations['input_text'] : 'Your Feedback';
			$t_input_text_p  = isset( $translations['input_text_placeholder'] ) ? $translations['input_text_placeholder'] : 'Please share details about your visit...';
			$t_submit_btn    = isset( $translations['submit_btn'] ) ? $translations['submit_btn'] : 'Submit Review';

			// GDPR options
			$enable_gdpr       = get_option( 'wprf_enable_gdpr', 'no' );
			$gdpr_policy_url   = get_option( 'wprf_gdpr_policy_url', '' );
			$gdpr_text         = get_option( 'wprf_gdpr_text', 'Wyrażam zgodę na przetwarzanie moich danych osobowych zgodnie z {privacy_policy} w celu opublikowania opinii.' );
			$enable_newsletter = get_option( 'wprf_enable_newsletter', 'no' );
			$newsletter_text   = get_option( 'wprf_newsletter_text', 'Chcę zapisać się na newsletter i wyrażam zgodę na przesyłanie informacji handlowych.' );

			$policy_link       = '<a href="' . esc_url( $gdpr_policy_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'polityką prywatności', 'review-funnel' ) . '</a>';
			$gdpr_consent_html = str_replace( '{privacy_policy}', $policy_link, esc_html( $gdpr_text ) );

			// Customization options (PRO)
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

			ob_start();
			?>
			<div class="wprf-funnel-container" id="wprf-funnel-box" style="
				--wprf-btn-color: <?php echo esc_attr( $btn_color ); ?>; 
				--wprf-btn-text-color: <?php echo esc_attr( $text_color ); ?>;
				--wprf-form-bg: <?php echo esc_attr( $form_bg_color ); ?>;
				--wprf-form-border-style: <?php echo esc_attr( $form_border_style ); ?>;
				--wprf-form-border-width: <?php echo esc_attr( $form_border_width ); ?>;
				--wprf-form-border-color: <?php echo esc_attr( $form_border_color ); ?>;
				--wprf-form-radius: <?php echo esc_attr( $form_border_radius ); ?>;
				--wprf-form-padding: <?php echo esc_attr( $form_padding ); ?>;
				--wprf-form-shadow: <?php echo esc_attr( $form_box_shadow ); ?>;
				--wprf-field-bg: <?php echo esc_attr( $field_bg_color ); ?>;
				--wprf-field-border: <?php echo esc_attr( $field_border_color ); ?>;
				--wprf-field-focus: <?php echo esc_attr( $field_focus_color ); ?>;
				--wprf-star-size: <?php echo esc_attr( $star_size ); ?>;
				--wprf-star-active: <?php echo esc_attr( $star_active_color ); ?>;
				--wprf-star-inactive: <?php echo esc_attr( $star_inactive_color ); ?>;
				--wprf-title-color: <?php echo esc_attr( $title_color ); ?>;
				--wprf-title-size: <?php echo esc_attr( $title_size ); ?>;
				--wprf-label-color: <?php echo esc_attr( $label_color ); ?>;
				--wprf-label-size: <?php echo esc_attr( $label_size ); ?>;
			">
				<form id="wprf-funnel-form" method="post">
					<?php wp_nonce_field( 'wprf_frontend_submit', 'wprf_nonce' ); ?>
					<input type="hidden" name="profile_id" value="<?php echo esc_attr( $profile_id ); ?>">

					<!-- Invisible anti-spam honeypot field -->
					<input type="text" name="wprf_verify_phone" style="display:none !important;" tabindex="-1" autocomplete="off">

					<p class="wprf-step-title"><?php echo esc_html( $t_rating_title ); ?></p>
					
					<div class="wprf-stars-wrapper">
						<div class="wprf-stars-rating">
							<?php for ( $i = 5; $i >= 1; $i-- ) : ?>
								<input type="radio" id="star-<?php echo esc_attr( $i ); ?>" name="wprf_rating" value="<?php echo esc_attr( $i ); ?>" />
								<label for="star-<?php echo esc_attr( $i ); ?>" title="<?php echo esc_attr( $i ); ?> stars">★</label>
							<?php endfor; ?>
						</div>
					</div>

					<div class="wprf-input-group">
						<label for="wprf_author"><?php echo esc_html( $t_input_name ); ?></label>
						<input type="text" id="wprf_author" name="wprf_author" placeholder="<?php echo esc_attr( $t_input_name_p ); ?>">
					</div>

					<div class="wprf-input-group">
						<label for="wprf_author_email"><?php echo esc_html( $t_input_email ); ?></label>
						<input type="email" id="wprf_author_email" name="wprf_author_email" placeholder="<?php echo esc_attr( $t_input_email_p ); ?>">
					</div>

					<div class="wprf-input-group">
						<label for="wprf_text"><?php echo esc_html( $t_input_text ); ?></label>
						<textarea id="wprf_text" name="wprf_text" rows="4" required placeholder="<?php echo esc_attr( $t_input_text_p ); ?>"></textarea>
					</div>

					<?php if ( 'yes' === $enable_gdpr ) : ?>
						<div class="wprf-gdpr-consent-group">
							<input type="checkbox" id="wprf_gdpr_consent" name="wprf_gdpr_consent" required>
							<label for="wprf_gdpr_consent"><?php echo $gdpr_consent_html; ?></label>
						</div>
					<?php endif; ?>

					<?php if ( 'yes' === $enable_newsletter ) : ?>
						<div class="wprf-gdpr-consent-group" id="wprf-newsletter-consent-wrapper" style="display: none;">
							<input type="checkbox" id="wprf_marketing_consent" name="wprf_marketing_consent">
							<label for="wprf_marketing_consent"><?php echo esc_html( $newsletter_text ); ?></label>
						</div>
					<?php endif; ?>

					<?php if ( 'yes' === $enable_turnstile && ! empty( $turnstile_site_key ) ) : ?>
						<div class="wprf-input-group wprf-turnstile-wrapper" style="margin-top: 15px; margin-bottom: 15px; display: flex; justify-content: center;">
							<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( $turnstile_site_key ); ?>"></div>
						</div>
					<?php endif; ?>

					<button type="submit" class="wprf-submit-btn">
						<?php echo esc_html( $t_submit_btn ); ?>
					</button>
				</form>
				<div id="wprf-ajax-response" style="display:none; margin-top:15px; padding:15px; border-radius:5px; font-weight:500; line-height: 1.5;"></div>
			</div>
			<?php
			return ob_get_clean();
		}

		/**
		 * 2. Shortcode Handler: [review_list count="..." columns="..."]
		 */
		public function render_reviews_list( $atts ) {
			global $wpdb;
			wp_enqueue_style( 'wprf-frontend' );
			wp_enqueue_script( 'wprf-frontend' );

			$a = shortcode_atts( array(
				'id'         => '',
				'name'       => '',
				'count'      => '6',
				'columns'    => '3',
				'layout'     => 'grid', // 'grid' or 'slider'
				'autoplay'   => '0',    // milliseconds, e.g. 5000, 0 = disabled
				'arrows'     => 'true', // 'true' or 'false'
				'dots'       => 'true', // 'true' or 'false'
				'show_date'  => '',
				'char_limit' => '180',
			), $atts );

			$count      = absint( $a['count'] );
			$columns    = absint( $a['columns'] );
			$layout     = sanitize_text_field( $a['layout'] );
			$autoplay   = absint( $a['autoplay'] );
			$arrows     = sanitize_text_field( $a['arrows'] );
			$dots       = sanitize_text_field( $a['dots'] );
			$char_limit = isset( $a['char_limit'] ) ? intval( $a['char_limit'] ) : 180;

			if ( $columns < 1 || $columns > 4 ) {
				$columns = 3;
			}

			if ( ! empty( $a['id'] ) ) {
				$raw_ids      = explode( ',', $a['id'] );
				$clean_ids    = array_map( 'sanitize_text_field', array_map( 'trim', $raw_ids ) );
				
				$clauses = array();
				$query_args = array();
				foreach ( $clean_ids as $id ) {
					$clauses[]    = "FIND_IN_SET(%s, REPLACE(profile_id, ' ', ''))";
					$query_args[] = $id;
				}
				$where_clause = implode( ' OR ', $clauses );
				$query_args[] = $count;

				$query = $wpdb->prepare(
					"SELECT * FROM {$this->table_name} WHERE status='approved' AND ($where_clause) ORDER BY time DESC LIMIT %d",
					$query_args
				);
				$results = $wpdb->get_results( $query );
			} else {
				$results = $wpdb->get_results(
					$wpdb->prepare( "SELECT * FROM {$this->table_name} WHERE status='approved' ORDER BY time DESC LIMIT %d", $count )
				);
			}

			if ( empty( $results ) ) {
				$translations = get_option( 'wprf_translations', array() );
				$t_empty_reviews = isset( $translations['empty_reviews_msg'] ) ? $translations['empty_reviews_msg'] : '{profile} does not have any reviews yet.';
				
				$profile_name = '';
				if ( ! empty( $a['name'] ) ) {
					$profile_name = sanitize_text_field( $a['name'] );
				} elseif ( ! empty( $a['id'] ) ) {
					$raw_ids = explode( ',', $a['id'] );
					$first_id = trim( $raw_ids[0] );
					$profile_name = ucwords( str_replace( '-', ' ', $first_id ) );
				} else {
					$profile_name = __( 'This profile', 'review-funnel' );
				}
				
				$msg = str_replace( '{profile}', $profile_name, $t_empty_reviews );
				
				return '<p class="wprf-no-reviews-msg">' . esc_html( $msg ) . '</p>';
			}

			$slider_arrow_color = get_option( 'wprf_slider_arrow_color', '#007a78' );
			$slider_dot_color   = get_option( 'wprf_slider_dot_color', '#007a78' );
			$slider_arrow_style = get_option( 'wprf_slider_arrow_style', 'circle' );

			$show_date_opt      = get_option( 'wprf_show_review_date', 'yes' );
			$show_date_attr     = isset( $a['show_date'] ) ? trim( strtolower( $a['show_date'] ) ) : '';
			$show_date          = $show_date_opt;
			if ( 'false' === $show_date_attr || 'no' === $show_date_attr ) {
				$show_date = 'no';
			} elseif ( 'true' === $show_date_attr || 'yes' === $show_date_attr ) {
				$show_date = 'yes';
			}
			$date_color         = get_option( 'wprf_review_date_color', '#718096' );
			$date_size          = get_option( 'wprf_review_date_size', '11px' );

			$translations       = get_option( 'wprf_translations', array() );
			$t_read_more        = isset( $translations['read_more'] ) ? $translations['read_more'] : 'read more';

			// Compute stats for SEO JSON-LD
			$total_rating_val = 0;
			$total_count      = count( $results );
			foreach ( $results as $res ) {
				$total_rating_val += intval( $res->rating );
			}
			$avg_val = $total_count > 0 ? round( $total_rating_val / $total_count, 2 ) : 0;
			$stats   = new stdClass();
			$stats->count_rating = $total_count;
			$stats->avg_rating   = $avg_val;

			ob_start();
			// Output SEO JSON-LD block
			echo $this->generate_json_ld( $a['id'], $stats, $results );
			?>
			<?php if ( 'slider' === $layout ) : ?>
				<div class="wprf-slider-container wprf-slider-cols-<?php echo esc_attr( $columns ); ?>" 
				     id="wprf-slider-<?php echo esc_attr( uniqid() ); ?>" 
				     data-autoplay="<?php echo esc_attr( $autoplay ); ?>" 
				     data-arrows="<?php echo esc_attr( $arrows ); ?>" 
				     data-dots="<?php echo esc_attr( $dots ); ?>"
				     style="--wprf-slider-nav-color: <?php echo esc_attr( $slider_arrow_color ); ?>; --wprf-slider-dot-color: <?php echo esc_attr( $slider_dot_color ); ?>;">
					
					<div class="wprf-slider-viewport">
						<div class="wprf-slider-track">
							<?php if ( empty( $results ) ) : ?>
								<p style="text-align: center; color: #718096; width: 100%;">
									<?php esc_html_e( 'No reviews verified yet for this profile.', 'review-funnel' ); ?>
								</p>
							<?php else : ?>
								<?php foreach ( $results as $res ) : ?>
									<div class="wprf-slider-slide wprf-review-card">
										<div class="wprf-card-header">
											<strong class="wprf-author"><?php echo esc_html( $res->author_name ); ?></strong>
											<span class="wprf-stars"><?php echo esc_html( str_repeat( '★', $res->rating ) ); ?></span>
										</div>
										<p class="wprf-text">
											<?php
											$text = $res->review_text;
											if ( $char_limit > 0 && mb_strlen( $text, 'UTF-8' ) > $char_limit ) {
												$visible_text = mb_substr( $text, 0, $char_limit, 'UTF-8' );
												$hidden_text  = mb_substr( $text, $char_limit, null, 'UTF-8' );
												?>
												<span class="wprf-text-teaser">"<?php echo esc_html( $visible_text ); ?></span><span class="wprf-text-more" style="display: none;"><?php echo esc_html( $hidden_text ); ?></span>"
												<span class="wprf-readmore-toggle" style="color: <?php echo esc_attr( $slider_arrow_color ); ?>; font-weight: 600; cursor: pointer; margin-left: 5px; display: inline-block; text-decoration: underline; font-size: 12px;"><?php echo esc_html( $t_read_more ); ?></span>
												<?php
											} else {
												?>
												"<?php echo esc_html( $text ); ?>"
												<?php
											}
											?>
										</p>
										<?php if ( 'yes' === $show_date ) : ?>
											<small class="wprf-date" style="color: <?php echo esc_attr( $date_color ); ?>; font-size: <?php echo esc_attr( $date_size ); ?>; display: block; margin-top: 8px;">
												<?php echo esc_html( date( 'd.m.Y', strtotime( $res->time ) ) ); ?>
											</small>
										<?php endif; ?>
									</div>
								<?php endforeach; ?>
							<?php endif; ?>
						</div>
					</div>

					<?php if ( 'true' === $arrows ) : ?>
						<?php $arrow_class = ( 'clean' === $slider_arrow_style ) ? 'style-clean' : ''; ?>
						<button type="button" class="wprf-slider-nav prev <?php echo esc_attr( $arrow_class ); ?>">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
								<polyline points="15 18 9 12 15 6"></polyline>
							</svg>
						</button>
						<button type="button" class="wprf-slider-nav next <?php echo esc_attr( $arrow_class ); ?>">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
								<polyline points="9 18 15 12 9 6"></polyline>
							</svg>
						</button>
					<?php endif; ?>

					<?php if ( 'true' === $dots ) : ?>
						<div class="wprf-slider-dots"></div>
					<?php endif; ?>
				</div>
			<?php else : ?>
				<div class="wprf-reviews-grid wprf-cols-<?php echo esc_attr( $columns ); ?>">
					<?php if ( empty( $results ) ) : ?>
						<p style="grid-column: 1 / -1; text-align: center; color: #718096;">
							<?php esc_html_e( 'No reviews verified yet for this profile.', 'review-funnel' ); ?>
						</p>
					<?php else : ?>
						<?php foreach ( $results as $res ) : ?>
							<div class="wprf-review-card">
								<div class="wprf-card-header">
									<strong class="wprf-author"><?php echo esc_html( $res->author_name ); ?></strong>
									<span class="wprf-stars"><?php echo esc_html( str_repeat( '★', $res->rating ) ); ?></span>
								</div>
								<p class="wprf-text">
									<?php
									$text = $res->review_text;
									if ( $char_limit > 0 && mb_strlen( $text, 'UTF-8' ) > $char_limit ) {
										$visible_text = mb_substr( $text, 0, $char_limit, 'UTF-8' );
										$hidden_text  = mb_substr( $text, $char_limit, null, 'UTF-8' );
										?>
										<span class="wprf-text-teaser">"<?php echo esc_html( $visible_text ); ?></span><span class="wprf-text-more" style="display: none;"><?php echo esc_html( $hidden_text ); ?></span>"
										<span class="wprf-readmore-toggle" style="color: <?php echo esc_attr( $slider_arrow_color ); ?>; font-weight: 600; cursor: pointer; margin-left: 5px; display: inline-block; text-decoration: underline; font-size: 12px;"><?php echo esc_html( $t_read_more ); ?></span>
										<?php
									} else {
										?>
										"<?php echo esc_html( $text ); ?>"
										<?php
									}
									?>
								</p>
								<?php if ( 'yes' === $show_date ) : ?>
									<small class="wprf-date" style="color: <?php echo esc_attr( $date_color ); ?>; font-size: <?php echo esc_attr( $date_size ); ?>; display: block; margin-top: 8px;">
										<?php echo esc_html( date( 'd.m.Y', strtotime( $res->time ) ) ); ?>
									</small>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<?php
			return ob_get_clean();
		}

		/**
		 * 4. Shortcode: [review_badge] / [average_rating] / [srednia_ocen]
		 */
		public function render_review_badge( $atts ) {
			global $wpdb;
			wp_enqueue_style( 'wprf-frontend' );
			wp_enqueue_script( 'wprf-frontend' );

			$a = shortcode_atts(
				array(
					'id'         => '',
					'star_color' => '#ffcc00',
					'text_color' => '#007a78',
					'font_size'  => '20px',
					'show_count' => 'true',
				),
				$atts
			);

			if ( ! empty( $a['id'] ) ) {
				$ids = explode( ',', $a['id'] );
				$clean_ids = array_map( 'sanitize_text_field', array_map( 'trim', $ids ) );
				$clean_ids = array_filter( $clean_ids );
				
				if ( empty( $clean_ids ) ) {
					return '';
				}

				$clauses = array();
				$query_args = array();
				foreach ( $clean_ids as $id ) {
					$clauses[]    = "FIND_IN_SET(%s, REPLACE(profile_id, ' ', ''))";
					$query_args[] = $id;
				}
				$where_clause = implode( ' OR ', $clauses );
				
				$query = $wpdb->prepare(
					"SELECT AVG(rating) as avg_rating, COUNT(*) as count_rating FROM {$this->table_name} WHERE status='approved' AND ($where_clause)",
					$query_args
				);
				$stats = $wpdb->get_row( $query );
			} else {
				$stats = $wpdb->get_row( "SELECT AVG(rating) as avg_rating, COUNT(*) as count_rating FROM {$this->table_name} WHERE status='approved'" );
			}

			if ( ! $stats || intval( $stats->count_rating ) === 0 ) {
				return '';
			}

			$total_ratings = intval( $stats->count_rating );
			$average       = $stats->avg_rating ? round( $stats->avg_rating, 2 ) : 0;

			// Fetch translatable suffix labels
			$translations = get_option( 'wprf_translations', array() );
			$t_review_single      = isset( $translations['review_single'] ) ? $translations['review_single'] : 'review';
			$t_review_plural_234  = isset( $translations['review_plural_234'] ) ? $translations['review_plural_234'] : 'reviews';
			$t_review_plural_5plus = isset( $translations['review_plural_5plus'] ) ? $translations['review_plural_5plus'] : 'reviews';

			if ( $total_ratings === 1 ) {
				$suffix = $t_review_single;
			} else {
				$mod10  = $total_ratings % 10;
				$mod100 = $total_ratings % 100;
				
				// Polish grammar: numbers ending in 2, 3, 4 (except 12, 13, 14) get plural_234.
				// Everything else (ends in 5-9, 0, 11-14) gets plural_5plus.
				if ( $mod10 >= 2 && $mod10 <= 4 && ( $mod100 < 12 || $mod100 > 14 ) ) {
					$suffix = $t_review_plural_234;
				} else {
					$suffix = $t_review_plural_5plus;
				}
			}

			// Generate premium SVG star indicators (with precise half-star support)
			$stars_html = '';
			$star_color = esc_attr( $a['star_color'] );
			$empty_color = '#cbd5e1'; // Elegant light grey fill for empty star regions

			$star_path       = 'M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z';
			$star_left_path  = 'M12 17.27 L5.82 21 L7.46 13.97 L2 9.24 L9.19 8.63 L12 2 Z';
			$star_right_path = 'M12 17.27 L18.18 21 L16.54 13.97 L22 9.24 L14.81 8.63 L12 2 Z';

			for ( $i = 1; $i <= 5; $i++ ) {
				$stars_html .= '<svg viewBox="0 0 24 24" width="1.1em" height="1.1em" style="display:inline-block; vertical-align:middle; margin-right:2px;">';
				if ( $average >= $i ) {
					// Full Star
					$stars_html .= '<path d="' . $star_path . '" fill="' . $star_color . '" />';
				} elseif ( $average > ($i - 1) ) {
					// Fraction Star
					$fraction = $average - ($i - 1);
					if ( $fraction >= 0.75 ) {
						// Render Full Star
						$stars_html .= '<path d="' . $star_path . '" fill="' . $star_color . '" />';
					} elseif ( $fraction >= 0.25 ) {
						// Render Half Star (Left half color, Right half empty)
						$stars_html .= '<path d="' . $star_left_path . '" fill="' . $star_color . '" />';
						$stars_html .= '<path d="' . $star_right_path . '" fill="' . $empty_color . '" />';
					} else {
						// Render Empty Star
						$stars_html .= '<path d="' . $star_path . '" fill="' . $empty_color . '" />';
					}
				} else {
					// Empty Star
					$stars_html .= '<path d="' . $star_path . '" fill="' . $empty_color . '" />';
				}
				$stars_html .= '</svg>';
			}

			ob_start();
			// Output SEO JSON-LD block
			echo $this->generate_json_ld( $a['id'], $stats );
			?>
			<div class="wprf-badge-wrapper" style="font-size: <?php echo esc_attr( $a['font_size'] ); ?>; font-family: inherit; display: inline-flex; align-items: center; line-height: 1; vertical-align: middle; margin: 5px 0;">
				<span style="display: inline-flex; align-items: center; line-height: 1;">
					<?php echo $stars_html; ?>
				</span>
				<?php if ( 'false' !== strtolower( $a['show_count'] ) ) : ?>
					<span style="color: <?php echo esc_attr( $a['text_color'] ); ?>; margin-left: 10px; font-weight: 500;">
						<?php echo $total_ratings . ' ' . esc_html( $suffix ); ?>
					</span>
				<?php endif; ?>
			</div>
			<?php
			return ob_get_clean();
		}

		/**
		 * Generate SEO Schema.org JSON-LD structured data for aggregate rating and reviews.
		 *
		 * @param string   $profile_id
		 * @param stdClass $stats
		 * @param array    $reviews
		 * @return string
		 */
		private function generate_json_ld( $profile_id, $stats, $reviews = array() ) {
			if ( ! $stats || intval( $stats->count_rating ) === 0 ) {
				return '';
			}

			$site_name     = get_bloginfo( 'name' );
			$business_name = ! empty( $profile_id ) ? esc_html( ucwords( str_replace( array( '-', '_' ), ' ', $profile_id ) ) ) : $site_name;
			
			$schema = array(
				'@context' => 'https://schema.org',
				'@type'    => 'LocalBusiness',
				'name'     => $business_name,
				'url'      => esc_url( get_permalink() ),
				'aggregateRating' => array(
					'@type'       => 'AggregateRating',
					'ratingValue' => round( $stats->avg_rating, 2 ),
					'ratingCount' => intval( $stats->count_rating ),
					'bestRating'  => '5',
					'worstRating' => '1',
				),
			);

			// Add individual reviews if available
			if ( ! empty( $reviews ) ) {
				$schema['review'] = array();
				foreach ( $reviews as $rev ) {
					$schema['review'][] = array(
						'@type'         => 'Review',
						'author'        => array(
							'@type' => 'Person',
							'name'  => esc_html( ! empty( $rev->author_name ) ? $rev->author_name : __( 'Anonymous', 'review-funnel' ) ),
						),
						'datePublished' => date( 'Y-m-d', strtotime( $rev->time ) ),
						'reviewBody'    => esc_html( $rev->review_text ),
						'reviewRating'  => array(
							'@type'       => 'Rating',
							'ratingValue' => intval( $rev->rating ),
							'bestRating'  => '5',
							'worstRating' => '1',
						),
					);
				}
			}

			return '<script type="application/ld+json">' . wp_json_encode( $schema ) . '</script>';
		}

		/**
		 * 3. Dynamic AJAX Endpoint for Submissions
		 */
		public function handle_funnel_submission() {
			check_ajax_referer( 'wprf_frontend_submit', 'nonce' );

			global $wpdb;

			// Honeypot anti-spam check
			if ( ! empty( $_POST['wprf_verify_phone'] ) ) {
				wp_send_json_error( array( 'message' => __( 'Spam detected!', 'review-funnel' ) ) );
			}

			// Rate limiting check
			$disable_rate_limit = get_option( 'wprf_disable_ip_rate_limit', 'no' );
			$user_ip = $this->get_user_ip();
			$transient_key = 'wprf_limit_' . md5( $user_ip );
			if ( 'yes' !== $disable_rate_limit && get_transient( $transient_key ) ) {
				wp_send_json_error( array( 'message' => __( 'You have already submitted a review recently. Please try again in an hour.', 'review-funnel' ) ) );
			}

			// Cloudflare Turnstile Verification
			$enable_turnstile = get_option( 'wprf_enable_turnstile', 'no' );
			if ( 'yes' === $enable_turnstile ) {
				$turnstile_secret = get_option( 'wprf_turnstile_secret_key', '' );
				$turnstile_response = isset( $_POST['cf-turnstile-response'] ) ? sanitize_text_field( $_POST['cf-turnstile-response'] ) : '';

				if ( empty( $turnstile_secret ) || empty( $turnstile_response ) ) {
					wp_send_json_error( array( 'message' => __( 'Security verification failed. Please complete the captcha.', 'review-funnel' ) ) );
				}

				// Verify with Cloudflare API (Dual-SSL & Browser User-Agent)
				$verify_url = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
				$args = array(
					'headers' => array(
						'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
					),
					'body' => array(
						'secret'   => $turnstile_secret,
						'response' => $turnstile_response,
						'remoteip' => $user_ip,
					),
					'timeout'   => 15,
					'sslverify' => true,
				);

				$verify_response = wp_remote_post( $verify_url, $args );

				// SSL error fallback
				if ( is_wp_error( $verify_response ) ) {
					$err_msg = $verify_response->get_error_message();
					if ( false !== stripos( $err_msg, 'SSL' ) || false !== stripos( $err_msg, 'certificate' ) || false !== stripos( $err_msg, 'local issuer' ) ) {
						$args['sslverify'] = false;
						$verify_response = wp_remote_post( $verify_url, $args );
					}
				}

				if ( is_wp_error( $verify_response ) ) {
					wp_send_json_error( array( 'message' => __( 'Unable to reach security service. Please try again.', 'review-funnel' ) ) );
				}

				$verify_body = json_decode( wp_remote_retrieve_body( $verify_response ), true );
				if ( empty( $verify_body['success'] ) ) {
					wp_send_json_error( array( 'message' => __( 'Security verification failed. Please reload the page and try again.', 'review-funnel' ) ) );
				}
			}

			$rating      = isset( $_POST['rating'] ) ? intval( $_POST['rating'] ) : 0;
			$author      = isset( $_POST['author'] ) ? sanitize_text_field( $_POST['author'] ) : '';
			$email       = isset( $_POST['author_email'] ) ? sanitize_email( $_POST['author_email'] ) : '';
			$text        = isset( $_POST['text'] ) ? sanitize_textarea_field( $_POST['text'] ) : '';
			$profile_id  = isset( $_POST['profile_id'] ) ? sanitize_text_field( $_POST['profile_id'] ) : 'general';

			if ( ! $rating || empty( $text ) ) {
				wp_send_json_error( array( 'message' => __( 'Please complete all required fields.', 'review-funnel' ) ) );
			}

			if ( ! empty( $email ) && ! is_email( $email ) ) {
				wp_send_json_error( array( 'message' => __( 'Please enter a valid email address.', 'review-funnel' ) ) );
			}

			// GDPR marketing / newsletter consent status
			$marketing_consent_db = ( isset( $_POST['marketing_consent'] ) && '1' === $_POST['marketing_consent'] ) ? 1 : 0;
			$marketing_consent = $marketing_consent_db ? 'Yes' : 'No';

			$inserted = $wpdb->insert(
				$this->table_name,
				array(
					'profile_id'        => $profile_id,
					'author_name'       => $author,
					'author_email'      => $email,
					'rating'            => $rating,
					'review_text'       => $text,
					'status'            => 'pending',
					'marketing_consent' => $marketing_consent_db,
				)
			);

			if ( ! $inserted ) {
				wp_send_json_error( array( 'message' => __( 'Database operational error. Try again.', 'review-funnel' ) ) );
			}

			// Save rate limiting transient on success
			if ( 'yes' !== $disable_rate_limit ) {
				set_transient( $transient_key, true, 3600 );
			}

			// MailerLite automated list subscription if user checked opt-in
			if ( $marketing_consent_db && ! empty( $email ) ) {
				$this->subscribe_to_mailerlite( $author, $email, $rating );
			}

			// Send email notification upon new review submission
			$emails_str = get_option( 'wprf_notification_emails' );
			if ( ! empty( $emails_str ) ) {
				$emails = array_map( 'sanitize_email', array_map( 'trim', explode( ',', $emails_str ) ) );
				$emails = array_filter( $emails );

				if ( ! empty( $emails ) ) {
					$site_name = get_bloginfo( 'name' );
					$subject   = sprintf( '[%s] New Review Submitted - %d Stars', $site_name, $rating );
					
					$admin_url = admin_url( 'admin.php?page=review-funnel' );

					$body  = "A new review has been submitted on your website.\n\n";
					$body .= "--- Review Details ---\n";
					$body .= "Author: " . esc_html( $author ) . "\n";
					if ( ! empty( $email ) ) {
						$body .= "Author Email: " . esc_html( $email ) . "\n";
						$body .= "Newsletter Opt-in: " . $marketing_consent . "\n";
					}
					$body .= "Rating: " . esc_html( $rating ) . " / 5 Stars\n";
					$body .= "Assigned Profile ID: " . esc_html( $profile_id ) . "\n";
					$body .= "Content:\n\"" . esc_html( $text ) . "\"\n\n";
					$body .= "Manage reviews: " . esc_url( $admin_url ) . "\n";

					$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

					wp_mail( $emails, $subject, $body, $headers );
				}
			}

			$success_msg = get_option( 'wprf_success_msg', 'Thank you for your review! Your feedback helps us grow.' );
			$google_msg  = get_option( 'wprf_google_redirect_msg', 'Would you mind copying your review to our Google Maps profile to help others?' );
			
			// Check if Google direct redirect URL is set, fallback to Place ID
			$direct_url = get_option( 'wprf_google_direct_url' );
			$place_id   = get_option( 'wprf_google_place_id' );
			if ( empty( $place_id ) ) {
				$place_id = get_option( 'urf_google_place_id' );
			}

			$google_url = '';
			if ( ! empty( $direct_url ) ) {
				$google_url = $direct_url;
			} elseif ( ! empty( $place_id ) ) {
				$google_url = 'https://search.google.com/local/writereview?placeid=' . esc_attr( $place_id );
			}

			if ( $rating >= 4 && ! empty( $google_url ) ) {
				wp_send_json_success( array(
					'action'     => 'google_redirect',
					'message'    => $google_msg,
					'google_url' => $google_url,
					'text'       => $text
				) );
			} else {
				// Jeśli brak place_id i direct_url, przesyłamy info diagnostyczne dla JS console.log
				wp_send_json_success( array(
					'action'         => 'standard_success',
					'message'        => $success_msg,
					'debug_place_id' => ( empty( $place_id ) && empty( $direct_url ) ) ? 'missing_or_empty' : 'populated'
				) );
			}
		}

		/**
		 * Retrieve client IP address securely.
		 *
		 * @return string
		 */
		private function get_user_ip() {
			if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
				return $_SERVER['HTTP_CLIENT_IP'];
			} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
				return explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] )[0];
			}
			return $_SERVER['REMOTE_ADDR'];
		}

		/**
		 * Subscribe user to MailerLite groups based on rating satisfaction.
		 *
		 * @param string $name
		 * @param string $email
		 * @param int    $rating
		 * @return void
		 */
		private function subscribe_to_mailerlite( $name, $email, $rating ) {
			$api_key = get_option( 'wprf_mailerlite_api_key' );
			if ( empty( $api_key ) ) {
				return;
			}

			// Clean the key
			$api_key = trim( $api_key );

			$group_id = '';
			if ( $rating >= 4 ) {
				$group_id = get_option( 'wprf_mailerlite_satisfied_group' );
			} else {
				$group_id = get_option( 'wprf_mailerlite_dissatisfied_group' );
			}

			if ( empty( $group_id ) ) {
				return;
			}

			$is_classic = ( 32 === strlen( $api_key ) && ctype_alnum( $api_key ) );

			if ( $is_classic ) {
				// MailerLite Classic (v2) Add Subscriber
				$url = 'https://api.mailerlite.com/api/v2/groups/' . urlencode( $group_id ) . '/subscribers';
				$body = array(
					'email' => $email,
					'name'  => $name,
				);
				$headers = array(
					'X-MailerLite-ApiKey' => $api_key,
					'Content-Type'        => 'application/json',
					'Accept'              => 'application/json',
					'User-Agent'          => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
				);
			} else {
				// MailerLite New (v3) Add Subscriber
				$url = 'https://connect.mailerlite.com/api/subscribers';
				$body = array(
					'email'  => $email,
					'fields' => array(
						'name' => $name,
					),
					'groups' => array( $group_id ),
				);
				$headers = array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'User-Agent'    => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
				);
			}

			$args = array(
				'headers'   => $headers,
				'body'      => wp_json_encode( $body ),
				'timeout'   => 15,
				'sslverify' => true,
			);

			$response = wp_remote_post( $url, $args );

			// Check for SSL verification error, then fallback to false
			if ( is_wp_error( $response ) ) {
				$err_msg = $response->get_error_message();
				if ( false !== stripos( $err_msg, 'SSL' ) || false !== stripos( $err_msg, 'certificate' ) || false !== stripos( $err_msg, 'local issuer' ) ) {
					$args['sslverify'] = false;
					wp_remote_post( $url, $args );
				}
			}
		}
	}
}