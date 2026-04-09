<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Elementor\Widget_Base;
use Elementor\Controls_Manager;

class GEC_Elementor_Widget_Query_Control extends Widget_Base {
	public function get_name() {
		return 'gec_query_control';
	}

	public function get_title() {
		return __( 'CRAL - Query Control (Loop Grid)', 'gestione-eventi-cral' );
	}

	public function get_icon() {
		return 'eicon-filter';
	}

	public function get_categories() {
		return array( 'general' );
	}

	protected function register_controls() {
		$this->start_controls_section(
			'gec_section',
			array(
				'label' => __( 'Query', 'gestione-eventi-cral' ),
			)
		);

		$this->add_control(
			'query_id',
			array(
				'label'       => __( 'Query ID (Elementor)', 'gestione-eventi-cral' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => 'es. home_eventi',
			)
		);

		$this->add_control(
			'filter',
			array(
				'label'   => __( 'Filtro', 'gestione-eventi-cral' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'latest',
				'options' => array(
					'latest'   => __( 'Ultimi eventi', 'gestione-eventi-cral' ),
					'upcoming' => __( 'Prossimi eventi', 'gestione-eventi-cral' ),
					'past'     => __( 'Eventi passati', 'gestione-eventi-cral' ),
					'sold_out' => __( 'Sold out', 'gestione-eventi-cral' ),
					'category' => __( 'Per categoria (slug)', 'gestione-eventi-cral' ),
				),
			)
		);

		$this->add_control(
			'category_slug',
			array(
				'label'       => __( 'Slug categoria', 'gestione-eventi-cral' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => 'es. viaggi',
				'condition'   => array( 'filter' => 'category' ),
			)
		);

		$this->add_control(
			'cards',
			array(
				'label'   => __( 'Numero card', 'gestione-eventi-cral' ),
				'type'    => Controls_Manager::NUMBER,
				'default' => 3,
				'min'     => 1,
				'max'     => 50,
			)
		);

		$this->add_control(
			'show_note',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => __( '<strong>Nota:</strong> posiziona questo widget <em>sopra</em> il Loop Grid che usa lo stesso Query ID.', 'gestione-eventi-cral' ),
				'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
			)
		);

		$this->end_controls_section();
	}

	protected function render() {
		$settings = $this->get_settings_for_display();
		$query_id = isset( $settings['query_id'] ) ? sanitize_key( (string) $settings['query_id'] ) : '';
		if ( '' === $query_id ) {
			return;
		}

		// Set runtime override for this request. The Loop Grid below will pick it up.
		GEC_Elementor_Query::set_runtime_override(
			$query_id,
			array(
				'filter'        => isset( $settings['filter'] ) ? (string) $settings['filter'] : 'latest',
				'cards'         => isset( $settings['cards'] ) ? (int) $settings['cards'] : 3,
				'category_slug' => isset( $settings['category_slug'] ) ? (string) $settings['category_slug'] : '',
			)
		);

		// This widget doesn't need to output anything on frontend.
		if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
			echo '<div style="padding:10px 12px;border:1px dashed #cbd5e1;border-radius:12px;background:#f8fafc;color:#0f172a;font-size:13px;">';
			echo esc_html__( 'CRAL Query Control attivo per Query ID:', 'gestione-eventi-cral' ) . ' <code>' . esc_html( $query_id ) . '</code>';
			echo '</div>';
		}
	}
}

