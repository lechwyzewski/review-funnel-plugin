<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPRF_Database {
	public static function install_table() {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'universal_reviews';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			time datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			profile_id varchar(100) NOT NULL,
			author_name varchar(100) NOT NULL,
			author_email varchar(100) DEFAULT NULL,
			rating tinyint(1) NOT NULL,
			review_text text NOT NULL,
			status varchar(20) DEFAULT 'pending' NOT NULL,
			google_review_id varchar(255) DEFAULT NULL,
			marketing_consent tinyint(1) DEFAULT 0 NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY google_review_id (google_review_id)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Synchronize reviews from Google API to local database
	 */
	public static function sync_google_reviews() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'universal_reviews';

		$api_key = get_option( 'wprf_google_api_key' );
		if ( empty( $api_key ) ) {
			$api_key = get_option( 'urf_google_api_key' );
		}
		$place_id = get_option( 'wprf_google_place_id' );
		if ( empty( $place_id ) ) {
			$place_id = get_option( 'urf_google_place_id' );
		}

		if ( empty( $api_key ) || empty( $place_id ) ) {
			return false;
		}

		$url      = 'https://maps.googleapis.com/maps/api/place/details/json?place_id=' . urlencode( $place_id ) . '&fields=reviews&key=' . urlencode( $api_key ) . '&lang=en';
		$response = wp_remote_get( $url );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( isset( $data['result']['reviews'] ) ) {
			$imported_count = 0;
			foreach ( $data['result']['reviews'] as $g_review ) {
				$g_id   = sanitize_text_field( $g_review['time'] . '_' . crc32( $g_review['author_name'] ) );
				
				// 1. Sprawdź, czy opinia o tym ID z Google już istnieje w bazie
				$exists_by_id = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table_name} WHERE google_review_id = %s", $g_id ) );
				
				if ( $exists_by_id ) {
					continue; // Już zsynchronizowana, pomiń
				}

				$sanitized_author = sanitize_text_field( $g_review['author_name'] );
				$sanitized_text   = sanitize_textarea_field( $g_review['text'] );
				$rating           = intval( $g_review['rating'] );

				// 2. Sprawdź, czy istnieje lokalna opinia o tym samym autorze, tekście i ocenie (ale bez zapisanego google_review_id)
				$existing_local_id = $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM {$table_name} WHERE (google_review_id IS NULL OR google_review_id = '') AND author_name = %s AND review_text = %s AND rating = %d LIMIT 1",
					$sanitized_author,
					$sanitized_text,
					$rating
				) );

				if ( $existing_local_id ) {
					// Znaleziono lokalny duplikat - powiąż go z ID Google i oznacz jako zatwierdzony (widoczny na stronie)
					$wpdb->update(
						$table_name,
						array(
							'google_review_id' => $g_id,
							'status'           => 'approved'
						),
						array( 'id' => intval( $existing_local_id ) )
					);
				} else {
					// 3. Jeśli nie ma duplikatu, wstaw jako nową opinię
					if ( ! empty( $g_review['text'] ) ) {
						$wpdb->insert(
							$table_name,
							array(
								'profile_id'       => 'general',
								'author_name'      => $sanitized_author,
								'rating'           => $rating,
								'review_text'      => $sanitized_text,
								'status'           => 'approved', // Importowane opinie z Google są domyślnie zatwierdzone
								'google_review_id' => $g_id,
							)
						);
						$imported_count++;
					}
				}
			}
			return $imported_count;
		}
		return false;
	}
}