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

	public function __construct() {
		add_action( 'init', array( $this, 'maybe_handle_frontend_login' ), 1 );
		add_action( 'init', array( $this, 'maybe_handle_member_booking_delete' ), 1 );
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

