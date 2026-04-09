<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GEC_Bookings {

	private $bookings_table;
	private $booking_guests_table;
	private $members_table;

	public function __construct() {
		global $wpdb;
		$this->bookings_table       = $wpdb->prefix . 'cral_bookings';
		$this->booking_guests_table = $wpdb->prefix . 'cral_booking_guests';
		$this->members_table        = $wpdb->prefix . 'cral_members';

		add_action( 'admin_init', array( $this, 'maybe_handle_event_attendees_csv_export' ), 1 );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		add_action( 'wp_ajax_gec_get_booking_details', array( $this, 'ajax_get_booking_details' ) );
		add_action( 'wp_ajax_gec_update_booking', array( $this, 'ajax_update_booking' ) );
		add_action( 'wp_ajax_gec_delete_booking', array( $this, 'ajax_delete_booking' ) );
		add_action( 'wp_ajax_gec_add_guest', array( $this, 'ajax_add_guest' ) );
		add_action( 'wp_ajax_gec_delete_guest', array( $this, 'ajax_delete_guest' ) );
	}

	/**
	 * Downloads CSV for event attendees.
	 * Must run before admin page output, otherwise headers are already sent.
	 */
	public function maybe_handle_event_attendees_csv_export() {
		if ( ! is_admin() ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( (string) $_GET['page'] ) : '';
		if ( 'gec-event-attendees' !== $page ) {
			return;
		}

		$export_action = isset( $_GET['gec_export'] ) ? sanitize_key( (string) $_GET['gec_export'] ) : '';
		if ( 'csv' !== $export_action ) {
			return;
		}

		$event_id = isset( $_GET['event_id'] ) ? (int) $_GET['event_id'] : 0;
		if ( $event_id <= 0 ) {
			wp_die( esc_html__( 'Evento non valido.', 'gestione-eventi-cral' ) );
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? (string) $_GET['_wpnonce'] : '';
		if ( ! wp_verify_nonce( $nonce, 'gec_export_csv_event_' . $event_id ) ) {
			wp_die( esc_html__( 'Token non valido.', 'gestione-eventi-cral' ) );
		}

		$this->output_event_attendees_csv( $event_id );
	}

	public function admin_enqueue_scripts( $hook_suffix ) {
		if ( ! isset( $_GET['page'] ) || 'gec-event-attendees' !== $_GET['page'] ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		add_thickbox();

		wp_enqueue_script(
			'gec-admin-attendees',
			GEC_PLUGIN_URL . 'assets/admin-attendees.js',
			array(),
			GEC_VERSION,
			true
		);

		wp_localize_script(
			'gec-admin-attendees',
			'gecAttendees',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'gec_booking_admin' ),
			)
		);
	}

	public function render_bookings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->render_bookings_list();
	}

	public function render_event_attendees_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$event_id = isset( $_GET['event_id'] ) ? (int) $_GET['event_id'] : 0;
		if ( $event_id <= 0 ) {
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Iscritti evento', 'gestione-eventi-cral' ); ?></h1>
				<p><?php esc_html_e( 'Evento non valido.', 'gestione-eventi-cral' ); ?></p>
			</div>
			<?php
			return;
		}

		$event_title = get_the_title( $event_id );
		$back_url    = admin_url( 'edit.php?post_type=cral_event' );

		global $wpdb;

		// Summary (confirmed bookings only).
		$max_participants = (int) get_post_meta( $event_id, '_gec_max_participants', true );

		$confirmed_bookings_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->bookings_table} WHERE event_id = %d AND status = %s",
				$event_id,
				'confirmed'
			)
		);

		$confirmed_guests_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"
				SELECT COALESCE(SUM(bg.quantity), 0)
				FROM {$this->booking_guests_table} bg
				INNER JOIN {$this->bookings_table} b ON bg.booking_id = b.id
				WHERE b.event_id = %d AND b.status = %s
				",
				$event_id,
				'confirmed'
			)
		);

		$confirmed_total_paid = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(total_amount), 0) FROM {$this->bookings_table} WHERE event_id = %d AND status = %s",
				$event_id,
				'confirmed'
			)
		);

		$seats_occupied   = $confirmed_bookings_count + $confirmed_guests_count;
		$seats_available  = $max_participants > 0 ? max( 0, $max_participants - $seats_occupied ) : null;

		$bookings = $wpdb->get_results(
			$wpdb->prepare(
				"
				SELECT b.*, m.member_code, m.first_name, m.last_name, m.email
				FROM {$this->bookings_table} b
				LEFT JOIN {$this->members_table} m ON b.member_id = m.id
				WHERE b.event_id = %d
				ORDER BY b.created_at DESC
				",
				$event_id
			)
		);

		$booking_ids = array();
		foreach ( $bookings as $b ) {
			$booking_ids[] = (int) $b->id;
		}

		$guests_by_booking = array();
		if ( ! empty( $booking_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $booking_ids ), '%d' ) );
			$query        = $wpdb->prepare(
				"SELECT * FROM {$this->booking_guests_table} WHERE booking_id IN ($placeholders) ORDER BY id ASC",
				$booking_ids
			);
			$guests = $wpdb->get_results( $query );

			foreach ( $guests as $g ) {
				$bid = (int) $g->booking_id;
				if ( ! isset( $guests_by_booking[ $bid ] ) ) {
					$guests_by_booking[ $bid ] = array();
				}
				$guests_by_booking[ $bid ][] = $g;
			}
		}

		// Build preview CSV (optional, shown on this page).
		$show_csv_preview = ! empty( $_GET['gec_export_preview'] ) && 'csv' === sanitize_key( (string) $_GET['gec_export_preview'] );
		$csv_preview_text = '';
		if ( $show_csv_preview ) {
			$csv_preview_text = $this->build_event_attendees_csv_string( $event_id, $bookings, $guests_by_booking );
		}

		$export_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'      => 'gec-event-attendees',
					'event_id'  => $event_id,
					'gec_export'=> 'csv',
				),
				admin_url( 'admin.php' )
			),
			'gec_export_csv_event_' . $event_id
		);

		$preview_url = add_query_arg(
			array(
				'page'              => 'gec-event-attendees',
				'event_id'          => $event_id,
				'gec_export_preview'=> 'csv',
			),
			admin_url( 'admin.php' )
		);

		?>
		<div class="wrap gec-wrap">
			<div class="gec-header">
				<div>
					<h1 class="gec-page-title"><?php echo esc_html( sprintf( __( 'Iscritti: %s', 'gestione-eventi-cral' ), $event_title ? $event_title : '#' . $event_id ) ); ?></h1>
					<p class="gec-subtitle"><?php esc_html_e( 'Gestisci iscrizioni, accompagnatori e note.', 'gestione-eventi-cral' ); ?></p>
				</div>
			</div>

			<div class="gec-card gec-card--padded" style="margin-top:12px;">
				<div class="gec-label"><?php esc_html_e( 'Riepilogo (confermate)', 'gestione-eventi-cral' ); ?></div>
				<div class="gec-summary" style="margin-top:10px;">
					<div class="gec-kpi">
						<div class="gec-kpi__label"><?php esc_html_e( 'Soci iscritti', 'gestione-eventi-cral' ); ?></div>
						<div class="gec-kpi__value"><?php echo esc_html( (string) $confirmed_bookings_count ); ?></div>
					</div>
					<div class="gec-kpi">
						<div class="gec-kpi__label"><?php esc_html_e( 'Accompagnatori', 'gestione-eventi-cral' ); ?></div>
						<div class="gec-kpi__value"><?php echo esc_html( (string) $confirmed_guests_count ); ?></div>
					</div>
					<div class="gec-kpi gec-kpi--accent">
						<div class="gec-kpi__label"><?php esc_html_e( 'Posti', 'gestione-eventi-cral' ); ?></div>
						<div class="gec-kpi__value">
							<?php if ( $max_participants > 0 ) : ?>
								<?php echo esc_html( sprintf( '%d / %d', $seats_occupied, $max_participants ) ); ?>
							<?php else : ?>
								<?php echo esc_html( (string) $seats_occupied ); ?>
							<?php endif; ?>
						</div>
					</div>
					<div class="gec-kpi gec-kpi--accent">
						<div class="gec-kpi__label"><?php esc_html_e( 'Totale pagato', 'gestione-eventi-cral' ); ?></div>
						<div class="gec-kpi__value"><?php echo esc_html( number_format_i18n( $confirmed_total_paid, 2 ) ); ?></div>
					</div>
				</div>

				<?php if ( $max_participants > 0 ) : ?>
					<p class="gec-subtitle" style="margin-top:10px;">
						<?php
						echo esc_html(
							sprintf(
								__( 'Disponibili: %d posti', 'gestione-eventi-cral' ),
								(int) $seats_available
							)
						);
						?>
					</p>
				<?php endif; ?>
			</div>
			<p style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
				<a class="button" href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( '← Torna agli eventi', 'gestione-eventi-cral' ); ?></a>
				<a class="button" href="<?php echo esc_url( $preview_url ); ?>"><?php esc_html_e( 'Anteprima esportazione CSV', 'gestione-eventi-cral' ); ?></a>
				<a class="button button-primary" href="<?php echo esc_url( $export_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Confermi esportazione CSV di tutte le prenotazioni di questo evento?', 'gestione-eventi-cral' ) ); ?>');">
					<?php esc_html_e( 'Esporta CSV', 'gestione-eventi-cral' ); ?>
				</a>
			</p>

			<?php if ( $show_csv_preview ) : ?>
				<div class="gec-card gec-card--padded" style="margin-top:12px;">
					<div class="gec-label"><?php esc_html_e( 'Anteprima CSV', 'gestione-eventi-cral' ); ?></div>
					<p class="gec-subtitle" style="margin-top:8px;">
						<?php esc_html_e( 'Qui sotto vedi il contenuto che verrà esportato. Se è ok, premi “Esporta CSV”.', 'gestione-eventi-cral' ); ?>
					</p>
					<textarea readonly class="large-text code" rows="14" style="margin-top:10px;white-space:pre;"><?php echo esc_textarea( $csv_preview_text ); ?></textarea>
					<p style="margin-top:10px;">
						<a class="button button-primary" href="<?php echo esc_url( $export_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Confermi esportazione CSV di tutte le prenotazioni di questo evento?', 'gestione-eventi-cral' ) ); ?>');">
							<?php esc_html_e( 'Esporta CSV', 'gestione-eventi-cral' ); ?>
						</a>
					</p>
				</div>
			<?php endif; ?>

			<table class="widefat fixed striped gec-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Socio', 'gestione-eventi-cral' ); ?></th>
						<th><?php esc_html_e( 'Codice socio', 'gestione-eventi-cral' ); ?></th>
						<th><?php esc_html_e( 'Email', 'gestione-eventi-cral' ); ?></th>
						<th><?php esc_html_e( 'Azioni', 'gestione-eventi-cral' ); ?></th>
						<th><?php esc_html_e( 'Posti', 'gestione-eventi-cral' ); ?></th>
						<th><?php esc_html_e( 'Accompagnatori (q.tà / costo)', 'gestione-eventi-cral' ); ?></th>
						<th><?php esc_html_e( 'Nomi accompagnatori', 'gestione-eventi-cral' ); ?></th>
						<th><?php esc_html_e( 'Totale', 'gestione-eventi-cral' ); ?></th>
						<th><?php esc_html_e( 'Note', 'gestione-eventi-cral' ); ?></th>
						<th><?php esc_html_e( 'Stato', 'gestione-eventi-cral' ); ?></th>
						<th><?php esc_html_e( 'Data prenotazione', 'gestione-eventi-cral' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! empty( $bookings ) ) : ?>
					<?php foreach ( $bookings as $booking ) : ?>
						<?php
						$guest_names_lines = array();
						$guest_count       = 0;
						$by_type           = array();

						$booking_guests = isset( $guests_by_booking[ (int) $booking->id ] ) ? $guests_by_booking[ (int) $booking->id ] : array();
						foreach ( $booking_guests as $g ) {
							$qty = (int) $g->quantity;
							$name = isset( $g->guest_name ) ? trim( (string) $g->guest_name ) : '';
							$type = (string) $g->guest_type;
							$unit_price = (float) $g->unit_price;

							// New model: one row per guest (quantity=1) with a name.
							if ( $name !== '' ) {
								$guest_count += 1;
								$guest_names_lines[] = sprintf( '%s: %s', $type, $name );

								if ( ! isset( $by_type[ $type ] ) ) {
									$by_type[ $type ] = array( 'qty' => 0, 'subtotal' => 0.0 );
								}
								$by_type[ $type ]['qty'] += 1;
								$by_type[ $type ]['subtotal'] += $unit_price;
								continue;
							}

							// Back-compat: old aggregated rows without names.
							if ( $qty > 0 ) {
								$guest_count += $qty;
								if ( ! isset( $by_type[ $type ] ) ) {
									$by_type[ $type ] = array( 'qty' => 0, 'subtotal' => 0.0 );
								}
								$by_type[ $type ]['qty'] += $qty;
								$by_type[ $type ]['subtotal'] += ( $unit_price * $qty );
							}
						}

						$seats_total = 1 + $guest_count;

						$summary_lines = array();
						foreach ( $by_type as $type => $data ) {
							$summary_lines[] = sprintf(
								'%s: %d / %s',
								$type,
								(int) $data['qty'],
								number_format_i18n( (float) $data['subtotal'], 2 )
							);
						}
						?>
						<tr>
							<td><?php echo esc_html( trim( $booking->first_name . ' ' . $booking->last_name ) ); ?></td>
							<td><?php echo esc_html( $booking->member_code ); ?></td>
							<td><?php echo esc_html( $booking->email ); ?></td>
							<td>
								<span class="gec-row-actions">
									<a class="gec-row-btn gec-row-btn--primary" href="#" data-gec-view-booking="<?php echo esc_attr( (string) $booking->id ); ?>">
										<?php esc_html_e( 'Dettagli / Modifica', 'gestione-eventi-cral' ); ?>
									</a>
									<a class="gec-row-btn gec-row-btn--danger" href="#" data-gec-delete-booking="<?php echo esc_attr( (string) $booking->id ); ?>">
										<?php esc_html_e( 'Elimina', 'gestione-eventi-cral' ); ?>
									</a>
								</span>
							</td>
							<td><?php echo esc_html( (string) $seats_total ); ?></td>
							<td><?php echo ! empty( $summary_lines ) ? esc_html( implode( "\n", $summary_lines ) ) : esc_html__( '—', 'gestione-eventi-cral' ); ?></td>
							<td><?php echo ! empty( $guest_names_lines ) ? esc_html( implode( "\n", $guest_names_lines ) ) : esc_html__( '—', 'gestione-eventi-cral' ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (float) $booking->total_amount, 2 ) ); ?></td>
							<td><?php echo ! empty( $booking->notes ) ? esc_html( (string) $booking->notes ) : esc_html__( '—', 'gestione-eventi-cral' ); ?></td>
							<td><?php echo esc_html( $booking->status ); ?></td>
							<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $booking->created_at ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr>
						<td colspan="11"><?php esc_html_e( 'Nessuna prenotazione trovata per questo evento.', 'gestione-eventi-cral' ); ?></td>
					</tr>
				<?php endif; ?>
				</tbody>
			</table>

			<div id="gec-booking-modal" style="display:none;">
				<div class="wrap">
					<h2><?php esc_html_e( 'Dettaglio prenotazione', 'gestione-eventi-cral' ); ?></h2>
					<p id="gec-booking-modal-status" style="margin:8px 0;color:#1d2327;"></p>

					<table class="widefat striped" style="margin-top:10px;">
						<tbody>
							<tr>
								<th style="width:180px;"><?php esc_html_e( 'ID', 'gestione-eventi-cral' ); ?></th>
								<td><span id="gec-booking-id"></span></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Evento', 'gestione-eventi-cral' ); ?></th>
								<td><span id="gec-booking-event"></span></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Socio', 'gestione-eventi-cral' ); ?></th>
								<td><span id="gec-booking-member"></span></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Email', 'gestione-eventi-cral' ); ?></th>
								<td><span id="gec-booking-email"></span></td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Data prenotazione', 'gestione-eventi-cral' ); ?></th>
								<td><span id="gec-booking-created"></span></td>
							</tr>
						</tbody>
					</table>

					<h3 style="margin-top:16px;"><?php esc_html_e( 'Modifica', 'gestione-eventi-cral' ); ?></h3>
					<p>
						<label for="gec-booking-status"><strong><?php esc_html_e( 'Stato', 'gestione-eventi-cral' ); ?></strong></label><br/>
						<select id="gec-booking-status">
							<option value="confirmed"><?php esc_html_e( 'confirmed', 'gestione-eventi-cral' ); ?></option>
							<option value="cancelled"><?php esc_html_e( 'cancelled', 'gestione-eventi-cral' ); ?></option>
							<option value="pending"><?php esc_html_e( 'pending', 'gestione-eventi-cral' ); ?></option>
						</select>
					</p>
					<p>
						<label for="gec-booking-notes"><strong><?php esc_html_e( 'Note', 'gestione-eventi-cral' ); ?></strong></label><br/>
						<textarea id="gec-booking-notes" class="large-text" rows="3"></textarea>
					</p>

					<h3 style="margin-top:16px;"><?php esc_html_e( 'Accompagnatori', 'gestione-eventi-cral' ); ?></h3>
					<div id="gec-booking-guests"></div>
					<div style="margin-top:10px;">
						<h4 style="margin:0 0 6px;"><?php esc_html_e( 'Aggiungi accompagnatore', 'gestione-eventi-cral' ); ?></h4>
						<div id="gec-booking-add-guest"></div>
					</div>

					<p style="margin-top:16px;">
						<strong><?php esc_html_e( 'Totale', 'gestione-eventi-cral' ); ?>:</strong>
						<span id="gec-booking-total"></span>
					</p>

					<p style="margin-top:16px;">
						<button class="button button-primary" id="gec-booking-save"><?php esc_html_e( 'Salva', 'gestione-eventi-cral' ); ?></button>
						<button class="button" id="gec-booking-close"><?php esc_html_e( 'Chiudi', 'gestione-eventi-cral' ); ?></button>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	private function build_event_attendees_csv_rows( $event_id, $bookings, $guests_by_booking ) {
		$event_id    = (int) $event_id;
		$event_title = get_the_title( $event_id );

		$header = array(
			'event_id',
			'event_title',
			'booking_id',
			'status',
			'created_at',
			'member_id',
			'member_code',
			'member_name',
			'member_email',
			'seats_total',
			'guest_count',
			'guests_by_type',
			'guest_names',
			'total_amount',
			'notes',
		);

		$rows = array();
		$rows[] = $header;

		if ( empty( $bookings ) ) {
			return $rows;
		}

		foreach ( $bookings as $booking ) {
			$booking_id = isset( $booking->id ) ? (int) $booking->id : 0;

			$guest_count = 0;
			$guest_names = array();
			$by_type     = array();

			$booking_guests = isset( $guests_by_booking[ $booking_id ] ) ? $guests_by_booking[ $booking_id ] : array();
			foreach ( $booking_guests as $g ) {
				$type = isset( $g->guest_type ) ? (string) $g->guest_type : '';
				$name = isset( $g->guest_name ) ? trim( (string) $g->guest_name ) : '';
				$qty  = isset( $g->quantity ) ? (int) $g->quantity : 0;
				$unit = isset( $g->unit_price ) ? (float) $g->unit_price : 0.0;

				// New model: one row per guest with name (quantity=1).
				if ( '' !== $name ) {
					$guest_count += 1;
					$guest_names[] = $name;
					if ( '' !== $type ) {
						if ( ! isset( $by_type[ $type ] ) ) {
							$by_type[ $type ] = array( 'qty' => 0, 'subtotal' => 0.0 );
						}
						$by_type[ $type ]['qty'] += 1;
						$by_type[ $type ]['subtotal'] += $unit;
					}
					continue;
				}

				// Back-compat: aggregated guests without names.
				if ( $qty > 0 ) {
					$guest_count += $qty;
					if ( '' !== $type ) {
						if ( ! isset( $by_type[ $type ] ) ) {
							$by_type[ $type ] = array( 'qty' => 0, 'subtotal' => 0.0 );
						}
						$by_type[ $type ]['qty'] += $qty;
						$by_type[ $type ]['subtotal'] += ( $unit * $qty );
					}
				}
			}

			$seats_total = 1 + $guest_count;

			$by_type_parts = array();
			foreach ( $by_type as $type => $data ) {
				$by_type_parts[] = sprintf(
					'%s=%d (%.2f)',
					$type,
					(int) $data['qty'],
					(float) $data['subtotal']
				);
			}

			$member_name = trim( (string) $booking->first_name . ' ' . (string) $booking->last_name );

			$rows[] = array(
				$event_id,
				$event_title,
				$booking_id,
				isset( $booking->status ) ? (string) $booking->status : '',
				isset( $booking->created_at ) ? (string) $booking->created_at : '',
				isset( $booking->member_id ) ? (int) $booking->member_id : 0,
				isset( $booking->member_code ) ? (string) $booking->member_code : '',
				$member_name,
				isset( $booking->email ) ? (string) $booking->email : '',
				$seats_total,
				$guest_count,
				implode( ' | ', $by_type_parts ),
				implode( ' | ', $guest_names ),
				isset( $booking->total_amount ) ? (string) $booking->total_amount : '0',
				isset( $booking->notes ) ? (string) $booking->notes : '',
			);
		}

		return $rows;
	}

	private function build_event_attendees_csv_string( $event_id, $bookings, $guests_by_booking ) {
		$rows = $this->build_event_attendees_csv_rows( $event_id, $bookings, $guests_by_booking );

		$fp = fopen( 'php://temp', 'r+' );
		if ( ! $fp ) {
			return '';
		}

		foreach ( $rows as $row ) {
			fputcsv( $fp, $row, ';' );
		}

		rewind( $fp );
		$out = stream_get_contents( $fp );
		fclose( $fp );

		return is_string( $out ) ? $out : '';
	}

	private function output_event_attendees_csv( $event_id ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'gestione-eventi-cral' ) );
		}

		$event_id    = (int) $event_id;
		$event_title = get_the_title( $event_id );

		global $wpdb;

		$bookings = $wpdb->get_results(
			$wpdb->prepare(
				"
				SELECT b.*, m.member_code, m.first_name, m.last_name, m.email
				FROM {$this->bookings_table} b
				LEFT JOIN {$this->members_table} m ON b.member_id = m.id
				WHERE b.event_id = %d
				ORDER BY b.created_at DESC
				",
				$event_id
			)
		);

		$booking_ids = array();
		foreach ( $bookings as $b ) {
			$booking_ids[] = (int) $b->id;
		}

		$guests_by_booking = array();
		if ( ! empty( $booking_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $booking_ids ), '%d' ) );
			$query        = $wpdb->prepare(
				"SELECT * FROM {$this->booking_guests_table} WHERE booking_id IN ($placeholders) ORDER BY id ASC",
				$booking_ids
			);
			$guests = $wpdb->get_results( $query );
			foreach ( $guests as $g ) {
				$bid = (int) $g->booking_id;
				if ( ! isset( $guests_by_booking[ $bid ] ) ) {
					$guests_by_booking[ $bid ] = array();
				}
				$guests_by_booking[ $bid ][] = $g;
			}
		}

		$rows = $this->build_event_attendees_csv_rows( $event_id, $bookings, $guests_by_booking );

		$filename = 'iscritti-evento-' . $event_id . '-' . sanitize_title( (string) $event_title ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$fp = fopen( 'php://output', 'w' );
		if ( ! $fp ) {
			exit;
		}

		// UTF-8 BOM for Excel compatibility.
		fwrite( $fp, "\xEF\xBB\xBF" );

		foreach ( $rows as $row ) {
			fputcsv( $fp, $row, ';' );
		}

		fclose( $fp );
		exit;
	}

	public function ajax_get_booking_details() {
		$this->ajax_require_admin();

		$booking_id = isset( $_POST['booking_id'] ) ? (int) $_POST['booking_id'] : 0;
		if ( $booking_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'booking_id non valido' ), 400 );
		}

		global $wpdb;

		$booking = $wpdb->get_row(
			$wpdb->prepare(
				"
				SELECT b.*, m.member_code, m.first_name, m.last_name, m.email, p.post_title AS event_title
				FROM {$this->bookings_table} b
				LEFT JOIN {$this->members_table} m ON b.member_id = m.id
				LEFT JOIN {$wpdb->posts} p ON b.event_id = p.ID
				WHERE b.id = %d
				",
				$booking_id
			),
			ARRAY_A
		);

		if ( ! $booking ) {
			wp_send_json_error( array( 'message' => 'Prenotazione non trovata' ), 404 );
		}

		$guests = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, guest_type, guest_name, quantity, unit_price, total_price FROM {$this->booking_guests_table} WHERE booking_id = %d ORDER BY id ASC",
				$booking_id
			),
			ARRAY_A
		);

		$guests_out = array();
		foreach ( $guests as $g ) {
			$guests_out[] = array(
				'id'                 => (int) $g['id'],
				'guest_type'         => (string) $g['guest_type'],
				'guest_name'         => (string) $g['guest_name'],
				'unit_price'         => (float) $g['unit_price'],
				'unit_price_formatted' => number_format_i18n( (float) $g['unit_price'], 2 ),
			);
		}

		$guest_types_meta = get_post_meta( (int) $booking['event_id'], '_gec_guest_types', true );
		$guest_types      = $guest_types_meta ? json_decode( $guest_types_meta, true ) : array();
		$guest_types_out  = array();
		if ( is_array( $guest_types ) ) {
			foreach ( $guest_types as $t ) {
				if ( empty( $t['label'] ) ) {
					continue;
				}
				$guest_types_out[] = array(
					'label'           => (string) $t['label'],
					'price'           => isset( $t['price'] ) ? (float) $t['price'] : 0.0,
					'price_formatted' => number_format_i18n( isset( $t['price'] ) ? (float) $t['price'] : 0.0, 2 ),
				);
			}
		}

		$out = array(
			'booking' => array(
				'id'                 => (int) $booking['id'],
				'event_id'            => (int) $booking['event_id'],
				'event_title'         => (string) $booking['event_title'],
				'member_id'           => (int) $booking['member_id'],
				'member_code'         => (string) $booking['member_code'],
				'member_name'         => trim( (string) $booking['first_name'] . ' ' . (string) $booking['last_name'] ),
				'member_email'        => (string) $booking['email'],
				'total_amount'        => (float) $booking['total_amount'],
				'total_amount_formatted' => number_format_i18n( (float) $booking['total_amount'], 2 ),
				'notes'               => (string) $booking['notes'],
				'status'              => (string) $booking['status'],
				'created_at_formatted'=> mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $booking['created_at'] ),
			),
			'guests' => $guests_out,
			'guest_types' => $guest_types_out,
		);

		wp_send_json_success( $out );
	}

	public function ajax_update_booking() {
		$this->ajax_require_admin();

		$booking_id = isset( $_POST['booking_id'] ) ? (int) $_POST['booking_id'] : 0;
		if ( $booking_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'booking_id non valido' ), 400 );
		}

		$notes  = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'confirmed';
		$allowed_status = array( 'confirmed', 'cancelled', 'pending' );
		if ( ! in_array( $status, $allowed_status, true ) ) {
			$status = 'confirmed';
		}

		$guests_json = isset( $_POST['guests'] ) ? (string) wp_unslash( $_POST['guests'] ) : '[]';
		$guests_req  = json_decode( $guests_json, true );
		if ( ! is_array( $guests_req ) ) {
			$guests_req = array();
		}

		global $wpdb;

		$booking = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->bookings_table} WHERE id = %d",
				$booking_id
			),
			ARRAY_A
		);
		if ( ! $booking ) {
			wp_send_json_error( array( 'message' => 'Prenotazione non trovata' ), 404 );
		}

		$event_id = (int) $booking['event_id'];
		$member_price = (float) get_post_meta( $event_id, '_gec_member_price', true );

		$wpdb->update(
			$this->bookings_table,
			array(
				'notes'  => $notes,
				'status' => $status,
			),
			array( 'id' => $booking_id )
		);

		// Update guest names (and keep 1 row = 1 guest).
		foreach ( $guests_req as $g ) {
			$gid = isset( $g['id'] ) ? (int) $g['id'] : 0;
			if ( $gid <= 0 ) {
				continue;
			}

			$guest_name = isset( $g['guest_name'] ) ? sanitize_text_field( wp_unslash( $g['guest_name'] ) ) : '';

			$wpdb->update(
				$this->booking_guests_table,
				array(
					'guest_name' => $guest_name,
					'quantity'   => 1,
				),
				array(
					'id'         => $gid,
					'booking_id' => $booking_id,
				)
			);

			$unit_price = (float) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT unit_price FROM {$this->booking_guests_table} WHERE id = %d AND booking_id = %d",
					$gid,
					$booking_id
				)
			);

			$wpdb->update(
				$this->booking_guests_table,
				array( 'total_price' => $unit_price ),
				array(
					'id'         => $gid,
					'booking_id' => $booking_id,
				),
				array( '%f' ),
				array( '%d', '%d' )
			);
		}

		// Recompute total: member price + sum guests' total_price.
		$guests_total = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(total_price), 0) FROM {$this->booking_guests_table} WHERE booking_id = %d",
				$booking_id
			)
		);
		$new_total = $member_price + $guests_total;

		$wpdb->update(
			$this->bookings_table,
			array( 'total_amount' => $new_total ),
			array( 'id' => $booking_id ),
			array( '%f' ),
			array( '%d' )
		);

		wp_send_json_success( array( 'total_amount' => $new_total ) );
	}

	public function ajax_delete_booking() {
		$this->ajax_require_admin();

		$booking_id = isset( $_POST['booking_id'] ) ? (int) $_POST['booking_id'] : 0;
		if ( $booking_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'booking_id non valido' ), 400 );
		}

		global $wpdb;

		$wpdb->delete( $this->booking_guests_table, array( 'booking_id' => $booking_id ), array( '%d' ) );
		$wpdb->delete( $this->bookings_table, array( 'id' => $booking_id ), array( '%d' ) );

		wp_send_json_success( array( 'deleted' => true ) );
	}

	public function ajax_add_guest() {
		$this->ajax_require_admin();

		$booking_id  = isset( $_POST['booking_id'] ) ? (int) $_POST['booking_id'] : 0;
		$guest_type  = isset( $_POST['guest_type'] ) ? sanitize_text_field( wp_unslash( $_POST['guest_type'] ) ) : '';
		$guest_name  = isset( $_POST['guest_name'] ) ? sanitize_text_field( wp_unslash( $_POST['guest_name'] ) ) : '';

		if ( $booking_id <= 0 || $guest_type === '' || $guest_name === '' ) {
			wp_send_json_error( array( 'message' => 'Parametri non validi' ), 400 );
		}

		global $wpdb;

		$booking = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->bookings_table} WHERE id = %d",
				$booking_id
			),
			ARRAY_A
		);
		if ( ! $booking ) {
			wp_send_json_error( array( 'message' => 'Prenotazione non trovata' ), 404 );
		}

		$event_id = (int) $booking['event_id'];
		$guest_types_meta = get_post_meta( $event_id, '_gec_guest_types', true );
		$guest_types      = $guest_types_meta ? json_decode( $guest_types_meta, true ) : array();
		$unit_price        = null;

		if ( is_array( $guest_types ) ) {
			foreach ( $guest_types as $t ) {
				if ( isset( $t['label'] ) && (string) $t['label'] === $guest_type ) {
					$unit_price = isset( $t['price'] ) ? (float) $t['price'] : 0.0;
					break;
				}
			}
		}

		if ( null === $unit_price ) {
			wp_send_json_error( array( 'message' => 'Tipologia accompagnatore non valida per questo evento' ), 400 );
		}

		$wpdb->insert(
			$this->booking_guests_table,
			array(
				'booking_id'  => $booking_id,
				'guest_type'  => $guest_type,
				'guest_name'  => $guest_name,
				'quantity'    => 1,
				'unit_price'  => $unit_price,
				'total_price' => $unit_price,
			),
			array( '%d', '%s', '%s', '%d', '%f', '%f' )
		);

		$this->recompute_booking_total( $booking_id, $event_id );

		wp_send_json_success( array( 'added' => true ) );
	}

	public function ajax_delete_guest() {
		$this->ajax_require_admin();

		$booking_id = isset( $_POST['booking_id'] ) ? (int) $_POST['booking_id'] : 0;
		$guest_id   = isset( $_POST['guest_id'] ) ? (int) $_POST['guest_id'] : 0;

		if ( $booking_id <= 0 || $guest_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'Parametri non validi' ), 400 );
		}

		global $wpdb;

		$booking = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->bookings_table} WHERE id = %d",
				$booking_id
			),
			ARRAY_A
		);
		if ( ! $booking ) {
			wp_send_json_error( array( 'message' => 'Prenotazione non trovata' ), 404 );
		}

		$wpdb->delete(
			$this->booking_guests_table,
			array(
				'id'         => $guest_id,
				'booking_id' => $booking_id,
			),
			array( '%d', '%d' )
		);

		$this->recompute_booking_total( $booking_id, (int) $booking['event_id'] );

		wp_send_json_success( array( 'deleted' => true ) );
	}

	private function ajax_require_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		check_ajax_referer( 'gec_booking_admin' );
	}

	private function recompute_booking_total( $booking_id, $event_id ) {
		global $wpdb;

		$member_price = (float) get_post_meta( (int) $event_id, '_gec_member_price', true );
		$guests_total = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(total_price), 0) FROM {$this->booking_guests_table} WHERE booking_id = %d",
				(int) $booking_id
			)
		);

		$new_total = $member_price + $guests_total;

		$wpdb->update(
			$this->bookings_table,
			array( 'total_amount' => $new_total ),
			array( 'id' => (int) $booking_id ),
			array( '%f' ),
			array( '%d' )
		);

		return $new_total;
	}

	private function render_bookings_list() {
		global $wpdb;

		$sql = "
			SELECT b.*, m.member_code, m.first_name, m.last_name, p.post_title AS event_title
			FROM {$this->bookings_table} b
			LEFT JOIN {$this->members_table} m ON b.member_id = m.id
			LEFT JOIN {$wpdb->posts} p ON b.event_id = p.ID
			ORDER BY b.created_at DESC
		";

		$bookings = $wpdb->get_results( $sql );

		?>
		<div class="wrap gec-wrap">
			<div class="gec-header">
				<div>
					<h1 class="gec-page-title"><?php esc_html_e( 'Prenotazioni', 'gestione-eventi-cral' ); ?></h1>
					<p class="gec-subtitle"><?php esc_html_e( 'Elenco prenotazioni (tutti gli eventi).', 'gestione-eventi-cral' ); ?></p>
				</div>
			</div>

			<table class="widefat fixed striped gec-table" style="margin-top:12px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Evento', 'gestione-eventi-cral' ); ?></th>
						<th><?php esc_html_e( 'Socio', 'gestione-eventi-cral' ); ?></th>
						<th><?php esc_html_e( 'Codice socio', 'gestione-eventi-cral' ); ?></th>
						<th><?php esc_html_e( 'Importo totale', 'gestione-eventi-cral' ); ?></th>
						<th><?php esc_html_e( 'Stato', 'gestione-eventi-cral' ); ?></th>
						<th><?php esc_html_e( 'Data prenotazione', 'gestione-eventi-cral' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! empty( $bookings ) ) : ?>
					<?php foreach ( $bookings as $booking ) : ?>
						<tr>
							<td>
								<?php if ( $booking->event_id ) : ?>
									<a href="<?php echo esc_url( get_edit_post_link( $booking->event_id ) ); ?>">
										<?php echo esc_html( $booking->event_title ); ?>
									</a>
								<?php else : ?>
									<?php esc_html_e( '(Evento eliminato)', 'gestione-eventi-cral' ); ?>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( trim( $booking->first_name . ' ' . $booking->last_name ) ); ?></td>
							<td><?php echo esc_html( $booking->member_code ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (float) $booking->total_amount, 2 ) ); ?></td>
							<td><?php echo esc_html( $booking->status ); ?></td>
							<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $booking->created_at ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr>
						<td colspan="6"><?php esc_html_e( 'Nessuna prenotazione trovata.', 'gestione-eventi-cral' ); ?></td>
					</tr>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}

