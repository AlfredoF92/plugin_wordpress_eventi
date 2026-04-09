<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GEC_Elementor_Query {
	/**
	 * Runtime overrides set by an Elementor widget on the same page.
	 *
	 * @var array<string, array{query_id:string,filter:string,cards:int,category_slug:string}>
	 */
	private static $runtime_overrides = array();

	/**
	 * Singleton instance (used to register hooks at runtime).
	 *
	 * @var GEC_Elementor_Query|null
	 */
	private static $instance = null;

	/**
	 * @var array<string,bool>
	 */
	private $registered_query_ids = array();

	/**
	 * @var GEC_Query_Settings
	 */
	private $settings;

	public function __construct( GEC_Query_Settings $settings ) {
		$this->settings = $settings;
		self::$instance = $this;
	}

	public function register() {
		$s = $this->settings->get_settings();
		$query_id = isset( $s['query_id'] ) ? sanitize_key( (string) $s['query_id'] ) : '';
		if ( '' === $query_id ) {
			return;
		}

		$this->ensure_query_hook_registered( $query_id );
	}

	private function ensure_query_hook_registered( $query_id ) {
		$query_id = sanitize_key( (string) $query_id );
		if ( '' === $query_id ) {
			return;
		}
		if ( isset( $this->registered_query_ids[ $query_id ] ) ) {
			return;
		}
		$this->registered_query_ids[ $query_id ] = true;
		add_action( 'elementor/query/' . $query_id, array( $this, 'apply_query' ) );
	}

	/**
	 * Called by a widget placed before the Loop Grid.
	 */
	public static function set_runtime_override( $query_id, array $settings ) {
		$query_id = sanitize_key( (string) $query_id );
		if ( '' === $query_id ) {
			return;
		}

		$filter = isset( $settings['filter'] ) ? sanitize_key( (string) $settings['filter'] ) : 'latest';
		$cards  = isset( $settings['cards'] ) ? max( 1, (int) $settings['cards'] ) : 3;
		$cat    = isset( $settings['category_slug'] ) ? sanitize_title( (string) $settings['category_slug'] ) : '';

		self::$runtime_overrides[ $query_id ] = array(
			'query_id'      => $query_id,
			'filter'        => $filter,
			'cards'         => $cards,
			'category_slug' => $cat,
		);

		// Ensure Elementor query hook exists for this Query ID even if it's not set in global options.
		if ( self::$instance instanceof self ) {
			self::$instance->ensure_query_hook_registered( $query_id );
		}
	}

	public function apply_query( $query ) {
		$s = $this->settings->get_settings();

		// If a widget set runtime override for this Query ID, prefer it.
		$hook = current_filter(); // elementor/query/<id>
		$qid  = '';
		if ( is_string( $hook ) && 0 === strpos( $hook, 'elementor/query/' ) ) {
			$qid = sanitize_key( substr( $hook, strlen( 'elementor/query/' ) ) );
		}
		if ( '' !== $qid && isset( self::$runtime_overrides[ $qid ] ) && is_array( self::$runtime_overrides[ $qid ] ) ) {
			$s = array_merge( $s, self::$runtime_overrides[ $qid ] );
		}

		$filter = isset( $s['filter'] ) ? sanitize_key( (string) $s['filter'] ) : 'latest';
		$cards  = isset( $s['cards'] ) ? max( 1, (int) $s['cards'] ) : 3;

		$query->set( 'post_type', 'cral_event' );
		$query->set( 'post_status', 'publish' );
		$query->set( 'posts_per_page', $cards );

		// Default sort: newest first.
		$query->set( 'orderby', 'date' );
		$query->set( 'order', 'DESC' );

		// Date filters based on _gec_event_date (YYYY-MM-DD).
		$today = current_time( 'Y-m-d' );

		if ( 'upcoming' === $filter ) {
			$query->set(
				'meta_query',
				array(
					array(
						'key'     => '_gec_event_date',
						'value'   => $today,
						'compare' => '>=',
						'type'    => 'DATE',
					),
				)
			);
			$query->set( 'meta_key', '_gec_event_date' );
			$query->set( 'orderby', 'meta_value' );
			$query->set( 'order', 'ASC' );
			return;
		}

		if ( 'past' === $filter ) {
			$query->set(
				'meta_query',
				array(
					array(
						'key'     => '_gec_event_date',
						'value'   => $today,
						'compare' => '<',
						'type'    => 'DATE',
					),
				)
			);
			$query->set( 'meta_key', '_gec_event_date' );
			$query->set( 'orderby', 'meta_value' );
			$query->set( 'order', 'DESC' );
			return;
		}

		if ( 'category' === $filter ) {
			$slug = isset( $s['category_slug'] ) ? sanitize_title( (string) $s['category_slug'] ) : '';
			if ( '' !== $slug ) {
				$query->set(
					'tax_query',
					array(
						array(
							'taxonomy' => 'cral_event_category',
							'field'    => 'slug',
							'terms'    => array( $slug ),
						),
					)
				);
			}
			return;
		}

		if ( 'sold_out' === $filter ) {
			// Sold out = occupied seats >= max participants (confirmed bookings only).
			// We implement with a posts_clauses filter for this query only.
			add_filter( 'posts_clauses', array( $this, 'filter_posts_clauses_sold_out' ), 10, 2 );
			$query->set( '_gec_sold_out_query', 1 );
			return;
		}

		// latest: no extra filters.
	}

	public function filter_posts_clauses_sold_out( $clauses, $query ) {
		if ( empty( $query->query_vars['_gec_sold_out_query'] ) ) {
			return $clauses;
		}

		// Remove filter after use to avoid leaking to other queries.
		remove_filter( 'posts_clauses', array( $this, 'filter_posts_clauses_sold_out' ), 10 );

		global $wpdb;
		$bookings_table = $wpdb->prefix . 'cral_bookings';
		$guests_table   = $wpdb->prefix . 'cral_booking_guests';

		// Join max participants meta.
		$clauses['join'] .= " LEFT JOIN {$wpdb->postmeta} gec_pm_max ON ({$wpdb->posts}.ID = gec_pm_max.post_id AND gec_pm_max.meta_key = '_gec_max_participants') ";

		// Join confirmed bookings + guests.
		$clauses['join'] .= " LEFT JOIN {$bookings_table} gec_b ON (gec_b.event_id = {$wpdb->posts}.ID AND gec_b.status = 'confirmed') ";
		$clauses['join'] .= " LEFT JOIN {$guests_table} gec_g ON (gec_g.booking_id = gec_b.id) ";

		// Ensure group by posts.ID for aggregates.
		if ( empty( $clauses['groupby'] ) ) {
			$clauses['groupby'] = "{$wpdb->posts}.ID";
		} elseif ( false === strpos( (string) $clauses['groupby'], "{$wpdb->posts}.ID" ) ) {
			$clauses['groupby'] .= ", {$wpdb->posts}.ID";
		}

		$occ_expr = "(COUNT(DISTINCT gec_b.id) + COALESCE(SUM(COALESCE(gec_g.quantity, 0)), 0))";
		$max_expr = "CAST(gec_pm_max.meta_value AS UNSIGNED)";

		$having = array();
		$having[] = "{$max_expr} > 0";
		$having[] = "{$occ_expr} >= {$max_expr}";

		$clauses['having'] = ( ! empty( $clauses['having'] ) ? $clauses['having'] . ' AND ' : '' ) . implode( ' AND ', $having );

		return $clauses;
	}
}

