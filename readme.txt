=== Kalima AI Multilingual ===
Contributors: Magdi Taha — DeMug
Tags: multilingual, translation, ai, gemini, woocommerce
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPL-2.0-or-later

Multi-language publishing with AI translation. Pages, posts, products, categories and menus.

== Description ==

Kalima AI Multilingual makes a WordPress site multilingual without any external
subscription. Every language version of a piece of content is a real WordPress
object — a real post, a real term — linked to the others by a shared
translation-group id.

That design choice matters:

* Permalinks, SEO plugins, the REST API, WooCommerce, and every other plugin
  keep working, because each translation is something WordPress already knows
  how to handle.
* Nothing is stored in a single blob that only this plugin can read.
* The source language is never modified. Translations are copies.

The translation itself comes from an AI provider you choose: Google Gemini,
OpenAI, DeepSeek, Anthropic Claude, or DeepL. A built-in **Mock** provider
lets you prove the whole pipeline works before you spend a cent on an API.

== Safety ==

Markup is never handed to the model. The tokenizer cuts each string into the
fragments a human actually reads, and only those go to the provider. Tags,
attributes, HTML entities (`&amp;`, `&#8217;`), WordPress shortcodes
(`[gallery id="12"]`), URLs, script and style blocks are structurally
incapable of being damaged, because the model never sees them.

Leading and trailing whitespace is kept outside the fragment, so it cannot be
lost or duplicated by a translation.

If a provider returns the wrong number of fragments, the original text is kept
instead of being mis-assembled.

== Usage ==

1. Settings → Languages
   * Pick the source language and tick every language to publish in.
   * Choose a provider and paste the API key.
   * Press "Test connection" before anything else.

2. Translate
   * "Scan for missing translations" finds pages, posts, products and product
     categories that have no version in each target language.
   * "Start translating" works through them in batches, with a progress bar,
     a live log, and a hard cap on API calls so a mis-click cannot spend your
     whole budget.

3. Single items
   * The Pages, Posts, Products and Product categories screens each gained one
     column per language. Click a "+ XX" badge to translate just that item.

4. Showing the switcher
   * Shortcode: `[kalima_switcher]`
   * Or `[kalima_switcher show_flags="no"]`
   * Or the "Language Switcher (Kalima AI Multilingual)" widget.
   * Or turn on automatic placement in Settings → Languages.

== URLs ==

The source language lives at the site root. Every other language gets a prefix:

    https://example.com/          source language
    https://example.com/ar/       Arabic
    https://example.com/de/       German

The prefix is removed from the request before WordPress runs its own rewrite
matching, so existing permalinks, product pages and category pages resolve
exactly as they did before.

== Translating out of the source ==

Translations are always generated from the source version, never from another
translation. A German page copied from an Arabic one would carry Arabic
structure and Arabic errors with it; this way every language is translated from
the one authoritative original.

== What is not translated ==

* The wp-admin interface. WordPress translates that itself (Settings ->
  General -> Site Language), and this plugin never touches it. The plugin's
  "source language" only decides which content version lives at the site
  root; it is not the dashboard language and does not change it.
* Images, files, and any other media.
* Theme and plugin strings. WordPress language packs handle those; this
  plugin ships no .po files and translates none of them itself.
* Page templates, menu order, and taxonomies other than the ones listed.
* Product prices, SKUs, stock, and other numeric metadata.

== Uninstalling ==

Uninstall removes only this plugin's options and its two meta keys. It never
deletes a post or a term, because every translation is real published content.

== Changelog ==

= 1.2.0 =
* Improved: the globe icon now prints the theme's real wireframe earth glyph
  (U+E94A, .xts-i-global) instead of the interim translate symbol that rendered
  nothing in the theme's icon font.
* Improved: in globe place the strip is moved directly AFTER the first visible
  header cart, and a CSS order rule pins that visual order even if a late
  theme pass reshuffles the cart node.
* Fixed: the language strip no longer flashes above the header on first paint —
  a body-child-only hide rule ships in the head and stops matching the moment
  the strip reaches its header place.
* Added: automatic update checks from the plugin's own GitHub repository
  (Plugin Update Checker library, bundled). New releases show up on the
  Plugins screen exactly like a plugin from the official directory.

= 1.1.0 =
* Added: Top bar (theme style) settings — print language, currency and country
  dropdowns in the theme's own menu markup (WoodMart header element structure),
  with one checkbox per dropdown and one for theme-native rendering.
* Added: Globe place — one icon beside the header cart (the theme's own
  translate glyph from its icon font) whose hover dropdown stacks the
  languages, currencies and countries; the top bar stays empty. Picked in the
  Place setting, same checkboxes decide the groups.
* Added: Currency dropdown reads the active multi-currency currencies and
  switches through the wpcmc_currency cookie; the visitor's currency and
  country default from the Cloudflare country header, a manual pick is kept
  for 30 days.
* Added: Country dropdown lists the WooCommerce shipping zones countries,
  with the detected visitor country on the trigger.
* Fixed: the strip is moved from wp_footer priority 5, before the theme's own
  footer scripts, so the theme menu JS initialises the moved navigation and
  dropdowns bind; a same-URL trigger click no longer reloads the page.

= 1.0.2 =
* Added: "No translation yet" option — a language with nothing published in it serves the English site instead of a 404. On by default.
* Added: Switcher position now lists every place the active theme actually offers — menu locations, the menus themselves, and widget areas — and prints inside the one you pick.

= 1.0.1 =
* Corrected authorship metadata to "Magdi Taha — DeMug".
* Removed the site-specific Plugin URI.

= 1.0.0 =
* First release. Translation sets for pages, posts, products, categories and
  tags. Five AI providers plus a mock. Bulk runner with progress and cost
  caps. Per-language columns. Frontend switcher by shortcode, widget, or
  automatic placement.
