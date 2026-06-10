=== SchemaForge WP ===
Contributors: schemaforge
Tags: schema, schema.org, json-ld, structured data, seo
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generates deep, specific schema.org JSON-LD markup for every post and page — rule-based or AI-assisted via the SchemaForge API.

== Description ==

SchemaForge WP connects your WordPress site to the SchemaForge API to produce accurate, type-specific schema.org JSON-LD markup. Instead of generic WebPage or Article markup, SchemaForge detects the actual content type (restaurant, product, event, local business, and hundreds more) and generates the matching structured data.

**Three modes — choose what fits:**

* **Deterministic (free, default)** — Rule-based extraction without AI. Fast, no account required. Covers the most common schema types reliably.
* **Premium: SchemaForge Server** — Uses the server's configured LLM (Claude or GPT-4o) for deep, context-aware markup. Requires a SchemaForge account.
* **Own LLM Key** — Supply your own Anthropic or OpenAI API key. Full AI-assisted generation billed directly to your account.

**Smart plugin detection & output strategies:**

SchemaForge detects active SEO plugins (Yoast SEO, Rank Math) and handles output accordingly:

* **Auto (default)** — Merges into Yoast's or Rank Math's JSON-LD graph if detected; adds a standalone `<script>` tag otherwise. Schema from themes or unknown plugins is preserved.
* **Merge** — Always merges SchemaForge entities into any existing schema, regardless of source.
* **Replace** — Disables all other schema output and lets SchemaForge be the sole source of JSON-LD.

**Per-post metabox:**

* Coverage score (0–100 %) with visual bar
* Validation issues and warnings
* Manual trigger ("Markup neu generieren")
* JSON-LD preview panel
* Per-post opt-out for auto-generation

**Privacy & security:**

* Credentials and API keys are stored AES-256 encrypted (libsodium) in the WordPress database, derived from your site's `AUTH_KEY`.
* Own-key requests pass your key directly to the SchemaForge server for a single request — it is never logged or stored server-side.

== Installation ==

1. Upload the `schemaforge-wp` folder to `/wp-content/plugins/`, or install via the WordPress plugin screen.
2. Activate the plugin in the **Plugins** menu.
3. Go to **Settings → SchemaForge WP** and choose a mode:
   * **Deterministic** — no further setup required.
   * **Premium** — enter your SchemaForge username and password.
   * **Own LLM Key** — select Anthropic or OpenAI and enter your API key.
4. Optionally adjust the output strategy and the post types to process.
5. Open any post or page — use the **SchemaForge WP** metabox on the right to generate markup manually or let it run automatically on save.

== Frequently Asked Questions ==

= Does this plugin work without an account? =

Yes. The default "Deterministic" mode is completely free and requires no account. It uses rule-based extraction to produce schema.org markup for the most common content types.

= Which SEO plugins are supported? =

Yoast SEO and Rank Math are detected automatically. SchemaForge merges its entities directly into their JSON-LD graph so there are no duplicate `<script>` tags. Other SEO plugins and theme-generated schema are also preserved in Auto and Merge modes.

= Where is my API key stored? =

API keys and passwords are encrypted with libsodium (`sodium_crypto_secretbox`) using a 256-bit key derived from your WordPress `AUTH_KEY`. They are never stored in plain text.

= Can I override the API endpoint? =

The endpoint is hardcoded to the SchemaForge production server. If you run a self-hosted SchemaForge instance, add this to your `wp-config.php`:

`define( 'SCHEMAFORGE_WP_ENDPOINT', 'https://your-server.example.com' );`

= What PHP version is required? =

PHP 8.1 or higher. The plugin uses libsodium (bundled with PHP 8.1+) for encryption and requires named arguments and match expressions.

== Screenshots ==

1. Settings page — mode selection, plugin detection, and strategy explanation.
2. Post metabox — coverage score, validation issues, and JSON-LD preview.

== Changelog ==

= 1.0.1 =
* Hardcoded API endpoint (overridable via `wp-config.php`); removed configurable endpoint field from settings.
* Renamed "Server-LLM" auth mode to "Premium: SchemaForge-Server" with clearer descriptions.
* Added plugin detection section to settings page, showing detected SEO plugin and explaining how schema from themes and unknown plugins is handled.
* Added per-strategy descriptions that adapt to the detected plugin.
* Extended "Test connection": server mode now verifies login credentials; own-key mode validates key format.
* Fixed: `context` fields with `null` values caused a validation error on the API ("Expected string, received null"). Null values are now omitted from the request payload.

= 1.0.0 =
* Initial release.
* Deterministic, Premium, and own-key modes.
* Yoast SEO and Rank Math integration (merge and replace strategies).
* Per-post metabox with coverage score, validation issues, and JSON-LD preview.
* Encrypted storage for credentials and API keys.
* Auto-generation on post save with per-post opt-out.

== Upgrade Notice ==

= 1.0.1 =
Fixes a validation error when generating markup with no SEO plugin active. Update recommended.
