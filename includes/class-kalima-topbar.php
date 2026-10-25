<?php
/**
 * Top bar element: language, currency and country in the theme's own markup.
 *
 * When the switcher position is the top bar and theme rendering is on, the
 * switcher is printed exactly the way WoodMart prints its own header language
 * element (ul.menu.wd-nav.wd-nav-header.wd-nav-secondary with wd-dropdown-menu
 * dropdowns), so the theme's own CSS and menu JS style and behave it. Three
 * groups can appear, each with the visitor's current value on the trigger and
 * the choices in the dropdown:
 *
 *   - Language  - the Kalima languages, current one on the trigger.
 *   - Currency  - the active multi-currency currencies (WPC Multi Currency
 *                 cookie wpcmc_currency is read and set, so the shop prices
 *                 follow immediately). Defaults to the visitor's country.
 *   - Country   - the shipping countries from the WooCommerce zones, with the
 *                 visitor's country (Cloudflare CF-IPCountry) on the trigger.
 *
 * The strip Kalima prints is moved into the leftmost visible WoodMart top bar
 * column by a tiny guarded script (falls back to staying above the header),
 * and the plugin's own chip stylesheet never loads in the header, so there is
 * no second design fighting the theme.
 *
 * A second place exists beside the cart: when the place setting is the cart
 * globe, one icon trigger (the theme's own translate glyph from its icon
 * font) is moved next to the header cart and the groups stack inside its
 * hover dropdown - same menu markup, same theme behaviour.
 *
 * @package KALIMA
 */

defined( 'ABSPATH' ) || exit;

class KALIMA_Topbar {

	/** @var KALIMA */
	private $kalima;

	/**
	 * Wire the filters.
	 *
	 * @param KALIMA $kalima Plugin root.
	 */
	public function __construct( $kalima ) {
		$this->kalima = $kalima;
	}

