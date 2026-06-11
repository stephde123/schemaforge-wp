<?php
defined( 'ABSPATH' ) || exit;

class SchemaForge_WP_Generator {

	private SchemaForge_WP_Api_Client $api;
	private SchemaForge_WP_Detector   $detector;

	public function __construct( SchemaForge_WP_Api_Client $api, SchemaForge_WP_Detector $detector ) {
		$this->api      = $api;
		$this->detector = $detector;
	}

	public function register_hooks(): void {
		add_action( 'save_post', [ $this, 'on_save_post' ], 10, 2 );
		// accepted_args = 2 so the $trigger argument is forwarded by WP cron.
		add_action( SCHEMAFORGE_WP_CRON_HOOK, [ $this, 'run_generation' ], 10, 2 );
	}

	public function on_save_post( int $post_id, \WP_Post $post ): void {
		// Skip autosaves and revisions.
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Only for configured post types.
		$enabled_types = (array) get_option( 'schemaforge_wp_post_types', [ 'post', 'page' ] );
		if ( ! in_array( $post->post_type, $enabled_types, true ) ) {
			return;
		}

		// Respect global toggle and per-post override.
		if ( ! get_option( 'schemaforge_wp_auto_on_save', false ) ) {
			return;
		}
		if ( get_post_meta( $post_id, '_schemaforge_wp_disabled', true ) ) {
			return;
		}
		// Manual markup takes precedence — don't overwrite.
		if ( get_post_meta( $post_id, '_schemaforge_wp_manual', true ) ) {
			return;
		}

		// Avoid scheduling duplicate events for the same post.
		$args = [ $post_id, 'save_post' ];
		if ( wp_next_scheduled( SCHEMAFORGE_WP_CRON_HOOK, $args ) ) {
			return;
		}

		// Mark as pending and schedule async generation (30s delay absorbs rapid multi-saves).
		$this->update_status( $post_id, 'pending' );
		wp_schedule_single_event( time() + 30, SCHEMAFORGE_WP_CRON_HOOK, $args );
	}

	/**
	 * Actually run the generation — called by WP cron or directly (manual trigger).
	 */
	public function run_generation( int $post_id, string $trigger = 'manual' ): void {
		$this->update_status( $post_id, 'pending' );

		$result = $this->api->generate( $post_id );

		if ( is_wp_error( $result ) ) {
			$this->update_status( $post_id, 'error', [
				'error_message' => $result->get_error_message(),
				'trigger'       => $trigger,
			] );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[SchemaForge WP] Generation error for post ' . $post_id . ': ' . $result->get_error_message() );
			}
			return;
		}

		$json = $this->normalize_jsonld( $result['jsonld'] ?? null );
		if ( ! $json ) {
			delete_post_meta( $post_id, '_schemaforge_wp_jsonld' );
			$this->update_status( $post_id, 'error', [
				'error_message' => __( 'API returned no valid JSON-LD.', 'schemaforge-wp' ),
				'trigger'       => $trigger,
			] );
			return;
		}

		update_post_meta( $post_id, '_schemaforge_wp_jsonld', wp_slash( $json ) );

		$meta = [
			'recommendation'  => $result['recommendation']  ?? '',
			'usedMode'        => $result['usedMode']         ?? 'deterministic',
			'detectedPlugins' => $result['detection']['detectedPlugins'] ?? [],
			'coverageScore'   => $result['coverageScore']   ?? 0,
			'issues'          => $result['validation']['issues'] ?? [],
			'missingRequired' => $result['validation']['missingRequired'] ?? [],
			'generatedAt'     => current_time( 'mysql' ),
			'trigger'         => $trigger,
			'status'          => 'done',
		];
		update_post_meta( $post_id, '_schemaforge_wp_meta', $meta );
	}

	/** Public method so REST handler can call it synchronously. */
	public function generate_now( int $post_id ): array|\WP_Error {
		$result = $this->api->generate( $post_id );

		if ( is_wp_error( $result ) ) {
			$this->update_status( $post_id, 'error', [
				'error_message' => $result->get_error_message(),
				'trigger'       => 'manual',
			] );
			return $result;
		}

		$json = $this->normalize_jsonld( $result['jsonld'] ?? null );
		if ( ! $json ) {
			delete_post_meta( $post_id, '_schemaforge_wp_jsonld' );
			$this->update_status( $post_id, 'error', [
				'error_message' => __( 'API returned no valid JSON-LD.', 'schemaforge-wp' ),
				'trigger'       => 'manual',
			] );
			return new \WP_Error( 'invalid_jsonld', __( 'API returned no valid JSON-LD.', 'schemaforge-wp' ) );
		}

		update_post_meta( $post_id, '_schemaforge_wp_jsonld', wp_slash( $json ) );

		$meta = [
			'recommendation'  => $result['recommendation']  ?? '',
			'usedMode'        => $result['usedMode']         ?? 'deterministic',
			'detectedPlugins' => $result['detection']['detectedPlugins'] ?? [],
			'coverageScore'   => $result['coverageScore']   ?? 0,
			'issues'          => $result['validation']['issues'] ?? [],
			'missingRequired' => $result['validation']['missingRequired'] ?? [],
			'generatedAt'     => current_time( 'mysql' ),
			'trigger'         => 'manual',
			'status'          => 'done',
		];
		update_post_meta( $post_id, '_schemaforge_wp_meta', $meta );

		return $meta;
	}

	/**
	 * Normalise an API-returned jsonld value (array or already-encoded string)
	 * to a clean JSON string, or null if it cannot be decoded.
	 */
	private function normalize_jsonld( mixed $jsonld ): ?string {
		if ( is_string( $jsonld ) ) {
			$decoded = json_decode( $jsonld, true );
			if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) {
				return null;
			}
			return wp_json_encode( $decoded );
		}

		if ( is_array( $jsonld ) ) {
			return wp_json_encode( $jsonld );
		}

		return null;
	}

	private function update_status( int $post_id, string $status, array $extra = [] ): void {
		$meta = (array) get_post_meta( $post_id, '_schemaforge_wp_meta', true );
		update_post_meta( $post_id, '_schemaforge_wp_meta', array_merge( $meta, [ 'status' => $status ], $extra ) );
	}
}
