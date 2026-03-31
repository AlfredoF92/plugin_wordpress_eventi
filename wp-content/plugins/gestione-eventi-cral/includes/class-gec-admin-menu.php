<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GEC_Admin_Menu {

	/**
	 * @var GEC_Members
	 */
	private $members;

	/**
	 * @var GEC_Bookings
	 */
	private $bookings;

	/**
	 * @var GEC_Brand
	 */
	private $brand;

	public function __construct( GEC_Members $members, GEC_Bookings $bookings, GEC_Brand $brand ) {
		$this->members  = $members;
		$this->bookings = $bookings;
		$this->brand    = $brand;
	}

	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
	}

	public function register_menus() {
		$capability = 'manage_options';

		add_menu_page(
			__( 'Gestione Eventi CRAL', 'gestione-eventi-cral' ),
			__( 'Eventi CRAL', 'gestione-eventi-cral' ),
			$capability,
			'gec-dashboard',
			array( $this, 'render_dashboard_page' ),
			'dashicons-tickets-alt',
			26
		);

		add_submenu_page(
			'gec-dashboard',
			__( 'Elenco eventi', 'gestione-eventi-cral' ),
			__( 'Eventi', 'gestione-eventi-cral' ),
			$capability,
			'edit.php?post_type=cral_event'
		);

		add_submenu_page(
			'gec-dashboard',
			__( 'Soci CRAL', 'gestione-eventi-cral' ),
			__( 'Soci', 'gestione-eventi-cral' ),
			$capability,
			'gec-members',
			array( $this->members, 'render_members_page' )
		);

		add_submenu_page(
			'gec-dashboard',
			__( 'Prenotazioni eventi', 'gestione-eventi-cral' ),
			__( 'Prenotazioni', 'gestione-eventi-cral' ),
			$capability,
			'gec-bookings',
			array( $this->bookings, 'render_bookings_page' )
		);

		add_submenu_page(
			'gec-dashboard',
			__( 'Brand Identity', 'gestione-eventi-cral' ),
			__( 'Brand Identity', 'gestione-eventi-cral' ),
			$capability,
			'gec-brand-identity',
			array( $this->brand, 'render_brand_identity_page' )
		);

		// Hidden page, used by "Iscritti" quick action from events list.
		add_submenu_page(
			'gec-dashboard',
			__( 'Iscritti evento', 'gestione-eventi-cral' ),
			'',
			$capability,
			'gec-event-attendees',
			array( $this->bookings, 'render_event_attendees_page' )
		);
	}

	public function render_dashboard_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap gec-wrap">
			<div class="gec-header">
				<div>
					<h1 class="gec-page-title"><?php esc_html_e( 'Gestione Eventi CRAL', 'gestione-eventi-cral' ); ?></h1>
					<p class="gec-subtitle"><?php esc_html_e( 'Da qui puoi gestire eventi, soci e prenotazioni del CRAL.', 'gestione-eventi-cral' ); ?></p>
				</div>
			</div>

			<div class="gec-card gec-card--padded" style="margin-top:12px;">
				<ul class="gec-list">
					<li><a class="gec-btn gec-btn--primary" href="<?php echo esc_url( admin_url( 'edit.php?post_type=cral_event' ) ); ?>"><?php esc_html_e( 'Vai agli eventi', 'gestione-eventi-cral' ); ?></a></li>
					<li style="margin-top:10px;"><a class="gec-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=gec-members' ) ); ?>"><?php esc_html_e( 'Gestisci soci', 'gestione-eventi-cral' ); ?></a></li>
					<li style="margin-top:10px;"><a class="gec-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=gec-bookings' ) ); ?>"><?php esc_html_e( 'Gestisci prenotazioni', 'gestione-eventi-cral' ); ?></a></li>
					<li style="margin-top:10px;"><a class="gec-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=gec-brand-identity' ) ); ?>"><?php esc_html_e( 'Brand Identity', 'gestione-eventi-cral' ); ?></a></li>
				</ul>
			</div>
		</div>
		<?php
	}
}

