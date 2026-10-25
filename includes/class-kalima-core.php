<?php
/**
 * Translation sets — the heart of the plugin.
 *
 * Model: every language version of a piece of content is a real WordPress
 * object (post or term) carrying two meta values — a shared translation-group id
 * and its own language. That keeps permalinks, SEO, queries, REST and every
 * plugin that reads `get_post()` working untouched, which a "store every
 * language in one blob" design cannot offer.
 *
 * @package KALIMA
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates, links and reads translation groups.
 */
class KALIMA_Core {

	/** Meta key holding the shared translation-group id. */
	const TRID = '_kalima_trid';

	/** Meta key holding an object's own language. */
	const LANG = '_kalima_lang';

	/** Meta flagging posts the plugin created (never the author's originals). */
	const CLONE = '_kalima_clone';

	/** @var KALIMA_Languages */
	private $languages;

	/** @var KALIMA|null Injected lazily to avoid a constructor cycle. */
	private $kalima;

	/**
	 * @param KALIMA_Languages $languages Language registry.
	 */
	public function __construct( KALIMA_Languages $languages ) {
		$this->languages = $languages;
	}

	/**
	 * Inject the plugin root once it exists.
	 *
	 * @param KALIMA $kalima Plugin instance.
	 */
	public function set_plugin( KALIMA $kalima ) {
		$this->kalima = $kalima;
	}

	/**
	 * Hooks that keep new content inside a translation set.
	 */
	public function init() {
		add_action( 'save_post', array( $this, 'maybe_assign_trid' ), 20, 2 );
		add_action( 'edited_product_cat', array( $this, 'maybe_assign_term_trid' ), 20, 1 );
		add_action( 'created_product_cat', array( $this, 'maybe_assign_term_trid' ), 20, 1 );
		add_action( 'delete_post', array( $this, 'cleanup_after_delete' ), 10, 1 );
	}

	/* ---------------------------------------------------------------------
	 * Posts
	 * ------------------------------------------------------------------ */

	/**
	 * Content types that take part in translation sets.
	 *
	 * @return string[]
	 */
	public function translatable_post_types() {
		$types = array( 'post', 'page', 'product' );

		/**
		 * Let other plugins add types (portfolio, cms_block …).
		 *
		 * @param string[] $types Post types.
		 */
		return apply_filters( 'kalima_translatable_post_types', $types );
	}

	/**
	 * The translation-group id of a post.
	 *
	 * @param int $post_id Post id.
	 * @return int 0 when the post is not in a set yet.
	 */
	public function get_trid( $post_id ) {
		return (int) get_post_meta( $post_id, self::TRID, true );
	}

	/**
	 * The language a post was authored in.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	public function get_lang( $post_id ) {
		$lang = get_post_meta( $post_id, self::LANG, true );

		return $lang ? $lang : $this->default_lang();
	}

	/**
	 * Language the site treats as the source of truth.
	 *
	 * @return string
	 */
	public function default_lang() {
		if ( $this->kalima ) {
			return (string) $this->kalima->get( 'default_lang', 'en' );
		}

		return 'en';
	}

	/**
	 * Force a post into a translation group.
	 *
	 * @param int $post_id Post id.
	 * @param int $trid    Group id.
	 * @return int The group id.
	 */
	public function set_trid( $post_id, $trid ) {
		$trid = (int) $trid;

		if ( $trid > 0 ) {
			update_post_meta( $post_id, self::TRID, $trid );
		}

		return $trid;
	}

	/**
	 * Give a post a group id if it does not have one yet.
	 *
	 * @param int $post_id Post id.
	 * @return int Group id.
	 */
	public function ensure_trid( $post_id ) {
		$trid = $this->get_trid( $post_id );

		if ( $trid > 0 ) {
			return $trid;
		}

		// An existing sibling in the default language may already own a group.
		$trid = $this->new_trid();
		$this->set_trid( $post_id, $trid );

		if ( ! get_post_meta( $post_id, self::LANG, true ) ) {
			update_post_meta( $post_id, self::LANG, $this->default_lang() );
		}

		return $trid;
	}

