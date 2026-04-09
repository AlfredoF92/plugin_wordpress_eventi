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

	/**
	 * @var GEC_Query_Settings
	 */
	private $query_settings;

	public function __construct( GEC_Members $members, GEC_Bookings $bookings, GEC_Brand $brand, GEC_Query_Settings $query_settings ) {
		$this->members  = $members;
		$this->bookings = $bookings;
		$this->brand    = $brand;
		$this->query_settings = $query_settings;
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

		add_submenu_page(
			'gec-dashboard',
			__( 'Documentazione', 'gestione-eventi-cral' ),
			__( 'Documentazione', 'gestione-eventi-cral' ),
			$capability,
			'gec-documentation',
			array( $this, 'render_documentation_page' )
		);

		add_submenu_page(
			'gec-dashboard',
			__( 'Impostazioni Query ID', 'gestione-eventi-cral' ),
			__( 'Impostazioni Query ID', 'gestione-eventi-cral' ),
			$capability,
			'gec-query-settings',
			array( $this->query_settings, 'render_settings_page' )
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

	public function render_documentation_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap gec-wrap">
			<div class="gec-header">
				<div>
					<h1 class="gec-page-title"><?php esc_html_e( 'Documentazione', 'gestione-eventi-cral' ); ?></h1>
					<p class="gec-subtitle"><?php esc_html_e( 'Shortcode disponibili e cosa fanno.', 'gestione-eventi-cral' ); ?></p>
				</div>
			</div>

			<div class="gec-card gec-card--padded" style="margin-top:12px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Shortcode', 'gestione-eventi-cral' ); ?></h2>

				<table class="widefat striped" style="margin-top:10px;">
					<thead>
						<tr>
							<th style="width:260px;"><?php esc_html_e( 'Shortcode', 'gestione-eventi-cral' ); ?></th>
							<th><?php esc_html_e( 'Cosa fa', 'gestione-eventi-cral' ); ?></th>
							<th style="width:320px;"><?php esc_html_e( 'Esempio', 'gestione-eventi-cral' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><code>[cral_login]</code></td>
							<td><?php esc_html_e( 'Mostra il form di login soci. Se il socio è già autenticato, mostra i link rapidi (area personale, eventi) e il tasto “Disconnettiti”.', 'gestione-eventi-cral' ); ?></td>
							<td><code>[cral_login]</code></td>
						</tr>
						<tr>
							<td><code>[cral_member_header]</code></td>
							<td><?php esc_html_e( 'Mostra un header sintetico: se il socio è loggato, saluto con nome e link all’area personale; altrimenti pulsante “Accedi”.', 'gestione-eventi-cral' ); ?></td>
							<td><code>[cral_member_header]</code></td>
						</tr>
						<tr>
							<td><code>[cral_area_personale]</code></td>
							<td><?php esc_html_e( 'Mostra i dati base del socio loggato (codice socio, username, email) e un link di logout. Se non loggato, propone il link di accesso.', 'gestione-eventi-cral' ); ?></td>
							<td><code>[cral_area_personale]</code></td>
						</tr>
						<tr>
							<td><code>[cral_mie_prenotazioni]</code></td>
							<td><?php esc_html_e( 'Elenco prenotazioni del socio (ultime 20). Include data evento, data iscrizione, totale e accompagnatori. Mostra anche l’azione “Elimina prenotazione” quando consentito.', 'gestione-eventi-cral' ); ?></td>
							<td><code>[cral_mie_prenotazioni]</code></td>
						</tr>
						<tr>
							<td><code>[cral_mie_prenotazioni_passate]</code></td>
							<td><?php esc_html_e( 'Come “mie prenotazioni”, ma filtrate solo sugli eventi già conclusi.', 'gestione-eventi-cral' ); ?></td>
							<td><code>[cral_mie_prenotazioni_passate]</code></td>
						</tr>
						<tr>
							<td><code>[cral_mie_prenotazioni_prossime]</code></td>
							<td><?php esc_html_e( 'Come “mie prenotazioni”, ma filtrate solo sugli eventi futuri.', 'gestione-eventi-cral' ); ?></td>
							<td><code>[cral_mie_prenotazioni_prossime]</code></td>
						</tr>
						<tr>
							<td><code>[cral_prenota_evento]</code></td>
							<td>
								<?php esc_html_e( 'Mostra il box di prenotazione per un evento: prezzi, disponibilità posti, tipologie accompagnatori e form di prenotazione (se loggato). Supporta accompagnatori come righe singole con campo “Nome Cognome”.', 'gestione-eventi-cral' ); ?>
								<div style="margin-top:6px;color:#6b7280;">
									<strong><?php esc_html_e( 'Attributi:', 'gestione-eventi-cral' ); ?></strong>
									<code style="margin-left:6px;">event_id</code>
									<span style="margin-left:6px;"><?php esc_html_e( '(opzionale: se omesso usa l’evento della pagina corrente)', 'gestione-eventi-cral' ); ?></span>
								</div>
							</td>
							<td>
								<code>[cral_prenota_evento]</code><br/>
								<code>[cral_prenota_evento event_id="123"]</code>
							</td>
						</tr>
					</tbody>
				</table>

				<p style="margin:12px 0 0;color:#6b7280;">
					<?php esc_html_e( 'Nota: gli shortcode sono pensati per essere inseriti in pagine come /login/, /area-personale/ e nella singola pagina evento.', 'gestione-eventi-cral' ); ?>
				</p>
			</div>
		</div>
		<?php
	}
}