	/**
	 * Hook everything.
	 */
	public function init() {
		if ( $this->inherit_theme() ) {
			// The theme owns the styles - the chip stylesheet stays off.
			add_filter( 'kalima_enqueue_frontend_assets', '__return_false' );
		}

		// Priority 20: whatever an old snippet prints earlier is replaced by
		// the plugin's own markup - the plugin is the authority here.
		add_filter( 'kalima_switcher_html', array( $this, 'switcher_nav' ), 20, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ), 25 );
		add_action( 'init', array( $this, 'pick_requests' ), 5 );
	}

	/**
	 * Theme-native mode is only meaningful in the top bar.
	 *
	 * @return bool
	 */
	private function inherit_theme() {
		$setting = $this->kalima->get( 'tb_inherit_theme', 1 );

		return 'topbar' === $this->kalima->frontend->placement() && ! empty( $setting );
	}

	/**
	 * The globe place: one icon beside the cart, the groups stacked in its
	 * hover dropdown. The icon is the theme's own translate glyph (the one
	 * the header builder uses for its language element) from the theme icon
	 * font; if that font file is missing the plugin stays in the top bar
	 * place instead of inventing a symbol of its own.
	 *
	 * @return bool
	 */
	private function globe_mode() {
		if ( ! $this->inherit_theme() || 'cart-globe' !== $this->kalima->get( 'tb_place', 'topbar' ) ) {
			return false;
		}

		return file_exists( get_template_directory() . '/inc/admin/assets/fonts/xts-icon-font.woff2' );
	}

	/**
	 * Replace the plain list with the theme-native navigation.
	 *
	 * @param string $html Switcher markup.
	 * @param array  $atts Shortcode attributes.
	 * @return string
	 */
	public function switcher_nav( $html, $atts ) {
		if ( ! $this->inherit_theme() ) {
			return $html;
		}

		$globe = $this->globe_mode();

		$groups = array();

		if ( ! empty( $this->kalima->get( 'tb_show_lang', 1 ) ) ) {
			$group = $this->language_group( $globe );
			if ( $group ) {
				$groups[] = $group;
			}
		}

		if ( ! empty( $this->kalima->get( 'tb_show_currency', 1 ) ) ) {
			$group = $this->currency_group( $globe );
			if ( $group ) {
				$groups[] = $group;
			}
		}

		if ( ! empty( $this->kalima->get( 'tb_show_country', 1 ) ) ) {
			$group = $this->country_group( $globe );
			if ( $group ) {
				$groups[] = $group;
			}
		}

		if ( empty( $groups ) ) {
			return $html;
		}

		if ( $globe ) {
			return $this->globe_markup( $groups );
		}

		return $this->nav_markup( $groups );
	}

	/**
	 * Language group from the Kalima registry.
	 *
	 * @param bool $include_current Also list the current language (globe place shows every value).
	 * @return array|null
	 */
	private function language_group( $include_current = false ) {
		$front = $this->kalima->frontend;
		$all   = $front->active_languages();
		$cur   = $front->current_language();

		if ( ! $all || ! is_array( $all ) ) {
			return null;
		}

		$trigger = '';
		$flag    = '';
		$url     = '';

		foreach ( $all as $code => $data ) {
			if ( $code === $cur ) {
				$trigger = $data['native'];
				$flag    = $data['flag'];
				$url     = $front->current_url_in( $code );
				$url     = $url ? : ( method_exists( $front, 'language_url' ) ? $front->language_url( $code ) : '' );
				break;
			}
		}

		if ( '' === $trigger ) {
			$first   = reset( $all );
			$trigger = $first['native'];
			$flag    = $first['flag'];
		}

		$items = array();

		foreach ( $all as $code => $data ) {
			if ( ! $include_current && $code === $cur ) {
				continue;
			}

			$url_item = $front->current_url_in( $code );
			$url_item = $url_item ? : ( method_exists( $front, 'language_url' ) ? $front->language_url( $code ) : '#' );

			$items[] = array(
				'url'  => $url_item,
				'text' => trim( $data['flag'] . ' ' . $data['native'] ),
			);
		}

		return array(
			'trigger' => trim( $flag . ' ' . $trigger ),
			'href'    => $url ? : $this->same_url(),
			'items'   => $items,
		);
	}

	/**
	 * Currency group from the active multi-currency currencies.
	 *
	 * @param bool $include_current Also list the current currency (globe place shows every value).
	 * @return array|null
	 */
	private function currency_group( $include_current = false ) {
		$current = $this->current_currency();
		$codes   = $this->currency_codes();

		if ( '' === $current || count( $codes ) < 2 ) {
			return null;
		}

		$items = array();

		foreach ( $codes as $code ) {
			if ( ! $include_current && $code === $current ) {
				continue;
			}

			$items[] = array(
				'url'  => add_query_arg( 'kalima_currency', $code, $this->same_url() ),
				'text' => $code,
			);
		}

		return array(
			'trigger' => $current,
			'href'    => $this->same_url(),
			'items'   => $items,
		);
	}

	/**
	 * Country group from the WooCommerce shipping zones.
	 *
	 * The trigger shows the visitor's country (Cloudflare), falling back to
	 * the choice cookie and the store base country. The dropdown lists the
	 * countries the shop actually ships to.
	 *
	 * @param bool $include_current Also list the current country (globe place shows every value).
	 * @return array|null
	 */
	private function country_group( $include_current = false ) {
		$detected = $this->visitor_country();
		$current  = $this->current_country( $detected );
		$names    = $this->country_names();
		$codes    = $this->shipping_country_codes();

		$trigger = $this->country_label( $current, $names );

		if ( '' === $trigger ) {
			return null;
		}

		$items = array();

		foreach ( $codes as $code ) {
			if ( ! $include_current && $code === $current ) {
				continue;
			}

			$items[] = array(
				'url'  => add_query_arg( 'kalima_country', $code, $this->same_url() ),
				'text' => $this->country_label( $code, $names ),
			);
		}

		return array(
			'trigger' => $trigger,
			'href'    => $this->same_url(),
			'items'   => $items,
		);
	}

	/**
	 * Theme-native menu markup (the WoodMart header element structure).
	 *
	 * @param array $groups Groups of trigger + dropdown items.
	 * @return string
	 */
	private function nav_markup( $groups ) {
		$html = '<div class="wd-header-nav wd-header-secondary-nav">'
			. '<ul class="menu wd-nav wd-nav-header wd-nav-secondary wd-style-default">';

		foreach ( $groups as $group ) {
			$has_items = ! empty( $group['items'] );

			/*
			 * wd-event-hover is the theme's own hover switch: its CSS opens
			 * `.wd-event-hover:hover > .wd-dropdown` - without it the menu
			 * markup is perfect and the dropdown simply never opens.
			 */
			$html .= '<li class="menu-item'
				. ( $has_items ? ' menu-item-has-children wd-event-hover' : '' )
				. '">';
			$html .= '<a href="' . esc_url( $group['href'] ) . '" class="woodmart-nav-link">'
				. '<span class="nav-link-text">' . esc_html( $group['trigger'] ) . '</span></a>';

			if ( $has_items ) {
				$html .= '<div class="wd-dropdown-menu wd-dropdown wd-design-default color-scheme-light">'
					. '<div class="container"><ul class="wd-sub-menu sub-menu">';

				foreach ( $group['items'] as $item ) {
					$html .= '<li class="menu-item"><a href="' . esc_url( $item['url'] ) . '" class="woodmart-nav-link">'
						. '<span class="nav-link-text">' . esc_html( $item['text'] ) . '</span></a></li>';
				}

				$html .= '</ul></div></div>';
			}

			$html .= '</li>';
		}

		return $html . '</ul></div>';
	}

	/**
	 * One icon trigger with the groups stacked in its hover dropdown - the
	 * same menu markup as the top bar place, only the trigger carries the
	 * theme's own translate glyph instead of a text label, and every group
	 * lists all its values (the icon carries the state, not a word).
	 *
	 * @param array $groups Groups of dropdown items.
	 * @return string
	 */
	private function globe_markup( $groups ) {
		$html = '<div class="wd-header-nav wd-header-secondary-nav itn-kalima-globe-wrap">'
			. '<ul class="menu wd-nav wd-nav-header wd-nav-secondary wd-style-default itn-kalima-globe-nav">';
		$html .= '<li class="menu-item menu-item-has-children wd-event-hover itn-kalima-globe-item">';
		$html .= '<a href="' . esc_url( $this->same_url() ) . '" class="woodmart-nav-link itn-kalima-globe-link"'
			. ' aria-label="' . esc_attr__( 'Language and currency', 'kalima' ) . '">'
			. '<span class="itn-kalima-globe"></span></a>';
		$html .= '<div class="wd-dropdown-menu wd-dropdown wd-design-default sub-menu-dropdown color-scheme-light">'
			. '<div class="container">';

		foreach ( $groups as $group ) {
			if ( empty( $group['items'] ) ) {
				continue;
			}

			$html .= '<ul class="wd-sub-menu sub-menu">';

			foreach ( $group['items'] as $item ) {
				$html .= '<li class="menu-item"><a href="' . esc_url( $item['url'] ) . '" class="woodmart-nav-link">'
					. '<span class="nav-link-text">' . esc_html( $item['text'] ) . '</span></a></li>';
			}

			$html .= '</ul>';
		}

		$html .= '</div></div></li></ul></div>';

		return $html;
	}

	/**
	 * Hand the strip to the theme: strip chrome off, move into the leftmost
	 * visible top bar column. Falls back to the full top bar row, then to
	 * staying above the header - nothing breaks if the theme changes.
	 */
	public function assets() {
		if ( is_admin() || ! $this->inherit_theme() ) {
			return;
		}

		wp_register_style( 'kalima-topbar', false, array(), null );
		wp_enqueue_style( 'kalima-topbar' );

		/*
		 * The strip prints as a direct body child and only reaches its
		 * header place when the move script runs: hiding it with a body-
		 * child selector (printed here, in <head>, before the first paint)
		 * removes the brief flash above the header for good. Once moved the
		 * strip is a descendant again and the rule stops matching.
		 */
		$css = '.kalima-topbar{display:contents !important;}'
			. 'body > .kalima-topbar{display:none !important;}';

		if ( $this->globe_mode() ) {
			/*
			 * The theme's own icon font and glyph - the wireframe EARTH
			 * globe (U+E94A, .xts-i-global) the header builder uses -
			 * served under a plugin-prefixed family name so nothing else
			 * can collide with it.
			 *
			 * The order rules are the visual guarantee that the globe sits
			 * AFTER the cart: a late theme/ajax pass can reshuffle the
			 * cart node, and columns are flex - the nav inside the
			 * display:contents strip is the real flex item, so ordering it
			 * last pins the globe behind the cart no matter what moves in
			 * the DOM.
			 */
			$font  = get_template_directory_uri() . '/inc/admin/assets/fonts/xts-icon-font.woff2';
			$css .= '@font-face{font-family:"itn-kalima-icons";font-style:normal;font-weight:400;font-display:swap;src:url(' . esc_url( $font ) . ') format("woff2");}'
				. '.itn-kalima-globe{font-family:"itn-kalima-icons";font-weight:400;font-style:normal;font-size:18px;line-height:1;display:inline-block;vertical-align:middle;}'
				. '.itn-kalima-globe::before{content:"\e94a";}'
				. '.whb-column .kalima-topbar > .wd-header-nav{order:9;}'
				. '.whb-column .kalima-topbar{order:9;}';
		}

		wp_add_inline_style( 'kalima-topbar', $css );

		/*
		 * The move script prints straight from wp_footer at priority 5 -
		 * the theme's own footer scripts print at 20 - so the strip is
		 * already inside the top bar column while the document still
		 * parses. The theme menu JS then initialises the moved navigation
		 * like every other menu on the page, which is what binds the
		 * dropdowns.
		 */
		add_action( 'wp_footer', array( $this, 'print_move' ), 5 );
	}

	/**
	 * Move + click-guard script, printed early in the footer.
	 *
	 * In globe mode the strip lands directly AFTER the first visible cart
	 * (the owner wants the globe behind the cart) - the globe-mode CSS
	 * order rules pin that visual order even if a late theme pass moves
	 * the cart node around.
	 *
	 * The guard stops a trigger link that points at the very same URL from
	 * reloading the page (a state indicator, not a destination) - that
	 * reload used to jump the page up and back. Real destinations, the
	 * dropdown items, keep working normally.
	 */
	public function print_move() {
		$mode = $this->globe_mode() ? 'globe' : 'topbar';

		$move = <<<'KALJS'
(function(){
  var mode = '__KALIMA_MODE__';
  var run = function(){
    try {
      var bar = document.querySelector('.kalima-topbar');
      if (!bar || bar.dataset.kalimaMoved) { return; }
      var target = null, ref = null;
      if (mode === 'globe') {
        var carts = document.querySelectorAll('.wd-header-cart');
        for (var i = 0; i < carts.length; i++) {
          if (carts[i].offsetParent !== null) { target = carts[i].parentNode; ref = carts[i].nextSibling; break; }
        }
        if (!target && carts.length) { target = carts[0].parentNode; ref = carts[0].nextSibling; }
        if (!target) {
          var allCols = document.querySelectorAll('.whb-column');
          for (var n = allCols.length - 1; n >= 0; n--) {
            if (allCols[n].offsetParent !== null && allCols[n].getBoundingClientRect().width > 10) { target = allCols[n]; break; }
          }
        }
      }
      if (!target) {
        var cols = document.querySelectorAll('.whb-row.whb-top-bar .whb-column');
        for (var j = 0; j < cols.length; j++) {
          var c = cols[j];
          if (c.offsetParent !== null && c.getBoundingClientRect().width > 10) { target = c; break; }
        }
        if (!target) { target = document.querySelector('.whb-row.whb-top-bar'); }
      }
      if (!target) { return; }
      if (ref) { target.insertBefore(bar, ref); } else { target.insertBefore(bar, target.firstChild); }
      bar.dataset.kalimaMoved = '1';
      bar.addEventListener('click', function(e){
        var a = (e.target && e.target.closest) ? e.target.closest('a.woodmart-nav-link') : null;
        if (!a || !a.parentElement || !a.parentElement.classList.contains('menu-item-has-children')) { return; }
        if (a.href === window.location.href) { e.preventDefault(); }
      });
    } catch (e) {}
  };
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', run); } else { run(); }
})();
KALJS;

		$move = str_replace( '__KALIMA_MODE__', $mode, $move );

		echo '<script id="kalima-topbar-move">' . $move . '</script>';
	}

	/**
	 * Switching requests: ?kalima_currency= and ?kalima_country= set the
	 * cookies (mirroring the multi-currency plugin storage) and bounce to the
	 * clean URL, so the links work without any JavaScript.
	 */
	public function pick_requests() {
		if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		if ( isset( $_GET['kalima_currency'] ) ) {
			$code  = strtoupper( sanitize_text_field( wp_unslash( $_GET['kalima_currency'] ) ) );
			$codes = $this->currency_codes();

			if ( '' !== $code && ( empty( $codes ) || in_array( $code, $codes, true ) ) ) {
				setcookie( 'wpcmc_currency', $code, time() + 30 * DAY_IN_SECONDS, '/' );
				$_COOKIE['wpcmc_currency'] = $code;
				$this->redirect_clean( 'kalima_currency' );
			}
		}

		if ( isset( $_GET['kalima_country'] ) ) {
			$cc = strtoupper( sanitize_text_field( wp_unslash( $_GET['kalima_country'] ) ) );

			if ( preg_match( '#^[A-Z]{2}$#', $cc ) ) {
				setcookie( 'kalima_country', $cc, time() + 30 * DAY_IN_SECONDS, '/' );
				$_COOKIE['kalima_country'] = $cc;
				$this->redirect_clean( 'kalima_country' );
			}
		}
	}

	/**
	 * Redirect to the same page without the switching parameter.
	 *
	 * @param string $param Query parameter to drop.
	 */
	private function redirect_clean( $param ) {
		$uri     = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$redirect = remove_query_arg( $param, $uri );

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * The current page without any switching parameters.
	 *
	 * @return string
	 */
	private function same_url() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';

		return remove_query_arg( array( 'kalima_currency', 'kalima_country' ), home_url( $uri ) );
	}

	/**
	 * Currencies the shop actually sells in.
	 *
	 * Reads the WPC Multi Currency option when present; falls back to the
	 * store currency so the group simply hides on single-currency shops.
	 *
	 * @return string[]
	 */
	private function currency_codes() {
		$codes = array();
		$all   = get_option( 'wpcmc_currencies', null );

		if ( is_array( $all ) ) {
			foreach ( $all as $c ) {
				if ( is_array( $c ) && ! empty( $c['code'] ) && empty( $c['disabled'] ) ) {
					$active = isset( $c['active'] ) ? $c['active'] : '1';

					if ( '1' === (string) $active || 1 === $active ) {
						$codes[] = strtoupper( (string) $c['code'] );
					}
				} elseif ( is_string( $c ) ) {
					$codes[] = strtoupper( $c );
				}
			}
		}

		if ( empty( $codes ) ) {
			$store = get_option( 'woocommerce_currency', '' );

			if ( $store ) {
				$codes[] = strtoupper( (string) $store );
			}
		}

		return array_values( array_unique( $codes ) );
	}

	/**
	 * The currency the visitor is shopping in.
	 *
	 * @param string[] $codes Known currencies.
	 * @return string
	 */
	private function current_currency( $codes = null ) {
		$codes = null === $codes ? $this->currency_codes() : $codes;

		if ( ! empty( $_COOKIE['wpcmc_currency'] ) ) {
			$cookie = strtoupper( sanitize_text_field( wp_unslash( $_COOKIE['wpcmc_currency'] ) ) );

			if ( '' !== $cookie && ( empty( $codes ) || in_array( $cookie, $codes, true ) ) ) {
				return $cookie;
			}
		}

		$store = get_option( 'woocommerce_currency', '' );

		return $store ? strtoupper( (string) $store ) : '';
	}

	/**
	 * Visitor country from the Cloudflare header.
	 *
	 * @return string Two-letter code or empty string.
	 */
	private function visitor_country() {
		if ( empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
			return '';
		}

		$cc = strtoupper( trim( (string) wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) );

		if ( ! preg_match( '#^[A-Z]{2}$#', $cc ) || in_array( $cc, array( 'XX', 'T1' ), true ) ) {
			return '';
		}

		return $cc;
	}

	/**
	 * The country the visitor is set to: choice cookie, then detection, then
	 * the store base country.
	 *
	 * @param string $detected Cloudflare country (may be empty).
	 * @return string
	 */
	private function current_country( $detected = '' ) {
		if ( ! empty( $_COOKIE['kalima_country'] ) ) {
			$cc = strtoupper( sanitize_text_field( wp_unslash( $_COOKIE['kalima_country'] ) ) );

			if ( preg_match( '#^[A-Z]{2}$#', $cc ) ) {
				return $cc;
			}
		}

		if ( '' !== $detected ) {
			return $detected;
		}

		$base = get_option( 'woocommerce_default_country', '' );
		$base = is_string( $base ) ? strtoupper( explode( ':', $base )[0] ) : '';

		return preg_match( '#^[A-Z]{2}$#', $base ) ? $base : '';
	}

	/**
	 * Countries the shop ships to, gathered from the WooCommerce shipping
	 * zones (including the rest-of-world zone when it is restricted).
	 *
	 * @return string[]
	 */
	private function shipping_country_codes() {
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return array();
		}

		$codes = array();

		try {
			$zones = WC_Shipping_Zones::get_zones();

			foreach ( $zones as $zone ) {
				foreach ( $zone['zone_locations'] as $loc ) {
					if ( 'country' === $loc->type ) {
						$codes[] = strtoupper( $loc->code );
					}
				}
			}

			$rest = WC_Shipping_Zones::get_zone( 0 );

			foreach ( $rest->get_locations() as $loc ) {
				if ( 'country' === $loc->type ) {
					$codes[] = strtoupper( $loc->code );
				}
			}
		} catch ( Exception $e ) {
			return array();
		}

		return array_values( array_unique( $codes ) );
	}

	/**
	 * Country display names from WooCommerce when available.
	 *
	 * @return array code => name.
	 */
	private function country_names() {
		if ( function_exists( 'WC' ) && WC() && WC()->countries ) {
			$names = WC()->countries->get_countries();

			if ( is_array( $names ) && $names ) {
				return $names;
			}
		}

		return array();
	}

	/**
	 * Flag emoji for a country code.
	 *
	 * @param string $cc Two-letter code.
	 * @return string
	 */
	private function flag_emoji( $cc ) {
		if ( ! preg_match( '#^[A-Z]{2}$#', (string) $cc ) ) {
			return '';
		}

		$cc = strtolower( $cc );

		return mb_chr( 0x1F1E6 + ord( $cc[0] ) - 97, 'UTF-8' )
			. mb_chr( 0x1F1E6 + ord( $cc[1] ) - 97, 'UTF-8' );
	}

	/**
	 * Flag + name for a country code.
	 *
	 * @param string $cc    Two-letter code.
	 * @param array  $names code => name map.
	 * @return string
	 */
	private function country_label( $cc, $names ) {
		if ( '' === (string) $cc ) {
			return '';
		}

		$name = isset( $names[ $cc ] ) ? $names[ $cc ] : $cc;

		return trim( $this->flag_emoji( $cc ) . ' ' . $name );
	}
}
