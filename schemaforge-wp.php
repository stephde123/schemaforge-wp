<?php
/**
 * Plugin Name:       SchemaForge WP
 * Plugin URI:        https://github.com/stephde123/schemaforge-wp
 * Description:       Connects WordPress to the SchemaForge API for deep, specific schema.org JSON-LD markup on every post and page.
 * Version:           1.3.2
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            SchemaForge
 * Text Domain:       schemaforge-wp
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'SCHEMAFORGE_WP_VERSION', '1.3.2' );
define( 'SCHEMAFORGE_WP_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCHEMAFORGE_WP_URL', plugin_dir_url( __FILE__ ) );
define( 'SCHEMAFORGE_WP_CRON_HOOK', 'schemaforge_wp_generate_event' );

// API endpoint — override in wp-config.php: define( 'SCHEMAFORGE_WP_ENDPOINT', 'https://your-server.example.com' );
if ( ! defined( 'SCHEMAFORGE_WP_ENDPOINT' ) ) {
	define( 'SCHEMAFORGE_WP_ENDPOINT', 'https://api.schemaforge.io' );
}

spl_autoload_register( function ( string $class ): void {
	if ( ! str_starts_with( $class, 'SchemaForge_WP_' ) ) {
		return;
	}
	$file = SCHEMAFORGE_WP_DIR . 'includes/class-' . strtolower(
		str_replace( [ 'SchemaForge_WP_', '_' ], [ '', '-' ], $class )
	) . '.php';
	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

register_activation_hook( __FILE__, function (): void {
	if ( ! wp_next_scheduled( SCHEMAFORGE_WP_CRON_HOOK ) ) {
		// The cron hook is scheduled per-post (single event), not globally.
		// Nothing to schedule on activation — just a placeholder.
	}
} );

register_deactivation_hook( __FILE__, function (): void {
	// Removes ALL scheduled events for this hook regardless of their args.
	wp_unschedule_hook( SCHEMAFORGE_WP_CRON_HOOK );
} );

add_action( 'plugins_loaded', function (): void {
	load_plugin_textdomain( 'schemaforge-wp', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	$encryption = new SchemaForge_WP_Encryption();
	$detector   = new SchemaForge_WP_Detector();
	$collector  = new SchemaForge_WP_Data_Collector();
	$api_client = new SchemaForge_WP_Api_Client( $encryption, $detector, $collector );
	$generator  = new SchemaForge_WP_Generator( $api_client, $detector );
	$output     = new SchemaForge_WP_Output( $detector );

	new SchemaForge_WP_Settings( $encryption );
	new SchemaForge_WP_Metabox( $detector );
	new SchemaForge_WP_Rest( $api_client, $generator );

	$generator->register_hooks();
	$output->register_hooks();
} );
