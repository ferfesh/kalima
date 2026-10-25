<?php
/**
 * Plugin Name:       Kalima AI Multilingual
 * Description:       Multi-language engine with AI translation. Pages, posts, products, categories and menus. Works like a translation-set system: every language is a real post linked to the others.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Magdi Taha — DeMug
 * License:           GPL-2.0-or-later
 * Text Domain:       kalima
 *
 * @package KALIMA
 */

defined( 'KALIMA_PLUGIN_FILE' ) || define( 'KALIMA_PLUGIN_FILE', __FILE__ );
defined( 'KALIMA_PLUGIN_DIR' ) || define( 'KALIMA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
defined( 'KALIMA_PLUGIN_URL' ) || define( 'KALIMA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
defined( 'KALIMA_VERSION' ) || define( 'KALIMA_VERSION', '1.2.0' );

/** Short-circuit before anything loads. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
|--------------------------------------------------------------------------
| Autoload
|--------------------------------------------------------------------------
*/
require_once KALIMA_PLUGIN_DIR . 'includes/class-kalima-languages.php';
require_once KALIMA_PLUGIN_DIR . 'includes/class-kalima-tokenizer.php';
require_once KALIMA_PLUGIN_DIR . 'includes/class-kalima-ai-engine.php';
require_once KALIMA_PLUGIN_DIR . 'includes/class-kalima-core.php';
require_once KALIMA_PLUGIN_DIR . 'includes/class-kalima-frontend.php';
require_once KALIMA_PLUGIN_DIR . 'includes/class-kalima-topbar.php';
require_once KALIMA_PLUGIN_DIR . 'includes/class-kalima-admin.php';

/**
 * Main singleton. Boots the plugin and exposes the component registry.
 */
final class KALIMA {

	/** @var KALIMA|null */
	private static $instance = null;

	/** @var KALIMA_Languages */
	public $languages;

	/** @var KALIMA_Core */
	public $core;

	/** @var KALIMA_AI_Engine */
	public $engine;

	/** @var KALIMA_Frontend */
	public $frontend;

	/** @var KALIMA_Topbar */
	public $topbar;

	/** @var KALIMA_Admin|null */
	public $admin;

	/** @var array Cached settings. */
	private $settings = null;

	/**
	 * Singleton accessor.
	 *
	 * @return KALIMA
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}

		return self::$instance;
	}

	/**
	 * Wire up every component.
	 */
	private function boot() {
		$this->languages = new KALIMA_Languages();
		$this->core      = new KALIMA_Core( $this->languages );
		$this->engine    = new KALIMA_AI_Engine( $this );
		$this->frontend  = new KALIMA_Frontend( $this );
		$this->topbar    = new KALIMA_Topbar( $this );

		// The core resolves the active language through the frontend, and the
		// frontend reads settings from the root — give both a way back in.
		$this->core->set_plugin( $this );

		$this->core->init();
		$this->engine->init();
		$this->frontend->init();
		$this->topbar->init();

		if ( is_admin() ) {
			$this->admin = new KALIMA_Admin( $this );
			$this->admin->init();
		}
	}

	/**
	 * Plugin settings, filtered once per request.
	 *
	 * @return array
	 */
	public function settings() {
		if ( null === $this->settings ) {
			$stored = get_option( 'kalima_settings', array() );
			if ( ! is_array( $stored ) ) {
				$stored = array();
			}

			$this->settings = wp_parse_args(
				$stored,
				array(
					'default_lang'    => 'en',
					'enabled_langs'   => array( 'en', 'de', 'fr', 'ar' ),
					'ai_provider'     => 'gemini',
					'ai_key'          => '',
					'ai_model'        => '',
					'units_per_call'  => 40,
					'delay_ms'        => 350,
					'max_calls'       => 60,
					'preserve_terms'  => 'JSE, WooCommerce, WordPress, Elementor, WoodMart',
					'auto_draft'      => 1,
					'link_new_posts'  => 1,
					'switcher_label'  => '',
					'auto_inject'     => 0,
					/*
					 * switcher_placement is deliberately not defaulted here.
					 * An absent value tells placement() to fall back to the
					 * legacy auto_inject checkbox, so sites saved before the
					 * dropdown existed keep behaving exactly as they did.
					 */
					'geo_default'     => 0,
					'geo_action'      => 'redirect',
					'geo_fallback'    => '',
					/*
					 * A language with nothing published in it yet shows the
					 * source language rather than a404. On by default: an
					 * empty page is worse than the right one in the wrong
					 * language, and a site can turn it off in one click.
					 */
					'fallback_english' => 1,
					/*
					 * Top bar groups (theme-native rendering). Language,
					 * currency and country dropdowns in the theme's own menu
					 * markup; the visitor's currency and country default from
					 * the Cloudflare country header. The place is the top bar
					 * itself or one globe icon beside the cart.
					 */
					'tb_place'         => 'topbar',
					'tb_inherit_theme' => 1,
					'tb_show_lang'     => 1,
					'tb_show_currency' => 1,
					'tb_show_country'  => 1,
				)
			);
		}

		return $this->settings;
	}

	/**
	 * Read one setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$settings = $this->settings();

		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	/**
	 * Drop the settings cache (after a save).
	 */
	public function flush_cache() {
		$this->settings = null;
	}

	/**
	 * Cache-busting version string for one of the plugin's own assets.
	 *
	 * Versioning with the fixed KALIMA_VERSION never changes the URL, so the
	 * browser keeps serving its cached copy forever and a style or script
	 * fix never reaches anyone — least of all the person checking it.
	 *
	 * @param string $relative Path inside the plugin directory.
	 * @return string
	 */
	public static function asset_version( $relative ) {
		$file = KALIMA_PLUGIN_DIR . ltrim( (string) $relative, '/' );

		return file_exists( $file ) ? (string) filemtime( $file ) : KALIMA_VERSION;
	}

	/**
	 * Languages currently switched on, in display order.
	 *
	 * @return array
	 */
	public function active_languages() {
		$enabled = (array) $this->get( 'enabled_langs', array( 'en' ) );

		return $this->languages->only( $enabled );
	}

	/**
	 * Absolute list of every language code we know about.
	 *
	 * @return string[]
	 */
	public static function all_codes() {
		return array_keys( KALIMA_Languages::registry() );
	}
}

/**
 * Late bootstrap so other plugins can hook in.
 */
function kalima() {
	return KALIMA::instance();
}

add_action( 'plugins_loaded', 'kalima', 5 );

/*
 * Update checks: the plugin asks its own GitHub repository - through a
 * small JSON manifest kept on the main branch - whether a newer release
 * exists, and offers it on the Plugins screen exactly like a plugin from
 * the official directory. The manifest's download URL points at the
 * release asset zip that carries a proper kalima/ root folder, so the
 * built-in updater replaces the plugin in place.
 *
 * Admin and WP-CLI only; failure-safe (no manifest reachable, no update
 * offered, nothing breaks).
 */
function kalima_update_checker() {
	if ( ! is_admin() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return;
	}

	require_once KALIMA_PLUGIN_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';

	$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://raw.githubusercontent.com/ferfesh/kalima/main/kalima-update.json',
		KALIMA_PLUGIN_FILE,
		'kalima'
	);
}

add_action( 'plugins_loaded', 'kalima_update_checker', 20 );
