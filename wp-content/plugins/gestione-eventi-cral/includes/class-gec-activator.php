<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GEC_Activator {

	public static function activate() {
		self::install_or_upgrade();
	}

	public static function maybe_upgrade() {
		$current = (int) get_option( 'gec_db_version', 0 );
		if ( $current >= 2 ) {
			return;
		}

		self::install_or_upgrade();
	}

	private static function install_or_upgrade() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$members_table = $wpdb->prefix . 'cral_members';
		$bookings_table = $wpdb->prefix . 'cral_bookings';
		$booking_guests_table = $wpdb->prefix . 'cral_booking_guests';

		$members_sql = "CREATE TABLE {$members_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_code VARCHAR(50) NOT NULL,
			first_name VARCHAR(100) NOT NULL,
			last_name VARCHAR(100) NOT NULL,
			email VARCHAR(190) NOT NULL,
			password_hash VARCHAR(255) NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY member_code (member_code),
			UNIQUE KEY email (email)
		) {$charset_collate};";

		$bookings_sql = "CREATE TABLE {$bookings_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id BIGINT UNSIGNED NOT NULL,
			member_id BIGINT UNSIGNED NOT NULL,
			total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
			notes TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'confirmed',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY event_id (event_id),
			KEY member_id (member_id)
		) {$charset_collate};";

		$booking_guests_sql = "CREATE TABLE {$booking_guests_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			booking_id BIGINT UNSIGNED NOT NULL,
			guest_type VARCHAR(100) NOT NULL,
			guest_name VARCHAR(190) NULL,
			quantity INT UNSIGNED NOT NULL DEFAULT 1,
			unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
			total_price DECIMAL(10,2) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY booking_id (booking_id)
		) {$charset_collate};";

		dbDelta( $members_sql );
		dbDelta( $bookings_sql );
		dbDelta( $booking_guests_sql );

		update_option( 'gec_db_version', 2 );
	}
}

