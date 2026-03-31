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

		?>
		<div class="wrap">
			<h1><?php echo esc_html( sprintf( __( 'Iscritti: %s', 'gestione-eventi-cral' ), $event_title ? $event_title : '#' . $event_id ) ); ?></h1>
			<p><a class="button" href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( '← Torna agli eventi', 'gestione-eventi-cral' ); ?></a></p>

			<table class="widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Socio', 'gestione-eventi-cral' ); ?></th>
						<th><?php esc_html_e( 'Codice socio', 'gestione-eventi-cral' ); ?></th>
						<th><?php esc_html_e( 'Email', 'gestione-eventi-cral' ); ?></th>
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
						<td colspan="10"><?php esc_html_e( 'Nessuna prenotazione trovata per questo evento.', 'gestione-eventi-cral' ); ?></td>
					</tr>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
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
		<div class="wrap">
			<h1><?php esc_html_e( 'Prenotazioni eventi', 'gestione-eventi-cral' ); ?></h1>

			<table class="widefat fixed striped">
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

