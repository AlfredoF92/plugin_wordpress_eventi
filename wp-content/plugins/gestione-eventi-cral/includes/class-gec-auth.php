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

	public function __construct() {
		add_action( 'init', array( $this, 'maybe_handle_frontend_login' ), 1 );
		add_action( 'admin_init', array( $this, 'block_cral_members_from_admin' ) );
		add_action( 'login_init', array( $this, 'redirect_wp_login_to_frontend' ) );
		add_filter( 'show_admin_bar', array( $this, 'maybe_hide_admin_bar' ) );
		add_filter( 'login_redirect', array( $this, 'filter_login_redirect' ), 10, 3 );

		add_shortcode( 'cral_login', array( $this, 'render_login_shortcode' ) );
		add_shortcode( 'cral_member_header', array( $this, 'render_member_header_shortcode' ) );
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

