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
			'schemaforge_wp_mode',
			'schemaforge_wp_auth_type',
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

	public function sanitize_mode( mixed $v ): string {
		return in_array( $v, [ 'deterministic', 'auto' ], true ) ? $v : 'deterministic';
	}

	public function sanitize_auth_type( mixed $v ): string {
		return in_array( $v, [ 'none', 'server', 'own-key' ], true ) ? $v : 'none';
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
		return (string) $v;
	}

	// --- Render ---

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$mode         = get_option( 'schemaforge_wp_mode', 'deterministic' );
		$auth_type    = get_option( 'schemaforge_wp_auth_type', 'none' );
		$strategy     = get_option( 'schemaforge_wp_strategy', 'auto' );
		$post_types   = get_option( 'schemaforge_wp_post_types', [ 'post', 'page' ] );
		$all_types    = get_post_types( [ 'public' => true ], 'objects' );
		$has_password = $this->enc->get_option( 'schemaforge_wp_password' ) !== '';
		$has_own_key  = $this->enc->get_option( 'schemaforge_wp_own_key' ) !== '';

		$detector      = new SchemaForge_WP_Detector();
		$active_plugin = $detector->get_active_plugin();
		$plugin_label  = $detector->get_label();
		?>
		<div class="wrap schemaforge-wp-settings">
			<h1><?php esc_html_e( 'SchemaForge WP — Einstellungen', 'schemaforge-wp' ); ?></h1>

			<div class="sfwp-layout">

				<!-- ── Main column ──────────────────────────────────── -->
				<div class="sfwp-layout__main">
					<form method="post" action="options.php">
						<?php settings_fields( 'schemaforge_wp_settings' ); ?>

						<!-- 1. Authentifizierung -->
						<div class="sfwp-card">
							<h2><?php esc_html_e( 'Authentifizierung', 'schemaforge-wp' ); ?></h2>
							<div class="sfwp-mode-cards">

								<label class="sfwp-mode-card<?php echo $auth_type === 'none' ? ' is-checked' : ''; ?>">
									<input type="radio" name="schemaforge_wp_auth_type" value="none"
										<?php checked( $auth_type, 'none' ); ?> />
									<div class="sfwp-mode-card__inner">
										<span class="sfwp-mode-card__title">
											<?php esc_html_e( 'Kein LLM-Zugang', 'schemaforge-wp' ); ?>
											<span class="sfwp-badge sfwp-badge--neutral"><?php esc_html_e( 'Standard', 'schemaforge-wp' ); ?></span>
										</span>
										<p class="sfwp-mode-card__desc">
											<?php esc_html_e( 'Kein API-Key hinterlegt. Modus läuft immer deterministisch.', 'schemaforge-wp' ); ?>
										</p>
									</div>
								</label>

								<label class="sfwp-mode-card<?php echo $auth_type === 'server' ? ' is-checked' : ''; ?>">
									<input type="radio" name="schemaforge_wp_auth_type" value="server"
										<?php checked( $auth_type, 'server' ); ?> />
									<div class="sfwp-mode-card__inner">
										<span class="sfwp-mode-card__title">
											<?php esc_html_e( 'SchemaForge-Server', 'schemaforge-wp' ); ?>
											<span class="sfwp-badge sfwp-badge--premium"><?php esc_html_e( 'Premium', 'schemaforge-wp' ); ?></span>
										</span>
										<p class="sfwp-mode-card__desc">
											<?php esc_html_e( 'Einloggen mit Benutzername und Passwort. Nutzt den LLM-Provider des Servers.', 'schemaforge-wp' ); ?>
										</p>
									</div>
								</label>

								<label class="sfwp-mode-card<?php echo $auth_type === 'own-key' ? ' is-checked' : ''; ?>">
									<input type="radio" name="schemaforge_wp_auth_type" value="own-key"
										<?php checked( $auth_type, 'own-key' ); ?> />
									<div class="sfwp-mode-card__inner">
										<span class="sfwp-mode-card__title">
											<?php esc_html_e( 'Eigener API-Key', 'schemaforge-wp' ); ?>
											<span class="sfwp-badge sfwp-badge--neutral"><?php esc_html_e( 'Anthropic oder OpenAI', 'schemaforge-wp' ); ?></span>
										</span>
										<p class="sfwp-mode-card__desc">
											<?php esc_html_e( 'Eigenen Anthropic- oder OpenAI-Key verwenden. Direkte Abrechnung über deinen Account.', 'schemaforge-wp' ); ?>
										</p>
									</div>
								</label>

							</div>
						</div>

						<!-- 1a. Server-Zugangsdaten (conditional) -->
						<div id="sfwp-auth-server" <?php echo $auth_type !== 'server' ? 'style="display:none"' : ''; ?>>
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
											placeholder="<?php echo $has_password ? '••••••••' : ''; ?>" />
										<p class="description"><?php esc_html_e( 'Leer lassen, um das gespeicherte Passwort beizubehalten.', 'schemaforge-wp' ); ?></p>
									</div>
								</div>
							</div>
						</div>

						<!-- 1b. Eigener LLM-Key (conditional) -->
						<div id="sfwp-auth-own-key" <?php echo $auth_type !== 'own-key' ? 'style="display:none"' : ''; ?>>
							<div class="sfwp-card">
								<h2><?php esc_html_e( 'LLM-Key Einstellungen', 'schemaforge-wp' ); ?></h2>
								<div class="sfwp-field">
									<div class="sfwp-field-label">
										<label for="sfwp-own-provider"><?php esc_html_e( 'Provider', 'schemaforge-wp' ); ?></label>
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
											placeholder="<?php echo $has_own_key ? '••••••••' : ''; ?>" />
										<p class="description"><?php esc_html_e( 'Leer lassen, um den gespeicherten Key beizubehalten.', 'schemaforge-wp' ); ?></p>
									</div>
								</div>
							</div>
						</div>

						<!-- 2. Modus (auto locked when no auth) -->
						<div class="sfwp-card">
							<h2><?php esc_html_e( 'Modus', 'schemaforge-wp' ); ?></h2>
							<div class="sfwp-mode-cards">

								<label class="sfwp-mode-card<?php echo $mode === 'deterministic' ? ' is-checked' : ''; ?>">
									<input type="radio" name="schemaforge_wp_mode" value="deterministic"
										<?php checked( $mode, 'deterministic' ); ?> />
									<div class="sfwp-mode-card__inner">
										<span class="sfwp-mode-card__title">
											<?php esc_html_e( 'Deterministisch', 'schemaforge-wp' ); ?>
											<span class="sfwp-badge sfwp-badge--neutral"><?php esc_html_e( 'Standard · kostenlos', 'schemaforge-wp' ); ?></span>
										</span>
										<p class="sfwp-mode-card__desc">
											<?php esc_html_e( 'Regelbasierte Erkennung aus HTML-Struktur, Meta-Tags und vorhandenem JSON-LD. Schnell, kein API-Key erforderlich.', 'schemaforge-wp' ); ?>
										</p>
									</div>
								</label>

								<label id="sfwp-mode-auto-card" class="sfwp-mode-card<?php echo $mode === 'auto' ? ' is-checked' : ''; ?><?php echo $auth_type === 'none' ? ' is-locked' : ''; ?>">
									<input type="radio" name="schemaforge_wp_mode" value="auto"
										<?php checked( $mode, 'auto' ); ?> />
									<div class="sfwp-mode-card__inner">
										<span class="sfwp-mode-card__title">
											<?php esc_html_e( 'Auto / LLM', 'schemaforge-wp' ); ?>
											<span class="sfwp-badge sfwp-badge--premium"><?php esc_html_e( 'KI-gestützt', 'schemaforge-wp' ); ?></span>
										</span>
										<p class="sfwp-mode-card__desc">
											<?php esc_html_e( 'Nutzt KI für tiefere Analyse des Seiteninhalts. Erfordert konfigurierte Authentifizierung.', 'schemaforge-wp' ); ?>
										</p>
										<p class="sfwp-mode-card__lock-note">
											<?php esc_html_e( 'Authentifizierung erforderlich — wähle Server oder Eigener Key.', 'schemaforge-wp' ); ?>
										</p>
									</div>
								</label>

							</div>
						</div>

						<!-- 3. Ausgabe & Generierung -->
						<div class="sfwp-card">
							<h2><?php esc_html_e( 'Ausgabe & Generierung', 'schemaforge-wp' ); ?></h2>

							<div class="sfwp-field">
								<div class="sfwp-field-label">
									<label for="sfwp-strategy"><?php esc_html_e( 'Ausgabe-Strategie', 'schemaforge-wp' ); ?></label>
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
														esc_html__( '%s erkannt → SchemaForge ergänzt dessen JSON-LD-Graph.', 'schemaforge-wp' ),
														esc_html( $plugin_label )
													);
												} else {
													esc_html_e( 'Kein SEO-Plugin → SchemaForge gibt Markup eigenständig aus.', 'schemaforge-wp' );
												}
												?>
											</p>
										</div>
										<div data-strategy="merge" <?php echo $strategy !== 'merge' ? 'style="display:none"' : ''; ?>>
											<p class="description"><?php esc_html_e( 'Entitäten werden immer in vorhandenes Schema eingemischt — egal welche Quelle.', 'schemaforge-wp' ); ?></p>
										</div>
										<div data-strategy="replace" <?php echo $strategy !== 'replace' ? 'style="display:none"' : ''; ?>>
											<p class="description"><?php esc_html_e( 'Alle anderen Schema-Ausgaben werden deaktiviert. SchemaForge ist die alleinige JSON-LD-Quelle.', 'schemaforge-wp' ); ?></p>
										</div>
									</div>
								</div>
							</div>

							<div class="sfwp-field">
								<div class="sfwp-field-label"><?php esc_html_e( 'Auto beim Speichern', 'schemaforge-wp' ); ?></div>
								<div class="sfwp-field-body">
									<label>
										<input type="checkbox" name="schemaforge_wp_auto_on_save" value="1"
											<?php checked( get_option( 'schemaforge_wp_auto_on_save', false ) ); ?> />
										<?php esc_html_e( 'Markup automatisch generieren, wenn ein Beitrag gespeichert wird', 'schemaforge-wp' ); ?>
									</label>
									<p class="description"><?php esc_html_e( 'Beim Generieren wird die Seiten-URL an den API-Endpoint übertragen.', 'schemaforge-wp' ); ?></p>
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
									<label for="sfwp-timeout"><?php esc_html_e( 'Timeout (Sek.)', 'schemaforge-wp' ); ?></label>
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
				</div><!-- /.sfwp-layout__main -->

				<!-- ── Aside column ─────────────────────────────────── -->
				<div class="sfwp-layout__aside">

					<!-- Verbindung testen -->
					<div class="sfwp-card sfwp-aside-card">
						<h2><?php esc_html_e( 'Verbindung testen', 'schemaforge-wp' ); ?></h2>
						<p class="description sfwp-aside-desc">
							<?php esc_html_e( 'Verwendet aktuelle Formularwerte — ohne vorheriges Speichern.', 'schemaforge-wp' ); ?>
						</p>
						<button type="button" id="sfwp-test-connection" class="button button-secondary sfwp-test-btn">
							<?php esc_html_e( 'Jetzt testen', 'schemaforge-wp' ); ?>
							<span id="sfwp-test-spinner" class="spinner" style="float:none;vertical-align:middle;margin:0 0 0 4px;display:none"></span>
						</button>
						<div id="sfwp-test-results" style="display:none; margin-top:12px;">
							<div class="sfwp-tri" id="sfwp-tri-server">
								<span class="sfwp-tri__icon"></span>
								<div class="sfwp-tri__body">
									<strong><?php esc_html_e( 'Server', 'schemaforge-wp' ); ?></strong>
									<div class="sfwp-tri__detail"></div>
								</div>
							</div>
							<div class="sfwp-tri" id="sfwp-tri-auth">
								<span class="sfwp-tri__icon"></span>
								<div class="sfwp-tri__body">
									<strong><?php esc_html_e( 'Zugangsdaten', 'schemaforge-wp' ); ?></strong>
									<div class="sfwp-tri__detail"></div>
								</div>
							</div>
							<div class="sfwp-tri" id="sfwp-tri-llm">
								<span class="sfwp-tri__icon"></span>
								<div class="sfwp-tri__body">
									<strong><?php esc_html_e( 'LLM-Key', 'schemaforge-wp' ); ?></strong>
									<div class="sfwp-tri__detail"></div>
								</div>
							</div>
						</div>
					</div>

					<!-- API-Server -->
					<div class="sfwp-card sfwp-aside-card">
						<h2><?php esc_html_e( 'API-Server', 'schemaforge-wp' ); ?></h2>
						<code class="sfwp-aside-code"><?php echo esc_html( SCHEMAFORGE_WP_ENDPOINT ); ?></code>
						<p class="description sfwp-aside-desc">
							<?php esc_html_e( 'Fest konfiguriert. Überschreiben per wp-config.php:', 'schemaforge-wp' ); ?>
						</p>
						<code class="sfwp-aside-code sfwp-aside-code--small">define( 'SCHEMAFORGE_WP_ENDPOINT', '…' );</code>
					</div>

					<!-- Erkannte Plugins -->
					<div class="sfwp-card sfwp-aside-card">
						<h2><?php esc_html_e( 'Erkannte Plugins', 'schemaforge-wp' ); ?></h2>
						<?php if ( $active_plugin ) : ?>
							<span class="sfwp-badge sfwp-badge--detected">&#10003; <?php echo esc_html( $plugin_label ); ?></span>
							<p class="description sfwp-aside-desc">
								<?php
								if ( $active_plugin === 'yoast' ) {
									esc_html_e( 'Yoast SEO erkannt. Im Auto/Merge-Modus fügt SchemaForge Entitäten direkt in den Yoast-Graph ein.', 'schemaforge-wp' );
								} elseif ( $active_plugin === 'rankmath' ) {
									esc_html_e( 'Rank Math erkannt. SchemaForge ergänzt die vorhandenen JSON-LD-Blöcke.', 'schemaforge-wp' );
								}
								?>
							</p>
						<?php else : ?>
							<span class="sfwp-badge sfwp-badge--none"><?php esc_html_e( 'Keins erkannt', 'schemaforge-wp' ); ?></span>
							<p class="description sfwp-aside-desc">
								<?php esc_html_e( 'Kein SEO-Plugin aktiv. SchemaForge gibt Markup eigenständig aus.', 'schemaforge-wp' ); ?>
							</p>
						<?php endif; ?>
						<p class="description sfwp-aside-desc" style="margin-top:6px">
							<?php esc_html_e( 'Schema von Theme/anderen Plugins bleibt im Auto- und Merge-Modus erhalten.', 'schemaforge-wp' ); ?>
						</p>
					</div>

				</div><!-- /.sfwp-layout__aside -->
			</div><!-- /.sfwp-layout -->
		</div><!-- /.wrap -->
		<?php
	}
}
