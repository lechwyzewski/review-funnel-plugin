=== Complete Review Funnel – Google Reviews & Reputation Manager ===
Contributors: complete-review-funnel
Tags: reviews, google maps, review funnel, feedback, testimonial
Requires at least: 5.0
Tested up to: 6.7
Stable tag: 1.7.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html


A universal review funnel with star ratings, Google API integration, automated clipboard copying, and RODO/GDPR compliance.

== Description ==

Review Funnel Plugin is a self-hosted reputation management tool for WordPress. It acts as a funnel:
* Satisfied clients (4-5 stars) are prompted to copy their feedback to clipboard and redirected directly to your Google Maps review page.
* Unhappy clients (1-3 stars) submit their feedback privately to the local database, allowing you to resolve their complaints before they publish negative public reviews.

Features:
* Self-hosted - all review data is saved in your local WordPress database.
* Keyless option - works with a direct review link without requiring complex Google API configurations.
* Schema.org Rich Snippets JSON-LD aggregate rating integration.
* RODO / GDPR privacy policy consent options.
* Honeypot and Transient-based IP Rate Limiting anti-spam protection.

== Installation ==

1. Upload the `Review_Funnel_Plugin` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Configure the redirect options and translations in the settings page.
4. Place the shortcode `[review_funnel]` on any page or post to display the form.
5. Place the shortcode `[review_list]` to display approved reviews, or `[review_badge]` to display the average star badge.

== Changelog ==

= 1.7.0 =
* Initial public release with GDPR checkbox, anti-spam, and keyless review redirection support.
