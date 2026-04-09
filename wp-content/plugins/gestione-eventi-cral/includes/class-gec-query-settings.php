<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GEC_Query_Settings {
	const OPTION_KEY = 'gec_query_id_settings';

	public function get_settings() {
		$opt = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $opt ) ) {
			$opt = array();
		}

		return array(
			'query_id'     => isset( $opt['query_id'] ) ? sanitize_key( (string) $opt['query_id'] ) : '',
			'filter'       => isset( $opt['filter'] ) ? sanitize_key( (string) $opt['filter'] ) : 'latest',
			'cards'        => isset( $opt['cards'] ) ? max( 1, (int) $opt['cards'] ) : 3,
			'category_slug'=> isset( $opt['category_slug'] ) ? sanitize_title( (string) $opt['category_slug'] ) : '',
		);
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = $this->get_settings();
		$notice   = '';

		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && ! empty( $_POST['gec_query_settings_save'] ) ) {
			if ( ! isset( $_POST['gec_query_settings_nonce'] ) || ! wp_verify_nonce( (string) $_POST['gec_query_settings_nonce'], 'gec_query_settings_save' ) ) {
				$notice = __( 'Token non valido. Riprova.', 'gestione-eventi-cral' );
			} else {
				$query_id = isset( $_POST['gec_query_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['gec_query_id'] ) ) : '';
				$filter   = isset( $_POST['gec_query_filter'] ) ? sanitize_key( wp_unslash( (string) $_POST['gec_query_filter'] ) ) : 'latest';
				$cards    = isset( $_POST['gec_query_cards'] ) ? (int) $_POST['gec_query_cards'] : 3;
				$cards    = max( 1, min( 50, $cards ) );
				$cat_slug = isset( $_POST['gec_query_category_slug'] ) ? sanitize_title( wp_unslash( (string) $_POST['gec_query_category_slug'] ) ) : '';

				$allowed_filters = array( 'latest', 'upcoming', 'past', 'sold_out', 'category' );
				if ( ! in_array( $filter, $allowed_filters, true ) ) {
					$filter = 'latest';
				}

				update_option(
					self::OPTION_KEY,
					array(
						'query_id'      => $query_id,
						'filter'        => $filter,
						'cards'         => $cards,
						'category_slug' => $cat_slug,
					)
				);

				$settings = $this->get_settings();
				$notice   = __( 'Impostazioni salvate.', 'gestione-eventi-cral' );
			}
		}

		?>
		<div class="wrap gec-wrap">
			<div class="gec-header">
				<div>
					<h1 class="gec-page-title"><?php esc_html_e( 'Impostazioni Query ID', 'gestione-eventi-cral' ); ?></h1>
					<p class="gec-subtitle"><?php esc_html_e( 'Configura il Query ID del Loop Grid Elementor e il filtro da applicare.', 'gestione-eventi-cral' ); ?></p>
				</div>
			</div>

			<?php if ( '' !== $notice ) : ?>
				<div class="notice notice-info" style="margin-top:12px;">
					<p><?php echo esc_html( $notice ); ?></p>
				</div>
			<?php endif; ?>

			<div class="gec-card gec-card--padded" style="margin-top:12px;max-width:920px;">
				<form method="post">
					<?php wp_nonce_field( 'gec_query_settings_save', 'gec_query_settings_nonce' ); ?>
					<input type="hidden" name="gec_query_settings_save" value="1" />

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="gec_query_id"><?php esc_html_e( 'Query ID (Elementor)', 'gestione-eventi-cral' ); ?></label></th>
							<td>
								<input type="text" id="gec_query_id" name="gec_query_id" value="<?php echo esc_attr( $settings['query_id'] ); ?>" class="regular-text" placeholder="es. home_eventi" />
								<p class="description"><?php esc_html_e( 'Inserisci lo stesso Query ID impostato nel widget Loop Grid.', 'gestione-eventi-cral' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="gec_query_filter"><?php esc_html_e( 'Filtro', 'gestione-eventi-cral' ); ?></label></th>
							<td>
								<select id="gec_query_filter" name="gec_query_filter">
									<option value="latest" <?php selected( $settings['filter'], 'latest' ); ?>><?php esc_html_e( 'Ultimi eventi', 'gestione-eventi-cral' ); ?></option>
									<option value="upcoming" <?php selected( $settings['filter'], 'upcoming' ); ?>><?php esc_html_e( 'Prossimi eventi', 'gestione-eventi-cral' ); ?></option>
									<option value="past" <?php selected( $settings['filter'], 'past' ); ?>><?php esc_html_e( 'Eventi passati', 'gestione-eventi-cral' ); ?></option>
									<option value="sold_out" <?php selected( $settings['filter'], 'sold_out' ); ?>><?php esc_html_e( 'Sold out', 'gestione-eventi-cral' ); ?></option>
									<option value="category" <?php selected( $settings['filter'], 'category' ); ?>><?php esc_html_e( 'Per categoria (slug)', 'gestione-eventi-cral' ); ?></option>
								</select>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="gec_query_category_slug"><?php esc_html_e( 'Slug categoria', 'gestione-eventi-cral' ); ?></label></th>
							<td>
								<input type="text" id="gec_query_category_slug" name="gec_query_category_slug" value="<?php echo esc_attr( $settings['category_slug'] ); ?>" class="regular-text" placeholder="es. viaggi" />
								<p class="description"><?php esc_html_e( 'Usato solo se il filtro è “Per categoria”.', 'gestione-eventi-cral' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="gec_query_cards"><?php esc_html_e( 'Numero card', 'gestione-eventi-cral' ); ?></label></th>
							<td>
								<input type="number" id="gec_query_cards" name="gec_query_cards" value="<?php echo esc_attr( (string) (int) $settings['cards'] ); ?>" min="1" max="50" />
								<p class="description"><?php esc_html_e( 'Quanti eventi mostrare nel Loop Grid.', 'gestione-eventi-cral' ); ?></p>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Salva', 'gestione-eventi-cral' ) ); ?>
				</form>
			</div>
		</div>
		<?php
	}
}

