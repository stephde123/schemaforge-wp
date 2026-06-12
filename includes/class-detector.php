<?php
defined( 'ABSPATH' ) || exit;

class SchemaForge_WP_Detector {

	public function is_yoast_active(): bool {
		return defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Frontend' );
	}

	public function is_rankmath_active(): bool {
		return defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' );
	}

	public function is_aioseo_active(): bool {
		return defined( 'AIOSEO_VERSION' ) || class_exists( 'AIOSEO\Plugin\AIOSEO' );
	}

	public function is_tsf_active(): bool {
		return defined( 'THE_SEO_FRAMEWORK_VERSION' ) || function_exists( 'tsf' ) || class_exists( 'The_SEO_Framework\Core' );
	}

	public function is_seopress_active(): bool {
		return defined( 'SEOPRESS_VERSION' ) || class_exists( 'SeoPress_Admin_Init' );
	}

	/** Returns the primary active SEO plugin slug, or null if none detected. */
	public function get_active_plugin(): ?string {
		if ( $this->is_yoast_active() )    return 'yoast';
		if ( $this->is_rankmath_active() ) return 'rankmath';
		if ( $this->is_aioseo_active() )   return 'aioseo';
		if ( $this->is_tsf_active() )      return 'the-seo-framework';
		if ( $this->is_seopress_active() ) return 'seopress';
		return null;
	}

	/** Human-readable label for the settings/meta-box display. */
	public function get_label(): string {
		return match ( $this->get_active_plugin() ) {
			'yoast'              => 'Yoast SEO',
			'rankmath'           => 'Rank Math',
			'aioseo'             => 'All in One SEO',
			'the-seo-framework'  => 'The SEO Framework',
			'seopress'           => 'SEOPress',
			default              => __( 'Kein SEO-Plugin erkannt', 'schemaforge-wp' ),
		};
	}
}
