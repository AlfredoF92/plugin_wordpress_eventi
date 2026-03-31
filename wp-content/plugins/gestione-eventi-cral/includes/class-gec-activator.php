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
		if ( $current < 3 ) {
			self::install_or_upgrade();
		}

		// Keep WP users in sync for existing members.
		self::maybe_sync_members_to_wp_users();
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
			login_token VARCHAR(64) NULL,
			login_token_expires DATETIME NULL,
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

		update_option( 'gec_db_version', 3 );

		// Ensure login page exists at /login with [cral_login].
		self::maybe_create_login_page();
		self::maybe_create_area_personale_page();
	}

	private static function maybe_create_login_page() {
		$existing = get_page_by_path( 'login' );
		if ( $existing instanceof WP_Post ) {
			return;
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'Login Soci', 'gestione-eventi-cral' ),
				'post_name'    => 'login',
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => '[cral_login]',
			)
		);

		if ( ! is_wp_error( $page_id ) && $page_id ) {
			update_option( 'gec_login_page_id', (int) $page_id );
		}
	}

	private static function maybe_sync_members_to_wp_users() {
		if ( get_option( 'gec_members_wp_synced' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'cral_members';

		$members = $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A );
		if ( empty( $members ) ) {
			update_option( 'gec_members_wp_synced', 1 );
			return;
		}

		if ( ! get_role( 'cral_member' ) ) {
			add_role( 'cral_member', 'Socio CRAL', array( 'read' => true ) );
		}

		foreach ( $members as $m ) {
			if ( empty( $m['email'] ) ) {
				continue;
			}

			$email = (string) $m['email'];
			$user  = get_user_by( 'email', $email );

			$member_code = isset( $m['member_code'] ) ? (string) $m['member_code'] : '';
			$username_base = $member_code !== '' ? $member_code : $email;
			$user_login = sanitize_user( $username_base, true );
			if ( '' === $user_login ) {
				$user_login = sanitize_user( $email, true );
			}

			$display_name = trim( (string) $m['first_name'] . ' ' . (string) $m['last_name'] );
			$user_id = 0;

			if ( ! $user ) {
				// Create with random pass, we'll replace hash below.
				$user_id = wp_create_user( $user_login, wp_generate_password( 12, true ), $email );
				if ( is_wp_error( $user_id ) ) {
					continue;
				}
				$user = get_user_by( 'id', $user_id );
			} else {
				$user_id = (int) $user->ID;
			}

			if ( $user_id <= 0 ) {
				continue;
			}

			// Update profile fields.
			wp_update_user(
				array(
					'ID'           => $user_id,
					'user_email'   => $email,
					'display_name' => $display_name,
					'first_name'   => (string) $m['first_name'],
					'last_name'    => (string) $m['last_name'],
				)
			);

			// Map WP user -> member id.
			update_user_meta( $user_id, 'gec_member_id', (int) $m['id'] );

			// Set role if not admin.
			if ( $user instanceof WP_User ) {
				if ( ! in_array( 'administrator', (array) $user->roles, true ) ) {
					$user->set_role( 'cral_member' );
				}
			}

			// Make WP password match member password hash (so email+password works).
			if ( ! empty( $m['password_hash'] ) ) {
				$wpdb->update(
					$wpdb->users,
					array( 'user_pass' => (string) $m['password_hash'] ),
					array( 'ID' => $user_id ),
					array( '%s' ),
					array( '%d' )
				);
			}
		}

		update_option( 'gec_members_wp_synced', 1 );
	}

	private static function maybe_create_area_personale_page() {
		$existing = get_page_by_path( 'area-personale' );
		if ( $existing instanceof WP_Post ) {
			return;
		}

		wp_insert_post(
			array(
				'post_title'   => __( 'Area personale', 'gestione-eventi-cral' ),
				'post_name'    => 'area-personale',
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => __( 'Area personale socio (in sviluppo).', 'gestione-eventi-cral' ),
			)
		);
	}
}

