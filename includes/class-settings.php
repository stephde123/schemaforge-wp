<?php
defined( 'ABSPATH' ) || exit;

class SchemaForge_WP_Settings {

	private SchemaForge_WP_Encryption $enc;

	/** Encrypted option names (values stored encrypted, displayed masked). */
	private const ENCRYPTED_OPTIONS = [
		'schemaforge_wp_password',
		'schemaforge_wp_own_key',
	];

	public function __construct( SchemaForge_WP_Encryption $enc ) {
		$this->enc = $enc;
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function add_menu(): void {
		add_options_page(
			__( 'SchemaForge WP', 'schemaforge-wp' ),
			__( 'SchemaForge WP', 'schemaforge-wp' ),
			'manage_options',
			'schemaforge-wp',
			[ $this, 'render_page' ]
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( $hook !== 'settings_page_schemaforge-wp' ) {
			return;
		}
		wp_enqueue_script(
			'schemaforge-wp-admin',
			SCHEMAFORGE_WP_URL . 'assets/admin.js',
			[ 'jquery' ],
			SCHEMAFORGE_WP_VERSION,
			true
		);
		wp_enqueue_style(
			'schemaforge-wp-admin',
			SCHEMAFORGE_WP_URL . 'assets/admin.css',
			[],
			SCHEMAFORGE_WP_VERSION
		);
		wp_localize_script( 'schemaforge-wp-admin', 'sfwp', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'schemaforge_wp_test_connection' ),
		] );
	}

	public function register_settings(): void {
		// Plain options.
		$plain = [
			'schemaforge_wp_endpoint',
			'schemaforge_wp_auth_mode',
			'schemaforge_wp_username',
			'schemaforge_wp_own_provider',
			'schemaforge_wp_strategy',
			'schemaforge_wp_post_types',
			'schemaforge_wp_timeout',
		];
		foreach ( $plain as $opt ) {
			register_setting( 'schemaforge_wp_settings', $opt, [
				'sanitize_callback' => [ $this, 'sanitize_' . str_replace( 'schemaforge_wp_', '', $opt ) ],
			] );
		}
		// Encrypted options: custom save via pre_update_option.
		foreach ( self::ENCRYPTED_OPTIONS as $opt ) {
			register_setting( 'schemaforge_wp_settings', $opt, [
				'sanitize_callback' => [ $this, 'sanitize_encrypted' ],
			] );
		}

		// Separate hook to encrypt before saving.
		foreach ( self::ENCRYPTED_OPTIONS as $opt ) {
			add_filter( "pre_update_option_{$opt}", function ( $new, $old ) {
				if ( $new === '' || $new === '********' ) {
					return $old; // Keep existing value if field was left blank/masked.
				}
				return $this->enc->encrypt( $new );
			}, 10, 2 );
		}

		// Auto-on-save is a checkbox — handle separately (checkboxes send nothing when unchecked).
		register_setting( 'schemaforge_wp_settings', 'schemaforge_wp_auto_on_save', [
			'sanitize_callback' => fn( $v ) => (bool) $v,
		] );
	}

	// --- Sanitize callbacks ---

	public function sanitize_endpoint( mixed $v ): string {
		return esc_url_raw( trim( (string) $v ) );
	}

	public function sanitize_auth_mode( mixed $v ): string {
		return in_array( $v, [ 'server', 'own-key', 'none' ], true ) ? $v : 'none';
	}

	public function sanitize_username( mixed $v ): string {
		return sanitize_text_field( (string) $v );
	}

	public function sanitize_own_provider( mixed $v ): string {
		return in_array( $v, [ 'anthropic', 'openai' ], true ) ? $v : 'anthropic';
	}

	public function sanitize_strategy( mixed $v ): string {
		return in_array( $v, [ 'auto', 'merge', 'replace' ], true ) ? $v : 'auto';
	}

