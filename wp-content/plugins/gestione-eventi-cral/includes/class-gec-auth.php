<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GEC_Auth {
	/**
	 * Cached current member.
	 *
	 * @var array|null|false
	 */
	private $current_member = false;

	/**
	 * Front-end login error (rendered by shortcode).
	 *
	 * @var string
	 */
	private $login_error = '';

	/**
	 * Last posted email for login form.
	 *
	 * @var string
	 */
	private $login_email = '';

	/**
	 * Message for member bookings shortcode.
	 *
	 * @var string
	 */
	private $bookings_message = '';

	/**
	 * Message for booking form shortcode.
	 *
	 * @var string
	 */
	private $booking_form_message = '';

	public function __construct() {
		add_action( 'init', array( $this, 'maybe_handle_frontend_login' ), 1 );
		add_action( 'init', array( $this, 'maybe_handle_member_booking_delete' ), 1 );
		add_action( 'init', array( $this, 'maybe_handle_member_event_booking_create' ), 1 );
		add_action( 'admin_init', array( $this, 'block_cral_members_from_admin' ) );
		add_action( 'login_init', array( $this, 'redirect_wp_login_to_frontend' ) );
		add_filter( 'show_admin_bar', array( $this, 'maybe_hide_admin_bar' ) );
		add_filter( 'login_redirect', array( $this, 'filter_login_redirect' ), 10, 3 );

		add_shortcode( 'cral_login', array( $this, 'render_login_shortcode' ) );
		add_shortcode( 'cral_member_header', array( $this, 'render_member_header_shortcode' ) );
		add_shortcode( 'cral_area_personale', array( $this, 'render_area_personale_shortcode' ) );
		add_shortcode( 'cral_mie_prenotazioni', array( $this, 'render_member_bookings_shortcode' ) );
		add_shortcode( 'cral_mie_prenotazioni_passate', array( $this, 'render_member_bookings_past_shortcode' ) );
		add_shortcode( 'cral_mie_prenotazioni_prossime', array( $this, 'render_member_bookings_upcoming_shortcode' ) );
		add_shortcode( 'cral_prenota_evento', array( $this, 'render_event_booking_form_shortcode' ) );
	}

	private function parse_ymd_to_start_ts( $ymd ) {
		$ymd = trim( (string) $ymd );
		if ( '' === $ymd ) {
			return 0;
		}

		$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : null;
		if ( $tz ) {
			$dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $ymd . ' 00:00:00', $tz );
			if ( $dt instanceof DateTimeImmutable ) {
				return (int) $dt->getTimestamp();
			}
		}

		$ts = strtotime( $ymd . ' 00:00:00' );
		return $ts ? (int) $ts : 0;
	}

	private function parse_ymd_to_end_ts( $ymd ) {
		$ymd = trim( (string) $ymd );
		if ( '' === $ymd ) {
			return 0;
		}

		$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : null;
		if ( $tz ) {
			$dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $ymd . ' 23:59:59', $tz );
			if ( $dt instanceof DateTimeImmutable ) {
				return (int) $dt->getTimestamp();
			}
		}

		$ts = strtotime( $ymd . ' 23:59:59' );
		return $ts ? (int) $ts : 0;
	}

	private function get_event_id_from_context_or_atts( $atts = array() ) {
		$event_id = 0;
		if ( is_array( $atts ) && isset( $atts['event_id'] ) ) {
			$event_id = (int) $atts['event_id'];
		}

		if ( $event_id <= 0 ) {
			$event_id = (int) get_queried_object_id();
		}

		if ( $event_id <= 0 || 'cral_event' !== get_post_type( $event_id ) ) {
			global $post;
			if ( $post instanceof WP_Post && 'cral_event' === $post->post_type ) {
				$event_id = (int) $post->ID;
			}
		}

		if ( $event_id <= 0 || 'cral_event' !== get_post_type( $event_id ) ) {
			return 0;
		}

		return $event_id;
	}

	private function get_event_guest_types_config( $event_id ) {
		$guest_types_meta = get_post_meta( (int) $event_id, '_gec_guest_types', true );
		$guest_types      = $guest_types_meta ? json_decode( (string) $guest_types_meta, true ) : array();

		$out = array();
		if ( is_array( $guest_types ) ) {
			foreach ( $guest_types as $t ) {
				if ( empty( $t['label'] ) ) {
					continue;
				}
				$label = (string) $t['label'];
				$key   = sanitize_key( $label );
				if ( '' === $key ) {
					$key = 't_' . substr( md5( $label ), 0, 8 );
				}

				$out[ $key ] = array(
					'label' => $label,
					'price' => isset( $t['price'] ) ? (float) $t['price'] : 0.0,
					'max'   => isset( $t['max'] ) ? (int) $t['max'] : 0,
				);
			}
		}

		return $out;
	}

	private function compute_event_occupied_seats( $event_id ) {
		global $wpdb;
		$event_id = (int) $event_id;

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

	private function get_member_active_booking_for_event( $member_id, $event_id ) {
		global $wpdb;
		$bookings_table = $wpdb->prefix . 'cral_bookings';

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$bookings_table} WHERE event_id = %d AND member_id = %d AND status <> %s ORDER BY id DESC LIMIT 1",
				(int) $event_id,
				(int) $member_id,
				'cancelled'
			)
		);

		return $row ? $row : null;
	}

	private function is_valid_guest_full_name( $name ) {
		$name = trim( (string) $name );
		if ( '' === $name ) {
			return false;
		}
		// Must contain at least one space between non-space chunks.
		return (bool) preg_match( '/\S+\s+\S+/', $name );
	}

	public function render_event_booking_form_shortcode( $atts ) {
		if ( is_admin() ) {
			return '';
		}

		$atts = shortcode_atts(
			array(
				'event_id' => 0,
			),
			$atts,
			'cral_prenota_evento'
		);

		$event_id = $this->get_event_id_from_context_or_atts( $atts );
		if ( $event_id <= 0 ) {
			return '';
		}

		$member_price = (float) get_post_meta( $event_id, '_gec_member_price', true );
		$max          = (int) get_post_meta( $event_id, '_gec_max_participants', true );
		$occupied     = $this->compute_event_occupied_seats( $event_id );
		$available    = ( $max > 0 ) ? max( 0, $max - $occupied ) : 0;

		$signup_start_raw   = (string) get_post_meta( $event_id, '_gec_signup_start', true );
		$signup_end_raw     = (string) get_post_meta( $event_id, '_gec_signup_end', true );
		$cancel_dead_raw    = (string) get_post_meta( $event_id, '_gec_cancellation_deadline', true );

		$signup_start_fmt = $signup_start_raw ? mysql2date( get_option( 'date_format' ), $signup_start_raw ) : '';
		$cancel_dead_fmt  = $cancel_dead_raw ? mysql2date( get_option( 'date_format' ), $cancel_dead_raw ) : '';

		$now_ts            = (int) current_time( 'timestamp' );
		$signup_start_ts   = $this->parse_ymd_to_start_ts( $signup_start_raw );
		$signup_end_ts     = $this->parse_ymd_to_end_ts( $signup_end_raw );
		$signup_open       = ( $signup_start_ts > 0 && $signup_end_ts > 0 ) ? ( $now_ts >= $signup_start_ts && $now_ts <= $signup_end_ts ) : true;

		$is_sold_out        = ( $max > 0 && $available <= 0 );

		$guest_types = $this->get_event_guest_types_config( $event_id );

		$member = $this->get_current_member();
		$is_logged = (bool) $member;
		$booking_existing = null;
		if ( $is_logged ) {
			$booking_existing = $this->get_member_active_booking_for_event( (int) $member['id'], $event_id );
		}

		$login_url = home_url( '/login/' );
		$current_url = ( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
		$login_with_redirect = add_query_arg( array( 'redirect_to' => rawurlencode( $current_url ) ), $login_url );

		$show_confirm_box = false;
		if ( $is_logged ) {
			$token = isset( $_GET['gec_booked'] ) ? sanitize_text_field( wp_unslash( $_GET['gec_booked'] ) ) : '';
			if ( '' !== $token ) {
				$transient_key = 'gec_booked_' . (int) $member['id'] . '_' . (int) $event_id;
				$expected      = (string) get_transient( $transient_key );
				if ( '' !== $expected && hash_equals( $expected, $token ) ) {
					$show_confirm_box = true;
					delete_transient( $transient_key );
				}
			}
		}

		ob_start();
		?>
		<div id="gec-booking-box" class="gec-event-booking-form" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:16px;padding:14px 14px;">
			<?php if ( $show_confirm_box ) : ?>
				<div style="margin:0 0 12px;border:1px solid #bbf7d0;background:#ecfdf5;border-radius:14px;padding:10px 12px;">
					<div style="font-weight:900;color:#065f46;"><?php esc_html_e( 'Prenotazione confermata', 'gestione-eventi-cral' ); ?></div>
					<div style="margin-top:4px;font-size:13px;color:#064e3b;">
						<?php esc_html_e( 'La tua prenotazione è stata registrata correttamente.', 'gestione-eventi-cral' ); ?>
					</div>
				</div>
				<script>
					(function() {
						try {
							const url = new URL(window.location.href);
							url.searchParams.delete('gec_booked');
							window.history.replaceState({}, document.title, url.toString());
						} catch (e) {}
					})();
				</script>
			<?php endif; ?>

			<?php if ( '' !== $this->booking_form_message ) : ?>
				<div class="notice notice-info" style="padding:10px 12px;margin:0 0 12px;">
					<p style="margin:0;"><?php echo esc_html( $this->booking_form_message ); ?></p>
				</div>
			<?php endif; ?>

			<div style="padding:10px 0 12px;">
				<div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
					<?php if ( $signup_start_fmt ) : ?>
						<span style="font-size:13px;color:#374151;"><strong><?php echo esc_html( sprintf( __( "E' possibile prenotarsi all'evento entro: %s", 'gestione-eventi-cral' ), $signup_start_fmt ) ); ?></strong></span>
					<?php endif; ?>
					<?php if ( $cancel_dead_fmt ) : ?>
						<span style="font-size:13px;color:#6b7280;"><?php echo esc_html( sprintf( __( "Puoi cancellarti all'evento entro: %s", 'gestione-eventi-cral' ), $cancel_dead_fmt ) ); ?></span>
					<?php endif; ?>
					<?php if ( $max > 0 ) : ?>
						<span style="font-size:13px;color:#111827;"><?php echo esc_html( sprintf( __( 'Posti disponibili: %d', 'gestione-eventi-cral' ), (int) $available ) ); ?></span>
					<?php endif; ?>
					<?php if ( $is_sold_out ) : ?>
						<span style="font-size:12px;font-weight:700;color:#991b1b;background:#fee2e2;border:1px solid #fecaca;padding:3px 8px;border-radius:999px;"><?php esc_html_e( 'SOLD OUT', 'gestione-eventi-cral' ); ?></span>
					<?php endif; ?>
				</div>
			</div>

			<div class="gec-card gec-card--padded" style="margin-top:10px;">
				<div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;justify-content:space-between;">
					<div style="flex:1;min-width:240px;">
						<div style="font-weight:750;color:#0f2a52;"><?php esc_html_e( 'Prezzi', 'gestione-eventi-cral' ); ?></div>
						<div style="margin-top:10px;border:1px solid #dbe3ee;border-radius:14px;padding:10px 12px;background:linear-gradient(180deg,#ffffff 0%, #f4f6f9 100%);">
							<div style="font-size:12px;letter-spacing:.2px;color:#334155;font-weight:800;">
								<?php esc_html_e( 'Prezzo riservato ai soci CRAL', 'gestione-eventi-cral' ); ?>
							</div>
							<div style="margin-top:4px;font-size:26px;font-weight:900;color:#0f2a52;line-height:1.05;">
								<?php echo esc_html( number_format_i18n( $member_price, 2 ) ); ?> €
							</div>
						</div>
					</div>
				</div>

				<?php if ( ! empty( $guest_types ) ) : ?>
					<div style="margin-top:12px;display:grid;grid-template-columns:1fr;gap:10px;">
						<?php foreach ( $guest_types as $cfg ) : ?>
							<div style="border:1px solid #e5e7eb;border-radius:12px;padding:10px 12px;background:#fff;">
								<div style="display:flex;gap:10px;align-items:flex-start;justify-content:space-between;">
									<div style="min-width:0;">
										<div style="font-weight:700;color:#111827;line-height:1.2;">
											<?php echo esc_html( (string) $cfg['label'] ); ?>
										</div>
										<div style="margin-top:4px;font-size:12px;color:#6b7280;">
											<?php
											$max_txt = (int) $cfg['max'] > 0 ? sprintf( __( 'Max %d posti', 'gestione-eventi-cral' ), (int) $cfg['max'] ) : __( 'Max: —', 'gestione-eventi-cral' );
											echo esc_html( $max_txt );
											?>
										</div>
									</div>
									<div style="text-align:right;white-space:nowrap;">
										<div style="font-size:12px;color:#6b7280;"><?php esc_html_e( 'Costo', 'gestione-eventi-cral' ); ?></div>
										<div style="font-size:16px;font-weight:800;color:#111827;line-height:1.1;">
											<?php echo esc_html( number_format_i18n( (float) $cfg['price'], 2 ) ); ?> €
										</div>
									</div>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<?php if ( ! $is_logged ) : ?>
					<div style="margin-top:12px;padding-top:12px;border-top:1px solid #e5e7eb;">
						<p style="margin:0;color:#374151;">
							<?php esc_html_e( 'Per prenotarti devi effettuare il login.', 'gestione-eventi-cral' ); ?>
							<a href="<?php echo esc_url( $login_with_redirect ); ?>" style="text-decoration:underline;"><?php esc_html_e( 'Accedi', 'gestione-eventi-cral' ); ?></a>
						</p>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( $is_logged ) : ?>
				<?php if ( $booking_existing ) : ?>
					<?php
					global $wpdb;
					$booking_guests_table = $wpdb->prefix . 'cral_booking_guests';
					$guests = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT guest_type, guest_name, unit_price FROM {$booking_guests_table} WHERE booking_id = %d ORDER BY id ASC",
							(int) $booking_existing->id
						)
					);

					$guest_lines = array();
					foreach ( $guests as $g ) {
						$n = trim( (string) $g->guest_name );
						if ( '' === $n ) {
							continue;
						}
						$guest_lines[] = sprintf(
							'%s — %s (%s €)',
							(string) $g->guest_type,
							$n,
							number_format_i18n( (float) $g->unit_price, 2 )
						);
					}

					$deadline_raw = (string) get_post_meta( $event_id, '_gec_cancellation_deadline', true );
					$deadline_ts  = $deadline_raw ? strtotime( $deadline_raw . ' 23:59:59' ) : 0;
					$deadline_fmt = $deadline_raw ? mysql2date( get_option( 'date_format' ), $deadline_raw ) : '';
					$can_cancel   = $deadline_ts > 0 && current_time( 'timestamp' ) <= $deadline_ts;

					$current_url = ( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
					$cancel_url = add_query_arg(
						array(
							'gec_action' => 'delete_booking',
							'booking_id' => (int) $booking_existing->id,
							'_wpnonce'   => wp_create_nonce( 'gec_delete_booking_' . (int) $booking_existing->id ),
						),
						$current_url
					);
					?>

					<div class="gec-card gec-card--padded" style="margin-top:10px;">
						<div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;">
							<div style="font-weight:900;color:#0f2a52;"><?php esc_html_e( 'Riepilogo prenotazione', 'gestione-eventi-cral' ); ?></div>
							<div style="font-weight:900;color:#0f2a52;white-space:nowrap;">
								<?php echo esc_html( number_format_i18n( (float) $booking_existing->total_amount, 2 ) ); ?> €
							</div>
						</div>

						<div style="margin-top:8px;font-size:13px;color:#374151;line-height:1.35;">
							<div><strong><?php esc_html_e( 'Data prenotazione', 'gestione-eventi-cral' ); ?>:</strong> <?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $booking_existing->created_at ) ); ?></div>
							<?php if ( ! empty( $guest_lines ) ) : ?>
								<div style="margin-top:6px;"><strong><?php esc_html_e( 'Accompagnatori', 'gestione-eventi-cral' ); ?>:</strong></div>
								<ul style="margin:6px 0 0 18px;">
									<?php foreach ( $guest_lines as $line ) : ?>
										<li style="margin:2px 0;"><?php echo esc_html( $line ); ?></li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
							<?php if ( ! empty( $booking_existing->notes ) ) : ?>
								<div style="margin-top:6px;"><strong><?php esc_html_e( 'Note', 'gestione-eventi-cral' ); ?>:</strong> <?php echo esc_html( (string) $booking_existing->notes ); ?></div>
							<?php endif; ?>

							<?php if ( $deadline_fmt ) : ?>
								<div style="margin-top:8px;font-size:13px;color:#6b7280;">
									<?php echo esc_html( sprintf( __( "Puoi cancellarti all'evento entro: %s", 'gestione-eventi-cral' ), $deadline_fmt ) ); ?>
								</div>
							<?php endif; ?>
						</div>

						<div style="margin-top:10px;">
							<?php if ( $can_cancel ) : ?>
								<a class="gec-row-btn" href="<?php echo esc_url( $cancel_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Annullare questa prenotazione?', 'gestione-eventi-cral' ) ); ?>');" style="height:32px;padding:0 12px;border-radius:10px;background:#fff;border:1px solid #ef4444;color:#b91c1c;font-weight:800;">
									<?php esc_html_e( 'Annulla prenotazione', 'gestione-eventi-cral' ); ?>
								</a>
							<?php else : ?>
								<span style="font-size:13px;color:#6b7280;"><?php esc_html_e( 'Annullamento non disponibile.', 'gestione-eventi-cral' ); ?></span>
							<?php endif; ?>
						</div>
					</div>

				<?php elseif ( ! $signup_open ) : ?>
					<p style="margin-top:12px;color:#991b1b;font-weight:600;">
						<?php esc_html_e( 'Non è possibile prenotarsi: iscrizioni chiuse.', 'gestione-eventi-cral' ); ?>
					</p>
				<?php elseif ( $is_sold_out ) : ?>
					<p style="margin-top:12px;color:#991b1b;font-weight:600;">
						<?php esc_html_e( 'Non è possibile prenotarsi: posti esauriti.', 'gestione-eventi-cral' ); ?>
					</p>
				<?php else : ?>
					<form method="post" style="margin-top:12px;">
						<?php wp_nonce_field( 'gec_frontend_booking', 'gec_frontend_booking_nonce' ); ?>
						<input type="hidden" name="gec_frontend_booking" value="1" />
						<input type="hidden" name="gec_event_id" value="<?php echo esc_attr( (string) $event_id ); ?>" />

						<div class="gec-card gec-card--padded" style="margin-top:10px;">
							<?php
							$member_code = isset( $member['member_code'] ) ? (string) $member['member_code'] : '';
							$member_name = trim( (string) $member['first_name'] . ' ' . (string) $member['last_name'] );
							?>
							<div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;">
								<div style="font-weight:800;color:#0f2a52;">
									<?php esc_html_e( 'Il tuo posto', 'gestione-eventi-cral' ); ?>:
									<span style="font-weight:700;color:#111827;"><?php echo esc_html( $member_code ); ?></span>
									<span style="opacity:.7;">—</span>
									<span style="font-weight:700;color:#111827;"><?php echo esc_html( $member_name ); ?></span>
								</div>
								<div style="font-weight:900;color:#0f2a52;white-space:nowrap;">
									<?php echo esc_html( number_format_i18n( $member_price, 2 ) ); ?> €
								</div>
							</div>

							<div style="margin-top:10px;padding-top:10px;border-top:1px solid #e5e7eb;">
								<div style="font-weight:750;margin-bottom:6px;color:#111827;"><?php esc_html_e( 'Accompagnatori', 'gestione-eventi-cral' ); ?></div>

							<?php if ( empty( $guest_types ) ) : ?>
								<p style="margin:0;color:#6b7280;"><?php esc_html_e( 'Nessuna tipologia accompagnatore configurata per questa attività.', 'gestione-eventi-cral' ); ?></p>
							<?php else : ?>
								<?php foreach ( $guest_types as $key => $cfg ) : ?>
									<div style="padding:10px 0;border-top:1px solid #e5e7eb;">
										<div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;">
											<div style="min-width:220px;">
												<div style="font-weight:800;color:#111827;line-height:1.2;"><?php echo esc_html( (string) $cfg['label'] ); ?></div>
												<div style="margin-top:3px;font-size:12px;color:#6b7280;line-height:1.2;">
													<?php
													$max_txt = (int) $cfg['max'] > 0 ? sprintf( __( 'Max %d posti', 'gestione-eventi-cral' ), (int) $cfg['max'] ) : __( 'Max: —', 'gestione-eventi-cral' );
													echo esc_html( sprintf( __( 'Costo: %1$s € • %2$s', 'gestione-eventi-cral' ), number_format_i18n( (float) $cfg['price'], 2 ), $max_txt ) );
													?>
												</div>
											</div>

											<div style="display:flex;gap:8px;align-items:center;">
												<button
													type="button"
													class="gec-row-btn"
													data-gec-add-guest
													data-gec-guest-key="<?php echo esc_attr( (string) $key ); ?>"
													data-gec-guest-label="<?php echo esc_attr( (string) $cfg['label'] ); ?>"
													data-gec-guest-price="<?php echo esc_attr( (string) (float) $cfg['price'] ); ?>"
													data-gec-guest-max="<?php echo esc_attr( (string) (int) $cfg['max'] ); ?>"
													style="white-space:nowrap;height:28px;padding:0 10px;border-radius:999px;font-size:12px;font-weight:700;background:#f3f4f6;border:1px solid #d4d4d8;color:#111827;"
												>
													<?php esc_html_e( 'Aggiungi accompagnatore', 'gestione-eventi-cral' ); ?>
												</button>
											</div>
										</div>

										<div data-gec-guest-names-wrap="<?php echo esc_attr( (string) $key ); ?>" style="margin-top:8px;display:grid;gap:6px;"></div>
									</div>
								<?php endforeach; ?>
							<?php endif; ?>
							</div>
						</div>

						<div class="gec-card gec-card--padded" style="margin-top:10px;">
							<div style="font-weight:700;margin-bottom:8px;"><?php esc_html_e( 'Note', 'gestione-eventi-cral' ); ?></div>
							<textarea name="gec_notes" rows="3" class="large-text" style="min-height:70px;" placeholder="<?php echo esc_attr__( 'Scrivi eventuali note...', 'gestione-eventi-cral' ); ?>"></textarea>
						</div>

						<div class="gec-card gec-card--padded" style="margin-top:10px;">
							<div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;">
								<div style="font-size:13px;color:#374151;">
									<?php esc_html_e( 'Totale posti richiesti:', 'gestione-eventi-cral' ); ?>
									<span id="gec-seats-requested" style="font-weight:900;margin-left:6px;">1</span>
								</div>
								<div style="font-size:15px;color:#111827;">
									<?php esc_html_e( 'Totale:', 'gestione-eventi-cral' ); ?>
									<strong>
										<span id="gec-total-amount"><?php echo esc_html( number_format_i18n( $member_price, 2 ) ); ?></span> €
									</strong>
								</div>
								<button type="submit" class="gec-row-btn gec-row-btn--primary" style="white-space:nowrap;">
									<?php esc_html_e( 'Prenotati', 'gestione-eventi-cral' ); ?>
								</button>
							</div>
							<div style="margin-top:8px;font-size:12px;color:#6b7280;">
								<?php esc_html_e( 'Inserisci Nome e Cognome per ogni accompagnatore.', 'gestione-eventi-cral' ); ?>
							</div>
						</div>
					</form>

					<script>
						(function() {
							const memberPrice = <?php echo wp_json_encode( (float) $member_price ); ?>;
							const maxAvailable = <?php echo wp_json_encode( (int) $available ); ?>;

							function formatPrice(n) {
								try {
									return (n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
								} catch (e) {
									return String(n.toFixed(2));
								}
							}

							function countGuestsInWrap(wrap) {
								if (!wrap) return 0;
								return wrap.querySelectorAll('input[type="text"][data-gec-guest-name="1"]').length;
							}

							function addGuestRow(key, label, price, maxPerType) {
								const wrap = document.querySelector('[data-gec-guest-names-wrap="' + key + '"]');
								if (!wrap) return;

								const current = countGuestsInWrap(wrap);
								if (maxPerType > 0 && current >= maxPerType) {
									return;
								}

								const row = document.createElement('div');
								row.style.display = 'flex';
								row.style.gap = '8px';
								row.style.alignItems = 'center';
								row.innerHTML =
									'<input data-gec-guest-name="1" type="text" name="gec_guests[' + key + '][]" placeholder="nome e cognome" style="flex:1;min-width:0;max-width:520px;height:36px;padding:6px 10px;border:1px solid #d4d4d8;border-radius:10px;" required />' +
									'<button type="button" class="gec-row-btn" style="height:36px;width:36px;padding:0;border-radius:10px;background:#f3f4f6;border:1px solid #d4d4d8;color:#111827;" data-gec-remove-guest>×</button>';
								wrap.appendChild(row);

								// Disable add button if max reached.
								const btn = document.querySelector('[data-gec-add-guest][data-gec-guest-key="' + key + '"]');
								if (btn) {
									const after = countGuestsInWrap(wrap);
									if (maxPerType > 0 && after >= maxPerType) {
										btn.disabled = true;
										btn.style.opacity = '0.6';
									}
								}
							}

							function recompute() {
								let guests = 0;
								let total = memberPrice;

								document.querySelectorAll('[data-gec-guest-names-wrap]').forEach((wrap) => {
									const key = wrap.getAttribute('data-gec-guest-names-wrap');
									const btn = document.querySelector('[data-gec-add-guest][data-gec-guest-key="' + key + '"]');
									const price = btn ? (parseFloat(btn.getAttribute('data-gec-guest-price') || '0') || 0) : 0;
									const maxPerType = btn ? (parseInt(btn.getAttribute('data-gec-guest-max') || '0', 10) || 0) : 0;
									const count = countGuestsInWrap(wrap);
									guests += count;
									total += (count * price);

									if (btn) {
										if (maxPerType > 0 && count >= maxPerType) {
											btn.disabled = true;
											btn.style.opacity = '0.6';
										} else {
											btn.disabled = false;
											btn.style.opacity = '1';
										}
									}
								});

								const seatsRequested = 1 + guests;
								const seatsEl = document.getElementById('gec-seats-requested');
								const totalEl = document.getElementById('gec-total-amount');
								if (seatsEl) seatsEl.textContent = String(seatsRequested);
								if (totalEl) totalEl.textContent = formatPrice(total);

								// Soft warning if over capacity.
								const form = document.querySelector('.gec-event-booking-form form');
								if (form) {
									form.dataset.gecSeatsRequested = String(seatsRequested);
									form.dataset.gecMaxAvailable = String(maxAvailable);
								}
							}

							document.addEventListener('click', function(e) {
								const addBtn = e.target && e.target.closest ? e.target.closest('[data-gec-add-guest]') : null;
								if (addBtn) {
									e.preventDefault();
									const key = addBtn.getAttribute('data-gec-guest-key');
									const label = addBtn.getAttribute('data-gec-guest-label') || '';
									const price = parseFloat(addBtn.getAttribute('data-gec-guest-price') || '0') || 0;
									const maxPerType = parseInt(addBtn.getAttribute('data-gec-guest-max') || '0', 10) || 0;
									addGuestRow(key, label, price, maxPerType);
									recompute();
									return;
								}

								const removeBtn = e.target && e.target.closest ? e.target.closest('[data-gec-remove-guest]') : null;
								if (removeBtn) {
									e.preventDefault();
									const row = removeBtn.closest('div');
									if (row && row.parentNode) {
										// Re-enable the add button for that section.
										const wrap = row.parentNode;
										const key = wrap.getAttribute('data-gec-guest-names-wrap');
										const btn = document.querySelector('[data-gec-add-guest][data-gec-guest-key="' + key + '"]');
										if (btn) {
											btn.disabled = false;
											btn.style.opacity = '1';
										}
										row.remove();
									}
									recompute();
									return;
								}
							});

							document.addEventListener('input', function(e) {
								if (e.target && e.target.matches('input[type="text"][data-gec-guest-name="1"]')) {
									// Keep totals updated (not strictly necessary but nice).
									recompute();
								}
							});

							recompute();
						})();
					</script>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Get currently logged in member (or null).
	 *
	 * @return array|null
	 */
	public function get_current_member() {
		if ( false !== $this->current_member ) {
			return $this->current_member;
		}

		if ( ! is_user_logged_in() ) {
			$this->current_member = null;
			return null;
		}

		$user = wp_get_current_user();
		if ( ! $user || empty( $user->ID ) ) {
			$this->current_member = null;
			return null;
		}

		$member_id = (int) get_user_meta( $user->ID, 'gec_member_id', true );

		global $wpdb;
		$table = $wpdb->prefix . 'cral_members';

		$row = null;
		if ( $member_id > 0 ) {
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $member_id ),
				ARRAY_A
			);
		}

		// If no mapping yet, fallback by email and store mapping.
		if ( ! $row && ! empty( $user->user_email ) ) {
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s LIMIT 1", $user->user_email ),
				ARRAY_A
			);

			if ( $row && ! empty( $row['id'] ) ) {
				update_user_meta( $user->ID, 'gec_member_id', (int) $row['id'] );
			}
		}

		$this->current_member = $row ? $row : null;
		return $this->current_member;
	}

	public function render_login_shortcode( $atts ) {
		if ( is_admin() ) {
			return '';
		}

		$member = $this->get_current_member();
		if ( $member ) {
			$area_url   = home_url( '/area-personale/' );
			$events_url = home_url( '/eventi/' );
			$logout_url = wp_logout_url( ( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] );

			ob_start();
			?>
			<div class="gec-login-box">
				<ul style="margin:0 0 10px 18px;">
					<li><a href="<?php echo esc_url( $area_url ); ?>"><?php esc_html_e( 'Vai alla tua area personale', 'gestione-eventi-cral' ); ?></a></li>
					<li><a href="<?php echo esc_url( $events_url ); ?>"><?php esc_html_e( 'Vai alla pagina eventi', 'gestione-eventi-cral' ); ?></a></li>
				</ul>
				<a href="<?php echo esc_url( $logout_url ); ?>" style="border:0;background:none;padding:0;text-decoration:underline;cursor:pointer;">
					<?php esc_html_e( 'Disconnettiti', 'gestione-eventi-cral' ); ?>
				</a>
			</div>
			<?php
			return ob_get_clean();
		}

		ob_start();
		?>
		<div class="gec-login-form">
			<h2><?php esc_html_e( 'Accesso Soci', 'gestione-eventi-cral' ); ?></h2>
			<?php if ( ! empty( $this->login_error ) ) : ?>
				<div class="notice notice-error" style="padding:8px 10px;margin:8px 0 12px;">
					<p><?php echo esc_html( $this->login_error ); ?></p>
				</div>
			<?php endif; ?>
			<form method="post">
				<?php wp_nonce_field( 'gec_frontend_login', 'gec_frontend_login_nonce' ); ?>
				<input type="hidden" name="gec_frontend_login" value="1" />
				<p>
					<label for="gec_email"><?php esc_html_e( 'Email', 'gestione-eventi-cral' ); ?></label><br/>
					<input type="email" id="gec_email" name="gec_email" class="regular-text" required value="<?php echo esc_attr( $this->login_email ); ?>" />
				</p>
				<p>
					<label for="gec_password"><?php esc_html_e( 'Password', 'gestione-eventi-cral' ); ?></label><br/>
					<input type="password" id="gec_password" name="gec_password" class="regular-text" required />
				</p>
				<p>
					<label>
						<input type="checkbox" name="gec_remember" value="1" checked />
						<?php esc_html_e( 'Ricordami', 'gestione-eventi-cral' ); ?>
					</label>
				</p>
				<p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Accedi', 'gestione-eventi-cral' ); ?></button>
				</p>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	public function render_member_header_shortcode() {
		if ( is_admin() ) {
			return '';
		}

		$member = $this->get_current_member();

		$login_url = home_url( '/login/' );
		$area_url   = home_url( '/area-personale/' );

		ob_start();
		?>
		<div class="gec-member-header">
			<?php if ( $member ) : ?>
				<?php
				$name = trim( $member['first_name'] . ' ' . $member['last_name'] );
				?>
				<a href="<?php echo esc_url( $area_url ); ?>" class="gec-member-header__greeting" style="text-decoration:none;color:inherit;">
					<span style="font-weight:400;"><?php esc_html_e( 'Ciao', 'gestione-eventi-cral' ); ?></span>
					<span style="font-weight:400;">,</span>
					<span style="font-weight:700;font-size:1.1em;"><?php echo esc_html( $name ); ?></span>
				</a>
			<?php else : ?>
				<a class="gec-row-btn gec-row-btn--primary" href="<?php echo esc_url( $login_url ); ?>">
					<?php esc_html_e( 'Accedi', 'gestione-eventi-cral' ); ?>
				</a>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	public function render_area_personale_shortcode() {
		if ( is_admin() ) {
			return '';
		}

		if ( ! is_user_logged_in() ) {
			$login_url = home_url( '/login/' );
			return '<p><a href="' . esc_url( $login_url ) . '">' . esc_html__( 'Accedi', 'gestione-eventi-cral' ) . '</a></p>';
		}

		$user   = wp_get_current_user();
		$member = $this->get_current_member();

		if ( ! $member ) {
			$login_url = home_url( '/login/' );
			return '<p>' . esc_html__( 'Profilo socio non trovato.', 'gestione-eventi-cral' ) . ' <a href="' . esc_url( $login_url ) . '">' . esc_html__( 'Accedi', 'gestione-eventi-cral' ) . '</a></p>';
		}

		$logout_url = wp_logout_url( home_url( '/login/' ) );

		$member_code = isset( $member['member_code'] ) ? (string) $member['member_code'] : '';
		$username    = $user instanceof WP_User ? (string) $user->user_login : '';
		$email       = $user instanceof WP_User ? (string) $user->user_email : ( isset( $member['email'] ) ? (string) $member['email'] : '' );

		ob_start();
		?>
		<div class="gec-area-personale">
			<p><strong><?php esc_html_e( 'Codice Socio', 'gestione-eventi-cral' ); ?>:</strong> <?php echo esc_html( $member_code ); ?></p>
			<p><strong><?php esc_html_e( 'Username', 'gestione-eventi-cral' ); ?>:</strong> <?php echo esc_html( $username ); ?></p>
			<p><strong><?php esc_html_e( 'Email', 'gestione-eventi-cral' ); ?>:</strong> <?php echo esc_html( $email ); ?></p>
			<p><strong><?php esc_html_e( 'Password', 'gestione-eventi-cral' ); ?>:</strong> *****</p>
			<p style="margin-top:12px;">
				<a href="<?php echo esc_url( $logout_url ); ?>" style="border:0;background:none;padding:0;text-decoration:underline;cursor:pointer;">
					<?php esc_html_e( 'Disconnettiti', 'gestione-eventi-cral' ); ?>
				</a>
			</p>
		</div>
		<?php
		return ob_get_clean();
	}

	public function render_member_bookings_shortcode() {
		return $this->render_member_bookings( 'all' );
	}

	public function render_member_bookings_past_shortcode() {
		return $this->render_member_bookings( 'past' );
	}

	public function render_member_bookings_upcoming_shortcode() {
		return $this->render_member_bookings( 'upcoming' );
	}

	private function parse_event_date_to_end_of_day_ts( $event_date_raw ) {
		$event_date_raw = trim( (string) $event_date_raw );
		if ( '' === $event_date_raw ) {
			return 0;
		}

		// Expected format from admin meta box: YYYY-MM-DD.
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $event_date_raw ) ) {
			$ts = strtotime( $event_date_raw . ' 23:59:59' );
			return $ts ? (int) $ts : 0;
		}

		// Defensive: sometimes dates are stored as dd/mm/yyyy.
		$dt = DateTime::createFromFormat( 'd/m/Y H:i:s', $event_date_raw . ' 23:59:59' );
		if ( $dt instanceof DateTime ) {
			return (int) $dt->getTimestamp();
		}

		// Last resort.
		$ts = strtotime( $event_date_raw );
		return $ts ? (int) $ts : 0;
	}

	private function render_member_bookings( $mode ) {
		if ( is_admin() ) {
			return '';
		}

		$member = $this->get_current_member();
		if ( ! $member ) {
			$login_url = home_url( '/login/' );
			return '<p><a href="' . esc_url( $login_url ) . '">' . esc_html__( 'Accedi', 'gestione-eventi-cral' ) . '</a></p>';
		}

		global $wpdb;
		$bookings_table       = $wpdb->prefix . 'cral_bookings';
		$booking_guests_table = $wpdb->prefix . 'cral_booking_guests';

		$member_id = (int) $member['id'];
		$rows      = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$bookings_table} WHERE member_id = %d ORDER BY created_at DESC LIMIT 50",
				$member_id
			)
		);

		$now_ts = (int) current_time( 'timestamp' );

		// Filter by event date.
		$filtered = array();
		foreach ( $rows as $b ) {
			$event_id = (int) $b->event_id;
			if ( $event_id <= 0 ) {
				continue;
			}

			$event_date_raw = get_post_meta( $event_id, '_gec_event_date', true );
			$event_end_ts   = $this->parse_event_date_to_end_of_day_ts( $event_date_raw );

			if ( $event_end_ts <= 0 ) {
				continue;
			}

			$is_past     = $now_ts > $event_end_ts;   // concluso (fine giornata passata)
			$is_upcoming = ! $is_past;

			if ( 'past' === $mode && $is_past ) {
				$filtered[] = $b;
			} elseif ( 'upcoming' === $mode && $is_upcoming ) {
				$filtered[] = $b;
			} elseif ( 'all' === $mode ) {
				$filtered[] = $b;
			}
		}

		$rows = $filtered;
		$rows = array_slice( $rows, 0, 20 );

		ob_start();
		?>
		<div class="gec-member-bookings">
			<?php if ( ! empty( $this->bookings_message ) ) : ?>
				<div class="notice notice-info" style="padding:8px 10px;margin:8px 0 12px;">
					<p><?php echo esc_html( $this->bookings_message ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( empty( $rows ) ) : ?>
				<?php if ( 'past' === $mode ) : ?>
					<p><?php esc_html_e( 'Non hai prenotazioni passate.', 'gestione-eventi-cral' ); ?></p>
				<?php elseif ( 'upcoming' === $mode ) : ?>
					<p><?php esc_html_e( 'Non hai prenotazioni per eventi futuri.', 'gestione-eventi-cral' ); ?></p>
				<?php else : ?>
					<p><?php esc_html_e( 'Non hai ancora effettuato prenotazioni.', 'gestione-eventi-cral' ); ?></p>
				<?php endif; ?>
			<?php else : ?>
				<div>
					<?php foreach ( $rows as $idx => $b ) : ?>
						<?php
						$event_id    = (int) $b->event_id;
						$event_link  = $event_id ? get_permalink( $event_id ) : '';
						$event_title = $event_id ? get_the_title( $event_id ) : __( '(Evento eliminato)', 'gestione-eventi-cral' );

						$thumb_url = '';
						if ( $event_id ) {
							$thumb_url = get_the_post_thumbnail_url( $event_id, 'medium' );
						}

						$event_date_raw = $event_id ? get_post_meta( $event_id, '_gec_event_date', true ) : '';
						$event_date_fmt = $event_date_raw ? mysql2date( get_option( 'date_format' ), $event_date_raw ) : '';

						$guests = $wpdb->get_results(
							$wpdb->prepare(
								"SELECT guest_name FROM {$booking_guests_table} WHERE booking_id = %d ORDER BY id ASC",
								(int) $b->id
							)
						);

						$guest_names = array();
						foreach ( $guests as $g ) {
							$name = trim( (string) $g->guest_name );
							if ( $name !== '' ) {
								$guest_names[] = $name;
							}
						}

						$created = mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $b->created_at );

						$deadline_raw = $event_id ? get_post_meta( $event_id, '_gec_cancellation_deadline', true ) : '';
						$deadline_ts  = $deadline_raw ? strtotime( $deadline_raw . ' 23:59:59' ) : 0;
						$deadline_fmt = $deadline_raw ? mysql2date( get_option( 'date_format' ), $deadline_raw ) : '';
						$can_delete   = $deadline_ts > 0 && current_time( 'timestamp' ) <= $deadline_ts;

						$delete_url = add_query_arg(
							array(
								'gec_action' => 'delete_booking',
								'booking_id' => (int) $b->id,
								'_wpnonce'   => wp_create_nonce( 'gec_delete_booking_' . (int) $b->id ),
							),
							( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']
						);
						?>

						<div style="padding:10px 0;">
							<div style="display:flex;gap:12px;align-items:flex-start;">
								<?php if ( $thumb_url ) : ?>
									<div style="flex:0 0 86px;width:86px;height:60px;border-radius:10px;overflow:hidden;background:#f3f4f6;border:1px solid #d4d4d8;">
										<img src="<?php echo esc_url( $thumb_url ); ?>" alt="" style="width:100%;height:100%;object-fit:cover;display:block;" />
									</div>
								<?php else : ?>
									<div style="flex:0 0 86px;width:86px;height:60px;border-radius:10px;background:#f3f4f6;border:1px solid #d4d4d8;"></div>
								<?php endif; ?>

								<div style="flex:1;">
									<div style="font-weight:700;margin-bottom:4px;line-height:1.25;">
										<?php if ( $event_link ) : ?>
											<a href="<?php echo esc_url( $event_link ); ?>" style="text-decoration:none;"><?php echo esc_html( $event_title ); ?></a>
										<?php else : ?>
											<?php echo esc_html( $event_title ); ?>
										<?php endif; ?>
									</div>

									<div style="display:flex;flex-wrap:wrap;gap:10px;color:#1f2937;font-size:13px;line-height:1.25;">
										<?php if ( $event_date_fmt ) : ?>
											<span><strong><?php echo esc_html( sprintf( __( 'Data evento: %s', 'gestione-eventi-cral' ), $event_date_fmt ) ); ?></strong></span>
										<?php endif; ?>
										<span><strong><?php echo esc_html( sprintf( __( 'Iscrizione: %s', 'gestione-eventi-cral' ), $created ) ); ?></strong></span>
										<span><strong><?php echo esc_html( sprintf( __( 'Totale pagato: %s', 'gestione-eventi-cral' ), number_format_i18n( (float) $b->total_amount, 2 ) ) ); ?></strong></span>
									</div>

									<div style="margin-top:6px;color:#111827;line-height:1.25;">
										<?php if ( ! empty( $guest_names ) ) : ?>
											<span style="font-size:13px;opacity:.8;"><?php esc_html_e( 'Accompagnatori:', 'gestione-eventi-cral' ); ?></span>
											<span style="font-size:13px;"><?php echo esc_html( implode( ', ', $guest_names ) ); ?></span>
										<?php endif; ?>
									</div>

									<?php if ( $deadline_fmt ) : ?>
										<div style="margin-top:6px;font-size:13px;color:#6b7280;line-height:1.25;">
											<?php echo esc_html( sprintf( __( 'Puoi eliminare la tua prenotazione entro il %s', 'gestione-eventi-cral' ), $deadline_fmt ) ); ?>
										</div>
									<?php endif; ?>

									<div style="margin-top:6px;line-height:1.25;">
										<?php if ( $can_delete ) : ?>
											<a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Eliminare questa prenotazione?', 'gestione-eventi-cral' ) ); ?>');" style="text-decoration:underline;font-size:13px;">
												<?php esc_html_e( 'Elimina prenotazione', 'gestione-eventi-cral' ); ?>
											</a>
										<?php else : ?>
											<span style="font-size:13px;opacity:0.75;"><?php esc_html_e( 'Eliminazione non disponibile.', 'gestione-eventi-cral' ); ?></span>
										<?php endif; ?>
									</div>
								</div>
							</div>
						</div>

						<?php if ( $idx < ( count( $rows ) - 1 ) ) : ?>
							<hr style="border:0;border-top:1px solid #e5e7eb;margin:0;" />
						<?php endif; ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	public function maybe_handle_frontend_login() {
		if ( is_admin() ) {
			return;
		}

		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}

		if ( empty( $_POST['gec_frontend_login'] ) ) {
			return;
		}

		if ( ! isset( $_POST['gec_frontend_login_nonce'] ) || ! wp_verify_nonce( $_POST['gec_frontend_login_nonce'], 'gec_frontend_login' ) ) {
			$this->login_error = __( 'Token non valido. Riprova.', 'gestione-eventi-cral' );
			return;
		}

		$this->login_email = isset( $_POST['gec_email'] ) ? sanitize_email( wp_unslash( $_POST['gec_email'] ) ) : '';
		$password          = isset( $_POST['gec_password'] ) ? (string) $_POST['gec_password'] : '';
		$remember          = ! empty( $_POST['gec_remember'] );

		if ( empty( $this->login_email ) || empty( $password ) ) {
			$this->login_error = __( 'Inserisci email e password.', 'gestione-eventi-cral' );
			return;
		}

		$user = get_user_by( 'email', $this->login_email );
		if ( ! $user ) {
			// Do not leak which email exists.
			$this->login_error = __( 'Credenziali non valide.', 'gestione-eventi-cral' );
			return;
		}

		$signon = wp_signon(
			array(
				'user_login'    => $user->user_login,
				'user_password' => $password,
				'remember'      => $remember,
			),
			is_ssl()
		);

		if ( is_wp_error( $signon ) ) {
			$this->login_error = __( 'Credenziali non valide.', 'gestione-eventi-cral' );
			return;
		}

		// Stay on same page after login.
		$current_url = ( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
		if ( ! headers_sent() ) {
			wp_safe_redirect( $current_url );
			exit;
		}
	}

	public function maybe_handle_member_booking_delete() {
		if ( is_admin() ) {
			return;
		}

		if ( empty( $_GET['gec_action'] ) || 'delete_booking' !== $_GET['gec_action'] ) {
			return;
		}

		$member = $this->get_current_member();
		if ( ! $member ) {
			return;
		}

		$booking_id = isset( $_GET['booking_id'] ) ? (int) $_GET['booking_id'] : 0;
		if ( $booking_id <= 0 ) {
			return;
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'gec_delete_booking_' . $booking_id ) ) {
			$this->bookings_message = __( 'Token non valido.', 'gestione-eventi-cral' );
			return;
		}

		global $wpdb;
		$bookings_table       = $wpdb->prefix . 'cral_bookings';
		$booking_guests_table = $wpdb->prefix . 'cral_booking_guests';

		$booking = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$bookings_table} WHERE id = %d AND member_id = %d LIMIT 1",
				$booking_id,
				(int) $member['id']
			),
			ARRAY_A
		);

		if ( ! $booking ) {
			$this->bookings_message = __( 'Prenotazione non trovata.', 'gestione-eventi-cral' );
			return;
		}

		$event_id = (int) $booking['event_id'];
		$deadline_raw = $event_id ? get_post_meta( $event_id, '_gec_cancellation_deadline', true ) : '';
		$deadline_ts  = $deadline_raw ? strtotime( $deadline_raw . ' 23:59:59' ) : 0;
		$can_delete   = $deadline_ts > 0 && current_time( 'timestamp' ) <= $deadline_ts;

		if ( ! $can_delete ) {
			$this->bookings_message = __( 'Non puoi più eliminare questa prenotazione.', 'gestione-eventi-cral' );
			return;
		}

		$wpdb->delete( $booking_guests_table, array( 'booking_id' => $booking_id ), array( '%d' ) );
		$wpdb->delete( $bookings_table, array( 'id' => $booking_id ), array( '%d' ) );

		// Redirect to same page without params (avoid repeat delete).
		$current_url = ( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
		$clean_url   = remove_query_arg( array( 'gec_action', 'booking_id', '_wpnonce' ), $current_url );

		if ( ! headers_sent() ) {
			wp_safe_redirect( $clean_url );
			exit;
		}

		$this->bookings_message = __( 'Prenotazione eliminata.', 'gestione-eventi-cral' );
	}

	public function maybe_handle_member_event_booking_create() {
		if ( is_admin() ) {
			return;
		}

		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}

		if ( empty( $_POST['gec_frontend_booking'] ) ) {
			return;
		}

		$member = $this->get_current_member();
		if ( ! $member ) {
			$this->booking_form_message = __( 'Per prenotarti devi effettuare il login.', 'gestione-eventi-cral' );
			return;
		}

		if ( ! isset( $_POST['gec_frontend_booking_nonce'] ) || ! wp_verify_nonce( $_POST['gec_frontend_booking_nonce'], 'gec_frontend_booking' ) ) {
			$this->booking_form_message = __( 'Token non valido. Riprova.', 'gestione-eventi-cral' );
			return;
		}

		$event_id = isset( $_POST['gec_event_id'] ) ? (int) $_POST['gec_event_id'] : 0;
		if ( $event_id <= 0 || 'cral_event' !== get_post_type( $event_id ) ) {
			$this->booking_form_message = __( 'Attività non valida.', 'gestione-eventi-cral' );
			return;
		}

		// Validate signup window.
		$signup_start_raw = (string) get_post_meta( $event_id, '_gec_signup_start', true );
		$signup_end_raw   = (string) get_post_meta( $event_id, '_gec_signup_end', true );
		$now_ts           = (int) current_time( 'timestamp' );
		$signup_start_ts  = $this->parse_ymd_to_start_ts( $signup_start_raw );
		$signup_end_ts    = $this->parse_ymd_to_end_ts( $signup_end_raw );
		if ( $signup_start_ts > 0 && $signup_end_ts > 0 ) {
			if ( $now_ts < $signup_start_ts || $now_ts > $signup_end_ts ) {
				$this->booking_form_message = __( 'Non è possibile prenotarsi: iscrizioni chiuse.', 'gestione-eventi-cral' );
				return;
			}
		}

		$max      = (int) get_post_meta( $event_id, '_gec_max_participants', true );
		$occupied = $this->compute_event_occupied_seats( $event_id );
		$available = ( $max > 0 ) ? max( 0, $max - $occupied ) : 0;
		if ( $max > 0 && $available <= 0 ) {
			$this->booking_form_message = __( 'Non è possibile prenotarsi: posti esauriti.', 'gestione-eventi-cral' );
			return;
		}

		$guest_types_cfg = $this->get_event_guest_types_config( $event_id );

		$guests_in = isset( $_POST['gec_guests'] ) && is_array( $_POST['gec_guests'] ) ? (array) $_POST['gec_guests'] : array();
		$notes     = isset( $_POST['gec_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['gec_notes'] ) ) : '';

		$requested_guest_rows = array();
		$total_guests         = 0;
		$guests_total_amount  = 0.0;

		foreach ( $guests_in as $type_key => $names ) {
			$type_key = sanitize_key( (string) $type_key );
			if ( '' === $type_key || ! isset( $guest_types_cfg[ $type_key ] ) ) {
				continue;
			}

			$cfg   = $guest_types_cfg[ $type_key ];
			$label = (string) $cfg['label'];
			$price = (float) $cfg['price'];
			$max_per_type = (int) $cfg['max'];

			if ( ! is_array( $names ) ) {
				continue;
			}

			$names_clean = array();
			foreach ( $names as $n ) {
				$n = sanitize_text_field( wp_unslash( $n ) );
				$n = trim( (string) $n );
				if ( '' === $n ) {
					continue;
				}
				if ( ! $this->is_valid_guest_full_name( $n ) ) {
					$this->booking_form_message = __( 'Ogni accompagnatore deve avere Nome e Cognome (con uno spazio).', 'gestione-eventi-cral' );
					return;
				}
				$names_clean[] = $n;
			}

			$qty = count( $names_clean );
			if ( $qty <= 0 ) {
				continue;
			}

			if ( $max_per_type > 0 && $qty > $max_per_type ) {
				$this->booking_form_message = __( 'Hai superato il numero massimo per una tipologia di accompagnatore.', 'gestione-eventi-cral' );
				return;
			}

			foreach ( $names_clean as $guest_name ) {
				$requested_guest_rows[] = array(
					'guest_type' => $label,
					'guest_name' => $guest_name,
					'unit_price' => $price,
				);
				$total_guests += 1;
				$guests_total_amount += $price;
			}
		}

		$seats_requested = 1 + $total_guests;
		if ( $max > 0 && $seats_requested > $available ) {
			$this->booking_form_message = __( 'Non ci sono abbastanza posti disponibili per questa prenotazione.', 'gestione-eventi-cral' );
			return;
		}

		global $wpdb;
		$bookings_table       = $wpdb->prefix . 'cral_bookings';
		$booking_guests_table = $wpdb->prefix . 'cral_booking_guests';

		// Avoid duplicates: one active booking per member per event.
		$existing = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$bookings_table} WHERE event_id = %d AND member_id = %d AND status <> %s ORDER BY id DESC LIMIT 1",
				$event_id,
				(int) $member['id'],
				'cancelled'
			)
		);
		if ( $existing > 0 ) {
			// Booking already exists: do not show an extra message, the shortcode will render the summary.
			$current_url = ( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
			$clean_url   = remove_query_arg( array( 'gec_booked' ), $current_url );
			if ( ! headers_sent() ) {
				wp_safe_redirect( $clean_url );
				exit;
			}
			return;
		}

		$member_price = (float) get_post_meta( $event_id, '_gec_member_price', true );
		$total_amount = $member_price + $guests_total_amount;

		$wpdb->insert(
			$bookings_table,
			array(
				'event_id'      => $event_id,
				'member_id'     => (int) $member['id'],
				'total_amount'  => $total_amount,
				'notes'         => $notes,
				'status'        => 'confirmed',
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%f', '%s', '%s', '%s' )
		);

		if ( ! $wpdb->insert_id ) {
			$this->booking_form_message = __( 'Errore durante la prenotazione. Riprova.', 'gestione-eventi-cral' );
			return;
		}

		$booking_id = (int) $wpdb->insert_id;

		foreach ( $requested_guest_rows as $g ) {
			$wpdb->insert(
				$booking_guests_table,
				array(
					'booking_id'  => $booking_id,
					'guest_type'  => (string) $g['guest_type'],
					'guest_name'  => (string) $g['guest_name'],
					'quantity'    => 1,
					'unit_price'  => (float) $g['unit_price'],
					'total_price' => (float) $g['unit_price'],
				),
				array( '%d', '%s', '%s', '%d', '%f', '%f' )
			);
		}

		// Redirect to avoid resubmission + show one-time confirmation box.
		$current_url = ( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
		$clean_url   = remove_query_arg( array( 'gec_booked' ), $current_url );

		$token         = wp_generate_password( 20, false, false );
		$transient_key = 'gec_booked_' . (int) $member['id'] . '_' . (int) $event_id;
		set_transient( $transient_key, $token, 60 );

		$ok_url = add_query_arg( array( 'gec_booked' => rawurlencode( $token ) ), $clean_url );
		$ok_url .= '#gec-booking-box';

		if ( ! headers_sent() ) {
			wp_safe_redirect( $ok_url );
			exit;
		}

		$this->booking_form_message = __( 'Prenotazione completata.', 'gestione-eventi-cral' );
	}

	public function block_cral_members_from_admin() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user = wp_get_current_user();
		if ( ! $user || empty( $user->ID ) ) {
			return;
		}

		// Allow admins.
		if ( user_can( $user, 'manage_options' ) ) {
			return;
		}

		// Block CRAL members from wp-admin.
		if ( in_array( 'cral_member', (array) $user->roles, true ) ) {
			wp_safe_redirect( home_url( '/area-personale/' ) );
			exit;
		}
	}

	public function maybe_hide_admin_bar( $show ) {
		if ( ! is_user_logged_in() ) {
			return $show;
		}

		$user = wp_get_current_user();
		if ( $user && in_array( 'cral_member', (array) $user->roles, true ) ) {
			return false;
		}

		return $show;
	}

	public function filter_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
		if ( $user instanceof WP_User ) {
			if ( in_array( 'cral_member', (array) $user->roles, true ) ) {
				return home_url( '/area-personale/' );
			}
		}
		return $redirect_to;
	}

	/**
	 * Avoid using wp-login.php UI: redirect to /login.
	 * Keeps core flows like logout & password reset working.
	 */
	public function redirect_wp_login_to_frontend() {
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : 'login';

		// Allow these wp-login.php actions to work as-is.
		$allowed_actions = array(
			'logout',
			'postpass',
			'lostpassword',
			'retrievepassword',
			'resetpass',
			'rp',
		);

		if ( in_array( $action, $allowed_actions, true ) ) {
			return;
		}

		// If already logged in, go to area personale.
		if ( is_user_logged_in() ) {
			wp_safe_redirect( home_url( '/area-personale/' ) );
			exit;
		}

		// Otherwise, always use our /login page.
		$login_url = home_url( '/login/' );

		// Preserve redirect_to if present.
		if ( isset( $_REQUEST['redirect_to'] ) ) {
			$login_url = add_query_arg(
				array( 'redirect_to' => esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) ),
				$login_url
			);
		}

		wp_safe_redirect( $login_url );
		exit;
	}
}

