<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GEC_Post_Types {

	public function register() {
		add_action( 'init', array( $this, 'register_event_post_type' ) );
		add_action( 'init', array( $this, 'ensure_elementor_support_for_cral_event' ), 30 );
		add_action( 'add_meta_boxes', array( $this, 'register_event_meta_box' ) );
		add_action( 'save_post_cral_event', array( $this, 'save_event_meta' ), 10, 2 );

		// Admin list table enhancements.
		add_filter( 'manage_cral_event_posts_columns', array( $this, 'add_event_list_columns' ) );
		add_action( 'manage_cral_event_posts_custom_column', array( $this, 'render_event_list_columns' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'add_event_row_actions' ), 10, 2 );
	}

	/**
	 * Force Elementor to recognize our CPT in editor + Theme Builder conditions.
	 *
	 * Elementor uses both:
	 * - `post_type_supports( $cpt, 'elementor' )`
	 * - option `elementor_cpt_support` (array of CPT slugs)
	 */
	public function ensure_elementor_support_for_cral_event() {
		// Add post type support flag.
		add_post_type_support( 'cral_event', 'elementor' );

		// Add to Elementor supported CPTs option.
		$supported = get_option( 'elementor_cpt_support', array() );
		if ( ! is_array( $supported ) ) {
			$supported = array();
		}

		if ( ! in_array( 'cral_event', $supported, true ) ) {
			$supported[] = 'cral_event';
			update_option( 'elementor_cpt_support', array_values( array_unique( $supported ) ) );
		}
	}

	public function register_event_post_type() {
		$labels = array(
			'name'               => __( 'Attività CRAL', 'gestione-eventi-cral' ),
			'singular_name'      => __( 'Attività CRAL', 'gestione-eventi-cral' ),
			'add_new'            => __( 'Aggiungi nuova', 'gestione-eventi-cral' ),
			'add_new_item'       => __( 'Aggiungi nuova attività', 'gestione-eventi-cral' ),
			'edit_item'          => __( 'Modifica attività', 'gestione-eventi-cral' ),
			'new_item'           => __( 'Nuova attività', 'gestione-eventi-cral' ),
			'view_item'          => __( 'Vedi attività', 'gestione-eventi-cral' ),
			'search_items'       => __( 'Cerca attività', 'gestione-eventi-cral' ),
			'not_found'          => __( 'Nessuna attività trovata', 'gestione-eventi-cral' ),
			'not_found_in_trash' => __( 'Nessuna attività nel cestino', 'gestione-eventi-cral' ),
			'menu_name'          => __( 'Attività CRAL', 'gestione-eventi-cral' ),
		);

		$args = array(
			'label'               => __( 'Attività CRAL', 'gestione-eventi-cral' ),
			'labels'              => $labels,
			'public'              => true,
			'show_ui'             => true,
			'show_in_menu'        => false,
			'supports'            => array( 'title', 'editor', 'thumbnail' ),
			'has_archive'         => true,
			'rewrite'             => array( 'slug' => 'eventi-cral' ),
			'show_in_rest'        => true,
			'capability_type'     => 'post',
		);

		register_post_type( 'cral_event', $args );

		register_taxonomy(
			'cral_event_category',
			'cral_event',
			array(
				'label'             => __( 'Categorie attività', 'gestione-eventi-cral' ),
				'public'            => true,
				'hierarchical'      => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'query_var'         => true,
				'rewrite'           => array(
					'slug'         => 'categorie-attivita',
					'with_front'   => false,
					'hierarchical' => true,
				),
			)
		);
	}

	public function register_event_meta_box() {
		add_meta_box(
			'gec_event_details',
			__( 'Dettagli evento CRAL', 'gestione-eventi-cral' ),
			array( $this, 'render_event_meta_box' ),
			'cral_event',
			'normal',
			'high'
		);
	}

	public function render_event_meta_box( $post ) {
		wp_nonce_field( 'gec_save_event_meta', 'gec_event_meta_nonce' );

		$event_code               = get_post_meta( $post->ID, '_gec_event_code', true );
		$max_participants         = get_post_meta( $post->ID, '_gec_max_participants', true );
		$event_date               = get_post_meta( $post->ID, '_gec_event_date', true );
		$signup_start             = get_post_meta( $post->ID, '_gec_signup_start', true );
		$signup_end               = get_post_meta( $post->ID, '_gec_signup_end', true );
		$cancellation_deadline    = get_post_meta( $post->ID, '_gec_cancellation_deadline', true );
		$member_price             = get_post_meta( $post->ID, '_gec_member_price', true );
		$guest_config_json        = get_post_meta( $post->ID, '_gec_guest_types', true );
		$guest_config             = $guest_config_json ? json_decode( $guest_config_json, true ) : array();

		if ( ! is_array( $guest_config ) ) {
			$guest_config = array();
		}

		?>
		<table class="form-table">
			<tr>
				<th><label for="gec_event_code"><?php esc_html_e( 'Codice evento', 'gestione-eventi-cral' ); ?></label></th>
				<td><input type="text" id="gec_event_code" name="gec_event_code" value="<?php echo esc_attr( $event_code ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="gec_event_date"><?php esc_html_e( 'Data evento', 'gestione-eventi-cral' ); ?></label></th>
				<td><input type="date" id="gec_event_date" name="gec_event_date" value="<?php echo esc_attr( $event_date ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="gec_signup_start"><?php esc_html_e( 'Apertura iscrizioni', 'gestione-eventi-cral' ); ?></label></th>
				<td><input type="date" id="gec_signup_start" name="gec_signup_start" value="<?php echo esc_attr( $signup_start ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="gec_signup_end"><?php esc_html_e( 'Chiusura iscrizioni', 'gestione-eventi-cral' ); ?></label></th>
				<td><input type="date" id="gec_signup_end" name="gec_signup_end" value="<?php echo esc_attr( $signup_end ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="gec_cancellation_deadline"><?php esc_html_e( 'Termine annullamento prenotazione', 'gestione-eventi-cral' ); ?></label></th>
				<td><input type="date" id="gec_cancellation_deadline" name="gec_cancellation_deadline" value="<?php echo esc_attr( $cancellation_deadline ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="gec_max_participants"><?php esc_html_e( 'Numero massimo partecipanti', 'gestione-eventi-cral' ); ?></label></th>
				<td><input type="number" id="gec_max_participants" name="gec_max_participants" value="<?php echo esc_attr( $max_participants ); ?>" min="0" /></td>
			</tr>
			<tr>
				<th><label for="gec_member_price"><?php esc_html_e( 'Prezzo socio', 'gestione-eventi-cral' ); ?></label></th>
				<td><input type="number" step="0.01" id="gec_member_price" name="gec_member_price" value="<?php echo esc_attr( $member_price ); ?>" min="0" /></td>
			</tr>
		</table>

		<h4><?php esc_html_e( 'Tipologie accompagnatori', 'gestione-eventi-cral' ); ?></h4>
		<p><?php esc_html_e( 'Definisci le categorie di accompagnatore, il prezzo per ciascuna e il numero massimo di posti disponibili.', 'gestione-eventi-cral' ); ?></p>
		<table class="widefat" id="gec-guest-types-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Etichetta', 'gestione-eventi-cral' ); ?></th>
					<th><?php esc_html_e( 'Prezzo', 'gestione-eventi-cral' ); ?></th>
					<th><?php esc_html_e( 'Posti massimi per tipologia', 'gestione-eventi-cral' ); ?></th>
					<th><?php esc_html_e( 'Azioni', 'gestione-eventi-cral' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( ! empty( $guest_config ) ) : ?>
				<?php foreach ( $guest_config as $index => $guest_type ) : ?>
					<tr>
						<td><input type="text" name="gec_guest_types[<?php echo esc_attr( $index ); ?>][label]" value="<?php echo esc_attr( $guest_type['label'] ); ?>" /></td>
						<td><input type="number" step="0.01" name="gec_guest_types[<?php echo esc_attr( $index ); ?>][price]" value="<?php echo esc_attr( $guest_type['price'] ); ?>" min="0" /></td>
						<td><input type="number" name="gec_guest_types[<?php echo esc_attr( $index ); ?>][max]" value="<?php echo esc_attr( $guest_type['max'] ); ?>" min="0" /></td>
						<td><button type="button" class="button gec-remove-guest-row"><?php esc_html_e( 'Rimuovi', 'gestione-eventi-cral' ); ?></button></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		<p><button type="button" class="button" id="gec-add-guest-row"><?php esc_html_e( 'Aggiungi tipologia accompagnatore', 'gestione-eventi-cral' ); ?></button></p>

		<script>
			(function() {
				const tableBody = document.querySelector('#gec-guest-types-table tbody');
				const addButton = document.getElementById('gec-add-guest-row');

				if (!tableBody || !addButton) {
					return;
				}

				function addRow(label = '', price = '', max = '') {
					const index = tableBody.querySelectorAll('tr').length;
					const row = document.createElement('tr');
					row.innerHTML =
						'<td><input type="text" name="gec_guest_types[' + index + '][label]" value="' + label + '" /></td>' +
						'<td><input type="number" step="0.01" name="gec_guest_types[' + index + '][price]" value="' + price + '" min="0" /></td>' +
						'<td><input type="number" name="gec_guest_types[' + index + '][max]" value="' + max + '" min="0" /></td>' +
						'<td><button type="button" class="button gec-remove-guest-row"><?php echo esc_js( __( 'Rimuovi', 'gestione-eventi-cral' ) ); ?></button></td>';
					tableBody.appendChild(row);
				}

				addButton.addEventListener('click', function() {
					addRow();
				});

				tableBody.addEventListener('click', function(event) {
					if (event.target && event.target.classList.contains('gec-remove-guest-row')) {
						event.preventDefault();
						const row = event.target.closest('tr');
						if (row) {
							row.remove();
						}
					}
				});
			})();
		</script>
		<?php
	}

	public function save_event_meta( $post_id, $post ) {
		if ( ! isset( $_POST['gec_event_meta_nonce'] ) || ! wp_verify_nonce( $_POST['gec_event_meta_nonce'], 'gec_save_event_meta' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$fields = array(
			'gec_event_code'            => '_gec_event_code',
			'gec_event_date'            => '_gec_event_date',
			'gec_signup_start'          => '_gec_signup_start',
			'gec_signup_end'            => '_gec_signup_end',
			'gec_cancellation_deadline' => '_gec_cancellation_deadline',
			'gec_max_participants'      => '_gec_max_participants',
			'gec_member_price'          => '_gec_member_price',
		);

		foreach ( $fields as $request_key => $meta_key ) {
			$value = isset( $_POST[ $request_key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $request_key ] ) ) : '';
			update_post_meta( $post_id, $meta_key, $value );
		}

		if ( isset( $_POST['gec_guest_types'] ) && is_array( $_POST['gec_guest_types'] ) ) {
			$guest_types = array();

			foreach ( $_POST['gec_guest_types'] as $guest_type ) {
				if ( empty( $guest_type['label'] ) ) {
					continue;
				}

				$guest_types[] = array(
					'label' => sanitize_text_field( $guest_type['label'] ),
					'price' => isset( $guest_type['price'] ) ? (float) $guest_type['price'] : 0,
					'max'   => isset( $guest_type['max'] ) ? (int) $guest_type['max'] : 0,
				);
			}

			update_post_meta( $post_id, '_gec_guest_types', wp_json_encode( $guest_types ) );
		} else {
			delete_post_meta( $post_id, '_gec_guest_types' );
		}
	}

	public function add_event_list_columns( $columns ) {
		$new_columns = array();

		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;

			if ( 'title' === $key ) {
				$new_columns['gec_event_date'] = __( 'Data evento', 'gestione-eventi-cral' );
				$new_columns['gec_seats']      = __( 'Posti (occ./tot.)', 'gestione-eventi-cral' );
				$new_columns['gec_attendees']  = __( 'Iscritti', 'gestione-eventi-cral' );
			}
		}

		return $new_columns;
	}

	public function render_event_list_columns( $column, $post_id ) {
		if ( 'gec_event_date' === $column ) {
			$date = get_post_meta( $post_id, '_gec_event_date', true );
			if ( $date ) {
				echo esc_html( mysql2date( get_option( 'date_format' ), $date ) );
			} else {
				echo esc_html__( '—', 'gestione-eventi-cral' );
			}
			return;
		}

		if ( 'gec_seats' === $column ) {
			$max = (int) get_post_meta( $post_id, '_gec_max_participants', true );
			$occ = $this->get_occupied_seats_for_event( $post_id );

			if ( $max > 0 ) {
				echo esc_html( sprintf( '%d / %d', $occ, $max ) );
			} else {
				echo esc_html( $occ );
			}
			return;
		}

		if ( 'gec_attendees' === $column ) {
			$occ = $this->get_occupied_seats_for_event( $post_id );
			$url = add_query_arg(
				array(
					'page'     => 'gec-event-attendees',
					'event_id' => $post_id,
				),
				admin_url( 'admin.php' )
			);

			$link_label = sprintf(
				/* translators: %d is occupied seats */
				__( 'Vedi (%d)', 'gestione-eventi-cral' ),
				$occ
			);

			echo '<a class="gec-row-btn gec-row-btn--primary" href="' . esc_url( $url ) . '">' . esc_html( $link_label ) . '</a>';
			return;
		}
	}

	public function add_event_row_actions( $actions, $post ) {
		if ( ! $post instanceof WP_Post || 'cral_event' !== $post->post_type ) {
			return $actions;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return $actions;
		}

		$url = add_query_arg(
			array(
				'page'     => 'gec-event-attendees',
				'event_id' => $post->ID,
			),
			admin_url( 'admin.php' )
		);

		$actions['gec_attendees'] = '<a class="gec-row-btn" href="' . esc_url( $url ) . '">' . esc_html__( 'Iscritti', 'gestione-eventi-cral' ) . '</a>';

		return $actions;
	}

	private function get_occupied_seats_for_event( $event_id ) {
		global $wpdb;

		$bookings_table       = $wpdb->prefix . 'cral_bookings';
		$booking_guests_table = $wpdb->prefix . 'cral_booking_guests';

		$bookings_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$bookings_table} WHERE event_id = %d AND status = %s",
				$event_id,
				'confirmed'
			)
		);

		$guests_sum = (int) $wpdb->get_var(
			$wpdb->prepare(
				"
				SELECT COALESCE(SUM(bg.quantity), 0)
				FROM {$booking_guests_table} bg
				INNER JOIN {$bookings_table} b ON bg.booking_id = b.id
				WHERE b.event_id = %d AND b.status = %s
				",
				$event_id,
				'confirmed'
			)
		);

		return $bookings_count + $guests_sum;
	}
}