	public function sanitize_post_types( mixed $v ): array {
		if ( ! is_array( $v ) ) {
			return [ 'post', 'page' ];
		}
		return array_map( 'sanitize_key', $v );
	}

	public function sanitize_timeout( mixed $v ): int {
		$n = (int) $v;
		return max( 5, min( 120, $n ) );
	}

	public function sanitize_encrypted( mixed $v ): string {
		// Actual encryption happens in the pre_update_option filter above.
		return (string) $v;
	}

	// --- Render ---

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$auth_mode    = get_option( 'schemaforge_wp_auth_mode', 'none' );
		$post_types   = get_option( 'schemaforge_wp_post_types', [ 'post', 'page' ] );
		$all_types    = get_post_types( [ 'public' => true ], 'objects' );
		?>
		<div class="wrap schemaforge-wp-settings">
			<h1><?php esc_html_e( 'SchemaForge WP — Einstellungen', 'schemaforge-wp' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'schemaforge_wp_settings' ); ?>

				<table class="form-table" role="presentation">

					<tr>
						<th scope="row"><label for="sfwp-endpoint"><?php esc_html_e( 'API-Endpoint', 'schemaforge-wp' ); ?></label></th>
						<td>
							<input type="url" id="sfwp-endpoint" name="schemaforge_wp_endpoint"
								value="<?php echo esc_attr( get_option( 'schemaforge_wp_endpoint', '' ) ); ?>"
								class="regular-text" placeholder="https://your-schemaforge-server.com" />
							<p class="description"><?php esc_html_e( 'URL deines SchemaForge-Servers (ohne abschließenden Slash).', 'schemaforge-wp' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Authentifizierung', 'schemaforge-wp' ); ?></th>
						<td>
							<fieldset>
								<label>
									<input type="radio" name="schemaforge_wp_auth_mode" value="server"
										<?php checked( $auth_mode, 'server' ); ?> />
									<?php esc_html_e( 'Server-LLM nutzen (Username + Passwort)', 'schemaforge-wp' ); ?>
								</label><br />
								<label>
									<input type="radio" name="schemaforge_wp_auth_mode" value="own-key"
										<?php checked( $auth_mode, 'own-key' ); ?> />
									<?php esc_html_e( 'Eigener LLM-Key (Provider + API-Key)', 'schemaforge-wp' ); ?>
								</label><br />
								<label>
									<input type="radio" name="schemaforge_wp_auth_mode" value="none"
										<?php checked( $auth_mode, 'none' ); ?> />
									<?php esc_html_e( 'Nur deterministisch (keine Anmeldung)', 'schemaforge-wp' ); ?>
								</label>
							</fieldset>
						</td>
					</tr>

					<tbody id="sfwp-auth-server" <?php echo $auth_mode !== 'server' ? 'style="display:none"' : ''; ?>>
						<tr>
							<th scope="row"><label for="sfwp-username"><?php esc_html_e( 'Benutzername', 'schemaforge-wp' ); ?></label></th>
							<td>
								<input type="text" id="sfwp-username" name="schemaforge_wp_username"
									value="<?php echo esc_attr( get_option( 'schemaforge_wp_username', '' ) ); ?>"
									class="regular-text" autocomplete="username" />
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="sfwp-password"><?php esc_html_e( 'Passwort', 'schemaforge-wp' ); ?></label></th>
							<td>
								<input type="password" id="sfwp-password" name="schemaforge_wp_password"
									value="" class="regular-text" autocomplete="new-password"
									placeholder="<?php echo $this->enc->get_option( 'schemaforge_wp_password' ) !== '' ? '••••••••' : ''; ?>" />
								<p class="description"><?php esc_html_e( 'Leer lassen, um das gespeicherte Passwort beizubehalten.', 'schemaforge-wp' ); ?></p>
							</td>
						</tr>
					</tbody>

					<tbody id="sfwp-auth-own-key" <?php echo $auth_mode !== 'own-key' ? 'style="display:none"' : ''; ?>>
						<tr>
							<th scope="row"><label for="sfwp-own-provider"><?php esc_html_e( 'LLM-Provider', 'schemaforge-wp' ); ?></label></th>
							<td>
								<select id="sfwp-own-provider" name="schemaforge_wp_own_provider">
									<option value="anthropic" <?php selected( get_option( 'schemaforge_wp_own_provider', 'anthropic' ), 'anthropic' ); ?>>Anthropic (Claude)</option>
									<option value="openai"    <?php selected( get_option( 'schemaforge_wp_own_provider', 'anthropic' ), 'openai' ); ?>>OpenAI (GPT-4o)</option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="sfwp-own-key"><?php esc_html_e( 'API-Key', 'schemaforge-wp' ); ?></label></th>
							<td>
								<input type="password" id="sfwp-own-key" name="schemaforge_wp_own_key"
									value="" class="regular-text"
									placeholder="<?php echo $this->enc->get_option( 'schemaforge_wp_own_key' ) !== '' ? '••••••••' : ''; ?>" />
								<p class="description"><?php esc_html_e( 'Leer lassen, um den gespeicherten Key beizubehalten.', 'schemaforge-wp' ); ?></p>
							</td>
						</tr>
					</tbody>

					<tr>
						<th scope="row"><label for="sfwp-strategy"><?php esc_html_e( 'Strategie', 'schemaforge-wp' ); ?></label></th>
						<td>
							<select id="sfwp-strategy" name="schemaforge_wp_strategy">
								<option value="auto"    <?php selected( get_option( 'schemaforge_wp_strategy', 'auto' ), 'auto' ); ?>><?php esc_html_e( 'Automatisch erkennen', 'schemaforge-wp' ); ?></option>
								<option value="merge"   <?php selected( get_option( 'schemaforge_wp_strategy', 'auto' ), 'merge' ); ?>><?php esc_html_e( 'Immer mergen', 'schemaforge-wp' ); ?></option>
								<option value="replace" <?php selected( get_option( 'schemaforge_wp_strategy', 'auto' ), 'replace' ); ?>><?php esc_html_e( 'Plugin ersetzen', 'schemaforge-wp' ); ?></option>
							</select>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Automatisch beim Speichern', 'schemaforge-wp' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="schemaforge_wp_auto_on_save" value="1"
									<?php checked( get_option( 'schemaforge_wp_auto_on_save', true ) ); ?> />
								<?php esc_html_e( 'Markup automatisch generieren, wenn ein Beitrag gespeichert wird', 'schemaforge-wp' ); ?>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Post-Types', 'schemaforge-wp' ); ?></th>
						<td>
							<?php foreach ( $all_types as $type ) : ?>
								<label style="display:block;margin-bottom:4px">
									<input type="checkbox" name="schemaforge_wp_post_types[]"
										value="<?php echo esc_attr( $type->name ); ?>"
										<?php checked( in_array( $type->name, (array) $post_types, true ) ); ?> />
									<?php echo esc_html( $type->labels->name . ' (' . $type->name . ')' ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="sfwp-timeout"><?php esc_html_e( 'Timeout (Sekunden)', 'schemaforge-wp' ); ?></label></th>
						<td>
							<input type="number" id="sfwp-timeout" name="schemaforge_wp_timeout"
								value="<?php echo esc_attr( get_option( 'schemaforge_wp_timeout', 20 ) ); ?>"
								min="5" max="120" class="small-text" />
						</td>
					</tr>

				</table>

				<?php submit_button(); ?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Verbindung testen', 'schemaforge-wp' ); ?></h2>
			<p>
				<button type="button" id="sfwp-test-connection" class="button button-secondary">
					<?php esc_html_e( 'Verbindung testen', 'schemaforge-wp' ); ?>
				</button>
				<span id="sfwp-test-result" style="margin-left:10px;"></span>
			</p>
		</div>
		<?php
	}
}
