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
		$plain = [
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
		foreach ( self::ENCRYPTED_OPTIONS as $opt ) {
			register_setting( 'schemaforge_wp_settings', $opt, [
				'sanitize_callback' => [ $this, 'sanitize_encrypted' ],
			] );
		}
		foreach ( self::ENCRYPTED_OPTIONS as $opt ) {
			add_filter( "pre_update_option_{$opt}", function ( $new, $old ) {
				if ( $new === '' || $new === '********' ) {
					return $old;
				}
				return $this->enc->encrypt( $new );
			}, 10, 2 );
		}
		register_setting( 'schemaforge_wp_settings', 'schemaforge_wp_auto_on_save', [
			'sanitize_callback' => fn( $v ) => (bool) $v,
		] );
	}

	// --- Sanitize callbacks ---

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
		return (string) $v;
	}

	// --- Render ---

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$auth_mode    = get_option( 'schemaforge_wp_auth_mode', 'none' );
		$strategy     = get_option( 'schemaforge_wp_strategy', 'auto' );
		$post_types   = get_option( 'schemaforge_wp_post_types', [ 'post', 'page' ] );
		$all_types    = get_post_types( [ 'public' => true ], 'objects' );

		$detector      = new SchemaForge_WP_Detector();
		$active_plugin = $detector->get_active_plugin();
		$plugin_label  = $detector->get_label();
		?>
		<div class="wrap schemaforge-wp-settings">
			<h1><?php esc_html_e( 'SchemaForge WP — Einstellungen', 'schemaforge-wp' ); ?></h1>

			<div class="sfwp-card sfwp-endpoint-info notice notice-info inline">
				<p>
					<strong><?php esc_html_e( 'API-Server:', 'schemaforge-wp' ); ?></strong>
					<code><?php echo esc_html( SCHEMAFORGE_WP_ENDPOINT ); ?></code>
					<span class="description" style="margin-left:8px">
						<?php esc_html_e( '(Fest konfiguriert. Kann per wp-config.php überschrieben werden.)', 'schemaforge-wp' ); ?>
					</span>
				</p>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'schemaforge_wp_settings' ); ?>

				<!-- Erkannte Plugins -->
				<div class="sfwp-card">
					<h2><?php esc_html_e( 'Erkannte Plugins & Schema-Quellen', 'schemaforge-wp' ); ?></h2>

					<div class="sfwp-field">
						<div class="sfwp-field-label"><?php esc_html_e( 'Aktives SEO-Plugin', 'schemaforge-wp' ); ?></div>
						<div class="sfwp-field-body">
							<?php if ( $active_plugin ) : ?>
								<span class="sfwp-badge sfwp-badge--detected">&#10003; <?php echo esc_html( $plugin_label ); ?></span>
								<p class="description">
									<?php
									if ( $active_plugin === 'yoast' ) {
										esc_html_e( 'Yoast SEO erzeugt ein vollständiges JSON-LD-Graph. Im Modus „Auto" oder „Mergen" fügt SchemaForge seine Entitäten direkt in diesen Graph ein.', 'schemaforge-wp' );
									} elseif ( $active_plugin === 'rankmath' ) {
										esc_html_e( 'Rank Math erzeugt JSON-LD-Blöcke. Im Modus „Auto" oder „Mergen" werden SchemaForge-Entitäten als neue Blöcke hinzugefügt.', 'schemaforge-wp' );
									}
									?>
								</p>
							<?php else : ?>
								<span class="sfwp-badge sfwp-badge--none"><?php esc_html_e( 'Keins erkannt (Yoast SEO, Rank Math)', 'schemaforge-wp' ); ?></span>
								<p class="description">
									<?php esc_html_e( 'Kein bekanntes SEO-Plugin aktiv. SchemaForge gibt sein Markup eigenständig als <script type="application/ld+json"> aus.', 'schemaforge-wp' ); ?>
								</p>
							<?php endif; ?>
						</div>
					</div>

					<div class="sfwp-field">
						<div class="sfwp-field-label"><?php esc_html_e( 'Schema von Theme / anderen Plugins', 'schemaforge-wp' ); ?></div>
						<div class="sfwp-field-body">
							<p class="description">
								<?php esc_html_e( 'SchemaForge erkennt beim Generieren auch Schema.org-Markup von Theme oder unbekannten Plugins. Im Modus „Auto" und „Mergen" wird dieses Markup nicht entfernt — SchemaForge ergänzt es. Im Modus „Ersetzen" wird alles außer SchemaForge-Output entfernt.', 'schemaforge-wp' ); ?>
							</p>
						</div>
					</div>
				</div>

				<!-- Modus & Auth -->
				<div class="sfwp-card">
					<h2><?php esc_html_e( 'Modus & Authentifizierung', 'schemaforge-wp' ); ?></h2>
					<div class="sfwp-mode-cards">

						<label class="sfwp-mode-card<?php echo $auth_mode === 'none' ? ' is-checked' : ''; ?>">
							<input type="radio" name="schemaforge_wp_auth_mode" value="none"
								<?php checked( $auth_mode, 'none' ); ?> />
							<div class="sfwp-mode-card__inner">
								<span class="sfwp-mode-card__title">
									<?php esc_html_e( 'Nur deterministisch', 'schemaforge-wp' ); ?>
									<span class="sfwp-badge sfwp-badge--neutral"><?php esc_html_e( 'Standard · kostenlos', 'schemaforge-wp' ); ?></span>
								</span>
								<p class="sfwp-mode-card__desc">
									<?php esc_html_e( 'Erkennt und generiert Schema.org-Markup regelbasiert ohne KI. Kein Account erforderlich.', 'schemaforge-wp' ); ?>
								</p>
							</div>
						</label>

						<label class="sfwp-mode-card<?php echo $auth_mode === 'server' ? ' is-checked' : ''; ?>">
							<input type="radio" name="schemaforge_wp_auth_mode" value="server"
								<?php checked( $auth_mode, 'server' ); ?> />
							<div class="sfwp-mode-card__inner">
								<span class="sfwp-mode-card__title">
									<?php esc_html_e( 'Premium: SchemaForge-Server', 'schemaforge-wp' ); ?>
									<span class="sfwp-badge sfwp-badge--premium"><?php esc_html_e( 'KI-gestützt', 'schemaforge-wp' ); ?></span>
								</span>
								<p class="sfwp-mode-card__desc">
									<?php esc_html_e( 'Nutzt den konfigurierten LLM-Provider des SchemaForge-Servers. Erfordert einen Account mit Benutzername und Passwort.', 'schemaforge-wp' ); ?>
								</p>
							</div>
						</label>

						<label class="sfwp-mode-card<?php echo $auth_mode === 'own-key' ? ' is-checked' : ''; ?>">
							<input type="radio" name="schemaforge_wp_auth_mode" value="own-key"
								<?php checked( $auth_mode, 'own-key' ); ?> />
							<div class="sfwp-mode-card__inner">
								<span class="sfwp-mode-card__title">
									<?php esc_html_e( 'Eigener LLM-Key', 'schemaforge-wp' ); ?>
									<span class="sfwp-badge sfwp-badge--neutral"><?php esc_html_e( 'Anthropic oder OpenAI', 'schemaforge-wp' ); ?></span>
								</span>
								<p class="sfwp-mode-card__desc">
									<?php esc_html_e( 'Verwende deinen eigenen Anthropic- oder OpenAI-API-Key. Der Key wird nur für deinen WordPress-Aufruf genutzt.', 'schemaforge-wp' ); ?>
								</p>
							</div>
						</label>

					</div>
				</div>

				<!-- Server-Zugangsdaten (conditional) -->
				<div id="sfwp-auth-server" <?php echo $auth_mode !== 'server' ? 'style="display:none"' : ''; ?>>
					<div class="sfwp-card">
						<h2><?php esc_html_e( 'Server-Zugangsdaten', 'schemaforge-wp' ); ?></h2>
						<div class="sfwp-field">
							<div class="sfwp-field-label">
								<label for="sfwp-username"><?php esc_html_e( 'Benutzername', 'schemaforge-wp' ); ?></label>
							</div>
							<div class="sfwp-field-body">
								<input type="text" id="sfwp-username" name="schemaforge_wp_username"
									value="<?php echo esc_attr( get_option( 'schemaforge_wp_username', '' ) ); ?>"
									autocomplete="username" />
							</div>
						</div>
						<div class="sfwp-field">
							<div class="sfwp-field-label">
								<label for="sfwp-password"><?php esc_html_e( 'Passwort', 'schemaforge-wp' ); ?></label>
							</div>
							<div class="sfwp-field-body">
								<input type="password" id="sfwp-password" name="schemaforge_wp_password"
									value="" autocomplete="new-password"
									placeholder="<?php echo $this->enc->get_option( 'schemaforge_wp_password' ) !== '' ? '••••••••' : ''; ?>" />
								<p class="description"><?php esc_html_e( 'Leer lassen, um das gespeicherte Passwort beizubehalten.', 'schemaforge-wp' ); ?></p>
							</div>
						</div>
					</div>
				</div>

				<!-- Eigener LLM-Key (conditional) -->
				<div id="sfwp-auth-own-key" <?php echo $auth_mode !== 'own-key' ? 'style="display:none"' : ''; ?>>
					<div class="sfwp-card">
						<h2><?php esc_html_e( 'LLM-Key Einstellungen', 'schemaforge-wp' ); ?></h2>
						<div class="sfwp-field">
							<div class="sfwp-field-label">
								<label for="sfwp-own-provider"><?php esc_html_e( 'LLM-Provider', 'schemaforge-wp' ); ?></label>
							</div>
							<div class="sfwp-field-body">
								<select id="sfwp-own-provider" name="schemaforge_wp_own_provider">
									<option value="anthropic" <?php selected( get_option( 'schemaforge_wp_own_provider', 'anthropic' ), 'anthropic' ); ?>>Anthropic (Claude)</option>
									<option value="openai"    <?php selected( get_option( 'schemaforge_wp_own_provider', 'anthropic' ), 'openai' ); ?>>OpenAI (GPT-4o)</option>
								</select>
							</div>
						</div>
						<div class="sfwp-field">
							<div class="sfwp-field-label">
								<label for="sfwp-own-key"><?php esc_html_e( 'API-Key', 'schemaforge-wp' ); ?></label>
							</div>
							<div class="sfwp-field-body">
								<input type="password" id="sfwp-own-key" name="schemaforge_wp_own_key"
									value=""
									placeholder="<?php echo $this->enc->get_option( 'schemaforge_wp_own_key' ) !== '' ? '••••••••' : ''; ?>" />
								<p class="description"><?php esc_html_e( 'Leer lassen, um den gespeicherten Key beizubehalten.', 'schemaforge-wp' ); ?></p>
							</div>
						</div>
					</div>
				</div>

				<!-- Ausgabe-Strategie -->
				<div class="sfwp-card">
					<h2><?php esc_html_e( 'Ausgabe-Strategie', 'schemaforge-wp' ); ?></h2>

					<div class="sfwp-field">
						<div class="sfwp-field-label">
							<label for="sfwp-strategy"><?php esc_html_e( 'Strategie', 'schemaforge-wp' ); ?></label>
						</div>
						<div class="sfwp-field-body">
							<select id="sfwp-strategy" name="schemaforge_wp_strategy">
								<option value="auto"    <?php selected( $strategy, 'auto' ); ?>><?php esc_html_e( 'Automatisch', 'schemaforge-wp' ); ?></option>
								<option value="merge"   <?php selected( $strategy, 'merge' ); ?>><?php esc_html_e( 'Immer mergen', 'schemaforge-wp' ); ?></option>
								<option value="replace" <?php selected( $strategy, 'replace' ); ?>><?php esc_html_e( 'Ersetzen', 'schemaforge-wp' ); ?></option>
							</select>
							<div id="sfwp-strategy-desc" style="margin-top:8px">
								<div data-strategy="auto" <?php echo $strategy !== 'auto' ? 'style="display:none"' : ''; ?>>
									<p class="description">
										<?php
										if ( $active_plugin ) {
											printf(
												/* translators: %s: plugin name */
												esc_html__( '%s erkannt → SchemaForge ergänzt dessen JSON-LD-Graph, ohne vorhandene Einträge zu entfernen. Bei unbekannten Schema-Quellen (Theme, andere Plugins) wird ebenfalls ergänzt.', 'schemaforge-wp' ),
												esc_html( $plugin_label )
											);
										} else {
											esc_html_e( 'Kein bekanntes SEO-Plugin erkannt → SchemaForge gibt sein Markup eigenständig aus. Schema von Theme oder unbekannten Plugins bleibt erhalten.', 'schemaforge-wp' );
										}
										?>
									</p>
								</div>
								<div data-strategy="merge" <?php echo $strategy !== 'merge' ? 'style="display:none"' : ''; ?>>
									<p class="description">
										<?php esc_html_e( 'SchemaForge-Entitäten werden immer in vorhandenes Schema eingemischt — egal ob von Yoast, Rank Math, Theme oder einem unbekannten Plugin. Bestehende Einträge werden nicht überschrieben, nur ergänzt.', 'schemaforge-wp' ); ?>
									</p>
								</div>
								<div data-strategy="replace" <?php echo $strategy !== 'replace' ? 'style="display:none"' : ''; ?>>
									<p class="description">
										<?php esc_html_e( 'Alle vorhandenen Schema.org-Ausgaben werden deaktiviert. SchemaForge ist die alleinige Quelle für JSON-LD. Nur wählen, wenn du volle Kontrolle über das Markup übernehmen möchtest.', 'schemaforge-wp' ); ?>
									</p>
								</div>
							</div>
						</div>
					</div>

					<div class="sfwp-field">
						<div class="sfwp-field-label"><?php esc_html_e( 'Automatisch beim Speichern', 'schemaforge-wp' ); ?></div>
						<div class="sfwp-field-body">
							<label>
								<input type="checkbox" name="schemaforge_wp_auto_on_save" value="1"
									<?php checked( get_option( 'schemaforge_wp_auto_on_save', false ) ); ?> />
								<?php esc_html_e( 'Markup automatisch generieren, wenn ein Beitrag gespeichert wird', 'schemaforge-wp' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Beim Generieren wird die Seiten-URL (und ggf. der Inhalt) an den API-Endpoint übertragen.', 'schemaforge-wp' ); ?>
							</p>
						</div>
					</div>

					<div class="sfwp-field">
						<div class="sfwp-field-label"><?php esc_html_e( 'Post-Types', 'schemaforge-wp' ); ?></div>
						<div class="sfwp-field-body">
							<div class="sfwp-post-types">
								<?php foreach ( $all_types as $type ) : ?>
									<label>
										<input type="checkbox" name="schemaforge_wp_post_types[]"
											value="<?php echo esc_attr( $type->name ); ?>"
											<?php checked( in_array( $type->name, (array) $post_types, true ) ); ?> />
										<?php echo esc_html( $type->labels->name . ' (' . $type->name . ')' ); ?>
									</label>
								<?php endforeach; ?>
							</div>
						</div>
					</div>

					<div class="sfwp-field">
						<div class="sfwp-field-label">
							<label for="sfwp-timeout"><?php esc_html_e( 'Timeout (Sekunden)', 'schemaforge-wp' ); ?></label>
						</div>
						<div class="sfwp-field-body">
							<input type="number" id="sfwp-timeout" name="schemaforge_wp_timeout"
								value="<?php echo esc_attr( get_option( 'schemaforge_wp_timeout', 20 ) ); ?>"
								min="5" max="120" />
						</div>
					</div>
				</div>

				<div class="sfwp-submit-row"><?php submit_button(); ?></div>
			</form>

			<!-- Connection test (outside form, AJAX) -->
			<div class="sfwp-card sfwp-connection-test">
				<h2><?php esc_html_e( 'Verbindung testen', 'schemaforge-wp' ); ?></h2>
				<p class="description">
					<?php
					if ( $auth_mode === 'server' ) {
						esc_html_e( 'Prüft die Erreichbarkeit des Servers und ob die Premium-Zugangsdaten gültig sind.', 'schemaforge-wp' );
					} elseif ( $auth_mode === 'own-key' ) {
						esc_html_e( 'Prüft die Erreichbarkeit des Servers und das Format des eingetragenen API-Keys.', 'schemaforge-wp' );
					} else {
						esc_html_e( 'Prüft die Erreichbarkeit des SchemaForge-Servers.', 'schemaforge-wp' );
					}
					?>
				</p>
				<div class="sfwp-test-row">
					<button type="button" id="sfwp-test-connection" class="button button-secondary">
						<?php esc_html_e( 'Verbindung testen', 'schemaforge-wp' ); ?>
					</button>
					<span id="sfwp-test-result"></span>
				</div>
			</div>
		</div>
		<?php
	}
}
