<?php
defined( 'ABSPATH' ) || exit;

class SchemaForge_WP_Detector {

	public function is_yoast_active(): bool {
		return defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Frontend' );
	}

	public function is_rankmath_active(): bool {
		return defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' );
	}

	/** Returns the primary active SEO plugin slug, or null if none detected. */
	public function get_active_plugin(): ?string {
		if ( $this->is_yoast_active() ) {
			return 'yoast';
		}
		if ( $this->is_rankmath_active() ) {
			return 'rankmath';
		}
		return null;
	}

	/** Human-readable label for the settings/meta-box display. */
	public function get_label(): string {
		return match ( $this->get_active_plugin() ) {
			'yoast'    => 'Yoast SEO',
			'rankmath' => 'Rank Math',
			default    => __( 'Kein SEO-Plugin erkannt', 'schemaforge-wp' ),
		};
	}
}
