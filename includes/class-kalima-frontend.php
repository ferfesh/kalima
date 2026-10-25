<?php
/**
 * Frontend: language resolution, content filtering and the switcher.
 *
 * URL scheme — the default language lives at the site root, every other
 * language gets a `/de/`, `/ar/` … prefix. The prefix is stripped from
 * `$_SERVER['REQUEST_URI']` inside `do_parse_request`, i.e. before WordPress
 * runs its own rewrite matching, so existing permalinks, products and
 * category pages keep resolving with no extra rewrite rules to get wrong.
 *
 * @package KALIMA
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the active language and renders the switcher.
 */
class KALIMA_Frontend {

	/** @var KALIMA */
	private $kalima;

	/** @var string|null Resolved for this request, null until asked. */
	private $current = null;

	/** @var string Original request URI before we trimmed the prefix. */
	private $original_uri = null;

	/** @var bool Whether the switcher has already been printed this request. */
	private $injected = false;

	/**
	 * @param KALIMA $kalima Plugin instance.
	 */
	public function __construct( KALIMA $kalima ) {
		$this->kalima = $kalima;
	}

	/**
	 * Wire the hooks.
	 */
	public function init() {
		/*
		 * do_parse_request is a real filter: core does
		 *     if ( ! apply_filters( 'do_parse_request', true, $wp, $extra ) ) {
		 *         return false;
		 *     }
		 * A callback that returns null therefore stops WordPress parsing the
		 * request at all — every URL serves one generic page and 404s turn into
		 * 200s. Both callbacks below take the running value and hand it back
		 * untouched, changing it only by redirecting (which ends the request).
		 */
		add_filter( 'do_parse_request', array( $this, 'strip_language_prefix' ), 1, 3 );
		add_filter( 'do_parse_request', array( $this, 'maybe_geo_redirect' ), 5, 3 );

		add_filter( 'query_vars', array( $this->kalima->languages, 'query_vars' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_main_query' ), 20 );
		add_filter( 'body_class', array( $this, 'body_class' ) );
		add_action( 'wp_head', array( $this, 'html_language' ), 1 );
		add_action( 'init', array( $this, 'register_shortcode' ) );
		add_action( 'widgets_init', array( $this, 'register_widget' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );

		// Optional automatic placement of the switcher in the header.
		add_action( 'wp_body_open', array( $this, 'auto_inject' ), 5 );

		/*
		 * Placements picked on the settings screen from the locations the
		 * active theme actually registered: a menu (by location or by the
		 * menu itself, which is how header builders usually pick one) or a
		 * widget area. Both hooks hand back the running value untouched when
		 * the choice is somewhere else.
		 */
		add_filter( 'wp_nav_menu_items', array( $this, 'inject_in_menu' ), 20, 2 );
		add_action( 'dynamic_sidebar_after', array( $this, 'inject_in_widget_area' ), 20, 2 );

		// Term links must point at the translated term.
		add_filter( 'get_term_link', array( $this, 'term_link' ), 10, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Resolution
	 * ------------------------------------------------------------------ */

	/**
	 * Trim a `/xx/` language prefix off the request before WP parses it.
	 *
	 * This runs as a `do_parse_request` filter, so it must hand the running
	 * boolean straight back on every exit — see the note in init(). Returning
	 * nothing would abort request parsing site-wide.
	 *
	 * @param bool         $bool              Whether to parse the request.
	 * @param \WP          $wp                WP object.
	 * @param array|string $extra_query_vars  Extra query variables.
	 * @return bool
	 */
	public function strip_language_prefix( $bool, $wp = null, $extra_query_vars = null ) {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return $bool;
		}

		$uri      = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$codes    = array_keys( $this->kalima->languages->registry() );
		$path     = wp_parse_url( $uri, PHP_URL_PATH );
		$query    = wp_parse_url( $uri, PHP_URL_QUERY );
		$segments = array_values( array_filter( explode( '/', trim( (string) $path, '/' ) ) ) );

		if ( empty( $segments ) ) {
			return $bool;
		}

		$first = strtolower( $segments[0] );

		if ( ! in_array( $first, $codes, true ) ) {
			return $bool;
		}

		// `/ar` on its own is the language home page.
		array_shift( $segments );
		$rest = implode( '/', $segments );

		$this->current     = $first;
		$this->original_uri = $uri;

		/*
		 * An empty $rest must yield `/`, not `//`. A doubled slash here makes
		 * WordPress treat the request as a different URL from the canonical
		 * one and 301 the visitor back to the site root, undoing the whole
		 * language prefix.
		 */
		$new_path = '/' . ( '' !== $rest ? $rest . '/' : '' );
		$new_uri  = $new_path . ( $query ? '?' . $query : '' );

		$_SERVER['REQUEST_URI'] = $new_uri;
		$_SERVER['PHP_SELF']    = '/index.php';

		// The home link inside the rewrite engine must not carry the prefix.
		$GLOBALS['wp']->requested_path = trim( $rest, '/' );

		return $bool;
	}

	/**
	 * Active language for this request.
	 *
	 * Order of precedence: explicit `?lang=`, URL prefix, saved visitor choice,
	 * the visitor's country (only when the site is configured for it), then
	 * the default language.
	 *
	 * @return string
	 */
	public function current_language() {
		if ( null !== $this->current ) {
			return $this->current;
		}

		$this->current = $this->resolve_language();

		return $this->current;
	}

	/**
	 * The actual resolution chain.
	 *
	 * @return string
	 */
	private function resolve_language() {
		$enabled = array_keys( $this->active_languages() );

		if ( empty( $enabled ) ) {
			return $this->default_language();
		}

		return $this->language_with_fallback( $this->pick_language( $enabled ) );
	}

	/**
	 * Steps 1-4: which language this request is asking for.
	 *
	 * @param string[] $enabled Enabled language codes.
	 * @return string
	 */
	private function pick_language( $enabled ) {
		// 1. Explicit query var (also what the switcher links use).
		$requested = isset( $_GET['kalima_lang'] ) ? sanitize_text_field( wp_unslash( $_GET['kalima_lang'] ) ) : '';
		$requested = $requested ? : ( isset( $_GET['lang'] ) ? sanitize_text_field( wp_unslash( $_GET['lang'] ) ) : '' );

		if ( $requested && in_array( $requested, $enabled, true ) ) {
			$this->remember( $requested );

			return $requested;
		}

		// 2. URL prefix, stripped in do_parse_request().
		if ( $this->current && in_array( $this->current, $enabled, true ) ) {
			return $this->current;
		}

		// 3. Cookie set by the switcher.
		$cookie = isset( $_COOKIE['kalima_lang'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['kalima_lang'] ) ) : '';

		if ( $cookie && in_array( $cookie, $enabled, true ) ) {
			return $cookie;
		}

		/*
		 * 4. The visitor's country. Empty unless geo defaults are switched on,
		 * and rejected below if it names a language the site does not serve or
		 * one that has nothing translated into it yet.
		 */
		$geo = $this->geo_language();

		if ( $geo && in_array( $geo, $enabled, true ) && $this->language_has_content( $geo ) ) {
			return $geo;
		}

		return $this->default_language();
	}

	/**
	 * A language with nothing translated into it yet serves the source
	 * language instead of an empty page.
	 *
	 * Without this, an untranslated `/ar/`, `?lang=ar` or a switcher click
	 * hands the visitor a 404 — the meta filter matches zero rows and
	 * WordPress does what it always does with an empty main query. The
	 * setting lets a site switch the courtesy off and keep the strict
	 * behaviour: only translated content, or nothing.
	 *
	 * @param string $lang Language the request asked for.
	 * @return string
	 */
	private function language_with_fallback( $lang ) {
		$default = $this->default_language();

		if ( $lang === $default ) {
			return $lang;
		}

		if ( ! $this->kalima->get( 'fallback_english' ) ) {
			return $lang;
		}

		if ( $this->language_has_content( $lang ) ) {
			return $lang;
		}

		return $default;
	}

	/**
	 * Persist the visitor's choice.
	 *
	 * @param string $lang Language code.
	 */
	private function remember( $lang ) {
		if ( headers_sent() ) {
			return;
		}

		// 30 days, on the root path so every subpage sees it.
		setcookie( 'kalima_lang', $lang, time() + MONTH_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN );
		$_COOKIE['kalima_lang'] = $lang;
	}

	/* ---------------------------------------------------------------------
	 * The visitor's country
	 * ------------------------------------------------------------------ */

	/**
	 * Country the visitor is arriving from, when a header tells us so.
	 *
	 * @return string ISO 3166-1 alpha-2 code, or '' when unknown.
	 */
	public function visitor_country() {
		static $country = null;

		if ( null !== $country ) {
			return $country;
		}

		$country = '';

		/*
		 * Ordered by trustworthiness. Cloudflare leads because it sits in
		 * front of the live site and derives the code from the address it
		 * itself terminated the connection on, not from a value the client
		 * chose to send.
		 */
		$sources = array(
			'HTTP_CF_IPCOUNTRY',        // Cloudflare
			'HTTP_X_COUNTRY_CODE',      // Fastly, Vercel, several CDNs
			'HTTP_X_APPENGINE_COUNTRY', // Google App Engine
			'GEOIP_COUNTRY_CODE',       // mod_geoip
		);

		foreach ( $sources as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}

			$value = strtoupper( sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) );

			if ( 2 === strlen( $value ) && preg_match( '/^[A-Z]{2}$/', $value ) ) {
				$country = $value;
				break;
			}
		}

		// Cloudflare reports XX / T1 when it genuinely could not tell.
		if ( 'XX' === $country || 'T1' === $country ) {
			$country = '';
		}

		return $country;
	}

	/**
	 * Language the visitor's country maps to.
	 *
	 * @return string Language code, or '' when the site is not using geo
	 *                defaults or the country maps to nothing.
	 */
	public function geo_language() {
		if ( ! $this->kalima->get( 'geo_default' ) ) {
			return '';
		}

		$country = $this->visitor_country();

		if ( '' === $country ) {
			return '';
		}

		$code = $this->kalima->languages->language_for_country( $country );

		/**
		 * Filter the language chosen for a country. Return '' to decline.
		 *
		 * @param string $code    Language code, '' when unmapped.
		 * @param string $country ISO country code.
		 */
		$code = (string) apply_filters( 'kalima_geo_language', $code, $country );

		if ( '' === $code ) {
			// Country known, language not: the configured fallback.
			$code = (string) $this->kalima->get( 'geo_fallback', $this->default_language() );
		}

		return $code;
	}

	/**
	 * Whether a language has anything translated to show yet.
	 *
	 * Without this, switching on the country redirect would walk a German
	 * visitor straight into an empty site: non-source languages are filtered
	 * to translated content only, so a language with no translations at all
	 * renders nothing. Cached for an hour because the answer only moves when
	 * a translation is created.
	 *
	 * @param string $lang Language code.
	 * @return bool
	 */
	public function language_has_content( $lang ) {
		if ( ! $lang || $lang === $this->default_language() ) {
			return true;
		}

		$cache_key = 'kalima_has_content_' . $lang;
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return (bool) $cached;
		}

		$found = get_posts(
			array(
				'post_type'      => $this->kalima->core->translatable_post_types(),
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => KALIMA_Core::LANG,
				'meta_value'     => $lang,
			)
		);

		$has = ! empty( $found );

		set_transient( $cache_key, $has ? 1 : 0, HOUR_IN_SECONDS );

		return $has;
	}

	/**
	 * Send a first-time visitor to the language of their country.
	 *
	 * Runs at priority 5 on do_parse_request, i.e. after the prefix has been
	 * stripped, so an explicit `/ar/` URL has already claimed the request and
	 * cannot be re-pointed. Returns the running boolean untouched on every
	 * path — see the note in init().
	 *
	 * @param bool $bool Whether to parse the request.
	 * @return bool
	 */
	public function maybe_geo_redirect( $bool ) {
		if ( ! $bool || is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return $bool;
		}

		if ( ! $this->kalima->get( 'geo_default' ) ) {
			return $bool;
		}

		if ( 'redirect' !== (string) $this->kalima->get( 'geo_action', 'redirect' ) ) {
			return $bool;
		}

		if ( 'GET' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) {
			return $bool;
		}

		if ( ! apply_filters( 'kalima_geo_redirect_enabled', true ) ) {
			return $bool;
		}

		// Never override something the visitor has already asked for.
		if ( isset( $_GET['kalima_lang'] ) || isset( $_GET['lang'] ) ) {
			return $bool;
		}

		if ( $this->current ) { // a /xx/ prefix already chose a language
			return $bool;
		}

		if ( isset( $_COOKIE['kalima_lang'] ) ) { // a choice they made with the switcher
			return $bool;
		}

		$raw    = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$parts  = explode( '?', $raw, 2 );
		$uri    = $parts[0];
		$query  = isset( $parts[1] ) ? $parts[1] : '';

		// Leave WP's own endpoints, and anything that is a file, alone.
		if ( preg_match( '#/(?:wp-login\.php|wp-admin|wp-cron\.php|xmlrpc\.php|wp-json)(?:/|$)#', $uri ) ) {
			return $bool;
		}

		if ( preg_match( '#\.[a-z0-9]{2,5}$#i', $uri ) ) {
			return $bool;
		}

		$geo = $this->geo_language();

		if ( '' === $geo || $geo === $this->default_language() ) {
			return $bool;
		}

		$enabled = $this->active_languages();

		if ( ! isset( $enabled[ $geo ] ) ) {
			return $bool;
		}

		// Nothing translated into it yet — do not walk them into an empty site.
		if ( ! $this->language_has_content( $geo ) ) {
			return $bool;
		}

		$clean  = trim( $uri, '/' );
		$target = home_url( '/' . $geo . '/' . ( '' !== $clean ? $clean . '/' : '' ) );

		if ( '' !== $query ) {
			$target .= '?' . $query;
		}

		// 302, not 301: the country a visitor is in is not a property of the URL.
		wp_safe_redirect( $target, 302, 'Kalima AI Multilingual' );
		exit;
	}

	/**
	 * Site's source language.
	 *
	 * @return string
	 */
	public function default_language() {
		return (string) $this->kalima->get( 'default_lang', 'en' );
	}

	/**
	 * Enabled languages keyed by code.
	 *
	 * @return array<string,array>
	 */
	public function active_languages() {
		return $this->kalima->active_languages();
	}

	/* ---------------------------------------------------------------------
	 * Query filtering
	 * ------------------------------------------------------------------ */

	/**
	 * Make the main query return only content in the active language.
	 *
	 * @param \WP_Query $query Query.
	 */
	public function filter_main_query( $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		$lang = $this->current_language();

		/*
		 * The source language is never filtered.
		 *
		 * Nothing has been translated yet, so a meta_query here would match
		 * zero rows and empty the shop, the blog and every archive of a site
		 * that worked perfectly before this plugin existed. Non-default
		 * languages filter, because there the visitor only wants translated
		 * content.
		 */
		if ( $lang === $this->default_language() ) {
			return;
		}

		/*
		 * Append rather than replace: a plain set() here would wipe the
		 * meta_query another plugin has already put on this query — for a
		 * product archive that is usually the price-sorting clause.
		 */
		$meta_query = (array) $query->get( 'meta_query' );

		$meta_query[] = array(
			'key'     => KALIMA_Core::LANG,
			'value'   => $lang,
			'compare' => '=',
		);

		$query->set( 'meta_query', $meta_query );
	}

	/* ---------------------------------------------------------------------
	 * Links
	 * ------------------------------------------------------------------ */

	/**
	 * Base URL for a language.
	 *
	 * @param string $lang Language code.
	 * @return string
	 */
	public function language_url( $lang ) {
		$home = untrailingslashit( home_url() );

		if ( $lang === $this->default_language() ) {
			return $home . '/';
		}

		return $home . '/' . $lang . '/';
	}

	/**
	 * URL of the current page in another language, when a twin exists.
	 *
	 * @param string $lang Target language.
	 * @return string|null Null when no translation is available.
	 */
	public function current_url_in( $lang ) {
		$object = $this->current_object();

		if ( $object && 'post' === $object['kind'] ) {
			$translations = $this->kalima->core->get_translations( $object['id'] );

			if ( isset( $translations[ $lang ] ) ) {
				$link = get_permalink( $translations[ $lang ] );

				return $link ? $this->prefix_url( $link, $lang ) : null;
			}

			// No twin yet — the original is still the best landing page.
			$link = get_permalink( $object['id'] );

			return $link ? $this->prefix_url( $link, $lang ) : null;
		}

		if ( $object && 'term' === $object['kind'] ) {
			$translations = $this->kalima->core->get_term_translations( $object['id'] );
			$term_id      = isset( $translations[ $lang ] ) ? $translations[ $lang ] : $object['id'];
			$link         = get_term_link( (int) $term_id );

			if ( ! is_wp_error( $link ) ) {
				return $this->prefix_url( $link, $lang );
			}
		}

		return null;
	}

	/**
	 * Swap or add the language prefix on an absolute URL.
	 *
	 * @param string $url  Absolute URL.
	 * @param string $lang Target language.
	 * @return string
	 */
	private function prefix_url( $url, $lang ) {
		$home = untrailingslashit( home_url() );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		// Remove any existing language prefix.
		$segments = array_values( array_filter( explode( '/', trim( $path, '/' ) ) ) );
		$codes    = array_keys( $this->kalima->languages->registry() );

		if ( ! empty( $segments ) && in_array( strtolower( $segments[0] ), $codes, true ) ) {
			array_shift( $segments );
			$path = '/' . implode( '/', $segments ) . '/';
		}

		if ( '' === trim( $path ) ) {
			$path = '/';
		}

		if ( $lang === $this->default_language() ) {
			return $home . $path;
		}

		return $home . '/' . $lang . $path;
	}

	/**
	 * What the request is currently about.
	 *
	 * @return array{id:int,kind:string}|null
	 */
	private function current_object() {
		global $wp_query;

		if ( ! isset( $wp_query ) || ! ( $wp_query instanceof \WP_Query ) ) {
			return null;
		}

		if ( $wp_query->is_singular() ) {
			return array(
				'id'   => (int) get_queried_object_id(),
				'kind' => 'post',
			);
		}

		if ( $wp_query->is_tax() || $wp_query->is_category() || $wp_query->is_tag() ) {
			return array(
				'id'   => (int) get_queried_object_id(),
				'kind' => 'term',
			);
		}

		return null;
	}

	/**
	 * Point term links at the version in the active language.
	 *
	 * @param string   $link Term URL.
	 * @param \WP_Term $term Term.
	 * @return string
	 */
	public function term_link( $link, $term ) {
		if ( is_admin() ) {
			return $link;
		}

		$translated = $this->kalima->core->translated_term_id( (int) $term->term_id );

		if ( $translated === (int) $term->term_id ) {
			return $link;
		}

		$fresh = get_term_link( (int) $translated );

		if ( is_wp_error( $fresh ) ) {
			return $link;
		}

		return $fresh;
	}

	/* ---------------------------------------------------------------------
	 * Markup
	 * ------------------------------------------------------------------ */

	/**
	 * Add the active language to body classes.
	 *
	 * @param string[] $classes Body classes.
	 * @return string[]
	 */
	public function body_class( $classes ) {
		$lang = $this->current_language();

		$classes[] = 'kalima-lang-' . $lang;

		if ( $this->kalima->languages->is_rtl( $lang ) ) {
			$classes[] = 'rtl-lang';
		}

		return $classes;
	}

	/**
	 * Announce the document language to crawlers.
	 */
	public function html_language() {
		if ( is_admin() ) {
			return;
		}

		printf(
			'<meta name="kalima-language" content="%s" />' . "\n",
			esc_attr( $this->current_language() )
		);
	}

	/* ---------------------------------------------------------------------
	 * Switcher
	 * ------------------------------------------------------------------ */

	/**
	 * `[kalima_switcher]` shortcode.
	 */
	public function register_shortcode() {
		add_shortcode( 'kalima_switcher', array( $this, 'render_switcher' ) );
	}

	/**
	 * Sidebar widget wrapper.
	 */
	public function register_widget() {
		register_widget( 'KALIMA_Switcher_Widget' );
	}

	/**
	 * Load the switcher stylesheet only when something on the page will use it.
	 */
	public function assets() {
		$should = ( 'off' !== $this->placement() )
			|| is_active_sidebar( 'sidebar-1' )
			|| shortcode_exists( 'kalima_switcher' );

		if ( ! apply_filters( 'kalima_enqueue_frontend_assets', $should ) ) {
			return;
		}

		wp_enqueue_style(
			'kalima-frontend',
			KALIMA_PLUGIN_URL . 'assets/frontend.css',
			array(),
			KALIMA::asset_version( 'assets/frontend.css' )
		);
	}

	/**
	 * Render the language switcher.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_switcher( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'style'  => 'list',
				'show_flags' => 'yes',
				'label'  => $this->kalima->get( 'switcher_label', '' ),
			),
			$atts,
			'kalima_switcher'
		);

		$current = $this->current_language();
		$all     = $this->active_languages();

		$html = '';

		if ( '' !== trim( (string) $atts['label'] ) ) {
			$html .= '<span class="kalima-switcher__label">' . esc_html( $atts['label'] ) . '</span>';
		}

		$html .= '<ul class="kalima-switcher kalima-switcher--' . esc_attr( $atts['style'] ) . '">';

		foreach ( $all as $code => $data ) {
			$url = $this->current_url_in( $code );
			$url = $url ? : $this->language_url( $code );

			$is_current = ( $code === $current );

			$html .= sprintf(
				'<li class="kalima-switcher__item%s"><a class="kalima-switcher__link" href="%s" hreflang="%s" lang="%s"%s>%s%s</a></li>',
				$is_current ? ' is-current' : '',
				esc_url( $url ),
				esc_attr( $code ),
				esc_attr( $data['locale'] ),
				$is_current ? ' aria-current="true"' : '',
				'yes' === $atts['show_flags'] ? '<span class="kalima-switcher__flag">' . esc_html( $data['flag'] ) . '</span>' : '',
				esc_html( $data['native'] )
			);
		}

		$html .= '</ul>';

		/**
		 * Filter the switcher markup.
		 *
		 * @param string $html Switcher HTML.
		 * @param array  $atts Shortcode attributes.
		 */
		return apply_filters( 'kalima_switcher_html', $html, $atts );
	}

