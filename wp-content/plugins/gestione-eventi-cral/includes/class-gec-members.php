<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GEC_Members {

	private $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'cral_members';
	}

	public function render_members_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		if ( 'edit' === $action && isset( $_GET['member'] ) ) {
			$this->handle_member_save();
			$this->render_member_form( (int) $_GET['member'] );
			return;
		}

		if ( 'add' === $action ) {
			$this->handle_member_save();
			$this->render_member_form();
			return;
		}

		$this->handle_member_delete();
		$this->render_members_list();
	}

	private function handle_member_save() {
		if ( ! isset( $_POST['gec_member_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( $_POST['gec_member_nonce'], 'gec_save_member' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;

		$id           = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$member_code  = isset( $_POST['member_code'] ) ? sanitize_text_field( wp_unslash( $_POST['member_code'] ) ) : '';
		$first_name   = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
		$last_name    = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
		$email        = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$password     = isset( $_POST['password'] ) ? (string) $_POST['password'] : '';

		$data = array(
			'member_code' => $member_code,
			'first_name'  => $first_name,
			'last_name'   => $last_name,
			'email'       => $email,
		);

		if ( ! empty( $password ) ) {
			$data['password_hash'] = wp_hash_password( $password );
		}

		if ( $id > 0 ) {
			$wpdb->update(
				$this->table,
				$data,
				array( 'id' => $id ),
				null,
				array( '%d' )
			);
		} else {
			$data['created_at'] = current_time( 'mysql' );
			$wpdb->insert( $this->table, $data );
			$id = (int) $wpdb->insert_id;
		}

		if ( $id > 0 ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => 'gec-members',
						'updated' => 1,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}
	}

	private function handle_member_delete() {
		if ( ! isset( $_GET['action'], $_GET['member'], $_GET['_wpnonce'] ) ) {
			return;
		}

		if ( 'delete' !== $_GET['action'] ) {
			return;
		}

		if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'gec_delete_member_' . (int) $_GET['member'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;
		$wpdb->delete(
			$this->table,
			array( 'id' => (int) $_GET['member'] ),
			array( '%d' )
		);
	}

	private function render_members_list() {
		global $wpdb;

		$members = $wpdb->get_results( "SELECT * FROM {$this->table} ORDER BY created_at DESC" );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Soci CRAL', 'gestione-eventi-cral' ); ?></h1>
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'gec-members', 'action' => 'add' ), admin_url( 'admin.php' ) ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Aggiungi nuovo', 'gestione-eventi-cral' ); ?>
			</a>
			<hr class="wp-header-end" />

			<table class="widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Codice socio', 'gestione-eventi-cral' ); ?></th>
						<th><?php esc_html_e( 'Nome', 'gestione-eventi-cral' ); ?></th>
						<th><?php esc_html_e( 'Cognome', 'gestione-eventi-cral' ); ?></th>
						<th><?php esc_html_e( 'Email', 'gestione-eventi-cral' ); ?></th>
						<th><?php esc_html_e( 'Azioni', 'gestione-eventi-cral' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! empty( $members ) ) : ?>
					<?php foreach ( $members as $member ) : ?>
						<tr>
							<td><?php echo esc_html( $member->member_code ); ?></td>
							<td><?php echo esc_html( $member->first_name ); ?></td>
							<td><?php echo esc_html( $member->last_name ); ?></td>
							<td><?php echo esc_html( $member->email ); ?></td>
							<td>
								<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'gec-members', 'action' => 'edit', 'member' => $member->id ), admin_url( 'admin.php' ) ) ); ?>">
									<?php esc_html_e( 'Modifica', 'gestione-eventi-cral' ); ?>
								</a> |
								<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'gec-members', 'action' => 'delete', 'member' => $member->id ), admin_url( 'admin.php' ) ), 'gec_delete_member_' . $member->id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Sei sicuro di voler eliminare questo socio?', 'gestione-eventi-cral' ) ); ?>');">
									<?php esc_html_e( 'Elimina', 'gestione-eventi-cral' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr>
						<td colspan="5"><?php esc_html_e( 'Nessun socio trovato.', 'gestione-eventi-cral' ); ?></td>
					</tr>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function render_member_form( $id = 0 ) {
		global $wpdb;

		$member = null;

		if ( $id > 0 ) {
			$member = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$this->table} WHERE id = %d",
					$id
				)
			);
		}

		$member_code = $member ? $member->member_code : '';
		$first_name  = $member ? $member->first_name : '';
		$last_name   = $member ? $member->last_name : '';
		$email       = $member ? $member->email : '';

		?>
		<div class="wrap">
			<h1>
				<?php echo $id > 0 ? esc_html__( 'Modifica socio', 'gestione-eventi-cral' ) : esc_html__( 'Aggiungi socio', 'gestione-eventi-cral' ); ?>
			</h1>

			<form method="post">
				<?php wp_nonce_field( 'gec_save_member', 'gec_member_nonce' ); ?>
				<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>" />

				<table class="form-table">
					<tr>
						<th><label for="member_code"><?php esc_html_e( 'Codice socio', 'gestione-eventi-cral' ); ?></label></th>
						<td><input type="text" id="member_code" name="member_code" value="<?php echo esc_attr( $member_code ); ?>" class="regular-text" required /></td>
					</tr>
					<tr>
						<th><label for="first_name"><?php esc_html_e( 'Nome', 'gestione-eventi-cral' ); ?></label></th>
						<td><input type="text" id="first_name" name="first_name" value="<?php echo esc_attr( $first_name ); ?>" class="regular-text" required /></td>
					</tr>
					<tr>
						<th><label for="last_name"><?php esc_html_e( 'Cognome', 'gestione-eventi-cral' ); ?></label></th>
						<td><input type="text" id="last_name" name="last_name" value="<?php echo esc_attr( $last_name ); ?>" class="regular-text" required /></td>
					</tr>
					<tr>
						<th><label for="email"><?php esc_html_e( 'Email', 'gestione-eventi-cral' ); ?></label></th>
						<td><input type="email" id="email" name="email" value="<?php echo esc_attr( $email ); ?>" class="regular-text" required /></td>
					</tr>
					<tr>
						<th><label for="password"><?php esc_html_e( 'Password', 'gestione-eventi-cral' ); ?></label></th>
						<td>
							<input type="password" id="password" name="password" class="regular-text" <?php echo $id ? '' : 'required'; ?> />
							<p class="description">
								<?php
								echo esc_html(
									$id
										? __( 'Lascia vuoto per non modificare la password.', 'gestione-eventi-cral' )
										: __( 'Imposta la password iniziale per il socio.', 'gestione-eventi-cral' )
								);
								?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( $id > 0 ? __( 'Aggiorna socio', 'gestione-eventi-cral' ) : __( 'Crea socio', 'gestione-eventi-cral' ) ); ?>
			</form>
		</div>
		<?php
	}
}

