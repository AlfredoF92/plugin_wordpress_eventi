<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GEC_Members {

	private $table;
	private $import_preview = null;
	private $import_token   = '';
	private $import_message = '';

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'cral_members';

		add_action( 'admin_init', array( $this, 'maybe_handle_members_csv_export' ), 1 );
		add_action( 'admin_init', array( $this, 'maybe_handle_member_save_request' ), 1 );
	}

	public function render_members_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Handle import preview/confirm before rendering list.
		$this->maybe_handle_members_csv_import();

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		if ( 'edit' === $action && isset( $_GET['member'] ) ) {
			$this->render_member_form( (int) $_GET['member'] );
			return;
		}

		if ( 'add' === $action ) {
			$this->render_member_form();
			return;
		}

		$this->handle_member_delete();
		$this->render_members_list();
	}

	/**
	 * Export members CSV before any output (avoid "headers already sent").
	 */
	public function maybe_handle_members_csv_export() {
		if ( ! is_admin() ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( (string) $_GET['page'] ) : '';
		if ( 'gec-members' !== $page ) {
			return;
		}

		$export = isset( $_GET['gec_export'] ) ? sanitize_key( (string) $_GET['gec_export'] ) : '';
		if ( 'members_csv' !== $export ) {
			return;
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? (string) $_GET['_wpnonce'] : '';
		if ( ! wp_verify_nonce( $nonce, 'gec_export_members_csv' ) ) {
			wp_die( esc_html__( 'Token non valido.', 'gestione-eventi-cral' ) );
		}

		$this->output_members_csv();
	}

	/**
	 * Save member before admin output (redirect reliably works).
	 */
	public function maybe_handle_member_save_request() {
		if ( ! is_admin() ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( (string) $_GET['page'] ) : '';
		if ( 'gec-members' !== $page ) {
			return;
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : '';
		if ( 'edit' !== $action && 'add' !== $action ) {
			return;
		}

		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}

		if ( empty( $_POST['gec_member_nonce'] ) ) {
			return;
		}

		$id = $this->handle_member_save();
		if ( $id <= 0 ) {
			return;
		}

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

	private function output_members_csv() {
		global $wpdb;

		$members = $wpdb->get_results( "SELECT id, member_code, first_name, last_name, email, created_at FROM {$this->table} ORDER BY created_at DESC", ARRAY_A );

		$filename = 'soci-cral-' . gmdate( 'Y-m-d' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$fp = fopen( 'php://output', 'w' );
		if ( ! $fp ) {
			exit;
		}

		// UTF-8 BOM for Excel compatibility.
		fwrite( $fp, "\xEF\xBB\xBF" );

		fputcsv( $fp, array( 'id', 'member_code', 'first_name', 'last_name', 'email', 'created_at' ), ';' );

		foreach ( $members as $m ) {
			fputcsv(
				$fp,
				array(
					isset( $m['id'] ) ? (string) $m['id'] : '',
					isset( $m['member_code'] ) ? (string) $m['member_code'] : '',
					isset( $m['first_name'] ) ? (string) $m['first_name'] : '',
					isset( $m['last_name'] ) ? (string) $m['last_name'] : '',
					isset( $m['email'] ) ? (string) $m['email'] : '',
					isset( $m['created_at'] ) ? (string) $m['created_at'] : '',
				),
				';'
			);
		}

		fclose( $fp );
		exit;
	}

	/**
	 * Import members from CSV with preview + confirm.
	 *
	 * CSV columns required: member_code, first_name, last_name, email
	 * Optional: password
	 */
	private function maybe_handle_members_csv_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}

		$post_action = isset( $_POST['gec_members_csv_action'] ) ? sanitize_key( wp_unslash( $_POST['gec_members_csv_action'] ) ) : '';
		if ( '' === $post_action ) {
			return;
		}

		if ( 'preview' === $post_action ) {
			if ( ! isset( $_POST['gec_members_csv_nonce'] ) || ! wp_verify_nonce( (string) $_POST['gec_members_csv_nonce'], 'gec_members_csv_import' ) ) {
				$this->import_message = __( 'Token non valido. Riprova.', 'gestione-eventi-cral' );
				return;
			}

			if ( empty( $_FILES['gec_members_csv_file'] ) || empty( $_FILES['gec_members_csv_file']['tmp_name'] ) ) {
				$this->import_message = __( 'Seleziona un file CSV.', 'gestione-eventi-cral' );
				return;
			}

			$tmp = (string) $_FILES['gec_members_csv_file']['tmp_name'];
			$rows = $this->parse_members_csv_file( $tmp );
			if ( is_wp_error( $rows ) ) {
				$this->import_message = $rows->get_error_message();
				return;
			}

			// Enrich preview rows with info about existing members (match by email or member_code).
			$rows = $this->enrich_members_rows_with_existing_info( $rows );

			$token = wp_generate_password( 20, false, false );
			$transient_key = 'gec_members_csv_' . get_current_user_id() . '_' . $token;
			set_transient( $transient_key, $rows, 10 * MINUTE_IN_SECONDS );

			$new_count    = 0;
			$update_count = 0;
			foreach ( $rows as $r ) {
				if ( ! empty( $r['_existing_id'] ) ) {
					$update_count++;
				} else {
					$new_count++;
				}
			}

			$this->import_token = $token;
			$this->import_preview = array_slice( $rows, 0, 20 );
			$this->import_message = sprintf(
				/* translators: 1: rows count 2: new count 3: update count */
				__( 'Anteprima pronta. Righe: %1$d • Nuovi: %2$d • Aggiornamenti: %3$d', 'gestione-eventi-cral' ),
				count( $rows ),
				(int) $new_count,
				(int) $update_count
			);
			return;
		}

		if ( 'confirm' === $post_action ) {
			if ( ! isset( $_POST['gec_members_csv_nonce'] ) || ! wp_verify_nonce( (string) $_POST['gec_members_csv_nonce'], 'gec_members_csv_import' ) ) {
				$this->import_message = __( 'Token non valido. Riprova.', 'gestione-eventi-cral' );
				return;
			}

			$token = isset( $_POST['gec_members_csv_token'] ) ? sanitize_text_field( wp_unslash( $_POST['gec_members_csv_token'] ) ) : '';
			if ( '' === $token ) {
				$this->import_message = __( 'Token import mancante. Rifai anteprima.', 'gestione-eventi-cral' );
				return;
			}

			$transient_key = 'gec_members_csv_' . get_current_user_id() . '_' . $token;
			$rows = get_transient( $transient_key );
			if ( ! is_array( $rows ) || empty( $rows ) ) {
				$this->import_message = __( 'Anteprima scaduta o non valida. Rifai anteprima.', 'gestione-eventi-cral' );
				return;
			}

			delete_transient( $transient_key );

			$result = $this->import_members_rows( $rows );
			$this->import_message = $result;

			if ( ! headers_sent() ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'page'    => 'gec-members',
							'imported'=> 1,
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}
		}
	}

	private function parse_members_csv_file( $file_path ) {
		$handle = fopen( $file_path, 'r' );
		if ( ! $handle ) {
			return new WP_Error( 'gec_csv_open', __( 'Impossibile leggere il file CSV.', 'gestione-eventi-cral' ) );
		}

		$delimiter = ';';
		$header = fgetcsv( $handle, 0, $delimiter );
		if ( ! is_array( $header ) ) {
			fclose( $handle );
			return new WP_Error( 'gec_csv_header', __( 'Header CSV non valido.', 'gestione-eventi-cral' ) );
		}

		// Normalize header (strip BOM on first column).
		$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );
		$header = array_map(
			static function ( $h ) {
				return sanitize_key( trim( (string) $h ) );
			},
			$header
		);

		$required = array( 'member_code', 'first_name', 'last_name', 'email' );
		foreach ( $required as $req ) {
			if ( ! in_array( $req, $header, true ) ) {
				fclose( $handle );
				return new WP_Error(
					'gec_csv_missing',
					sprintf(
						/* translators: %s: column name */
						__( 'Colonna obbligatoria mancante: %s', 'gestione-eventi-cral' ),
						$req
					)
				);
			}
		}

		$idx = array();
		foreach ( $header as $i => $col ) {
			if ( '' !== $col ) {
				$idx[ $col ] = (int) $i;
			}
		}

		$rows = array();
		while ( ( $data = fgetcsv( $handle, 0, $delimiter ) ) !== false ) {
			if ( ! is_array( $data ) ) {
				continue;
			}

			$row = array(
				'member_code' => isset( $idx['member_code'] ) && isset( $data[ $idx['member_code'] ] ) ? trim( (string) $data[ $idx['member_code'] ] ) : '',
				'first_name'  => isset( $idx['first_name'] ) && isset( $data[ $idx['first_name'] ] ) ? trim( (string) $data[ $idx['first_name'] ] ) : '',
				'last_name'   => isset( $idx['last_name'] ) && isset( $data[ $idx['last_name'] ] ) ? trim( (string) $data[ $idx['last_name'] ] ) : '',
				'email'       => isset( $idx['email'] ) && isset( $data[ $idx['email'] ] ) ? trim( (string) $data[ $idx['email'] ] ) : '',
				'password'    => isset( $idx['password'] ) && isset( $data[ $idx['password'] ] ) ? (string) $data[ $idx['password'] ] : '',
			);

			// Skip empty lines.
			if ( '' === $row['member_code'] && '' === $row['email'] ) {
				continue;
			}

			$rows[] = $row;
		}

		fclose( $handle );
		return $rows;
	}

	private function enrich_members_rows_with_existing_info( array $rows ) {
		global $wpdb;

		foreach ( $rows as $i => $r ) {
			$member_code = isset( $r['member_code'] ) ? sanitize_text_field( (string) $r['member_code'] ) : '';
			$email       = isset( $r['email'] ) ? sanitize_email( (string) $r['email'] ) : '';

			if ( '' === $member_code && '' === $email ) {
				$rows[ $i ]['_existing_id'] = 0;
				$rows[ $i ]['_existing_email'] = '';
				$rows[ $i ]['_existing_member_code'] = '';
				continue;
			}

			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, email, member_code FROM {$this->table} WHERE email = %s OR member_code = %s LIMIT 1",
					$email,
					$member_code
				),
				ARRAY_A
			);

			$rows[ $i ]['_existing_id'] = $existing && ! empty( $existing['id'] ) ? (int) $existing['id'] : 0;
			$rows[ $i ]['_existing_email'] = $existing && isset( $existing['email'] ) ? (string) $existing['email'] : '';
			$rows[ $i ]['_existing_member_code'] = $existing && isset( $existing['member_code'] ) ? (string) $existing['member_code'] : '';
		}

		return $rows;
	}

	private function import_members_rows( array $rows ) {
		global $wpdb;

		$created = 0;
		$updated = 0;
		$skipped = 0;

		foreach ( $rows as $r ) {
			$member_code = sanitize_text_field( (string) $r['member_code'] );
			$first_name  = sanitize_text_field( (string) $r['first_name'] );
			$last_name   = sanitize_text_field( (string) $r['last_name'] );
			$email       = sanitize_email( (string) $r['email'] );
			$password    = isset( $r['password'] ) ? (string) $r['password'] : '';

			if ( '' === $member_code || '' === $first_name || '' === $last_name || '' === $email ) {
				$skipped++;
				continue;
			}

			$existing = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$this->table} WHERE email = %s OR member_code = %s LIMIT 1", $email, $member_code ),
				ARRAY_A
			);

			$data = array(
				'member_code' => $member_code,
				'first_name'  => $first_name,
				'last_name'   => $last_name,
				'email'       => $email,
			);

			$plain_password = trim( $password );
			if ( '' === $plain_password ) {
				$plain_password = wp_generate_password( 12, true );
			}
			$data['password_hash'] = wp_hash_password( $plain_password );

			if ( $existing && ! empty( $existing['id'] ) ) {
				$wpdb->update(
					$this->table,
					$data,
					array( 'id' => (int) $existing['id'] )
				);
				$member_id = (int) $existing['id'];
				$updated++;
			} else {
				$data['created_at'] = current_time( 'mysql' );
				$wpdb->insert( $this->table, $data );
				$member_id = (int) $wpdb->insert_id;
				if ( $member_id <= 0 ) {
					$skipped++;
					continue;
				}
				$created++;
			}

			// Sync WP user (lets them login using email/password).
			$this->sync_wp_user_for_member(
				$member_id,
				array(
					'member_code' => $member_code,
					'first_name'  => $first_name,
					'last_name'   => $last_name,
					'email'       => $email,
				),
				$plain_password
			);
		}

		return sprintf(
			/* translators: 1: created 2: updated 3: skipped */
			__( 'Import completato. Creati: %1$d • Aggiornati: %2$d • Saltati: %3$d', 'gestione-eventi-cral' ),
			(int) $created,
			(int) $updated,
			(int) $skipped
		);
	}

	private function handle_member_save() {
		if ( ! isset( $_POST['gec_member_nonce'] ) ) {
			return 0;
		}

		if ( ! wp_verify_nonce( $_POST['gec_member_nonce'], 'gec_save_member' ) ) {
			return 0;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return 0;
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

		// Sync with WordPress users so members can log in site-wide.
		$this->sync_wp_user_for_member(
			$id,
			array(
				'member_code' => $member_code,
				'first_name'  => $first_name,
				'last_name'   => $last_name,
				'email'       => $email,
			),
			$password
		);

		return (int) $id;
	}

	private function sync_wp_user_for_member( $member_id, array $member_data, $plain_password = '' ) {
		if ( $member_id <= 0 || empty( $member_data['email'] ) ) {
			return;
		}

		// Ensure role exists.
		if ( ! get_role( 'cral_member' ) ) {
			add_role( 'cral_member', 'Socio CRAL', array( 'read' => true ) );
		}

		$email = $member_data['email'];
		$user  = get_user_by( 'email', $email );

		$display_name = trim( $member_data['first_name'] . ' ' . $member_data['last_name'] );
		$username_base = ! empty( $member_data['member_code'] ) ? $member_data['member_code'] : $email;
		$username      = sanitize_user( $username_base, true );
		if ( '' === $username ) {
			$username = sanitize_user( $email, true );
		}

		if ( ! $user ) {
			// Create WP user.
			$user_id = wp_create_user(
				$username,
				! empty( $plain_password ) ? $plain_password : wp_generate_password( 12, true ),
				$email
			);

			if ( is_wp_error( $user_id ) ) {
				return;
			}

			$user = get_user_by( 'id', $user_id );
		} else {
			$user_id = (int) $user->ID;
		}

		$userdata = array(
			'ID'           => $user_id,
			'user_email'   => $email,
			'display_name' => $display_name,
			'first_name'   => $member_data['first_name'],
			'last_name'    => $member_data['last_name'],
		);

		if ( ! empty( $plain_password ) ) {
			$userdata['user_pass'] = $plain_password;
		}

		wp_update_user( $userdata );

		// Set role (do not remove other roles if admin).
		if ( $user instanceof WP_User ) {
			if ( ! in_array( 'administrator', (array) $user->roles, true ) ) {
				$user->set_role( 'cral_member' );
			}
		}

		update_user_meta( $user_id, 'gec_member_id', (int) $member_id );
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
		<div class="wrap gec-wrap">
			<div class="gec-header">
				<div>
					<h1 class="gec-page-title"><?php esc_html_e( 'Soci', 'gestione-eventi-cral' ); ?></h1>
					<p class="gec-subtitle"><?php esc_html_e( 'Gestione anagrafica soci CRAL.', 'gestione-eventi-cral' ); ?></p>
				</div>
			</div>
			<div style="margin-top:10px;">
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'gec-members', 'action' => 'add' ), admin_url( 'admin.php' ) ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Aggiungi nuovo', 'gestione-eventi-cral' ); ?>
			</a>
			<hr class="wp-header-end" />
			</div>

			<?php
			$export_url = wp_nonce_url(
				add_query_arg(
					array(
						'page'      => 'gec-members',
						'gec_export'=> 'members_csv',
					),
					admin_url( 'admin.php' )
				),
				'gec_export_members_csv'
			);
			?>

			<div class="gec-card gec-card--padded" style="margin-top:12px;">
				<div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-start;justify-content:space-between;">
					<div style="min-width:280px;flex:1;">
						<div class="gec-label"><?php esc_html_e( 'Import/Export soci (CSV)', 'gestione-eventi-cral' ); ?></div>
						<p class="gec-subtitle" style="margin-top:8px;">
							<?php esc_html_e( 'Export: scarica i soci in CSV. Import: carica un CSV, vedi anteprima e poi conferma.', 'gestione-eventi-cral' ); ?>
						</p>
						<p class="gec-subtitle" style="margin-top:8px;">
							<?php esc_html_e( 'Nota sicurezza: le password non vengono esportate. In import puoi includere una colonna "password" oppure verrà generata una password temporanea.', 'gestione-eventi-cral' ); ?>
						</p>
					</div>
					<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
						<a class="button button-primary" href="<?php echo esc_url( $export_url ); ?>">
							<?php esc_html_e( 'Esporta soci (CSV)', 'gestione-eventi-cral' ); ?>
						</a>
					</div>
				</div>

				<?php if ( '' !== $this->import_message ) : ?>
					<div class="notice notice-info" style="margin:12px 0 0;">
						<p style="margin:0;"><?php echo esc_html( $this->import_message ); ?></p>
					</div>
				<?php endif; ?>

				<form method="post" enctype="multipart/form-data" style="margin-top:12px;">
					<?php wp_nonce_field( 'gec_members_csv_import', 'gec_members_csv_nonce' ); ?>
					<input type="hidden" name="gec_members_csv_action" value="preview" />

					<div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
						<input type="file" name="gec_members_csv_file" accept=".csv,text/csv" required />
						<button type="submit" class="button"><?php esc_html_e( 'Anteprima import', 'gestione-eventi-cral' ); ?></button>
					</div>
					<p class="description" style="margin-top:8px;">
						<?php esc_html_e( 'Colonne richieste: member_code; first_name; last_name; email. Opzionale: password', 'gestione-eventi-cral' ); ?>
					</p>
				</form>

				<?php if ( is_array( $this->import_preview ) && ! empty( $this->import_preview ) && '' !== $this->import_token ) : ?>
					<div style="margin-top:14px;">
						<h3 style="margin:0 0 8px;"><?php esc_html_e( 'Anteprima righe (prime 20)', 'gestione-eventi-cral' ); ?></h3>
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Esito', 'gestione-eventi-cral' ); ?></th>
									<th><?php esc_html_e( 'member_code', 'gestione-eventi-cral' ); ?></th>
									<th><?php esc_html_e( 'first_name', 'gestione-eventi-cral' ); ?></th>
									<th><?php esc_html_e( 'last_name', 'gestione-eventi-cral' ); ?></th>
									<th><?php esc_html_e( 'email', 'gestione-eventi-cral' ); ?></th>
									<th><?php esc_html_e( 'password', 'gestione-eventi-cral' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $this->import_preview as $r ) : ?>
									<tr>
										<td>
											<?php if ( ! empty( $r['_existing_id'] ) ) : ?>
												<strong style="color:#b45309;"><?php echo esc_html( sprintf( __( 'AGGIORNA (ID %d)', 'gestione-eventi-cral' ), (int) $r['_existing_id'] ) ); ?></strong>
											<?php else : ?>
												<strong style="color:#065f46;"><?php esc_html_e( 'NUOVO', 'gestione-eventi-cral' ); ?></strong>
											<?php endif; ?>
										</td>
										<td><code><?php echo esc_html( (string) $r['member_code'] ); ?></code></td>
										<td><?php echo esc_html( (string) $r['first_name'] ); ?></td>
										<td><?php echo esc_html( (string) $r['last_name'] ); ?></td>
										<td><?php echo esc_html( (string) $r['email'] ); ?></td>
										<td><?php echo '' !== (string) $r['password'] ? esc_html__( '(presente)', 'gestione-eventi-cral' ) : esc_html__( '(vuota → generata)', 'gestione-eventi-cral' ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>

						<form method="post" style="margin-top:10px;">
							<?php wp_nonce_field( 'gec_members_csv_import', 'gec_members_csv_nonce' ); ?>
							<input type="hidden" name="gec_members_csv_action" value="confirm" />
							<input type="hidden" name="gec_members_csv_token" value="<?php echo esc_attr( $this->import_token ); ?>" />
							<button type="submit" class="button button-primary" onclick="return confirm('<?php echo esc_js( __( 'Confermi import dei soci da CSV?', 'gestione-eventi-cral' ) ); ?>');">
								<?php esc_html_e( 'Conferma import', 'gestione-eventi-cral' ); ?>
							</button>
						</form>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success" style="margin-top:12px;">
					<p><?php esc_html_e( 'Socio salvato correttamente.', 'gestione-eventi-cral' ); ?></p>
				</div>
			<?php endif; ?>
			<?php if ( ! empty( $_GET['imported'] ) ) : ?>
				<div class="notice notice-success" style="margin-top:12px;">
					<p><?php esc_html_e( 'Import completato.', 'gestione-eventi-cral' ); ?></p>
				</div>
			<?php endif; ?>

			<table class="widefat fixed striped gec-table" style="margin-top:12px;">
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
								<span class="gec-row-actions">
									<a class="gec-row-btn gec-row-btn--primary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'gec-members', 'action' => 'edit', 'member' => $member->id ), admin_url( 'admin.php' ) ) ); ?>">
										<?php esc_html_e( 'Modifica', 'gestione-eventi-cral' ); ?>
									</a>
									<a class="gec-row-btn gec-row-btn--danger" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'gec-members', 'action' => 'delete', 'member' => $member->id ), admin_url( 'admin.php' ) ), 'gec_delete_member_' . $member->id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Sei sicuro di voler eliminare questo socio?', 'gestione-eventi-cral' ) ); ?>');">
										<?php esc_html_e( 'Elimina', 'gestione-eventi-cral' ); ?>
									</a>
								</span>
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
		<div class="wrap gec-wrap">
			<div class="gec-header">
				<div>
					<h1 class="gec-page-title">
						<?php echo $id > 0 ? esc_html__( 'Modifica socio', 'gestione-eventi-cral' ) : esc_html__( 'Aggiungi socio', 'gestione-eventi-cral' ); ?>
					</h1>
					<p class="gec-subtitle"><?php esc_html_e( 'Inserisci i dati anagrafici del socio.', 'gestione-eventi-cral' ); ?></p>
				</div>
			</div>

			<form method="post">
				<?php wp_nonce_field( 'gec_save_member', 'gec_member_nonce' ); ?>
				<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>" />

				<?php if ( ! empty( $_GET['updated'] ) ) : ?>
					<div class="notice notice-success" style="margin:12px 0 0;">
						<p style="margin:0;"><?php esc_html_e( 'Modifiche salvate.', 'gestione-eventi-cral' ); ?></p>
					</div>
				<?php endif; ?>

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
							<label style="display:inline-flex;align-items:center;margin-top:6px;">
								<input type="checkbox" id="gec-toggle-password" style="margin-right:6px;" />
								<?php esc_html_e( 'Mostra password', 'gestione-eventi-cral' ); ?>
							</label>
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
			<script>
				(function() {
					var checkbox = document.getElementById('gec-toggle-password');
					var input = document.getElementById('password');
					if (!checkbox || !input) return;
					checkbox.addEventListener('change', function () {
						input.type = checkbox.checked ? 'text' : 'password';
					});
				})();
			</script>
		</div>
		<?php
	}
}

