<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GEC_Elementor_Widgets {
	public function register() {
		add_action( 'elementor/widgets/register', array( $this, 'register_widgets' ) );
	}

	public function register_widgets( $widgets_manager ) {
		if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
			return;
		}

		require_once __DIR__ . '/elementor-widgets/class-gec-elementor-widget-query-control.php';
		$widgets_manager->register( new GEC_Elementor_Widget_Query_Control() );
	}
}

