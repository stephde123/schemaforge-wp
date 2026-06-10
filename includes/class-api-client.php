<?php
defined( 'ABSPATH' ) || exit;

class SchemaForge_WP_Api_Client {

	private SchemaForge_WP_Encryption $enc;

	/** WP transient key for the cached session token. */
	private const TOKEN_TRANSIENT = 'schemaforge_wp_session_token';

	/** Session token TTL in seconds (23h — server expires at 24h). */
	private const TOKEN_TTL = 82800;

	public function __construct( SchemaForge_WP_Encryption $enc ) {
		$this->enc = $enc;
	}

	/**
	 * Generate schema.org markup for a post by calling /api/generate.
	 * Returns the decoded API response array, or WP_Error on failure.
	 */
	public function generate( int $post_id ): array|\WP_Error {
		$endpoint = $this->get_endpoint();
		if ( ! $endpoint ) {
			return new \WP_Error( 'no_endpoint', __( 'Kein API-Endpoint konfiguriert.', 'schemaforge-wp' ) );
		}

		$auth_mode = get_option( 'schemaforge_wp_auth_mode', 'none' );
		$payload   = $this->build_payload( $post_id, $auth_mode );
		$headers   = [ 'Content-Type' => 'application/json' ];

		if ( $auth_mode === 'server' ) {
			$token = $this->get_session_token();
			if ( is_wp_error( $token ) ) {
				return $token;
			}
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_remote_post(
			trailingslashit( $endpoint ) . 'api/generate',
			[
				'headers' => $headers,
				'body'    => wp_json_encode( $payload ),
				'timeout' => (int) get_option( 'schemaforge_wp_timeout', 20 ),
			]
		);

		return $this->parse_response( $response );
	}

	/**
	 * Test API connectivity — calls GET /api/health.
	 */
	public function test_connection(): array|\WP_Error {
		$endpoint = $this->get_endpoint();
		if ( ! $endpoint ) {
			return new \WP_Error( 'no_endpoint', __( 'Kein API-Endpoint konfiguriert.', 'schemaforge-wp' ) );
		}

		$response = wp_remote_get(
			trailingslashit( $endpoint ) . 'api/health',
			[ 'timeout' => 10 ]
		);

		return $this->parse_response( $response );
	}

	/**
	 * Verify saved server credentials by forcing a fresh login attempt.
	 * Only meaningful when auth_mode = 'server'.
	 */
	public function test_credentials(): array|\WP_Error {
		delete_transient( self::TOKEN_TRANSIENT );
		$token = $this->login();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		return [ 'auth' => 'ok' ];
	}

	/**
	 * Validate the format of the saved own-key without making an LLM call.
	 */
	public function validate_key_format(): array|\WP_Error {
		$provider = get_option( 'schemaforge_wp_own_provider', 'anthropic' );
		$key      = $this->enc->get_option( 'schemaforge_wp_own_key' );

		if ( ! $key ) {
			return new \WP_Error( 'no_key', __( 'Kein API-Key hinterlegt.', 'schemaforge-wp' ) );
		}

		$valid = match ( $provider ) {
			'anthropic' => str_starts_with( $key, 'sk-ant-' ),
			'openai'    => str_starts_with( $key, 'sk-' ),
			default     => true,
		};

		if ( ! $valid ) {
			return new \WP_Error(
				'invalid_key_format',
				sprintf(
					/* translators: %s: provider name */
					__( 'API-Key hat ungültiges Format für Provider „%s".', 'schemaforge-wp' ),
					$provider
				)
			);
		}

		return [ 'key_format' => 'ok', 'provider' => $provider ];
	}

	// --- Private helpers ---

	private function get_endpoint(): string {
		return rtrim( SCHEMAFORGE_WP_ENDPOINT, '/' );
	}

	private function build_payload( int $post_id, string $auth_mode ): array {
		$post     = get_post( $post_id );
		$url      = get_permalink( $post_id );
		$strategy = get_option( 'schemaforge_wp_strategy', 'auto' );

		// Detect active SEO plugin for context.
		$detector       = new SchemaForge_WP_Detector();
		$active_plugin  = $detector->get_active_plugin();
		$effective_strat = $strategy === 'auto'
			? ( $active_plugin ? 'merge' : 'add' )
			: $strategy;

		// Filter null values so Zod's z.string().optional() (which rejects null) doesn't fail.
		$context = array_filter( [
			'detectedPlugin' => $active_plugin,
			'strategy'       => $effective_strat,
			'lang'           => get_locale() !== '' ? substr( get_locale(), 0, 2 ) : null,
		], fn( $v ) => $v !== null && $v !== '' );

		$payload = [
			'url'     => $url ?: null,
			'mode'    => ( $auth_mode === 'none' ) ? 'deterministic' : 'auto',
			'context' => $context ?: null,
		];

		// Own LLM key: attach provider + key in the request body.
		if ( $auth_mode === 'own-key' ) {
			$payload['provider'] = get_option( 'schemaforge_wp_own_provider', 'anthropic' );
			$payload['apiKey']   = $this->enc->get_option( 'schemaforge_wp_own_key' );
		}

		// Fallback: if the URL isn't public (e.g. draft behind htaccess),
		// send the rendered HTML instead.
		if ( $post && in_array( $post->post_status, [ 'draft', 'pending', 'private' ], true ) ) {
			$payload['url']  = null;
			$payload['html'] = $this->get_post_html( $post_id );
		}

		return array_filter( $payload, fn( $v ) => $v !== null );
	}

	private function get_post_html( int $post_id ): ?string {
		// Make a loopback request to fetch the rendered HTML.
		$url      = get_permalink( $post_id );
		$response = wp_remote_get( $url, [ 'timeout' => 15, 'sslverify' => false ] );
		if ( is_wp_error( $response ) ) {
			return null;
		}
		return wp_remote_retrieve_body( $response ) ?: null;
	}

	/** Get a valid session token, logging in if needed. */
	private function get_session_token(): string|\WP_Error {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( $cached ) {
			return $cached;
		}
		return $this->login();
	}

	private function login(): string|\WP_Error {
		$endpoint = $this->get_endpoint();
		$username = get_option( 'schemaforge_wp_username', '' );
		$password = $this->enc->get_option( 'schemaforge_wp_password' );

		if ( ! $username || ! $password ) {
			return new \WP_Error( 'no_credentials', __( 'Username oder Passwort nicht konfiguriert.', 'schemaforge-wp' ) );
		}

		$response = wp_remote_post(
			trailingslashit( $endpoint ) . 'api/login',
			[
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( [ 'user' => $username, 'password' => $password ] ),
				'timeout' => 10,
			]
		);

		$parsed = $this->parse_response( $response );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$token = $parsed['token'] ?? '';
		if ( ! $token ) {
			return new \WP_Error( 'login_failed', __( 'Login fehlgeschlagen — kein Token erhalten.', 'schemaforge-wp' ) );
		}

		set_transient( self::TOKEN_TRANSIENT, $token, self::TOKEN_TTL );
		return $token;
	}

	private function parse_response( \WP_Error|array $response ): array|\WP_Error {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code === 401 ) {
			// Session expired — clear cached token so next call will re-login.
			delete_transient( self::TOKEN_TRANSIENT );
			return new \WP_Error( 'unauthorized', __( 'Nicht authentifiziert. Bitte Zugangsdaten prüfen.', 'schemaforge-wp' ) );
		}

		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $data ) && isset( $data['error'] ) ? $data['error'] : "HTTP $code";
			return new \WP_Error( 'api_error', $msg );
		}

		return is_array( $data ) ? $data : [];
	}
}
