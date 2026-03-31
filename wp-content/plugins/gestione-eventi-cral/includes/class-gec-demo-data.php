<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GEC_Demo_Data {

	/**
	 * Seed demo data once in admin.
	 */
	public static function maybe_seed() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$version = (int) get_option( 'gec_demo_data_version', 0 );

		if ( $version < 1 ) {
			self::seed();
			update_option( 'gec_demo_data_version', 1 );
			return;
		}

		if ( $version < 2 ) {
			self::seed_more_bookings_with_notes();
			update_option( 'gec_demo_data_version', 2 );
		}
	}

	private static function seed() {
		$event_ids  = self::create_events();
		$member_ids = self::create_members();

		if ( $event_ids && $member_ids ) {
			self::create_bookings( $event_ids, $member_ids );
		}
	}

	/**
	 * Add extra simulated bookings with notes for existing demo data.
	 */
	private static function seed_more_bookings_with_notes() {
		$event_ids  = get_posts(
			array(
				'post_type'      => 'cral_event',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 20,
			)
		);

		global $wpdb;
		$member_ids = $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}cral_members ORDER BY id ASC LIMIT 10" );
		$member_ids = array_map( 'intval', $member_ids );

		if ( empty( $event_ids ) || empty( $member_ids ) ) {
			return;
		}

		self::create_bookings( array_values( $event_ids ), array_values( $member_ids ), 15, true );
	}

	/**
	 * Create 5 sample events with pricing and guest types.
	 *
	 * @return int[]
	 */
	private static function create_events() {
		$events = array(
			array(
				'title'       => 'Viaggio alle Cinque Terre',
				'category'    => 'Viaggi',
				'event_code'  => 'V001',
				'member_price'=> 80.00,
				'max_part'    => 40,
				'days_offset' => 30,
			),
			array(
				'title'       => 'Serata Teatro: Commedia',
				'category'    => 'Teatro',
				'event_code'  => 'T010',
				'member_price'=> 25.00,
				'max_part'    => 120,
				'days_offset' => 10,
			),
			array(
				'title'       => 'Giornata al Parco Avventura',
				'category'    => 'Sport',
				'event_code'  => 'S005',
				'member_price'=> 35.00,
				'max_part'    => 60,
				'days_offset' => 20,
			),
			array(
				'title'       => 'Weekend Benessere in SPA',
				'category'    => 'Viaggi',
				'event_code'  => 'V020',
				'member_price'=> 150.00,
				'max_part'    => 25,
				'days_offset' => 45,
			),
			array(
				'title'       => 'Cena Sociale CRAL',
				'category'    => 'Sociale',
				'event_code'  => 'C001',
				'member_price'=> 30.00,
				'max_part'    => 200,
				'days_offset' => 5,
			),
		);

		$event_ids = array();
		$today     = current_time( 'timestamp' );

		foreach ( $events as $event ) {
			$event_date            = gmdate( 'Y-m-d', $today + ( $event['days_offset'] * DAY_IN_SECONDS ) );
			$signup_start          = gmdate( 'Y-m-d', $today + ( ( $event['days_offset'] - 20 ) * DAY_IN_SECONDS ) );
			$signup_end            = gmdate( 'Y-m-d', $today + ( ( $event['days_offset'] - 3 ) * DAY_IN_SECONDS ) );
			$cancellation_deadline = gmdate( 'Y-m-d', $today + ( ( $event['days_offset'] - 7 ) * DAY_IN_SECONDS ) );

			$post_id = wp_insert_post(
				array(
					'post_type'   => 'cral_event',
					'post_title'  => $event['title'],
					'post_status' => 'publish',
				)
			);

			if ( is_wp_error( $post_id ) || ! $post_id ) {
				continue;
			}

			// Simple categories via taxonomy (create if not exists).
			if ( ! empty( $event['category'] ) ) {
				wp_set_object_terms( $post_id, $event['category'], 'cral_event_category', true );
			}

			update_post_meta( $post_id, '_gec_event_code', $event['event_code'] );
			update_post_meta( $post_id, '_gec_event_date', $event_date );
			update_post_meta( $post_id, '_gec_signup_start', $signup_start );
			update_post_meta( $post_id, '_gec_signup_end', $signup_end );
			update_post_meta( $post_id, '_gec_cancellation_deadline', $cancellation_deadline );
			update_post_meta( $post_id, '_gec_max_participants', $event['max_part'] );
			update_post_meta( $post_id, '_gec_member_price', $event['member_price'] );

			$guest_types = array(
				array(
					'label' => __( 'Accompagnatore Adulto', 'gestione-eventi-cral' ),
					'price' => $event['member_price'],
					'max'   => 50,
				),
				array(
					'label' => __( 'Accompagnatore Junior', 'gestione-eventi-cral' ),
					'price' => max( 0, $event['member_price'] - 10 ),
					'max'   => 50,
				),
				array(
					'label' => __( 'Accompagnatore Socio', 'gestione-eventi-cral' ),
					'price' => max( 0, $event['member_price'] - 5 ),
					'max'   => 50,
				),
			);

			update_post_meta( $post_id, '_gec_guest_types', wp_json_encode( $guest_types ) );

			$event_ids[] = $post_id;
		}

		return $event_ids;
	}

	/**
	 * Create 3 sample members.
	 *
	 * @return int[]
	 */
	private static function create_members() {
		global $wpdb;

		$table = $wpdb->prefix . 'cral_members';

		$members = array(
			array(
				'code'      => 'M001',
				'first'     => 'Mario',
				'last'      => 'Rossi',
				'email'     => 'mario.rossi@example.com',
			),
			array(
				'code'      => 'M002',
				'first'     => 'Laura',
				'last'      => 'Bianchi',
				'email'     => 'laura.bianchi@example.com',
			),
			array(
				'code'      => 'M003',
				'first'     => 'Paolo',
				'last'      => 'Verdi',
				'email'     => 'paolo.verdi@example.com',
			),
		);

		$ids = array();

		foreach ( $members as $member ) {
			$wpdb->insert(
				$table,
				array(
					'member_code'   => $member['code'],
					'first_name'    => $member['first'],
					'last_name'     => $member['last'],
					'email'         => $member['email'],
					'password_hash' => wp_hash_password( 'Password123!' ),
					'created_at'    => current_time( 'mysql' ),
				)
			);

			if ( $wpdb->insert_id ) {
				$member_id = (int) $wpdb->insert_id;
				$ids[] = $member_id;

				// Also create/update WP user so demo members can log in.
				$username = sanitize_user( $member['code'], true );
				if ( '' === $username ) {
					$username = sanitize_user( $member['email'], true );
				}

				$user = get_user_by( 'email', $member['email'] );
				if ( ! $user ) {
					$user_id = wp_create_user( $username, 'Password123!', $member['email'] );
					if ( ! is_wp_error( $user_id ) ) {
						$user = get_user_by( 'id', $user_id );
					}
				}

				if ( $user instanceof WP_User ) {
					update_user_meta( $user->ID, 'gec_member_id', $member_id );
					wp_update_user(
						array(
							'ID'           => $user->ID,
							'display_name' => trim( $member['first'] . ' ' . $member['last'] ),
							'first_name'   => $member['first'],
							'last_name'    => $member['last'],
						)
					);
					if ( ! in_array( 'administrator', (array) $user->roles, true ) ) {
						if ( ! get_role( 'cral_member' ) ) {
							add_role( 'cral_member', 'Socio CRAL', array( 'read' => true ) );
						}
						$user->set_role( 'cral_member' );
					}
				}
			}
		}

		return $ids;
	}

	/**
	 * Create 10 sample bookings with different guests.
	 *
	 * @param int[] $event_ids Event IDs.
	 * @param int[] $member_ids Member IDs.
	 */
	private static function create_bookings( array $event_ids, array $member_ids, $extra_random = 0, $with_notes = false ) {
		global $wpdb;

		if ( empty( $event_ids ) || empty( $member_ids ) ) {
			return;
		}

		$bookings_table       = $wpdb->prefix . 'cral_bookings';
		$booking_guests_table = $wpdb->prefix . 'cral_booking_guests';

		// Pre-build event pricing/guest config map.
		$events_config = array();
		foreach ( $event_ids as $event_id ) {
			$member_price  = (float) get_post_meta( $event_id, '_gec_member_price', true );
			$guest_config  = get_post_meta( $event_id, '_gec_guest_types', true );
			$guest_types   = $guest_config ? json_decode( $guest_config, true ) : array();
			$guest_by_label = array();

			if ( is_array( $guest_types ) ) {
				foreach ( $guest_types as $g ) {
					$guest_by_label[ $g['label'] ] = $g;
				}
			}

			$events_config[ $event_id ] = array(
				'member_price' => $member_price,
				'guest_types'  => $guest_by_label,
			);
		}

		// Booking scenarios: event index, member index, guests composition.
		$scenarios = array(
			array( 0, 0, array( 'Accompagnatore Adulto' => 1 ) ),
			array( 0, 1, array( 'Accompagnatore Adulto' => 2, 'Accompagnatore Junior' => 1 ) ),
			array( 1, 0, array() ),
			array( 1, 2, array( 'Accompagnatore Junior' => 2 ) ),
			array( 2, 1, array( 'Accompagnatore Socio' => 1 ) ),
			array( 2, 2, array( 'Accompagnatore Adulto' => 1, 'Accompagnatore Socio' => 1 ) ),
			array( 3, 0, array( 'Accompagnatore Adulto' => 1 ) ),
			array( 3, 1, array( 'Accompagnatore Adulto' => 2, 'Accompagnatore Junior' => 2 ) ),
			array( 4, 2, array() ),
			array( 4, 0, array( 'Accompagnatore Adulto' => 3 ) ),
		);

		$now = current_time( 'mysql' );

		$first_names = array( 'Giulia', 'Marco', 'Elena', 'Luca', 'Sara', 'Davide', 'Chiara', 'Andrea', 'Francesca', 'Stefano' );
		$last_names  = array( 'Ferrari', 'Romano', 'Gallo', 'Costa', 'Fontana', 'Conti', 'Marini', 'Rizzi', 'Barbieri', 'Greco' );
		$notes_pool  = array(
			'Intolleranza al lattosio.',
			'Preferenza posto vicino al finestrino.',
			'Arriviamo con auto propria.',
			'Necessità accesso disabili.',
			'Portiamo passeggino.',
			'Allergia: frutta secca.',
			'Richiesta menù vegetariano.',
			'Contattare via email.',
			'Accompagnatore minorenne.',
			'Pagamento in sede.',
		);

		foreach ( $scenarios as $scenario ) {
			list( $event_index, $member_index, $guests ) = $scenario;

			if ( ! isset( $event_ids[ $event_index ], $member_ids[ $member_index ] ) ) {
				continue;
			}

			$event_id  = $event_ids[ $event_index ];
			$member_id = $member_ids[ $member_index ];

			if ( ! isset( $events_config[ $event_id ] ) ) {
				continue;
			}

			$config       = $events_config[ $event_id ];
			$member_price = $config['member_price'];
			$total        = $member_price; // base socio.
			$notes        = $with_notes ? $notes_pool[ $booking_id % count( $notes_pool ) ] : '';

			$wpdb->insert(
				$bookings_table,
				array(
					'event_id'    => $event_id,
					'member_id'   => $member_id,
					'total_amount'=> 0, // aggiorniamo dopo aver sommato gli accompagnatori.
					'notes'       => $notes,
					'status'      => 'confirmed',
					'created_at'  => $now,
				)
			);

			if ( ! $wpdb->insert_id ) {
				continue;
			}

			$booking_id = (int) $wpdb->insert_id;

			// Guests.
			foreach ( $guests as $label => $qty ) {
				$qty = (int) $qty;
				if ( $qty <= 0 ) {
					continue;
				}

				if ( ! isset( $config['guest_types'][ $label ] ) ) {
					continue;
				}

				$unit_price = (float) $config['guest_types'][ $label ]['price'];

				for ( $i = 0; $i < $qty; $i++ ) {
					$fn = $first_names[ ( $booking_id + $i ) % count( $first_names ) ];
					$ln = $last_names[ ( $event_id + $i ) % count( $last_names ) ];
					$guest_name = trim( $fn . ' ' . $ln );

					$total += $unit_price;

					$wpdb->insert(
						$booking_guests_table,
						array(
							'booking_id'  => $booking_id,
							'guest_type'  => $label,
							'guest_name'  => $guest_name,
							'quantity'    => 1,
							'unit_price'  => $unit_price,
							'total_price' => $unit_price,
						)
					);
				}
			}

			// Update total.
			$wpdb->update(
				$bookings_table,
				array( 'total_amount' => $total ),
				array( 'id' => $booking_id ),
				array( '%f' ),
				array( '%d' )
			);
		}

		// Extra random bookings (simulazione "varie prenotazioni").
		$extra_random = (int) $extra_random;
		if ( $extra_random <= 0 ) {
			return;
		}

		$guest_labels = array( 'Accompagnatore Adulto', 'Accompagnatore Junior', 'Accompagnatore Socio' );

		for ( $n = 0; $n < $extra_random; $n++ ) {
			$event_id  = $event_ids[ $n % count( $event_ids ) ];
			$member_id = $member_ids[ ( $n + 1 ) % count( $member_ids ) ];

			if ( ! isset( $events_config[ $event_id ] ) ) {
				continue;
			}

			$config       = $events_config[ $event_id ];
			$member_price = $config['member_price'];
			$total        = $member_price;
			$notes        = $with_notes ? $notes_pool[ ( $n + 3 ) % count( $notes_pool ) ] : '';

			$wpdb->insert(
				$bookings_table,
				array(
					'event_id'     => $event_id,
					'member_id'    => $member_id,
					'total_amount' => 0,
					'notes'        => $notes,
					'status'       => 'confirmed',
					'created_at'   => $now,
				)
			);

			if ( ! $wpdb->insert_id ) {
				continue;
			}

			$booking_id = (int) $wpdb->insert_id;

			// Random qty per type (0..3), then create one row per guest with name.
			foreach ( $guest_labels as $label ) {
				$qty = ( $booking_id + strlen( $label ) ) % 4; // deterministic 0..3
				if ( $qty <= 0 ) {
					continue;
				}

				if ( ! isset( $config['guest_types'][ $label ] ) ) {
					continue;
				}

				$unit_price = (float) $config['guest_types'][ $label ]['price'];

				for ( $i = 0; $i < $qty; $i++ ) {
					$fn = $first_names[ ( $booking_id + $i + $n ) % count( $first_names ) ];
					$ln = $last_names[ ( $event_id + $i + $n ) % count( $last_names ) ];
					$guest_name = trim( $fn . ' ' . $ln );

					$total += $unit_price;

					$wpdb->insert(
						$booking_guests_table,
						array(
							'booking_id'  => $booking_id,
							'guest_type'  => $label,
							'guest_name'  => $guest_name,
							'quantity'    => 1,
							'unit_price'  => $unit_price,
							'total_price' => $unit_price,
						)
					);
				}
			}

			$wpdb->update(
				$bookings_table,
				array( 'total_amount' => $total ),
				array( 'id' => $booking_id ),
				array( '%f' ),
				array( '%d' )
			);
		}
	}
}

