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
		add_action( SCHEMAFORGE_WP_CRON_HOOK, [ $this, 'run_generation' ] );
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
		if ( ! get_option( 'schemaforge_wp_auto_on_save', true ) ) {
			return;
		}
		if ( get_post_meta( $post_id, '_schemaforge_wp_disabled', true ) ) {
			return;
		}
		// Manual markup takes precedence — don't overwrite.
		if ( get_post_meta( $post_id, '_schemaforge_wp_manual', true ) ) {
			return;
		}

		// Mark as pending and schedule async generation.
		$this->update_status( $post_id, 'pending' );
		wp_schedule_single_event( time(), SCHEMAFORGE_WP_CRON_HOOK, [ $post_id, 'save_post' ] );
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

		// Save the JSON-LD.
		$jsonld = $result['jsonld'] ?? null;
		if ( $jsonld ) {
			update_post_meta( $post_id, '_schemaforge_wp_jsonld', wp_json_encode( $jsonld ) );
		}

		// Save meta (coverage, issues, recommendation, etc.).
		$meta = [
			'recommendation'  => $result['recommendation']  ?? '',
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

		$jsonld = $result['jsonld'] ?? null;
		if ( $jsonld ) {
			update_post_meta( $post_id, '_schemaforge_wp_jsonld', wp_json_encode( $jsonld ) );
		}

		$meta = [
			'recommendation'  => $result['recommendation']  ?? '',
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

	private function update_status( int $post_id, string $status, array $extra = [] ): void {
		$meta = (array) get_post_meta( $post_id, '_schemaforge_wp_meta', true );
		update_post_meta( $post_id, '_schemaforge_wp_meta', array_merge( $meta, [ 'status' => $status ], $extra ) );
	}
}
