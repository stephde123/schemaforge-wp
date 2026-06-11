<?php
defined( 'ABSPATH' ) || exit;

/**
 * Collects authoritative CMS data from WordPress for a given post.
 * The resulting array maps directly to the WpSignals type on the API server.
 */
class SchemaForge_WP_Data_Collector {

	/**
	 * Private meta keys that carry schema-relevant data despite their underscore prefix.
	 * Everything outside this list with a leading underscore is still blocked.
	 */
	private const ALLOWED_PRIVATE_KEYS = [
		// WooCommerce fallback (covered by dedicated adapter; here as belt-and-suspenders)
		'_price', '_regular_price', '_sale_price', '_sku', '_stock_status',
		// Events fallback
		'_EventStartDate', '_EventEndDate', '_EventCost',
		'_event_start_date', '_event_end_date',
		// Ratings
		'_glsr_average', '_glsr_count', '_rating', '_review_count',
		// Generic LMS / course meta
		'_course_duration', '_course_price', '_course_level',
		// Generic job meta
		'_job_salary', '_job_location', '_job_type',
	];

	// -------------------------------------------------------------------------
	// Main entry point
	// -------------------------------------------------------------------------

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

		$seo = $this->collect_seo( $post_id );
		if ( $seo ) {
			$signals['seo'] = $seo;
		}

		$meta = $this->collect_meta( $post_id );
		if ( $meta ) {
			$signals['meta'] = $meta;
		}

		$blocks = $this->collect_blocks( $post );
		if ( $blocks ) {
			$signals['blocks'] = $blocks;
		}

		if ( $this->is_woocommerce_product( $post ) ) {
			$woo = $this->collect_woocommerce( $post_id );
			if ( $woo ) {
				$signals['woocommerce'] = $woo;
			}
		}

		// Plugin adapters — each is gated on post type + plugin presence.
		$events = $this->collect_events_calendar( $post, $post_id );
		if ( $events ) $signals['events'] = $events;

		$courses = $this->collect_courses( $post, $post_id );
		if ( $courses ) $signals['courses'] = $courses;

		$jobs = $this->collect_jobs( $post, $post_id );
		if ( $jobs ) $signals['jobs'] = $jobs;

		$edd = $this->collect_edd( $post, $post_id );
		if ( $edd ) $signals['edd'] = $edd;

		$ratings = $this->collect_ratings( $post_id );
		if ( $ratings ) $signals['ratings'] = $ratings;

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
	// SEO plugin meta
	// -------------------------------------------------------------------------