	/**
	 * Fresh, unused group id.
	 *
	 * @return int
	 */
	private function new_trid() {
		global $wpdb;

		$max = (int) $wpdb->get_var(
			"SELECT MAX(CAST(meta_value AS UNSIGNED)) FROM {$wpdb->postmeta} WHERE meta_key = '" . self::TRID . "'"
		);

		return $max + 1;
	}

	/**
	 * Every language version of a post, keyed by language code.
	 *
	 * @param int $post_id Post id.
	 * @return array<string,int>
	 */
	public function get_translations( $post_id ) {
		$trid = $this->get_trid( $post_id );

		if ( $trid <= 0 ) {
			return array( $this->get_lang( $post_id ) => (int) $post_id );
		}

		return $this->get_group( $trid );
	}

	/**
	 * All posts sharing a group id.
	 *
	 * @param int $trid Group id.
	 * @return array<string,int> Language code => post id.
	 */
	public function get_group( $trid ) {
		global $wpdb;

		$trid = (int) $trid;

		if ( $trid <= 0 ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %d",
				self::TRID,
				$trid
			),
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$post_id = (int) $row['post_id'];

			if ( 'trash' === get_post_status( $post_id ) ) {
				continue;
			}

			$lang = get_post_meta( $post_id, self::LANG, true );

			if ( ! $lang ) {
				// Recover from a meta that was removed by hand.
				$first = $this->first_sibling( $trid, $post_id );
				$lang  = $first ? $first : $this->default_lang();
				update_post_meta( $post_id, self::LANG, $lang );
			}

			$out[ $lang ] = $post_id;
		}

