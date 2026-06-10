<?php
defined( 'ABSPATH' ) || exit;

class SchemaForge_WP_Metabox {

	private SchemaForge_WP_Detector $detector;

	public function __construct( SchemaForge_WP_Detector $detector ) {
		$this->detector = $detector;
		add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
		add_action( 'save_post',      [ $this, 'save_per_post_options' ], 5 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function add_meta_box(): void {
		$post_types = (array) get_option( 'schemaforge_wp_post_types', [ 'post', 'page' ] );
		foreach ( $post_types as $pt ) {
			add_meta_box(
				'schemaforge-wp',
				__( 'SchemaForge WP', 'schemaforge-wp' ),
				[ $this, 'render' ],
				$pt,
				'side',
				'default'
			);
		}
	}

	public function enqueue_assets( string $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
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

		global $post;
		wp_localize_script( 'schemaforge-wp-admin', 'sfwp', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'post_id'  => $post ? $post->ID : 0,
			'nonce'    => wp_create_nonce( 'schemaforge_wp_generate' ),
		] );
	}

	public function render( \WP_Post $post ): void {
		$meta      = (array) get_post_meta( $post->ID, '_schemaforge_wp_meta', true );
		$disabled  = (bool) get_post_meta( $post->ID, '_schemaforge_wp_disabled', true );
		$has_manual = (bool) get_post_meta( $post->ID, '_schemaforge_wp_manual', true );
		$status    = $meta['status']    ?? '';
		$used_mode = $meta['usedMode']  ?? '';
		$score     = isset( $meta['coverageScore'] ) ? round( (float) $meta['coverageScore'] * 100 ) : null;
		$generated = $meta['generatedAt'] ?? '';
		$trigger   = $meta['trigger']     ?? '';
		$issues    = $meta['issues']      ?? [];
		$plugin    = $this->detector->get_label();

		wp_nonce_field( 'schemaforge_wp_metabox', 'schemaforge_wp_metabox_nonce' );
		?>
		<div class="sfwp-metabox">
			<p class="sfwp-detected-plugin">
				<?php echo esc_html( $plugin ); ?> &middot;
				<?php echo esc_html( $this->strategy_label() ); ?>
			</p>

			<?php if ( $status ) : ?>
				<p class="sfwp-status sfwp-status--<?php echo esc_attr( $status ); ?>">
					<?php echo esc_html( $this->status_label( $status ) ); ?>
				</p>
			<?php endif; ?>

			<?php if ( $score !== null ) : ?>
				<div class="sfwp-score">
					<span><?php esc_html_e( 'Coverage', 'schemaforge-wp' ); ?></span>
					<div class="sfwp-score-bar">
						<div class="sfwp-score-fill" style="width:<?php echo esc_attr( $score ); ?>%"></div>
					</div>
					<span><?php echo esc_html( $score . '%' ); ?></span>
				</div>
			<?php endif; ?>

			<?php if ( $generated ) : ?>
				<p class="sfwp-generated-at">
					<?php
					$mode_label = $used_mode === 'llm'
						? __( '✦ LLM', 'schemaforge-wp' )
						: __( '⚙ deterministisch', 'schemaforge-wp' );
					printf(
						/* translators: 1: time, 2: trigger, 3: mode */
						esc_html__( 'Generiert: %1$s · %2$s · %3$s', 'schemaforge-wp' ),
						esc_html( $generated ),
						esc_html( $trigger ),
						esc_html( $mode_label )
					);
					?>
				</p>
			<?php endif; ?>

			<?php if ( ! empty( $issues ) ) : ?>
				<ul class="sfwp-issues">
					<?php foreach ( $issues as $issue ) :
						$level = $issue['level'] ?? 'info';
						?>
						<li class="sfwp-issue sfwp-issue--<?php echo esc_attr( $level ); ?>">
							<?php echo esc_html( $issue['message'] ?? '' ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<button type="button" id="sfwp-generate-btn" class="button button-primary" style="width:100%;margin-top:8px">
				<?php esc_html_e( 'Markup neu generieren', 'schemaforge-wp' ); ?>
			</button>
			<span id="sfwp-generate-spinner" class="spinner" style="float:none;margin-top:8px;display:none"></span>
			<p id="sfwp-generate-result" style="display:none"></p>

			<p style="margin-top:10px">
				<a href="#" id="sfwp-preview-toggle">
					<?php esc_html_e( 'JSON-LD-Vorschau', 'schemaforge-wp' ); ?>
				</a>
			</p>
			<div id="sfwp-preview-panel" style="display:none">
				<textarea id="sfwp-preview-content" class="sfwp-jsonld-preview" readonly rows="12"></textarea>
			</div>

			<p style="margin-top:10px">
				<label>
					<input type="checkbox" name="schemaforge_wp_disabled" id="sfwp-disabled"
						value="1" <?php checked( $disabled ); ?> />
					<?php esc_html_e( 'Auto-Generierung für diesen Beitrag deaktivieren', 'schemaforge-wp' ); ?>
				</label>
			</p>
		</div>
		<?php
	}

	public function save_per_post_options( int $post_id ): void {
		if (
			! isset( $_POST['schemaforge_wp_metabox_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['schemaforge_wp_metabox_nonce'] ) ), 'schemaforge_wp_metabox' )
		) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$disabled = isset( $_POST['schemaforge_wp_disabled'] ) ? 1 : 0;
		update_post_meta( $post_id, '_schemaforge_wp_disabled', $disabled );
	}

	private function status_label( string $status ): string {
		return match ( $status ) {
			'pending' => __( '⏳ Generierung läuft…', 'schemaforge-wp' ),
			'done'    => __( '✓ Markup vorhanden', 'schemaforge-wp' ),
			'error'   => __( '✗ Fehler bei der Generierung', 'schemaforge-wp' ),
			default   => __( 'Noch nicht generiert', 'schemaforge-wp' ),
		};
	}

	private function strategy_label(): string {
		$strategy = get_option( 'schemaforge_wp_strategy', 'auto' );
		return match ( $strategy ) {
			'merge'   => __( 'Strategie: Mergen', 'schemaforge-wp' ),
			'replace' => __( 'Strategie: Ersetzen', 'schemaforge-wp' ),
			default   => __( 'Strategie: Auto', 'schemaforge-wp' ),
		};
	}
}
