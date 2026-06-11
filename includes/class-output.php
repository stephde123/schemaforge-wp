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

	/**
	 * Article-family types that Yoast commonly emits as the primary content node.
	 * Treated as equivalent for dedup: if Yoast has any of these, we skip
	 * SchemaForge nodes of any type in this family to prevent a second Article.
	 */
	private const ARTICLE_FAMILY = [
		'Article', 'BlogPosting', 'NewsArticle', 'TechArticle', 'Report',
		'OpinionNewsArticle', 'AnalysisNewsArticle', 'ReviewNewsArticle',
		'SatiricalArticle', 'ScholarlyArticle', 'SocialMediaPosting',
	];

	public function merge_into_yoast( array $graph, mixed $context ): array {
		$nodes = $this->get_nodes();
		if ( ! $nodes ) {
			return $graph;
		}

		// Scan Yoast's graph for existing @ids we can reference.
		$webpage_id = $this->find_id( $graph, 'WebPage' );
		$org_id     = $this->find_id( $graph, 'Organization' );

		// Types where attaching mainEntityOfPage/publisher makes semantic sense.
		$page_context_types = [
			'Article', 'BlogPosting', 'NewsArticle', 'TechArticle', 'OpinionNewsArticle',
			'FAQPage', 'HowTo', 'Product', 'Recipe', 'Event',
		];

		foreach ( $nodes as &$node ) {
			$node_type  = $node['@type'] ?? '';
			$node_types = is_array( $node_type ) ? $node_type : [ $node_type ];
			$attach_ctx = (bool) array_intersect( $node_types, $page_context_types );

			if ( $attach_ctx ) {
				if ( $webpage_id && ! isset( $node['mainEntityOfPage'] ) ) {
					$node['mainEntityOfPage'] = [ '@id' => $webpage_id ];
				}
				if ( $org_id && ! isset( $node['publisher'] ) ) {
					$node['publisher'] = [ '@id' => $org_id ];
				}
			}

			// Skip if @id is already in Yoast's graph.
			if ( isset( $node['@id'] ) && $this->find_id( $graph, null, $node['@id'] ) ) {
				continue;
			}

			// Skip if Yoast's graph already has a node of the same (or equivalent) @type.
			// This prevents a second Article/BlogPosting/WebPage/Organization etc.
			if ( $this->graph_has_type_overlap( $graph, $node_types ) ) {
				continue;
			}

			$graph[] = $node;
		}

		return $graph;
	}

	/**
	 * Returns true when $graph already contains a node whose @type overlaps with $types.
	 * Article-family members (Article, BlogPosting, NewsArticle, …) are treated as
	 * equivalent so that a Yoast "Article" blocks a SchemaForge "BlogPosting" and vice versa.
	 */
	private function graph_has_type_overlap( array $graph, array $types ): bool {
		$in_article_family = (bool) array_intersect( $types, self::ARTICLE_FAMILY );
		$check = $in_article_family
			? array_unique( array_merge( $types, self::ARTICLE_FAMILY ) )
			: $types;

		foreach ( $graph as $node ) {
			$existing = $node['@type'] ?? '';
			$existing = is_array( $existing ) ? $existing : [ $existing ];
			if ( array_intersect( $check, $existing ) ) {
				return true;
			}
		}
		return false;
	}

	// --- Rank Math merge ---

	public function merge_into_rankmath( array $data, mixed $json_ld ): array {
		$nodes = $this->get_nodes();
		if ( ! $nodes ) {
			return $data;
		}

		// Use a counter-based key so multiple nodes of the same type don't overwrite each other.
		$counts = [];
		foreach ( $nodes as $node ) {
			$type = $node['@type'] ?? 'Thing';
			$type = sanitize_key( is_array( $type ) ? reset( $type ) : $type );

			// Prefer @id-based key for stable identity across saves.
			if ( ! empty( $node['@id'] ) ) {
				$key = 'schemaforge_' . md5( $node['@id'] );
			} else {
				$counts[ $type ] = ( $counts[ $type ] ?? 0 ) + 1;
				$key = 'schemaforge_' . $type . '_' . $counts[ $type ];
			}

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
		$decoded = json_decode( $json, true );
		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) {
			return;
		}
		// Re-encode with XSS-safe flags rather than outputting the raw stored string.
		echo '<script type="application/ld+json">' .
			wp_json_encode(
				$decoded,
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES |
				JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
			) .
			'</script>' . "\n";
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
