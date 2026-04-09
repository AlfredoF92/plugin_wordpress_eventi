<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Elementor\Core\DynamicTags\Tag;
use Elementor\Modules\DynamicTags\Module;

class GEC_Elementor_Tag_Event_Text extends Tag {
	public function get_name() {
		return 'gec_cral_event_text';
	}

	public function get_title() {
		return __( 'CRAL - Campo attività (testo)', 'gestione-eventi-cral' );
	}

	public function get_group() {
		return 'gec_cral';
	}

	public function get_categories() {
		return array( Module::TEXT_CATEGORY );
	}

	protected function register_controls() {
		$this->add_control(
			'field',
			array(
				'label'   => __( 'Campo', 'gestione-eventi-cral' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'codice_evento',
				'options' => array(
					'id_evento'                => __( 'ID attività', 'gestione-eventi-cral' ),
					'titolo'                   => __( 'Titolo', 'gestione-eventi-cral' ),
					'descrizione'              => __( 'Descrizione (testo)', 'gestione-eventi-cral' ),
					'descrizione_html'         => __( 'Descrizione (HTML con formattazione)', 'gestione-eventi-cral' ),
					'estratto'                 => __( 'Estratto', 'gestione-eventi-cral' ),
					'codice_evento'            => __( 'Codice attività', 'gestione-eventi-cral' ),
					'categorie'                => __( 'Categorie (testo)', 'gestione-eventi-cral' ),
					'categoria_principale'     => __( 'Categoria principale', 'gestione-eventi-cral' ),
					'data_evento'              => __( 'Data attività', 'gestione-eventi-cral' ),
					'data_evento_raw'          => __( 'Data attività (raw)', 'gestione-eventi-cral' ),
					'data_apertura_iscrizioni' => __( 'Apertura iscrizioni', 'gestione-eventi-cral' ),
					'data_chiusura_iscrizioni' => __( 'Chiusura iscrizioni', 'gestione-eventi-cral' ),
					'scadenza_annullamento'    => __( 'Scadenza annullamento', 'gestione-eventi-cral' ),
					'posti_totali'             => __( 'Posti totali', 'gestione-eventi-cral' ),
					'posti_occupati'           => __( 'Posti occupati', 'gestione-eventi-cral' ),
					'posti_disponibili'        => __( 'Posti disponibili', 'gestione-eventi-cral' ),
					'evento_sold_out'          => __( 'Sold out (sì/no)', 'gestione-eventi-cral' ),
					'prezzo_socio'             => __( 'Prezzo socio', 'gestione-eventi-cral' ),
					'tipi_accompagnatori_json' => __( 'Tipi accompagnatori (JSON)', 'gestione-eventi-cral' ),
				),
			)
		);
	}

	private function get_event_id() {
		$post_id = (int) get_queried_object_id();
		if ( $post_id > 0 && 'cral_event' === get_post_type( $post_id ) ) {
			return $post_id;
		}

		global $post;
		if ( $post instanceof WP_Post && 'cral_event' === $post->post_type ) {
			return (int) $post->ID;
		}

		return 0;
	}

	private function build_vars( $event_id ) {
		$post = get_post( $event_id );
		if ( ! ( $post instanceof WP_Post ) ) {
			return array();
		}

		$event_code          = (string) get_post_meta( $event_id, '_gec_event_code', true );
		$event_date_raw      = (string) get_post_meta( $event_id, '_gec_event_date', true );
		$signup_start_raw    = (string) get_post_meta( $event_id, '_gec_signup_start', true );
		$signup_end_raw      = (string) get_post_meta( $event_id, '_gec_signup_end', true );
		$cancel_deadline_raw = (string) get_post_meta( $event_id, '_gec_cancellation_deadline', true );
		$max_participants    = (int) get_post_meta( $event_id, '_gec_max_participants', true );
		$member_price        = (string) get_post_meta( $event_id, '_gec_member_price', true );
		$guest_types_json    = (string) get_post_meta( $event_id, '_gec_guest_types', true );

		$event_date_fmt      = $event_date_raw ? mysql2date( get_option( 'date_format' ), $event_date_raw ) : '';
		$event_date_long     = '';
		if ( $event_date_raw ) {
			$ts = strtotime( $event_date_raw . ' 00:00:00' );
			if ( $ts ) {
				// Localized day/month names; e.g. "Lunedì, 5 aprile 2026".
				$fmt = function_exists( 'wp_date' ) ? wp_date( 'l, j F Y', $ts, wp_timezone() ) : date_i18n( 'l, j F Y', $ts );
				$event_date_long = function_exists( 'mb_convert_case' )
					? mb_convert_case( (string) $fmt, MB_CASE_TITLE, 'UTF-8' )
					: ucfirst( (string) $fmt );
			}
		}
		$signup_start_fmt    = $signup_start_raw ? mysql2date( get_option( 'date_format' ), $signup_start_raw ) : '';
		$signup_end_fmt      = $signup_end_raw ? mysql2date( get_option( 'date_format' ), $signup_end_raw ) : '';
		$cancel_deadline_fmt = $cancel_deadline_raw ? mysql2date( get_option( 'date_format' ), $cancel_deadline_raw ) : '';

		$categories = get_the_terms( $event_id, 'cral_event_category' );
		$cats_names = array();
		if ( is_array( $categories ) ) {
			foreach ( $categories as $t ) {
				if ( ! empty( $t->name ) ) {
					$cats_names[] = $t->name;
				}
			}
		}
		$categories_text = ! empty( $cats_names ) ? implode( ', ', $cats_names ) : '';
		$primary_cat     = ! empty( $cats_names ) ? $cats_names[0] : '';

		// Seats: compute from bookings.
		global $wpdb;
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

		$occupied  = $bookings_count + $guests_sum;
		$available = ( $max_participants > 0 ) ? max( 0, $max_participants - $occupied ) : 0;

		$descr_html  = (string) apply_filters( 'the_content', $post->post_content );
		$descr_plain = wp_strip_all_tags( $descr_html );

		return array(
			'id_evento'                => (string) $event_id,
			'titolo'                   => (string) get_the_title( $event_id ),
			'descrizione'              => (string) $descr_plain,
			'descrizione_html'         => (string) $descr_html,
			'estratto'                 => (string) get_the_excerpt( $event_id ),
			'codice_evento'            => (string) $event_code,
			'categorie'                => (string) $categories_text,
			'categoria_principale'     => (string) $primary_cat,
			'data_evento'              => (string) $event_date_long,
			'data_evento_raw'          => (string) $event_date_raw,
			'data_apertura_iscrizioni' => (string) $signup_start_fmt,
			'data_chiusura_iscrizioni' => (string) $signup_end_fmt,
			'scadenza_annullamento'    => (string) $cancel_deadline_fmt,
			'posti_totali'             => (string) $max_participants,
			'posti_occupati'           => (string) $occupied,
			'posti_disponibili'        => (string) $available,
			'evento_sold_out'          => ( $max_participants > 0 && $available <= 0 ) ? 'sì' : 'no',
			'prezzo_socio'             => ( '' !== $member_price ) ? (string) number_format_i18n( (float) $member_price, 2 ) : '',
			'tipi_accompagnatori_json' => (string) $guest_types_json,
		);
	}

	public function render() {
		$event_id = $this->get_event_id();
		if ( $event_id <= 0 ) {
			return;
		}

		$field = (string) $this->get_settings( 'field' );
		$vars  = $this->build_vars( $event_id );

		if ( ! isset( $vars[ $field ] ) ) {
			return;
		}

		if ( 'descrizione_html' === $field ) {
			echo wp_kses_post( (string) $vars[ $field ] );
			return;
		}

		echo esc_html( (string) $vars[ $field ] );
	}
}

