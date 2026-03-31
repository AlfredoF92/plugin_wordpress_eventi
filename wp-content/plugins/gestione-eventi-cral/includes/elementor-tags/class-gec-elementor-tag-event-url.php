<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Elementor\Core\DynamicTags\Tag;
use Elementor\Modules\DynamicTags\Module;

class GEC_Elementor_Tag_Event_Url extends Tag {
	public function get_name() {
		return 'gec_cral_event_url';
	}

	public function get_title() {
		return __( 'CRAL - Campo attività (URL)', 'gestione-eventi-cral' );
	}

	public function get_group() {
		return 'gec_cral';
	}

	public function get_categories() {
		return array( Module::URL_CATEGORY );
	}

	protected function register_controls() {
		$this->add_control(
			'field',
			array(
				'label'   => __( 'Campo URL', 'gestione-eventi-cral' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'link_evento',
				'options' => array(
					'link_evento'           => __( 'Link attività', 'gestione-eventi-cral' ),
					'immagine_copertina_url'=> __( 'URL immagine copertina', 'gestione-eventi-cral' ),
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

	public function render() {
		$event_id = $this->get_event_id();
		if ( $event_id <= 0 ) {
			return;
		}

		$field = (string) $this->get_settings( 'field' );

		if ( 'immagine_copertina_url' === $field ) {
			$url = get_the_post_thumbnail_url( $event_id, 'large' );
			if ( $url ) {
				echo esc_url( $url );
			}
			return;
		}

		echo esc_url( get_permalink( $event_id ) );
	}
}

