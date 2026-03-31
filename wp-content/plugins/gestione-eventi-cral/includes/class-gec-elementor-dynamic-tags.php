<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor Dynamic Tags for CRAL Activities (cral_event).
 */
class GEC_Elementor_Dynamic_Tags {
	public function register() {
		// Only if Elementor is active.
		add_action( 'elementor/dynamic_tags/register', array( $this, 'register_dynamic_tags' ) );
	}

	public function register_dynamic_tags( $dynamic_tags ) {
		if ( ! class_exists( '\Elementor\Core\DynamicTags\Tag' ) ) {
			return;
		}

		$dynamic_tags->register_group(
			'gec_cral',
			array(
				'title' => __( 'CRAL', 'gestione-eventi-cral' ),
			)
		);

		require_once __DIR__ . '/elementor-tags/class-gec-elementor-tag-event-text.php';
		require_once __DIR__ . '/elementor-tags/class-gec-elementor-tag-event-url.php';
		require_once __DIR__ . '/elementor-tags/class-gec-elementor-tag-event-image.php';

		$dynamic_tags->register( new GEC_Elementor_Tag_Event_Text() );
		$dynamic_tags->register( new GEC_Elementor_Tag_Event_Url() );
		$dynamic_tags->register( new GEC_Elementor_Tag_Event_Image() );
	}
}