	private function collect_seo( int $post_id ): array {
		// Priority order within each field: first non-empty value wins.
		$candidates = [
			'description' => [
				'_yoast_wpseo_metadesc',
				'rank_math_description',
				'_aioseo_description',
				'_seopress_titles_desc',
				'_genesis_description',
			],
			'title' => [
				'_yoast_wpseo_title',
				'rank_math_title',
				'_aioseo_title',
				'_seopress_titles_title',
			],
			'canonical' => [
				'_yoast_wpseo_canonical',
				'rank_math_canonical_url',
			],
		];

		$data = [];
		foreach ( $candidates as $field => $meta_keys ) {
			foreach ( $meta_keys as $key ) {
				$val = get_post_meta( $post_id, $key, true );
				if ( $val ) {
					$data[ $field ] = (string) $val;
					break;
				}
			}
		}

		if ( defined( 'WPSEO_VERSION' ) ) {
			$data['plugin'] = 'yoast';
		} elseif ( defined( 'RANK_MATH_VERSION' ) ) {
			$data['plugin'] = 'rankmath';
		} elseif ( class_exists( 'AIOSEO\Plugin\AIOSEO' ) ) {
			$data['plugin'] = 'aioseo';
		} elseif ( defined( 'SEOPRESS_VERSION' ) ) {
			$data['plugin'] = 'seopress';
		}

		return array_filter( $data );
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
	// Post meta
	// -------------------------------------------------------------------------

	private function collect_meta( int $post_id ): array {
		$all = get_post_meta( $post_id );
		$out = [];

		foreach ( $all as $key => $values ) {
			if ( str_starts_with( $key, '_' ) && ! in_array( $key, self::ALLOWED_PRIVATE_KEYS, true ) ) {
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
	// Gutenberg block parsing
	// -------------------------------------------------------------------------

	private function collect_blocks( \WP_Post $post ): array {
		if ( ! function_exists( 'parse_blocks' ) || empty( $post->post_content ) ) {
			return [];
		}

		$flat   = $this->flatten_blocks( parse_blocks( $post->post_content ) );
		$result = [];

		foreach ( $flat as $block ) {
			$name = $block['blockName'] ?? null;
			if ( ! $name ) continue;

			$entry = [ 'name' => $name ];

			// Yoast FAQ block
			if ( $name === 'yoast/faq-block' ) {
				$items = [];
				foreach ( $block['attrs']['questions'] ?? [] as $q ) {
					$question = $this->yoast_rich_to_text( $q['jsonQuestion'] ?? '' );
					$answer   = $this->yoast_rich_to_text( $q['jsonAnswer']   ?? '' );
					if ( $question && $answer ) {
						$items[] = [ 'question' => $question, 'answer' => $answer ];
					}
				}
				if ( $items ) $entry['faqItems'] = array_slice( $items, 0, 20 );
			}

			// Rank Math FAQ block
			if ( $name === 'rank-math/faq-block' ) {
				$items = [];
				foreach ( $block['attrs']['faqs'] ?? [] as $faq ) {
					$q = wp_strip_all_tags( (string) ( $faq['question'] ?? '' ) );
					$a = wp_strip_all_tags( (string) ( $faq['answer']   ?? '' ) );
					if ( $q && $a ) $items[] = [ 'question' => $q, 'answer' => $a ];
				}
				if ( $items ) $entry['faqItems'] = array_slice( $items, 0, 20 );
			}

			// Native core/details block (WordPress 6.1+)
			if ( $name === 'core/details' ) {
				$q = wp_strip_all_tags( (string) ( $block['attrs']['summary'] ?? '' ) );
				if ( ! $q ) {
					preg_match( '/<summary[^>]*>(.*?)<\/summary>/is', $block['innerHTML'] ?? '', $m );
					$q = wp_strip_all_tags( $m[1] ?? '' );
				}
				$body = preg_replace( '/<summary[^>]*>.*?<\/summary>/is', '', $block['innerHTML'] ?? '' );
				$body = preg_replace( '/<\/?details[^>]*>/i', '', $body ?? '' );
				$a    = trim( wp_strip_all_tags( $body ) );
				if ( $q && $a && strlen( $a ) >= 10 ) {
					$entry['faqItems'] = [ [ 'question' => trim( $q ), 'answer' => $a ] ];
				}
			}

			// Ordered list → potential HowTo steps
			if ( $name === 'core/list' && ! empty( $block['attrs']['ordered'] ) ) {
				$items = [];
				foreach ( $block['innerBlocks'] as $item_block ) {
					$text = trim( wp_strip_all_tags( $item_block['innerHTML'] ?? '' ) );
					if ( $text ) $items[] = $text;
				}
				if ( count( $items ) >= 3 ) {
					$entry['ordered'] = true;
					$entry['items']   = array_slice( $items, 0, 30 );
				}
			}

			// Media
			if ( $name === 'core/image' ) {
				if ( ! empty( $block['attrs']['url'] ) ) $entry['url'] = $block['attrs']['url'];
				if ( ! empty( $block['attrs']['alt'] ) ) $entry['alt'] = $block['attrs']['alt'];
			}
			if ( $name === 'core/video' && ! empty( $block['attrs']['src'] ) ) {
				$entry['url'] = $block['attrs']['src'];
			}

			// Only include blocks that carry structured data beyond the block name.
			if ( count( array_filter( $entry ) ) > 1 ) {
				$result[] = array_filter( $entry );
			}
		}

		return array_slice( $result, 0, 50 );
	}

	private function flatten_blocks( array $blocks, int $depth = 0 ): array {
		if ( $depth > 6 ) return [];
		$out = [];
		foreach ( $blocks as $block ) {
			if ( ! empty( $block['blockName'] ) ) {
				$out[] = $block;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				foreach ( $this->flatten_blocks( $block['innerBlocks'], $depth + 1 ) as $inner ) {
					$out[] = $inner;
				}
			}
		}
		return $out;
	}

	/**
	 * Extracts plain text from a Yoast rich-text value, which can be a plain
	 * string or an array of React-style node objects ({type, props: {children}}).
	 */
	private function yoast_rich_to_text( $value ): string {
		if ( is_string( $value ) ) {
			return trim( wp_strip_all_tags( $value ) );
		}
		if ( is_array( $value ) ) {
			$parts = [];
			foreach ( $value as $node ) {
				if ( is_string( $node ) ) {
					$parts[] = $node;
				} elseif ( is_array( $node ) ) {
					$children = $node['props']['children'] ?? null;
					if ( $children !== null ) {
						$parts[] = $this->yoast_rich_to_text( $children );
					}
				}
			}
			return trim( wp_strip_all_tags( implode( ' ', array_filter( $parts ) ) ) );
		}
		return '';
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
				'width'  => $width  !== '' ? (string) $width  : null,
				'height' => $height !== '' ? (string) $height : null,
			] );
		}

		$woo_cats = get_the_terms( $post_id, 'product_cat' );
		if ( $woo_cats && ! is_wp_error( $woo_cats ) ) {
			$data['categories'] = wp_list_pluck( $woo_cats, 'name' );
		}

		return $data;
	}

	// -------------------------------------------------------------------------
	// Plugin adapters
	// -------------------------------------------------------------------------

	private function collect_events_calendar( \WP_Post $post, int $post_id ): array {
		if ( $post->post_type !== 'tribe_events' || ! class_exists( 'Tribe__Events__Main' ) ) {
			return [];
		}

		$data = array_filter( [
			'startDate' => get_post_meta( $post_id, '_EventStartDate', true ) ?: null,
			'endDate'   => get_post_meta( $post_id, '_EventEndDate',   true ) ?: null,
			'timezone'  => get_post_meta( $post_id, '_EventTimezone',  true ) ?: null,
			'ticketUrl' => get_post_meta( $post_id, '_EventURL',       true ) ?: null,
			'cost'      => get_post_meta( $post_id, '_EventCost',      true ) ?: null,
			'status'    => get_post_meta( $post_id, '_EventStatus',    true ) ?: null,
		] );

		if ( (bool) get_post_meta( $post_id, '_EventAllDay', true ) ) {
			$data['allDay'] = true;
		}

		$venue_id = (int) get_post_meta( $post_id, '_EventVenueID', true );
		if ( $venue_id ) {
			$venue = array_filter( [
				'name'    => get_the_title( $venue_id ) ?: null,
				'address' => get_post_meta( $venue_id, '_VenueAddress', true ) ?: null,
				'city'    => get_post_meta( $venue_id, '_VenueCity',    true ) ?: null,
				'zip'     => get_post_meta( $venue_id, '_VenueZip',     true ) ?: null,
				'country' => get_post_meta( $venue_id, '_VenueCountry', true ) ?: null,
				'phone'   => get_post_meta( $venue_id, '_VenuePhone',   true ) ?: null,
				'url'     => get_post_meta( $venue_id, '_VenueURL',     true ) ?: null,
			] );
			if ( $venue ) $data['venue'] = $venue;
		}

		$org_ids = get_post_meta( $post_id, '_EventOrganizerID', false );
		$org_id  = is_array( $org_ids ) ? (int) ( $org_ids[0] ?? 0 ) : (int) $org_ids;
		if ( $org_id ) {
			$organizer = array_filter( [
				'name'  => get_the_title( $org_id ) ?: null,
				'email' => get_post_meta( $org_id, '_OrganizerEmail',   true ) ?: null,
				'url'   => get_post_meta( $org_id, '_OrganizerWebsite', true ) ?: null,
				'phone' => get_post_meta( $org_id, '_OrganizerPhone',   true ) ?: null,
			] );
			if ( $organizer ) $data['organizer'] = $organizer;
		}

		return $data;
	}

	private function collect_courses( \WP_Post $post, int $post_id ): array {
		// LearnPress
		if ( $post->post_type === 'lp_course' && defined( 'LP_PLUGIN_FILE' ) ) {
			return array_filter( [
				'price'       => get_post_meta( $post_id, '_lp_price',        true ) ?: null,
				'currency'    => function_exists( 'learn_press_get_currency' ) ? learn_press_get_currency() : null,
				'duration'    => get_post_meta( $post_id, '_lp_duration',     true ) ?: null,
				'level'       => get_post_meta( $post_id, '_lp_level',        true ) ?: null,
				'maxStudents' => get_post_meta( $post_id, '_lp_max_students', true ) ?: null,
			] );
		}

		// TutorLMS
		if ( in_array( $post->post_type, [ 'tutor-course', 'tutor_course' ], true ) && defined( 'TUTOR_VERSION' ) ) {
			$instructor_id = (int) get_post_meta( $post_id, '_tutor_instructor_id', true );
			return array_filter( [
				'price'      => get_post_meta( $post_id, '_tutor_course_price', true ) ?: null,
				'duration'   => get_post_meta( $post_id, '_course_duration',    true ) ?: null,
				'level'      => get_post_meta( $post_id, '_tutor_course_level', true ) ?: null,
				'instructor' => $instructor_id ? get_the_author_meta( 'display_name', $instructor_id ) : null,
			] );
		}

		// LifterLMS
		if ( $post->post_type === 'course' && class_exists( 'LifterLMS' ) ) {
			return array_filter( [
				'price'  => get_post_meta( $post_id, '_llms_price',   true ) ?: null,
				'access' => get_post_meta( $post_id, '_llms_is_free', true ) === 'yes' ? 'free' : null,
			] );
		}

		return [];
	}

	private function collect_jobs( \WP_Post $post, int $post_id ): array {
		if ( $post->post_type !== 'job_listing' || ! class_exists( 'WP_Job_Manager' ) ) {
			return [];
		}

		return array_filter( [
			'jobType'    => get_post_meta( $post_id, '_job_type',          true ) ?: null,
			'location'   => get_post_meta( $post_id, '_job_location',      true ) ?: null,
			'salary'     => get_post_meta( $post_id, '_job_salary',        true ) ?: null,
			'company'    => get_post_meta( $post_id, '_company_name',      true ) ?: null,
			'companyUrl' => get_post_meta( $post_id, '_company_website',   true ) ?: null,
			'applyUrl'   => get_post_meta( $post_id, '_application',       true ) ?: null,
			'remote'     => (bool) get_post_meta( $post_id, '_remote_position', true ) ?: null,
			'expiryDate' => get_post_meta( $post_id, '_job_expires',       true ) ?: null,
		] );
	}

	private function collect_edd( \WP_Post $post, int $post_id ): array {
		if ( $post->post_type !== 'download' || ! class_exists( 'Easy_Digital_Downloads' ) ) {
			return [];
		}

		if ( function_exists( 'edd_has_variable_prices' ) && edd_has_variable_prices( $post_id ) ) {
			$price = function_exists( 'edd_get_lowest_price_option' )
				? (string) edd_get_lowest_price_option( $post_id )
				: null;
		} else {
			$price = function_exists( 'edd_get_download_price' )
				? (string) edd_get_download_price( $post_id )
				: null;
		}

		$cats = get_the_terms( $post_id, 'download_category' );
		$tags = get_the_terms( $post_id, 'download_tag' );

		return array_filter( [
			'price'            => $price ?: null,
			'currency'         => function_exists( 'edd_get_currency' ) ? edd_get_currency() : null,
			'downloadCategory' => ( $cats && ! is_wp_error( $cats ) ) ? wp_list_pluck( $cats, 'name' ) : null,
			'downloadTag'      => ( $tags && ! is_wp_error( $tags ) ) ? wp_list_pluck( $tags, 'name' ) : null,
		] );
	}

	private function collect_ratings( int $post_id ): array {
		// Site Reviews plugin
		if ( class_exists( 'GeminiLabs\SiteReviews\Plugin' ) ) {
			$avg = get_post_meta( $post_id, '_glsr_average', true );
			$cnt = get_post_meta( $post_id, '_glsr_count',   true );
			if ( $avg !== '' && $avg !== false ) {
				return array_filter( [
					'average' => (float) $avg,
					'count'   => $cnt !== '' && $cnt !== false ? (int) $cnt : null,
					'source'  => 'site-reviews',
				] );
			}
		}

		// WP-Review plugin
		if ( class_exists( 'WP_Review' ) || defined( 'WP_REVIEW_PLUGIN_VERSION' ) ) {
			$avg = get_post_meta( $post_id, 'wprev_average_result', true );
			if ( $avg !== '' && $avg !== false ) {
				return [ 'average' => (float) $avg, 'source' => 'wp-review' ];
			}
		}

		return [];
	}
}
