<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Elementor\Core\DynamicTags\Data_Tag;
use Elementor\Modules\DynamicTags\Module;

class GEC_Elementor_Tag_Event_Image extends Data_Tag {
	public function get_name() {
		return 'gec_cral_event_image';
	}

	public function get_title() {
		return __( 'CRAL - Immagine copertina attività', 'gestione-eventi-cral' );
	}

	public function get_group() {
		return 'gec_cral';
	}

	public function get_categories() {
		return array( Module::IMAGE_CATEGORY );
	}

	public function get_value( array $options = array() ) {
		$post_id = (int) get_queried_object_id();
		if ( $post_id <= 0 || 'cral_event' !== get_post_type( $post_id ) ) {
			global $post;
			if ( $post instanceof WP_Post && 'cral_event' === $post->post_type ) {
				$post_id = (int) $post->ID;
			}
		}

		if ( $post_id <= 0 ) {
			return array();
		}

		$thumb_id = (int) get_post_thumbnail_id( $post_id );
		$url      = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'large' ) : '';

		if ( ! $thumb_id || ! $url ) {
			return array();
		}

		return array(
			'id'  => $thumb_id,
			'url' => $url,
		);
	}
}

