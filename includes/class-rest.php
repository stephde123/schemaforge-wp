<?php
defined( 'ABSPATH' ) || exit;

class SchemaForge_WP_Rest {

	private SchemaForge_WP_Api_Client $api;
	private SchemaForge_WP_Generator  $generator;

	public function __construct( SchemaForge_WP_Api_Client $api, SchemaForge_WP_Generator $generator ) {
		$this->api       = $api;
		$this->generator = $generator;

		add_action( 'wp_ajax_schemaforge_wp_generate',         [ $this, 'ajax_generate' ] );
		add_action( 'wp_ajax_schemaforge_wp_test_connection',  [ $this, 'ajax_test_connection' ] );
		add_action( 'wp_ajax_schemaforge_wp_preview',          [ $this, 'ajax_preview' ] );
	}

	public function ajax_generate(): void {
		check_ajax_referer( 'schemaforge_wp_generate', 'nonce' );

		$post_id = (int) ( $_POST['post_id'] ?? 0 );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( __( 'Keine Berechtigung.', 'schemaforge-wp' ), 403 );
		}

		// Manual trigger runs synchronously so the meta box can be refreshed.
		$result = $this->generator->generate_now( $post_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( [
			'status'        => $result['status']        ?? 'done',
			'coverageScore' => $result['coverageScore']  ?? 0,
			'generatedAt'   => $result['generatedAt']    ?? '',
			'trigger'       => $result['trigger']        ?? 'manual',
			'issues'        => $result['issues']         ?? [],
		] );
	}

	public function ajax_test_connection(): void {
		check_ajax_referer( 'schemaforge_wp_test_connection', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Keine Berechtigung.', 'schemaforge-wp' ), 403 );
			return;
		}

		// Step 1: Basic server reachability.
		$health = $this->api->test_connection();
		if ( is_wp_error( $health ) ) {
			wp_send_json_error( $health->get_error_message() );
			return;
		}

		$auth_mode = get_option( 'schemaforge_wp_auth_mode', 'none' );

		// Step 2: Auth check depending on mode.
		if ( $auth_mode === 'server' ) {
			$cred = $this->api->test_credentials();
			if ( is_wp_error( $cred ) ) {
				wp_send_json_error(
					sprintf(
						/* translators: %s: error message */
						__( 'Server erreichbar, aber Zugangsdaten ungültig: %s', 'schemaforge-wp' ),
						$cred->get_error_message()
					)
				);
				return;
			}
			wp_send_json_success( array_merge( $health, $cred, [ 'auth_mode' => 'server' ] ) );
			return;
		}

		if ( $auth_mode === 'own-key' ) {
			$key_check = $this->api->validate_key_format();
			if ( is_wp_error( $key_check ) ) {
				wp_send_json_error(
					sprintf(
						/* translators: %s: error message */
						__( 'Server erreichbar, aber Key-Problem: %s', 'schemaforge-wp' ),
						$key_check->get_error_message()
					)
				);
				return;
			}
			wp_send_json_success( array_merge( $health, $key_check, [ 'auth_mode' => 'own-key' ] ) );
			return;
		}

		wp_send_json_success( array_merge( $health, [ 'auth_mode' => 'none' ] ) );
	}

	public function ajax_preview(): void {
		check_ajax_referer( 'schemaforge_wp_generate', 'nonce' );

		$post_id = (int) ( $_POST['post_id'] ?? 0 );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( __( 'Keine Berechtigung.', 'schemaforge-wp' ), 403 );
		}

		// Return manual markup if set, otherwise generated.
		$manual  = get_post_meta( $post_id, '_schemaforge_wp_manual', true );
		$jsonld  = $manual ?: get_post_meta( $post_id, '_schemaforge_wp_jsonld', true );

		if ( ! $jsonld ) {
			wp_send_json_error( __( 'Noch kein Markup vorhanden.', 'schemaforge-wp' ) );
		}

		// Pretty-print for the preview panel.
		$decoded = json_decode( $jsonld, true );
		wp_send_json_success( [
			'jsonld' => $decoded ? wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : $jsonld,
		] );
	}
}