	/**
	 * Where the switcher prints itself automatically.
	 *
	 * `off` prints nothing, `topbar` wraps it in a full-width bar above the
	 * header, `body` prints the plain list at the top of the page — which is
	 * what this option did before the dropdown existed.
	 *
	 * Anything else is a location discovered from the active theme:
	 * `loc:<theme location>`, `menu:<id or slug>` or `side:<widget area>`.
	 *
	 * @return string off|topbar|body|loc:*|menu:*|side:*
	 */
	public function placement() {
		$placement = (string) $this->kalima->get( 'switcher_placement', '' );

		// Settings saved before the dropdown existed only know the old checkbox.
		if ( '' === $placement ) {
			$placement = $this->kalima->get( 'auto_inject' ) ? 'body' : 'off';
		}

		if ( in_array( $placement, array( 'off', 'topbar', 'body' ), true ) ) {
			return $placement;
		}

		// A discovered location. The slug is what the settings screen offered.
		if ( preg_match( '#^(loc|menu|side):[A-Za-z0-9_.-]+$#', $placement ) ) {
			return $placement;
		}

		return 'off';
	}

	/**
	 * Optional automatic placement of the switcher.
	 *
	 * Only the two whole-page placements belong here — a menu or widget
	 * target is printed from its own hook, in the right place in the markup.
	 */
	public function auto_inject() {
		$placement = $this->placement();

		if ( ! in_array( $placement, array( 'topbar', 'body' ), true ) || is_admin() ) {
			return;
		}

		/*
		 * A theme may call wp_body_open from more than one template part, so
		 * print at most once. An instance property rather than a static so the
		 * guard can be reset — a static would silence every later call for the
		 * life of the process, which makes the method impossible to test.
		 */
		if ( $this->injected ) {
			return;
		}

		$this->injected = true;

		$style    = ( 'topbar' === $placement ) ? 'topbar' : 'list';
		$switcher = $this->render_switcher( array( 'style' => $style ) );

		if ( 'topbar' !== $placement ) {
			// Escaped where it is built (esc_url/esc_attr/esc_html) in render_switcher().
			echo $switcher; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		printf(
			'<div class="kalima-topbar" role="region" aria-label="%s">%s</div>',
			esc_attr__( 'Language', 'kalima' ),
			$switcher // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	/**
	 * Append the switcher to the menu the settings screen pointed at.
	 *
	 * `wp_nav_menu()` is called with either a `theme_location`, a menu, or
	 * both — header builders pass the menu they were configured with and no
	 * location at all, so a location-only match would silently miss the most
	 * useful target on a WoodMart site. Both are matched.
	 *
	 * @param string    $items Rendered list items.
	 * @param \stdClass $args  wp_nav_menu() arguments.
	 * @return string
	 */
	public function inject_in_menu( $items, $args ) {
		$placement = $this->placement();

		if ( 0 !== strpos( $placement, 'loc:' ) && 0 !== strpos( $placement, 'menu:' ) ) {
			return $items;
		}

		/*
		 * An empty string is a real answer here: a menu can be assigned to a
		 * header position before anyone has added items to it, and returning
		 * early would leave the switcher silently missing exactly where it was
		 * asked for. The match below is what decides, not the length.
		 */
		if ( is_admin() || ! is_string( $items ) ) {
			return $items;
		}

		// Themes clone a menu for the mobile or sticky header: print once.
		if ( $this->injected ) {
			return $items;
		}

		$hits = array();

		if ( ! empty( $args->theme_location ) ) {
			$hits[] = 'loc:' . $args->theme_location;
		}

		$menu = null;

		if ( isset( $args->menu ) && '' !== $args->menu && null !== $args->menu ) {
			$menu = is_object( $args->menu ) ? $args->menu : wp_get_nav_menu_object( $args->menu );
		}

		if ( $menu && ! is_wp_error( $menu ) && isset( $menu->term_id ) ) {
			$hits[] = 'menu:' . $menu->term_id;
			$hits[] = 'menu:' . $menu->slug;
		}

		if ( ! in_array( $placement, $hits, true ) ) {
			return $items;
		}

		$this->injected = true;

		return $items . '<li class="menu-item kalima-menu-switcher">'
			. $this->render_switcher( array( 'style' => 'inline' ) ) // Escaped where it is built.
			. '</li>';
	}

	/**
	 * Print the switcher at the end of the widget area the settings screen
	 * pointed at.
	 *
	 * `dynamic_sidebar_after` also fires for an area with no widgets in it
	 * when the theme calls it anyway, so the choice keeps working while the
	 * footer is still being built.
	 *
	 * @param int|string $index       Widget area id.
	 * @param bool       $has_widgets Whether the area is populated.
	 */
	public function inject_in_widget_area( $index, $has_widgets = true ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$placement = $this->placement();

		if ( 'side:' . $index !== $placement ) {
			return;
		}

		if ( is_admin() || $this->injected ) {
			return;
		}

		$this->injected = true;

		echo $this->render_switcher( array( 'style' => 'inline' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where it is built.
	}
}

/**
 * Block-style widget so the switcher can be dropped into any widget area.
 */
class KALIMA_Switcher_Widget extends WP_Widget {

	/**
	 * Register with WordPress.
	 */
	public function __construct() {
		parent::__construct(
			'kalima_switcher',
			__( 'Language Switcher (Kalima AI Multilingual)', 'kalima' ),
			array(
				'classname'   => 'kalima-switcher-widget',
				'description' => __( 'List of languages the visitor can switch to.', 'kalima' ),
			)
		);
	}

	/**
	 * Frontend output.
	 *
	 * @param array $args     Widget args.
	 * @param array $instance Instance settings.
	 */
	public function widget( $args, $instance ) {
		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput

		if ( ! empty( $instance['title'] ) ) {
			echo $args['before_title'] . esc_html( $instance['title'] ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput
		}

		echo kalima()->frontend->render_switcher( // phpcs:ignore WordPress.Security.EscapeOutput
			array(
				'show_flags' => empty( $instance['flags'] ) ? 'no' : 'yes',
			)
		);

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Admin form.
	 *
	 * @param array $instance Instance settings.
	 */
	public function form( $instance ) {
		$title = isset( $instance['title'] ) ? $instance['title'] : '';
		$flags = isset( $instance['flags'] ) ? (int) $instance['flags'] : 1;

		printf(
			'<p><label for="%s">%s</label><input class="widefat" id="%s" name="%s" type="text" value="%s" /></p>',
			esc_attr( $this->get_field_id( 'title' ) ),
			esc_html__( 'Title:', 'kalima' ),
			esc_attr( $this->get_field_id( 'title' ) ),
			esc_attr( $this->get_field_name( 'title' ) ),
			esc_attr( $title )
		);

		printf(
			'<p><label><input type="checkbox" name="%s" value="1" %s /> %s</label></p>',
			esc_attr( $this->get_field_name( 'flags' ) ),
			checked( $flags, 1, false ),
			esc_html__( 'Show flag emoji', 'kalima' )
		);
	}

	/**
	 * Sanitise saved settings.
	 *
	 * @param array $new_instance New values.
	 * @param array $old_instance Old values.
	 * @return array
	 */
	public function update( $new_instance, $old_instance ) {
		return array(
			'title' => sanitize_text_field( $new_instance['title'] ?? '' ),
			'flags' => empty( $new_instance['flags'] ) ? 0 : 1,
		);
	}
}
