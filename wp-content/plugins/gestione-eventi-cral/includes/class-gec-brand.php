<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GEC_Brand {

	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_brand_css' ) );
	}

	public function enqueue_admin_brand_css() {
		if ( ! is_admin() ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		$screen        = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$post_type     = $screen && isset( $screen->post_type ) ? $screen->post_type : '';

		// Apply to our plugin pages (gec-*) and also to Eventi CPT list (cral_event).
		$is_gec_page   = ( 0 === strpos( $page, 'gec-' ) ) || ( 'gec-dashboard' === $page );
		$is_events_cpt = ( 'cral_event' === $post_type );

		if ( ! $is_gec_page && ! $is_events_cpt ) {
			return;
		}

		wp_enqueue_style(
			'gec-admin-brand',
			GEC_PLUGIN_URL . 'assets/admin-brand.css',
			array(),
			GEC_VERSION
		);
	}

	public function render_brand_identity_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$css_url  = GEC_PLUGIN_URL . 'assets/admin-brand.css';

		?>
		<div class="wrap gec-wrap">
			<div class="gec-header">
				<div>
					<h1 class="gec-page-title"><?php esc_html_e( 'Brand Identity', 'gestione-eventi-cral' ); ?></h1>
					<p class="gec-subtitle">
						<?php
						echo esc_html__(
							'Riepilogo classi CSS e anteprima componenti (admin).',
							'gestione-eventi-cral'
						);
						?>
						<?php echo ' '; ?>
						<a href="<?php echo esc_url( $css_url ); ?>" target="_blank" rel="noreferrer"><?php esc_html_e( 'Apri CSS', 'gestione-eventi-cral' ); ?></a>
					</p>
				</div>
			</div>

			<h2 class="gec-section-title"><?php esc_html_e( 'Tipografia & testi', 'gestione-eventi-cral' ); ?></h2>
			<div class="gec-card gec-card--padded">
				<div class="gec-label"><?php esc_html_e( 'Label', 'gestione-eventi-cral' ); ?></div>
				<div style="margin-top:6px;">
					<div class="gec-page-title"><?php esc_html_e( 'Titolo view (gec-page-title)', 'gestione-eventi-cral' ); ?></div>
					<div class="gec-subtitle"><?php esc_html_e( 'Sottotitolo (gec-subtitle)', 'gestione-eventi-cral' ); ?></div>
				</div>
			</div>

			<h2 class="gec-section-title"><?php esc_html_e( 'Riepilogo / KPI', 'gestione-eventi-cral' ); ?></h2>
			<div class="gec-card gec-card--padded">
				<div class="gec-summary">
					<div class="gec-kpi">
						<div class="gec-kpi__label"><?php esc_html_e( 'Soci iscritti', 'gestione-eventi-cral' ); ?></div>
						<div class="gec-kpi__value">18</div>
					</div>
					<div class="gec-kpi">
						<div class="gec-kpi__label"><?php esc_html_e( 'Accompagnatori', 'gestione-eventi-cral' ); ?></div>
						<div class="gec-kpi__value">23</div>
					</div>
					<div class="gec-kpi gec-kpi--accent">
						<div class="gec-kpi__label"><?php esc_html_e( 'Posti occupati', 'gestione-eventi-cral' ); ?></div>
						<div class="gec-kpi__value">41 / 60</div>
					</div>
					<div class="gec-kpi gec-kpi--accent">
						<div class="gec-kpi__label"><?php esc_html_e( 'Totale pagato', 'gestione-eventi-cral' ); ?></div>
						<div class="gec-kpi__value">€ 1.245,00</div>
					</div>
				</div>
			</div>

			<h2 class="gec-section-title"><?php esc_html_e( 'Badge & pulsanti', 'gestione-eventi-cral' ); ?></h2>
			<div class="gec-card gec-card--padded">
				<div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
					<span class="gec-badge"><?php esc_html_e( 'Confirmed', 'gestione-eventi-cral' ); ?></span>
					<span class="gec-badge gec-badge--accent"><?php esc_html_e( 'Disponibili', 'gestione-eventi-cral' ); ?></span>
					<a class="gec-btn" href="#"><?php esc_html_e( 'Azione', 'gestione-eventi-cral' ); ?></a>
					<a class="gec-btn gec-btn--primary" href="#"><?php esc_html_e( 'Primario', 'gestione-eventi-cral' ); ?></a>
					<a class="gec-btn gec-btn--danger" href="#"><?php esc_html_e( 'Pericolo', 'gestione-eventi-cral' ); ?></a>
					<span class="gec-row-actions">
						<a class="gec-row-btn gec-row-btn--primary" href="#"><?php esc_html_e( 'Row primary', 'gestione-eventi-cral' ); ?></a>
						<a class="gec-row-btn gec-row-btn--danger" href="#"><?php esc_html_e( 'Row delete', 'gestione-eventi-cral' ); ?></a>
					</span>
				</div>
			</div>

			<h2 class="gec-section-title"><?php esc_html_e( 'Tabella', 'gestione-eventi-cral' ); ?></h2>
			<table class="widefat fixed striped gec-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Colonna', 'gestione-eventi-cral' ); ?></th>
						<th><?php esc_html_e( 'Valore', 'gestione-eventi-cral' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><?php esc_html_e( 'Esempio', 'gestione-eventi-cral' ); ?></td>
						<td><?php esc_html_e( 'Testo', 'gestione-eventi-cral' ); ?></td>
					</tr>
				</tbody>
			</table>

			<h2 class="gec-section-title"><?php esc_html_e( 'Lista', 'gestione-eventi-cral' ); ?></h2>
			<div class="gec-card gec-card--padded">
				<ul class="gec-list">
					<li><code>.gec-header</code></li>
					<li><code>.gec-page-title</code>, <code>.gec-subtitle</code>, <code>.gec-section-title</code>, <code>.gec-label</code></li>
					<li><code>.gec-card</code>, <code>.gec-summary</code>, <code>.gec-kpi</code></li>
					<li><code>.gec-btn</code>, <code>.gec-badge</code></li>
					<li><code>.gec-table</code>, <code>.gec-pre</code></li>
				</ul>
			</div>

			<h2 class="gec-section-title"><?php esc_html_e( 'Snippet classi CSS', 'gestione-eventi-cral' ); ?></h2>
			<pre class="gec-pre"><?php echo esc_html( $this->get_css_cheatsheet() ); ?></pre>
		</div>
		<?php
	}

	private function get_css_cheatsheet() {
		return implode(
			"\n",
			array(
				'.gec-header, .gec-page-title, .gec-subtitle, .gec-section-title, .gec-label',
				'.gec-card, .gec-card--padded',
				'.gec-summary, .gec-kpi, .gec-kpi__label, .gec-kpi__value, .gec-kpi--accent',
				'.gec-table (da usare insieme a .widefat)',
				'.gec-btn, .gec-btn--primary, .gec-btn--danger',
				'.gec-row-actions, .gec-row-btn, .gec-row-btn--primary, .gec-row-btn--danger',
				'.gec-badge, .gec-badge--accent',
				'.gec-list',
				'.gec-pre',
				'',
				'Palette:',
				'--gec-brand-primary: #1d3a6b',
				'--gec-brand-bg:      #f3f4f6',
				'--gec-brand-border:  #d4d4d8',
			)
		);
	}
}

