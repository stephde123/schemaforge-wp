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

		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
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
			'usedMode'      => $result['usedMode']       ?? 'deterministic',
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

		// Accept live form values — test without requiring a prior save.
		$live_auth_type    = sanitize_text_field( wp_unslash( $_POST['live_auth_type']    ?? '' ) );
		$live_username     = sanitize_text_field( wp_unslash( $_POST['live_username']     ?? '' ) );
		$live_password     = sanitize_text_field( wp_unslash( $_POST['live_password']     ?? '' ) );
		$live_own_provider = sanitize_text_field( wp_unslash( $_POST['live_own_provider'] ?? '' ) ) ?: 'anthropic';
		$live_own_key      = sanitize_text_field( wp_unslash( $_POST['live_own_key']      ?? '' ) );

		if ( ! in_array( $live_auth_type, [ 'none', 'server', 'own-key' ], true ) ) {
			$live_auth_type = 'none';
		}

		$result = [ 'server' => null, 'auth' => null, 'llm' => null ];

		// Step 1: Server reachability (always).
		$health = $this->api->test_connection();
		if ( is_wp_error( $health ) ) {
			$result['server'] = [ 'ok' => false, 'message' => $health->get_error_message() ];
			wp_send_json_success( $result );
			return;
		}
		$result['server'] = [
			'ok'       => true,
			'version'  => $health['version']  ?? '',
			'provider' => $health['provider'] ?? '',
		];

		// Step 2: Credentials check (server mode).
		if ( $live_auth_type === 'server' ) {
			$username = $live_username ?: get_option( 'schemaforge_wp_username', '' );
			$password = $live_password ?: $this->api->get_stored_password();
			if ( ! $username || ! $password ) {
				$result['auth'] = [ 'ok' => false, 'message' => __( 'Benutzername oder Passwort fehlt.', 'schemaforge-wp' ) ];
			} else {
				$login = $this->api->test_credentials_with( $username, $password );
				$result['auth'] = is_wp_error( $login )
					? [ 'ok' => false, 'message' => $login->get_error_message() ]
					: [ 'ok' => true,  'message' => __( 'Zugangsdaten gültig', 'schemaforge-wp' ) ];
			}
		}

		// Step 3: LLM key check (own-key mode).
		if ( $live_auth_type === 'own-key' ) {
			$key      = $live_own_key ?: $this->api->get_stored_own_key();
			$provider = in_array( $live_own_provider, [ 'anthropic', 'openai' ], true ) ? $live_own_provider : 'anthropic';
			if ( ! $key ) {
				$result['llm'] = [ 'ok' => false, 'message' => __( 'Kein API-Key hinterlegt.', 'schemaforge-wp' ) ];
			} else {
				$llm_test      = $this->api->test_own_key_live( $provider, $key );
				$result['llm'] = is_wp_error( $llm_test )
					? [ 'ok' => false, 'message' => $llm_test->get_error_message() ]
					: [ 'ok' => true,  'message' => $llm_test['detail'] ?? __( 'Key gültig', 'schemaforge-wp' ) ];
			}
		}

		wp_send_json_success( $result );
	}

	public function ajax_preview(): void {
		check_ajax_referer( 'schemaforge_wp_generate', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
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
