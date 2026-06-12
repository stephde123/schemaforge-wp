<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles all frontend JSON-LD output across three strategies:
 *
 * - Yoast SEO     → wpseo_schema_graph filter (true graph merge, one <script> tag)
 * - Rank Math      → rank_math/json_ld filter (true graph merge, one <script> tag)
 * - AIOSEO         → aioseo_schema_output inspect + standalone complement
 * - SEOPress       → seopress_schemas_output inspect + standalone complement
 * - The SEO Framework → inspect via output filter + standalone complement
 * - None detected  → standalone <script> tag
 *
 * Inspect+Complement: we observe which @types the SEO plugin emits, then
 * output only the SchemaForge nodes whose type isn't already covered.
 * This prevents duplicate @type nodes while staying compatible with plugins
 * that don't expose a graph-array filter.
 */
class SchemaForge_WP_Output {

	private SchemaForge_WP_Detector $detector;

	/**
	 * Accumulated @type values observed from the active SEO plugin.
	 * Populated during the plugin's own filter callbacks, consumed by
	 * output_complement() which runs later in wp_head.
	 */
	private array $covered_types = [];

	/**
	 * Article-family types treated as equivalent for dedup purposes.
	 * If a SEO plugin emits any member, we skip all members from SchemaForge.
	 */
	private const ARTICLE_FAMILY = [
		'Article', 'BlogPosting', 'NewsArticle', 'TechArticle', 'Report',
		'OpinionNewsArticle', 'AnalysisNewsArticle', 'ReviewNewsArticle',
		'SatiricalArticle', 'ScholarlyArticle', 'SocialMediaPosting',
	];

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
			switch ( $active ) {
				case 'yoast':
					add_filter( 'wpseo_schema_graph', [ $this, 'merge_into_yoast' ], 10, 2 );
					break;

				case 'rankmath':
					add_filter( 'rank_math/json_ld', [ $this, 'merge_into_rankmath' ], 99, 2 );
					break;

				case 'aioseo':
					// Inspect at priority 5 (before AIOSEO renders at priority 1+ of wp_head)
					add_filter( 'aioseo_schema_output', [ $this, 'inspect_aioseo_types' ], 5 );
					add_action( 'wp_head', [ $this, 'output_complement' ], 9999 );
					break;

				case 'seopress':
					add_filter( 'seopress_schemas_output', [ $this, 'inspect_seopress_types' ], 5, 2 );
					add_action( 'wp_head', [ $this, 'output_complement' ], 9999 );
					break;

				case 'the-seo-framework':
					// TSF doesn't expose a stable graph-array filter across versions;
					// intercept the JSON string to inspect types, output complement after.
					add_filter( 'the_seo_framework_schema_output', [ $this, 'inspect_tsf_types' ], 5 );
					add_action( 'wp_head', [ $this, 'output_complement' ], 9999 );
					break;

				default:
					// Strategy says merge but no known SEO plugin — fall back to add.
					add_action( 'wp_head', [ $this, 'output_full_graph' ] );
			}
			return;
		}

		// 'add' — output standalone script tag.
		add_action( 'wp_head', [ $this, 'output_full_graph' ] );
	}

	// -------------------------------------------------------------------------
	// Yoast merge — true @graph merge (one combined <script> tag)
	// -------------------------------------------------------------------------

	public function merge_into_yoast( array $graph, mixed $context ): array {
		$nodes = $this->get_nodes();
		if ( ! $nodes ) {
			return $graph;
		}

		$webpage_id = $this->find_id( $graph, 'WebPage' );
		$org_id     = $this->find_id( $graph, 'Organization' );

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

			if ( isset( $node['@id'] ) && $this->find_id( $graph, null, $node['@id'] ) ) {
				continue;
			}

			if ( $this->graph_has_type_overlap( $graph, $node_types ) ) {
				continue;
			}

			$graph[] = $node;
		}

		return $graph;
	}

	// -------------------------------------------------------------------------
	// Rank Math merge — true @graph merge (one combined <script> tag)
	// -------------------------------------------------------------------------

	public function merge_into_rankmath( array $data, mixed $json_ld ): array {
		$nodes = $this->get_nodes();
		if ( ! $nodes ) {
			return $data;
		}

		$counts = [];
		foreach ( $nodes as $node ) {
			$type = $node['@type'] ?? 'Thing';
			$type = sanitize_key( is_array( $type ) ? reset( $type ) : $type );

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

	// -------------------------------------------------------------------------
	// AIOSEO — inspect types via filter, output complement standalone
	// -------------------------------------------------------------------------

	/**
	 * Runs at aioseo_schema_output priority 5 (read-only).
	 * AIOSEO passes an array of graph class instances; we json_encode each
	 * to extract @type without modifying the array.
	 */
	public function inspect_aioseo_types( array $graphs ): array {
		foreach ( $graphs as $graph ) {
			$data = method_exists( $graph, 'toArray' ) ? $graph->toArray() : (array) $graph;
			foreach ( (array) ( $data['@type'] ?? [] ) as $type ) {
				if ( $type ) $this->covered_types[] = $type;
			}
		}
		return $graphs;
	}

	// -------------------------------------------------------------------------
	// SEOPress — inspect types via filter, output complement standalone
	// -------------------------------------------------------------------------

	/**
	 * Runs at seopress_schemas_output priority 5 (read-only).
	 * SEOPress passes a plain array of schema arrays.
	 */
	public function inspect_seopress_types( array $schemas, mixed $post_id ): array {
		foreach ( $schemas as $schema ) {
			if ( ! is_array( $schema ) ) continue;
			foreach ( (array) ( $schema['@type'] ?? [] ) as $type ) {
				if ( $type ) $this->covered_types[] = $type;
			}
		}
		return $schemas;
	}

	// -------------------------------------------------------------------------
	// The SEO Framework — inspect via JSON string filter, output complement
	// -------------------------------------------------------------------------

	/**
	 * TSF does not expose a stable graph-array filter across all versions;
	 * we intercept the serialized JSON to extract @type values.
	 * The filter may pass a string or array depending on TSF version.
	 */
	public function inspect_tsf_types( mixed $output ): mixed {
		$json = is_array( $output ) ? wp_json_encode( $output ) : (string) $output;
		if ( preg_match_all( '/"@type"\s*:\s*"([^"]+)"/', $json, $matches ) ) {
			$this->covered_types = array_merge( $this->covered_types, $matches[1] );
		}
		return $output;
	}

	// -------------------------------------------------------------------------
	// Complement output (AIOSEO / SEOPress / TSF)
	// -------------------------------------------------------------------------

	/**
	 * Outputs a standalone <script> containing only SchemaForge nodes whose
	 * @type is not already covered by the active SEO plugin.
	 * Runs at wp_head priority 9999, after any SEO plugin's own output.
	 */
	public function output_complement(): void {
		if ( ! is_singular() ) {
			return;
		}
		$nodes = $this->get_nodes();
		if ( ! $nodes ) {
			return;
		}

		$covered = array_unique( $this->covered_types );

		$complement = array_values( array_filter( $nodes, function ( array $node ) use ( $covered ): bool {
			$raw_type   = $node['@type'] ?? '';
			$node_types = is_array( $raw_type ) ? $raw_type : [ $raw_type ];

			// Treat Article-family members as equivalent for dedup.
			$in_family = (bool) array_intersect( $node_types, self::ARTICLE_FAMILY );
			$check     = $in_family
				? array_unique( array_merge( $node_types, self::ARTICLE_FAMILY ) )
				: $node_types;

			return ! (bool) array_intersect( $check, $covered );
		} ) );

		if ( ! $complement ) {
			return;
		}

		echo '<script type="application/ld+json">' .
			wp_json_encode(
				[ '@context' => 'https://schema.org', '@graph' => $complement ],
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES |
				JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
			) .
			'</script>' . "\n";
	}

	// -------------------------------------------------------------------------
	// Standalone output (no SEO plugin / add mode)
	// -------------------------------------------------------------------------

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
		echo '<script type="application/ld+json">' .
			wp_json_encode(
				$decoded,
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES |
				JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
			) .
			'</script>' . "\n";
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function get_raw_json(): ?string {
		if ( ! is_singular() ) {
			return null;
		}
		$post_id = get_queried_object_id();
		$manual  = get_post_meta( $post_id, '_schemaforge_wp_manual', true );
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
		if ( isset( $decoded['@graph'] ) && is_array( $decoded['@graph'] ) ) {
			return $decoded['@graph'];
		}
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

	private function resolve_strategy( ?string $active_plugin ): string {
		$setting = get_option( 'schemaforge_wp_strategy', 'auto' );
		if ( $setting !== 'auto' ) {
			return $setting;
		}
		return $active_plugin ? 'merge' : 'add';
	}

	private function disable_seo_plugin_output( ?string $active ): void {
		switch ( $active ) {
			case 'yoast':
				add_filter( 'wpseo_json_ld_output', '__return_false' );
				break;
			case 'rankmath':
				add_filter( 'rank_math/json_ld', '__return_empty_array' );
				break;
			case 'aioseo':
				add_filter( 'aioseo_schema_output', '__return_empty_array', 1 );
				break;
			case 'seopress':
				add_filter( 'seopress_schemas_output', '__return_empty_array', 1 );
				break;
			case 'the-seo-framework':
				// TSF: suppress via known disable filters (covers 4.x and 5.x)
				add_filter( 'the_seo_framework_schema_output', '__return_null',        1 );
				add_filter( 'the_seo_framework_json_ld_output', '__return_null',       1 );
				add_filter( 'the_seo_framework_schema_enabled', '__return_false',      1 );
				break;
		}
	}
}
