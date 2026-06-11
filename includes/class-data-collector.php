<?php
defined( 'ABSPATH' ) || exit;

/**
 * Collects authoritative CMS data from WordPress for a given post.
 * The resulting array maps directly to the WpSignals type on the API server.
 */
class SchemaForge_WP_Data_Collector {

	/**
	 * Collect all available signals for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed> Ready to JSON-encode as wpSignals.
	 */
	public function collect( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return [];
		}

		$signals = [
			'post'     => $this->collect_post( $post ),
			'taxonomy' => $this->collect_taxonomy( $post ),
			'site'     => $this->collect_site(),
		];

		$meta = $this->collect_meta( $post_id );
		if ( $meta ) {
			$signals['meta'] = $meta;
		}

		if ( $this->is_woocommerce_product( $post ) ) {
			$woo = $this->collect_woocommerce( $post_id );
			if ( $woo ) {
				$signals['woocommerce'] = $woo;
			}
		}

		return array_filter( $signals, fn( $v ) => ! empty( $v ) );
	}

	// -------------------------------------------------------------------------
	// Post core data
	// -------------------------------------------------------------------------

	private function collect_post( \WP_Post $post ): array {
		$data = [
			'type'    => $post->post_type,
			'title'   => get_the_title( $post ),
			'excerpt' => wp_strip_all_tags( get_the_excerpt( $post ) ) ?: null,
		];

		$author_id = (int) $post->post_author;
		if ( $author_id ) {
			$author = get_userdata( $author_id );
			if ( $author ) {
				$author_data = array_filter( [
					'name' => $author->display_name ?: null,
					'bio'  => get_user_meta( $author_id, 'description', true ) ?: null,
					'url'  => get_author_posts_url( $author_id ) ?: null,
				] );
				if ( $author_data ) {
					$data['author'] = $author_data;
				}
			}
		}

		$thumb_id = get_post_thumbnail_id( $post );
		if ( $thumb_id ) {
			$src = wp_get_attachment_image_src( $thumb_id, 'full' );
			$alt = get_post_meta( $thumb_id, '_wp_attachment_image_alt', true );
			if ( $src ) {
				$data['featuredImage'] = array_filter( [
					'url' => $src[0],
					'alt' => $alt ?: null,
				] );
			}
		}

		if ( $post->post_date_gmt && '0000-00-00 00:00:00' !== $post->post_date_gmt ) {
			$data['publishedAt'] = gmdate( 'c', strtotime( $post->post_date_gmt ) );
		}
		if ( $post->post_modified_gmt && '0000-00-00 00:00:00' !== $post->post_modified_gmt ) {
			$data['modifiedAt'] = gmdate( 'c', strtotime( $post->post_modified_gmt ) );
		}

		return array_filter( $data, fn( $v ) => $v !== null && $v !== '' && $v !== [] );
	}

	// -------------------------------------------------------------------------
	// Taxonomy
	// -------------------------------------------------------------------------

	private function collect_taxonomy( \WP_Post $post ): array {
		$data = [];

		$cats = get_the_terms( $post, 'category' );
		if ( $cats && ! is_wp_error( $cats ) ) {
			$data['categories'] = wp_list_pluck( $cats, 'name' );
		}

		$tags = get_the_terms( $post, 'post_tag' );
		if ( $tags && ! is_wp_error( $tags ) ) {
			$data['tags'] = wp_list_pluck( $tags, 'name' );
		}

		// Custom taxonomies — skip built-ins already handled above
		$taxonomies = get_object_taxonomies( $post->post_type, 'objects' );
		$custom     = [];
		foreach ( $taxonomies as $tax ) {
			if ( in_array( $tax->name, [ 'category', 'post_tag', 'post_format' ], true ) ) {
				continue;
			}
			$terms = get_the_terms( $post, $tax->name );
			if ( $terms && ! is_wp_error( $terms ) ) {
				$custom[ $tax->name ] = wp_list_pluck( $terms, 'name' );
			}
		}
		if ( $custom ) {
			$data['custom'] = $custom;
		}

		return $data;
	}

	// -------------------------------------------------------------------------
	// Site-level info
	// -------------------------------------------------------------------------

	private function collect_site(): array {
		$logo_url = null;
		$logo_id  = (int) get_theme_mod( 'custom_logo' );
		if ( $logo_id ) {
			$src = wp_get_attachment_image_src( $logo_id, 'full' );
			if ( $src ) {
				$logo_url = $src[0];
			}
		}

		return array_filter( [
			'name'        => get_bloginfo( 'name' ) ?: null,
			'description' => get_bloginfo( 'description' ) ?: null,
			'url'         => get_home_url() ?: null,
			'logo'        => $logo_url,
		] );
	}

	// -------------------------------------------------------------------------
	// Post meta — only public keys, truncated to 300 chars
	// -------------------------------------------------------------------------

	private function collect_meta( int $post_id ): array {
		$all = get_post_meta( $post_id );
		$out = [];

		foreach ( $all as $key => $values ) {
			if ( str_starts_with( $key, '_' ) ) {
				continue;
			}
			$val = is_array( $values ) ? $values[0] : $values;
			if ( is_serialized( $val ) ) {
				continue;
			}
			$str = is_scalar( $val ) ? (string) $val : wp_json_encode( $val );
			if ( strlen( $str ) > 300 ) {
				continue;
			}
			$out[ $key ] = $val;
		}

		return $out;
	}

	// -------------------------------------------------------------------------
	// WooCommerce
	// -------------------------------------------------------------------------

	private function is_woocommerce_product( \WP_Post $post ): bool {
		return class_exists( 'WooCommerce' ) && 'product' === $post->post_type;
	}

	private function collect_woocommerce( int $post_id ): array {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return [];
		}
		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return [];
		}

		$data = array_filter( [
			'sku'          => $product->get_sku() ?: null,
			'price'        => $product->get_price() !== '' ? (string) $product->get_price() : null,
			'regularPrice' => $product->get_regular_price() !== '' ? (string) $product->get_regular_price() : null,
			'salePrice'    => $product->get_sale_price() !== '' ? (string) $product->get_sale_price() : null,
			'currency'     => get_woocommerce_currency() ?: null,
			'availability' => $product->is_in_stock() ? 'InStock' : 'OutOfStock',
			'weight'       => $product->get_weight() !== '' ? (string) $product->get_weight() : null,
		] );

		$length = $product->get_length();
		$width  = $product->get_width();
		$height = $product->get_height();
		if ( $length !== '' || $width !== '' || $height !== '' ) {
			$data['dimensions'] = array_filter( [
				'length' => $length !== '' ? (string) $length : null,
				'width'  => $width !== '' ? (string) $width : null,
				'height' => $height !== '' ? (string) $height : null,
			] );
		}

		$woo_cats = get_the_terms( $post_id, 'product_cat' );
		if ( $woo_cats && ! is_wp_error( $woo_cats ) ) {
			$data['categories'] = wp_list_pluck( $woo_cats, 'name' );
		}

		return $data;
	}
}