		return $out;
	}

	/**
	 * Another post in the same group — used to re-derive a lost language.
	 *
	 * @param int $trid    Group id.
	 * @param int $exclude Post to ignore.
	 * @return string|null
	 */
	private function first_sibling( $trid, $exclude ) {
		foreach ( $this->get_group_raw( $trid ) as $post_id ) {
			if ( $post_id === (int) $exclude ) {
				continue;
			}

			$lang = get_post_meta( $post_id, self::LANG, true );

			if ( $lang ) {
				return $lang;
			}
		}

		return null;
	}

	/**
	 * Raw group membership without any recovery logic.
	 *
	 * @param int $trid Group id.
	 * @return int[]
	 */
	private function get_group_raw( $trid ) {
		global $wpdb;

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %d",
				self::TRID,
				(int) $trid
			)
		);

		return array_map( 'intval', (array) $rows );
	}

	/**
	 * Post id for one specific language of a group.
	 *
	 * @param int    $trid Group id.
	 * @param string $lang Language code.
	 * @return int 0 when missing.
	 */
	public function get_translation( $trid, $lang ) {
		$group = $this->get_group( $trid );

		return isset( $group[ $lang ] ) ? (int) $group[ $lang ] : 0;
	}

	/**
	 * Translation status for every enabled language.
	 *
	 * @param int $post_id Post id.
	 * @return array<string,string> Language code => 'source'|'exists'|'missing'.
	 */
	public function status_map( $post_id ) {
		$group   = $this->get_translations( $post_id );
		$source  = $this->get_lang( $post_id );
		$enabled = $this->kalima ? (array) $this->kalima->get( 'enabled_langs', array( 'en' ) ) : array( 'en' );

		$out = array();

		foreach ( $enabled as $code ) {
			if ( $code === $source ) {
				$out[ $code ] = 'source';
			} elseif ( isset( $group[ $code ] ) && $group[ $code ] > 0 ) {
				$out[ $code ] = 'exists';
			} else {
				$out[ $code ] = 'missing';
			}
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Creating a translation
	 * ------------------------------------------------------------------ */

	/**
	 * Duplicate a post into another language and translate it.
	 *
	 * @param int    $post_id   Source post.
	 * @param string $lang      Target language.
	 * @param bool   $translate Run the AI over the copy.
	 * @return int|WP_Error New post id.
	 */
	public function create_post_translation( $post_id, $lang, $translate = true ) {
		$source = get_post( $post_id );

		if ( ! $source ) {
			return new WP_Error( 'kalima_no_source', __( 'Source post not found.', 'kalima' ) );
		}

		$existing = $this->get_translation( $this->get_trid( $post_id ), $lang );

		if ( $existing > 0 ) {
			return $existing;
		}

		$from = $this->get_lang( $post_id );

		if ( $from === $lang ) {
			return new WP_Error( 'kalima_same_lang', __( 'Source and target languages are identical.', 'kalima' ) );
		}

		$new_id = wp_insert_post(
			array(
				'post_title'     => $source->post_title,
				'post_name'      => $source->post_name,
				'post_content'   => $source->post_content,
				'post_excerpt'   => $source->post_excerpt,
				'post_status'    => 'publish' === $source->post_status ? 'publish' : $source->post_status,
				'post_type'      => $source->post_type,
				'post_author'    => $source->post_author,
				'post_parent'    => $source->post_parent,
				'comment_status' => $source->comment_status,
				'ping_status'    => $source->ping_status,
				'menu_order'     => $source->menu_order,
				'to_ping'        => $source->to_ping,
				'pinged'         => $source->pinged,
				'post_password'  => $source->post_password,
			),
			true
		);

		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		$this->copy_meta( $post_id, $new_id );
		$this->copy_terms( $post_id, $new_id );

		update_post_meta( $new_id, self::LANG, $lang );
		update_post_meta( $new_id, self::CLONE, 1 );

		$trid = $this->get_trid( $post_id );

		if ( $trid <= 0 ) {
			$trid = $this->ensure_trid( $post_id );
		}

		update_post_meta( $new_id, self::TRID, $trid );

		if ( $translate ) {
			$result = $this->translate_post_fields( $new_id, $from, $lang );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		/**
		 * Fires after a translation object is created.
		 *
		 * @param int    $new_id  New post id.
		 * @param int    $post_id Source post id.
		 * @param string $lang    Target language.
		 */
		do_action( 'kalima_post_translation_created', $new_id, $post_id, $lang );

		return $new_id;
	}

	/**
	 * Meta keys that must never be copied between language versions.
	 *
	 * @return string[]
	 */
	public function meta_blacklist() {
		return array(
			'_edit_lock',
			'_edit_last',
			'_wp_old_slug',
			'_wp_page_template',
			self::TRID,
			self::LANG,
			self::CLONE,
		);
	}

	/**
	 * Copy post meta, minus the keys that must stay per-object.
	 *
	 * @param int $from Source post.
	 * @param int $to   Target post.
	 */
	private function copy_meta( $from, $to ) {
		$meta = get_post_meta( $from );

		if ( ! is_array( $meta ) ) {
			return;
		}

		$skip = $this->meta_blacklist();

		// Elementor layout must be rebuilt per language, not shared.
		$skip[] = '_elementor_data';
		$skip[] = '_elementor_edit_mode';
		$skip[] = '_elementor_version';

		foreach ( $meta as $key => $values ) {
			if ( in_array( $key, $skip, true ) || 0 === strpos( $key, '_kalima_' ) ) {
				continue;
			}

			delete_post_meta( $to, $key );

			foreach ( (array) $values as $value ) {
				add_post_meta( $to, $key, maybe_unserialize( $value ) );
			}
		}
	}

	/**
	 * Copy term relationships (categories, tags, brands, attributes).
	 *
	 * @param int $from Source post.
	 * @param int $to   Target post.
	 */
	private function copy_terms( $from, $to ) {
		$taxonomies = get_object_taxonomies( get_post_type( $from ) );

		foreach ( (array) $taxonomies as $taxonomy ) {
			if ( 'post_format' === $taxonomy ) {
				continue;
			}

			$terms = wp_get_post_terms( $from, $taxonomy, array( 'fields' => 'ids' ) );

			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			$mapped = array();

			foreach ( $terms as $term_id ) {
				$mapped[] = $this->translated_term_id( $term_id ) ? : $term_id;
			}

			wp_set_object_terms( $to, $mapped, $taxonomy, false );
		}
	}

	/**
	 * Run the AI over a freshly created translation.
	 *
	 * @param int    $post_id Translation post.
	 * @param string $from    Source language.
	 * @param string $to      Target language.
	 * @return true|WP_Error
	 */
	public function translate_post_fields( $post_id, $from, $to ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'kalima_no_post', __( 'Post not found.', 'kalima' ) );
		}

		$engine = $this->plugin()->engine;

		// 1. Title.
		$title = $engine->translate_one( $post->post_title, $from, $to );

		if ( ! is_wp_error( $title ) && '' !== trim( $title ) ) {
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_title'   => $title,
					'post_name'    => sanitize_title( $title ),
				)
			);
		}

		// 2. Body — tokenizer keeps every tag and shortcode intact.
		$content = $engine->translate_content( $post->post_content, $from, $to );

		$changes = array( 'ID' => $post_id );

		if ( ! is_wp_error( $content ) ) {
			$changes['post_content'] = $content;
		}

		// 3. Excerpt / short description.
		$excerpt = $engine->translate_content( $post->post_excerpt, $from, $to );

		if ( ! is_wp_error( $excerpt ) ) {
			$changes['post_excerpt'] = $excerpt;
		}

		wp_update_post( $changes );

		// 4. Product-specific short description lives in meta too.
		if ( 'product' === $post->post_type ) {
			$this->translate_product_meta( $post_id, $from, $to );
		}

		// 5. Terms.
		$this->translate_term_relationships( $post_id, $from, $to );

		return true;
	}

	/**
	 * Translate the handful of WooCommerce meta that holds prose.
	 *
	 * @param int    $post_id Product id.
	 * @param string $from    Source language.
	 * @param string $to      Target language.
	 */
	private function translate_product_meta( $post_id, $from, $to ) {
		$engine = $this->plugin()->engine;

		foreach ( array( '_purchase_note' ) as $key ) {
			$value = get_post_meta( $post_id, $key, true );

			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				continue;
			}

			$translated = $engine->translate_content( $value, $from, $to );

			if ( ! is_wp_error( $translated ) ) {
				update_post_meta( $post_id, $key, $translated );
			}
		}
	}

	/**
	 * Create translated versions of the terms a post belongs to, then attach.
	 *
	 * @param int    $post_id Post id.
	 * @param string $from    Source language.
	 * @param string $to      Target language.
	 */
	public function translate_term_relationships( $post_id, $from, $to ) {
		$post      = get_post( $post_id );
		$taxonomies = get_object_taxonomies( $post->post_type );

		foreach ( (array) $taxonomies as $taxonomy ) {
			if ( 'post_format' === $taxonomy ) {
				continue;
			}

			$terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'all' ) );

			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			$translated = array();

			foreach ( $terms as $term ) {
				$new_term = $this->create_term_translation( $term->term_id, $to, $from );

				if ( ! is_wp_error( $new_term ) ) {
					$translated[] = (int) $new_term;
				} else {
					$translated[] = (int) $term->term_id;
				}
			}

			wp_set_object_terms( $post_id, $translated, $taxonomy, false );
		}
	}

	/* ---------------------------------------------------------------------
	 * Terms
	 * ------------------------------------------------------------------ */

	/**
	 * Translation-group id of a term.
	 *
	 * @param int $term_id Term id.
	 * @return int
	 */
	public function get_term_trid( $term_id ) {
		return (int) get_term_meta( $term_id, self::TRID, true );
	}

	/**
	 * Language a term was authored in.
	 *
	 * @param int $term_id Term id.
	 * @return string
	 */
	public function get_term_lang( $term_id ) {
		$lang = get_term_meta( $term_id, self::LANG, true );

		return $lang ? $lang : $this->default_lang();
	}

	/**
	 * Ensure a term belongs to a translation group.
	 *
	 * @param int $term_id Term id.
	 * @return int Group id.
	 */
	public function ensure_term_trid( $term_id ) {
		$trid = $this->get_term_trid( $term_id );

		if ( $trid > 0 ) {
			return $trid;
		}

		global $wpdb;

		$max = (int) $wpdb->get_var(
			"SELECT MAX(CAST(meta_value AS UNSIGNED)) FROM {$wpdb->termmeta} WHERE meta_key = '" . self::TRID . "'"
		);

		$trid = $max + 1;
		update_term_meta( $term_id, self::TRID, $trid );

		if ( ! get_term_meta( $term_id, self::LANG, true ) ) {
			update_term_meta( $term_id, self::LANG, $this->default_lang() );
		}

		return $trid;
	}

	/**
	 * All language versions of a term.
	 *
	 * @param int $term_id Term id.
	 * @return array<string,int>
	 */
	public function get_term_translations( $term_id ) {
		$trid = $this->get_term_trid( $term_id );

		if ( $trid <= 0 ) {
			return array( $this->get_term_lang( $term_id ) => (int) $term_id );
		}

		return $this->get_term_group( $trid );
	}

	/**
	 * All terms sharing a group id.
	 *
	 * @param int $trid Group id.
	 * @return array<string,int>
	 */
	public function get_term_group( $trid ) {
		global $wpdb;

		$trid = (int) $trid;

		if ( $trid <= 0 ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT term_id, meta_value FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value = %d",
				self::TRID,
				$trid
			),
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$term_id = (int) $row['term_id'];
			$lang    = get_term_meta( $term_id, self::LANG, true );

			if ( ! $lang ) {
				continue;
			}

			$out[ $lang ] = $term_id;
		}

		return $out;
	}

	/**
	 * Term id in another language.
	 *
	 * @param int    $term_id Source term.
	 * @param string $lang    Target language.
	 * @return int 0 when missing.
	 */
	public function get_term_translation( $term_id, $lang ) {
		$group = $this->get_term_translations( $term_id );

		return isset( $group[ $lang ] ) ? (int) $group[ $lang ] : 0;
	}

	/**
	 * Resolve a term id to its equivalent in the current language.
	 *
	 * Falls back to the original term so a category never silently disappears
	 * just because it has not been translated yet.
	 *
	 * @param int $term_id Term id.
	 * @return int
	 */
	public function translated_term_id( $term_id ) {
		$current = $this->current_language();

		if ( $current === $this->get_term_lang( $term_id ) ) {
			return (int) $term_id;
		}

		$translated = $this->get_term_translation( $term_id, $current );

		return $translated > 0 ? $translated : (int) $term_id;
	}

	/**
	 * Language currently being viewed.
	 *
	 * @return string
	 */
	public function current_language() {
		return $this->plugin()->frontend->current_language();
	}

	/**
	 * Create the translated twin of a term.
	 *
	 * @param int    $term_id Source term.
	 * @param string $lang    Target language.
	 * @param string $from    Source language (optional, derived when empty).
	 * @return int|WP_Error
	 */
	public function create_term_translation( $term_id, $lang, $from = '' ) {
		$existing = $this->get_term_translation( $term_id, $lang );

		if ( $existing > 0 ) {
			return $existing;
		}

		$term = get_term( $term_id );

		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'kalima_no_term', __( 'Term not found.', 'kalima' ) );
		}

		if ( '' === $from ) {
			$from = $this->get_term_lang( $term_id );
		}

		if ( $from === $lang ) {
			return new WP_Error( 'kalima_same_lang', __( 'Source and target languages are identical.', 'kalima' ) );
		}

		$engine     = $this->plugin()->engine;
		$name       = $engine->translate_one( $term->name, $from, $lang );
		$description = $engine->translate_content( $term->description, $from, $lang );

		if ( is_wp_error( $name ) ) {
			return $name;
		}

		$result = wp_insert_term(
			$name,
			$term->taxonomy,
			array(
				'slug'        => sanitize_title( $name ) . '-' . $lang,
				'description' => is_wp_error( $description ) ? $term->description : $description,
				'parent'      => $this->translated_term_id( (int) $term->parent ),
			)
		);

		if ( is_wp_error( $result ) ) {
			// A term with this slug already exists — adopt it rather than fail.
			if ( 'term_exists' === $result->get_error_code() && $result->get_error_data() ) {
				$adopted = (int) $result->get_error_data();
				$this->link_term( $adopted, $term_id, $lang );

				return $adopted;
			}

			return $result;
		}

		$new_id = (int) $result['term_id'];

		$this->link_term( $new_id, $term_id, $lang );

		// Carry across term meta that holds display data.
		$source_meta = get_term_meta( $term_id );

		if ( is_array( $source_meta ) ) {
			$skip = array( self::TRID, self::LANG, self::CLONE, 'order', 'display_type', 'product_count' );

			foreach ( $source_meta as $key => $values ) {
				if ( in_array( $key, $skip, true ) ) {
					continue;
				}

				foreach ( (array) $values as $value ) {
					add_term_meta( $new_id, $key, maybe_unserialize( $value ) );
				}
			}
		}

		return $new_id;
	}

	/**
	 * Attach a term to a translation group.
	 *
	 * @param int    $term_id   New term.
	 * @param int    $source_id Source term.
	 * @param string $lang      Language of the new term.
	 */
	private function link_term( $term_id, $source_id, $lang ) {
		$trid = $this->ensure_term_trid( $source_id );

		update_term_meta( $term_id, self::TRID, $trid );
		update_term_meta( $term_id, self::LANG, $lang );
		update_term_meta( $term_id, self::CLONE, 1 );
	}

	/**
	 * Give a term a group id on first save.
	 *
	 * @param int $term_id Term id.
	 */
	public function maybe_assign_term_trid( $term_id ) {
		if ( get_term_meta( $term_id, self::TRID, true ) ) {
			return;
		}

		$this->ensure_term_trid( $term_id );
	}

	/* ---------------------------------------------------------------------
	 * Bookkeeping
	 * ------------------------------------------------------------------ */

	/**
	 * Give newly published content a group id so translations can attach.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post object.
	 */
	public function maybe_assign_trid( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! in_array( $post->post_type, $this->translatable_post_types(), true ) ) {
			return;
		}

		if ( get_post_meta( $post_id, self::TRID, true ) ) {
			return;
		}

		// Only originals get a language of their own.
		if ( ! get_post_meta( $post_id, self::LANG, true ) ) {
			update_post_meta( $post_id, self::LANG, $this->default_lang() );
		}

		$this->ensure_trid( $post_id );
	}

	/**
	 * When a clone is deleted, its group must not keep a dangling reference.
	 *
	 * @param int $post_id Post id.
	 */
	public function cleanup_after_delete( $post_id ) {
		if ( ! get_post_meta( $post_id, self::CLONE, true ) ) {
			return;
		}

		delete_post_meta( $post_id, self::TRID );
		delete_post_meta( $post_id, self::LANG );
		delete_post_meta( $post_id, self::CLONE );
	}

	/**
	 * Plugin root, resolved at call time.
	 *
	 * @return KALIMA
	 */
	private function plugin() {
		if ( ! $this->kalima ) {
			$this->kalima = kalima();
		}

		return $this->kalima;
	}
}
