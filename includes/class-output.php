<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles all frontend JSON-LD output across three strategies:
 * - merge into Yoast's wpseo_schema_graph
 * - merge into Rank Math's rank_math/json_ld
 * - fallback: emit own <script> via wp_head
 *
 * Strategy resolution:
 * 1. Global setting ('auto', 'merge', 'replace')
 * 2. In 'auto' mode: Yoast/RankMath detected → 'merge'; none → 'add'
 */
class SchemaForge_WP_Output {

	private SchemaForge_WP_Detector $detector;

	public function __construct( SchemaForge_WP_Detector $detector ) {
		$this->detector = $detector;
	}

	public function register_hooks(): void {
		$active = $this->detector->get_active_plugin();
		$strat  = $this->resolve_strategy( $active );

		if ( $strat === 'replace' ) {
			$this->disable_seo_plugin_output( $active );
			add_action( 'wp_head', [ $this, 'output_full_graph' ], 1 );
			return;
		}

		if ( $strat === 'merge' ) {
			if ( $active === 'yoast' ) {
				add_filter( 'wpseo_schema_graph', [ $this, 'merge_into_yoast' ], 10, 2 );
			} elseif ( $active === 'rankmath' ) {
				add_filter( 'rank_math/json_ld', [ $this, 'merge_into_rankmath' ], 99, 2 );
			} else {
				// Strategy says merge but no SEO plugin — fall back to add.
				add_action( 'wp_head', [ $this, 'output_full_graph' ] );
			}
			return;
		}

		// 'add' — output standalone script tag.
		add_action( 'wp_head', [ $this, 'output_full_graph' ] );
	}

	// --- Yoast merge ---

	public function merge_into_yoast( array $graph, mixed $context ): array {
		$nodes = $this->get_nodes();
		if ( ! $nodes ) {
			return $graph;
		}

		// Scan Yoast's graph for existing @ids we can reference.
		$webpage_id = $this->find_id( $graph, 'WebPage' );
		$org_id     = $this->find_id( $graph, 'Organization' );

		foreach ( $nodes as &$node ) {
			if ( $webpage_id && ! isset( $node['mainEntityOfPage'] ) ) {
				$node['mainEntityOfPage'] = [ '@id' => $webpage_id ];
			}
			if ( $org_id && ! isset( $node['publisher'] ) ) {
				$node['publisher'] = [ '@id' => $org_id ];
			}
			// Skip if this @id is already in Yoast's graph (prevent duplicates).
			if ( isset( $node['@id'] ) && $this->find_id( $graph, null, $node['@id'] ) ) {
				continue;
			}
			$graph[] = $node;
		}

		return $graph;
	}

	// --- Rank Math merge ---

	public function merge_into_rankmath( array $data, mixed $json_ld ): array {
		$nodes = $this->get_nodes();
		if ( ! $nodes ) {
			return $data;
		}

		foreach ( $nodes as $node ) {
			$type = $node['@type'] ?? 'Thing';
			$key  = 'schemaforge_' . strtolower( is_array( $type ) ? $type[0] : $type );
			$data[ $key ] = $node;
		}

		return $data;
	}

	// --- Standalone output ---

	public function output_full_graph(): void {
		if ( ! is_singular() ) {
			return;
		}
		$json = $this->get_raw_json();
		if ( ! $json ) {
			return;
		}
		// Validate JSON before output (NFA-04).
		if ( json_decode( $json ) === null ) {
			return;
		}
		echo '<script type="application/ld+json">' . $json . '</script>' . "\n";
	}

	// --- Helpers ---

	private function get_raw_json(): ?string {
		if ( ! is_singular() ) {
			return null;
		}
		$post_id = get_queried_object_id();

		// Manual markup takes precedence.
		$manual = get_post_meta( $post_id, '_schemaforge_wp_manual', true );
		if ( $manual ) {
			return $manual;
		}
		$jsonld = get_post_meta( $post_id, '_schemaforge_wp_jsonld', true );
		return $jsonld ?: null;
	}

	private function get_nodes(): ?array {
		$json = $this->get_raw_json();
		if ( ! $json ) {
			return null;
		}
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}
		// Unwrap @graph if present.
		if ( isset( $decoded['@graph'] ) && is_array( $decoded['@graph'] ) ) {
			return $decoded['@graph'];
		}
		// Single entity.
		return isset( $decoded['@type'] ) ? [ $decoded ] : null;
	}

	private function find_id( array $graph, ?string $type, ?string $id = null ): ?string {
		foreach ( $graph as $node ) {
			if ( $id !== null && ( $node['@id'] ?? '' ) === $id ) {
				return $node['@id'];
			}
			if ( $type !== null && ( $node['@type'] ?? '' ) === $type && isset( $node['@id'] ) ) {
				return $node['@id'];
			}
		}
		return null;
	}

	private function resolve_strategy( ?string $active_plugin ): string {
		$setting = get_option( 'schemaforge_wp_strategy', 'auto' );
		if ( $setting !== 'auto' ) {
			return $setting;
		}
		return $active_plugin ? 'merge' : 'add';
	}

	private function disable_seo_plugin_output( ?string $active ): void {
		if ( $active === 'yoast' ) {
			add_filter( 'wpseo_json_ld_output', '__return_false' );
		} elseif ( $active === 'rankmath' ) {
			add_filter( 'rank_math/json_ld', '__return_empty_array' );
		}
	}
}
