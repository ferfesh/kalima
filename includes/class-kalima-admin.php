<?php
/**
 * Admin: settings screen, per-language status columns and the bulk runner.
 *
 * @package KALIMA
 */

defined( 'ABSPATH' ) || exit;

/**
 * Everything that only happens inside wp-admin.
 */
class KALIMA_Admin {

	/** @var KALIMA */
	private $kalima;

	/**
	 * @param KALIMA $kalima Plugin instance.
	 */
	public function __construct( KALIMA $kalima ) {
		$this->kalima = $kalima;
	}

	/**
	 * Register admin hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );

		// Language status columns.
		add_filter( 'manage_posts_columns', array( $this, 'columns' ), 10, 2 );
		add_filter( 'manage_pages_columns', array( $this, 'columns' ), 10, 2 );
		add_filter( 'manage_product_posts_columns', array( $this, 'columns' ), 10, 2 );
		add_filter( 'manage_edit-product_cat_columns', array( $this, 'term_columns' ) );

		add_action( 'manage_posts_custom_column', array( $this, 'column_content' ), 10, 2 );
		add_action( 'manage_pages_custom_column', array( $this, 'column_content' ), 10, 2 );
		add_action( 'manage_product_posts_custom_column', array( $this, 'column_content' ), 10, 2 );
		add_action( 'manage_product_cat_custom_column', array( $this, 'term_column_content' ), 10, 3 );

		// AJAX endpoints.
		add_action( 'wp_ajax_kalima_test_key', array( $this, 'ajax_test_key' ) );
		add_action( 'wp_ajax_kalima_scan', array( $this, 'ajax_scan' ) );
		add_action( 'wp_ajax_kalima_translate_posts', array( $this, 'ajax_translate_posts' ) );
		add_action( 'wp_ajax_kalima_translate_terms', array( $this, 'ajax_translate_terms' ) );
	}

	/* ---------------------------------------------------------------------
	 * Menus and screens
	 * ------------------------------------------------------------------ */

	/**
	 * Two menus: one for options, one for running translations.
	 */
	public function menu() {
		add_options_page(
			__( 'Kalima AI Multilingual', 'kalima' ),
			__( 'Languages', 'kalima' ),
			'manage_options',
			'kalima',
			array( $this, 'render_settings' )
		);

		add_menu_page(
			__( 'Translate', 'kalima' ),
			__( 'Translate', 'kalima' ),
			'manage_options',
			'kalima-translate',
			array( $this, 'render_translate' ),
			'dashicons-translation',
			58
		);
	}

	/**
	 * Settings API registration.
	 */
	public function register_settings() {
		register_setting(
			'kalima',
			'kalima_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Clean up and coerce everything the form can send.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$all   = KALIMA_Languages::registry();

		$out = array();

		$out['default_lang'] = isset( $input['default_lang'] ) && isset( $all[ $input['default_lang'] ] )
			? $input['default_lang']
			: 'en';

		$enabled = array();

		if ( ! empty( $input['enabled_langs'] ) && is_array( $input['enabled_langs'] ) ) {
			foreach ( $input['enabled_langs'] as $code ) {
				if ( isset( $all[ $code ] ) ) {
					$enabled[] = $code;
				}
			}
		}

		if ( empty( $enabled ) ) {
			$enabled = array( $out['default_lang'] );
		}

		// The source language is always available.
		if ( ! in_array( $out['default_lang'], $enabled, true ) ) {
			array_unshift( $enabled, $out['default_lang'] );
		}

		$enabled = array_values( array_unique( $enabled ) );

		/*
		 * Honour the order set with the arrows on the settings screen.
		 *
		 * Without this the enabled list would come back in whatever order the
		 * checkboxes happen to sit in the DOM, and a saved site would silently
		 * fall back to registry order.
		 */
		$requested = isset( $input['lang_order'] ) ? (string) $input['lang_order'] : '';
		$requested = array_values( array_filter( array_map( 'trim', explode( ',', $requested ) ) ) );

		$ordered = array();

		foreach ( $requested as $code ) {
			if ( isset( $all[ $code ] ) && ! in_array( $code, $ordered, true ) ) {
				$ordered[] = $code;
			}
		}

		// Anything not named in the submitted order keeps its place, last.
		foreach ( $all as $code => $ignored ) {
			if ( ! in_array( $code, $ordered, true ) ) {
				$ordered[] = $code;
			}
		}

		/*
		 * The source language always leads the list. Every other language is a
		 * translation of it, so it is the one fixed point the arrows cannot
		 * move past — otherwise the switcher could open with a translation and
		 * hide the language the site is actually written in.
		 */
		$source = $out['default_lang'];

		$ordered = array_merge(
			array( $source ),
			array_values( array_diff( $ordered, array( $source ) ) )
		);

		$out['lang_order'] = $ordered;

		// Ticked languages, in that same order, source first.
		$ticked = array_values( array_intersect( $ordered, $enabled ) );

		$out['enabled_langs'] = array_values( array_unique( array_merge( array( $source ), $ticked ) ) );

		/*
		 * Switcher placement.
		 *
		 * Three shapes are legal: one of the three fixed positions, or a
		 * location the settings screen discovered from the active theme
		 * (`loc:`, `menu:`, `side:`). Anything else — a stale or mangled
		 * value — keeps whatever is already saved rather than switching the
		 * switcher off, so a saved site never silently loses its placement.
		 */
		$saved      = $this->kalima->settings();
		$existing   = isset( $saved['switcher_placement'] ) ? (string) $saved['switcher_placement'] : '';

		if ( '' === $existing ) {
			$existing = ! empty( $saved['auto_inject'] ) ? 'body' : 'off';
		}

		$placement = isset( $input['switcher_placement'] ) ? sanitize_text_field( (string) $input['switcher_placement'] ) : '';

		if ( ! $this->is_known_placement( $placement ) ) {
			$placement = $this->is_known_placement( $existing ) ? $existing : 'off';
		}

		$out['switcher_placement'] = $placement;

		// Kept in step with the dropdown so the legacy key never lies.
		$out['auto_inject'] = ( 'off' === $placement ) ? 0 : 1;

		// Language by visitor country.
		$out['geo_default'] = empty( $input['geo_default'] ) ? 0 : 1;

		$geo_action = isset( $input['geo_action'] ) ? sanitize_key( $input['geo_action'] ) : 'redirect';
		$out['geo_action'] = in_array( $geo_action, array( 'redirect', 'silent' ), true ) ? $geo_action : 'redirect';

		$fallback = isset( $input['geo_fallback'] ) ? sanitize_text_field( (string) $input['geo_fallback'] ) : '';

		// Blank means the source language; anything else must be a language we serve.
		$out['geo_fallback'] = ( isset( $all[ $fallback ] ) && $fallback !== $out['default_lang'] )
			? $fallback
			: '';

		/*
		 * No translation yet -> show the source language.
		 * Written out on every save: an absent key would fall back to the
		 * default in settings() and the box could never be unticked.
		 */
		$out['fallback_english'] = empty( $input['fallback_english'] ) ? 0 : 1;

		$providers  = array_keys( KALIMA_AI_Engine::providers() );
		$provider   = isset( $input['ai_provider'] ) ? sanitize_key( $input['ai_provider'] ) : 'gemini';
		$out['ai_provider'] = in_array( $provider, $providers, true ) ? $provider : 'gemini';

		$out['ai_key']   = isset( $input['ai_key'] ) ? trim( sanitize_text_field( $input['ai_key'] ) ) : '';
		$out['ai_model'] = isset( $input['ai_model'] ) ? sanitize_text_field( $input['ai_model'] ) : '';

		$out['units_per_call'] = isset( $input['units_per_call'] ) ? max( 1, min( 100, (int) $input['units_per_call'] ) ) : 40;
		$out['delay_ms']       = isset( $input['delay_ms'] ) ? max( 0, min( 5000, (int) $input['delay_ms'] ) ) : 350;
		$out['max_calls']      = isset( $input['max_calls'] ) ? max( 1, min( 2000, (int) $input['max_calls'] ) ) : 60;

		$out['preserve_terms'] = isset( $input['preserve_terms'] ) ? sanitize_textarea_field( $input['preserve_terms'] ) : '';

		$out['auto_draft']     = empty( $input['auto_draft'] ) ? 0 : 1;
		$out['link_new_posts'] = empty( $input['link_new_posts'] ) ? 0 : 1;
		$out['auto_inject']    = empty( $input['auto_inject'] ) ? 0 : 1;

		$out['switcher_label'] = isset( $input['switcher_label'] ) ? sanitize_text_field( $input['switcher_label'] ) : '';

		/*
		 * Top bar groups (language, currency, country) rendered in the
		 * theme's own menu markup. All four default ON; an unticked box
		 * saves a clean zero. The place is either the top bar itself or
		 * one globe icon beside the cart with the groups in its hover
		 * dropdown; anything else falls back to the top bar.
		 */
		$out['tb_place']         = ( isset( $input['tb_place'] ) && 'cart-globe' === $input['tb_place'] ) ? 'cart-globe' : 'topbar';
		$out['tb_inherit_theme'] = empty( $input['tb_inherit_theme'] ) ? 0 : 1;
		$out['tb_show_lang']     = empty( $input['tb_show_lang'] ) ? 0 : 1;
		$out['tb_show_currency'] = empty( $input['tb_show_currency'] ) ? 0 : 1;
		$out['tb_show_country']  = empty( $input['tb_show_country'] ) ? 0 : 1;

		// Never leave the key blank in the DB by accident — keep the old one.
		$stored = get_option( 'kalima_settings', array() );

		if ( '' === $out['ai_key'] && ! empty( $stored['ai_key'] ) ) {
			$out['ai_key'] = $stored['ai_key'];
		}

		$this->kalima->flush_cache();

		return $out;
	}

	/**
	 * Enqueue admin CSS/JS on our own screens only.
	 *
	 * @param string $hook Current admin page.
	 */
	public function assets( $hook ) {
		$relevant = in_array( $hook, array( 'settings_page_kalima', 'toplevel_page_kalima-translate' ), true )
			|| in_array( $GLOBALS['pagenow'], array( 'edit.php', 'edit.php?post_type=page' ), true );

		if ( ! $relevant ) {
			return;
		}

		wp_enqueue_style( 'kalima-admin', KALIMA_PLUGIN_URL . 'assets/admin.css', array(), KALIMA::asset_version( 'assets/admin.css' ) );

		if ( in_array( $hook, array( 'settings_page_kalima', 'toplevel_page_kalima-translate' ), true ) ) {
			wp_enqueue_script(
				'kalima-admin',
				KALIMA_PLUGIN_URL . 'assets/admin.js',
				array(),
				KALIMA::asset_version( 'assets/admin.js' ),
				true
			);

			wp_localize_script(
				'kalima-admin',
				'KALIMA',
				array(
					'ajax'   => admin_url( 'admin-ajax.php' ),
					'nonce'  => wp_create_nonce( 'kalima_ajax' ),
					'strings' => array(
						'running'    => __( 'Translating…', 'kalima' ),
						'done'       => __( 'Finished.', 'kalima' ),
						'call'       => __( 'API calls', 'kalima' ),
						'units'      => __( 'Text units', 'kalima' ),
						'chars'      => __( 'Characters', 'kalima' ),
						'fail'       => __( 'Failed', 'kalima' ),
						'confirmKey' => __( 'Replace the saved API key?', 'kalima' ),
					),
				)
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * Columns
	 * ------------------------------------------------------------------ */

	/**
	 * Add one column per enabled language.
	 *
	 * @param array    $columns Columns.
	 * @param \WP_Post $post    Current post.
	 * @return array
	 */
	public function columns( $columns, $post = null ) {
		$out = array();

		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;

			if ( 'title' === $key ) {
				foreach ( $this->kalima->active_languages() as $code => $data ) {
					$out[ 'kalima_' . $code ] = $data['flag'] . ' ' . strtoupper( $code );
				}
			}
		}

		return $out;
	}

	/**
	 * Render the per-language cells.
	 *
	 * @param string $column  Column id.
	 * @param int    $post_id Post id.
	 */
	public function column_content( $column, $post_id ) {
		if ( 0 !== strpos( $column, 'kalima_' ) ) {
			return;
		}

		$lang   = substr( $column, 5 );
		$status = $this->kalima->core->status_map( $post_id );

		if ( ! isset( $status[ $lang ] ) ) {
			return;
		}

		if ( 'source' === $status[ $lang ] ) {
			echo '<span class="kalima-badge kalima-badge--source">' . esc_html__( 'source', 'kalima' ) . '</span>';

			return;
		}

		if ( 'exists' === $status[ $lang ] ) {
			$group   = $this->kalima->core->get_translations( $post_id );
			$target  = $group[ $lang ];

			printf(
				'<a class="kalima-badge kalima-badge--exists" href="%s" title="%s">✓ %s</a>',
				esc_url( get_edit_post_link( $target ) ),
				esc_attr__( 'Open translation', 'kalima' ),
				esc_html( strtoupper( $lang ) )
			);

			return;
		}

		printf(
			'<button type="button" class="kalima-badge kalima-badge--missing" data-kalima-translate="%d" data-kalima-lang="%s">%s</button>',
			(int) $post_id,
			esc_attr( $lang ),
			esc_html( '+ ' . strtoupper( $lang ) )
		);
	}

	/**
	 * Language column on the category screen.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function term_columns( $columns ) {
		$out = array();

		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;

			if ( 'name' === $key ) {
				foreach ( $this->kalima->active_languages() as $code => $data ) {
					$out[ 'kalima_' . $code ] = $data['flag'] . ' ' . strtoupper( $code );
				}
			}
		}

		return $out;
	}

	/**
	 * Render category language cells.
	 *
	 * @param string $column  Column id.
	 * @param string $taxonomy Taxonomy.
	 * @param int    $term_id Term id.
	 */
	public function term_column_content( $column, $taxonomy, $term_id ) {
		if ( 0 !== strpos( $column, 'kalima_' ) ) {
			return;
		}

		$lang      = substr( $column, 5 );
		$source    = $this->kalima->core->get_term_lang( $term_id );
		$translated = $this->kalima->core->get_term_translation( $term_id, $lang );

		if ( $source === $lang ) {
			echo '<span class="kalima-badge kalima-badge--source">' . esc_html__( 'source', 'kalima' ) . '</span>';

			return;
		}

		if ( $translated > 0 ) {
			printf(
				'<a class="kalima-badge kalima-badge--exists" href="%s">✓ %s</a>',
				esc_url( get_edit_term_link( $translated ) ),
				esc_html( strtoupper( $lang ) )
			);

			return;
		}

		printf(
			'<button type="button" class="kalima-badge kalima-badge--missing" data-kalima-term="%d" data-kalima-lang="%s">%s</button>',
			(int) $term_id,
			esc_attr( $lang ),
			esc_html( '+ ' . strtoupper( $lang ) )
		);
	}

	/* ---------------------------------------------------------------------
	 * Switcher placement — what the theme offers
	 * ------------------------------------------------------------------ */

	/**
	 * Whether a stored placement is one this build understands.
	 *
	 * @param string $placement Placement value.
	 * @return bool
	 */
	private function is_known_placement( $placement ) {
		if ( in_array( $placement, array( 'off', 'topbar', 'body' ), true ) ) {
			return true;
		}

		return (bool) preg_match( '#^(loc|menu|side):[A-Za-z0-9_.-]+$#', (string) $placement );
	}

	/**
	 * Every place the switcher could be printed, discovered from the theme
	 * and the menus that exist right now.
	 *
	 * Nothing is hard-coded: the list is whatever `get_registered_nav_menus()`
	 * and the registered widget areas say the active theme supports, plus the
	 * menus themselves, because a header builder picks a menu by id and never
	 * mentions a location.
	 *
	 * @return array[] Each group: label => string, options => value => label.
	 */
	public function placement_options() {
		$groups = array();

		$groups[] = array(
			'label'   => __( 'Print automatically', 'kalima' ),
			'options' => array(
				'off'    => __( 'Do not print it automatically', 'kalima' ),
				'topbar' => __( 'Top bar, above the header', 'kalima' ),
				'body'   => __( 'Top of the page, under the header', 'kalima' ),
			),
		);

		// Menu locations the active theme registered.
		$locations = get_registered_nav_menus();

		if ( ! empty( $locations ) ) {
			$options = array();

			foreach ( $locations as $slug => $name ) {
				$options[ 'loc:' . $slug ] = $name;
			}

			$groups[] = array(
				'label'   => __( 'Menu location (from the active theme)', 'kalima' ),
				'options' => $options,
			);
		}

		// The menus themselves — what a header builder's menu element holds.
		$menus = wp_get_nav_menus();

		if ( ! empty( $menus ) ) {
			$options = array();

			foreach ( $menus as $menu ) {
				$label = $menu->name;

				$item_count = count( (array) wp_get_nav_menu_items( $menu->term_id ) );

				if ( $item_count ) {
					$label .= ' (' . sprintf(
						/* translators: %d: number of menu items. */
						_n( '%d item', '%d items', $item_count, 'kalima' ),
						$item_count
					) . ')';
				}

				$options[ 'menu:' . $menu->term_id ] = $label;
			}

			$groups[] = array(
				'label'   => __( 'Menu', 'kalima' ),
				'options' => $options,
			);
		}

		// Widget areas the active theme registered (footer columns, sidebars…).
		global $wp_registered_sidebars;

		if ( ! empty( $wp_registered_sidebars ) && is_array( $wp_registered_sidebars ) ) {
			$options = array();

			foreach ( $wp_registered_sidebars as $id => $sidebar ) {
				$name = isset( $sidebar['name'] ) && '' !== (string) $sidebar['name']
					? (string) $sidebar['name']
					: (string) $id;

				$options[ 'side:' . $id ] = $name;
			}

			if ( $options ) {
				$groups[] = array(
					'label'   => __( 'Widget area (from the active theme)', 'kalima' ),
					'options' => $options,
				);
			}
		}

		return $groups;
	}

	/**
	 * The saved placement, plus a labelled stand-in when the menu or area it
	 * names no longer exists — the choice is kept rather than thrown away.
	 *
	 * @param string   $placement Saved value.
	 * @param array[]  $groups    From placement_options().
	 * @return array[] The groups, possibly with one more option.
	 */
	private function placement_missing_option( $placement, $groups ) {
		if ( '' === $placement || ! $this->is_known_placement( $placement )
			|| in_array( $placement, array( 'off', 'topbar', 'body' ), true ) ) {
			return $groups;
		}

		foreach ( $groups as $group ) {
			if ( isset( $group['options'][ $placement ] ) ) {
				return $groups;
			}
		}

		$groups[] = array(
			'label'   => __( 'Saved choice (no longer available)', 'kalima' ),
			'options' => array( $placement => $placement ),
		);

		return $groups;
	}

	/* ---------------------------------------------------------------------
	 * Settings screen
	 * ------------------------------------------------------------------ */

	/**
	 * Options → Languages.
	 */
	public function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$s       = $this->kalima->settings();
		$all     = KALIMA_Languages::registry();
		$enabled = (array) $s['enabled_langs'];

		// Pre-dropdown saves only know the old checkbox.
		$placement = ( isset( $s['switcher_placement'] ) && '' !== (string) $s['switcher_placement'] )
			? (string) $s['switcher_placement']
			: ( ! empty( $s['auto_inject'] ) ? 'body' : 'off' );

		?>
		<div class="wrap kalima-wrap">
			<h1><?php esc_html_e( 'Kalima AI Multilingual', 'kalima' ); ?></h1>

			<form method="post" action="options.php">
				<?php settings_fields( 'kalima' ); ?>

				<h2><?php esc_html_e( 'Languages', 'kalima' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Tick every language the site should publish in. The source language cannot be removed.', 'kalima' ); ?></p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Source language', 'kalima' ); ?></th>
						<td>
							<select name="kalima_settings[default_lang]">
								<?php foreach ( $all as $code => $data ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $s['default_lang'], $code ); ?>>
										<?php echo esc_html( $data['flag'] . ' ' . $data['name'] . ' (' . $code . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Original content lives here. Every other language is generated from it.', 'kalima' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Languages shown on the site', 'kalima' ); ?></th>
						<td>
							<p class="description"><?php esc_html_e( 'Tick a language to show it in the switcher, untick it to hide it. Use the arrows to set the order visitors see. The source language is always shown and cannot be hidden.', 'kalima' ); ?></p>

							<?php
							// Prefer the explicit order saved on this screen; fall
							// back to the enabled list, then registry order.
							$ordered = array();

							$seeds = ( ! empty( $s['lang_order'] ) && is_array( $s['lang_order'] ) )
								? $s['lang_order']
								: (array) $enabled;

							foreach ( $seeds as $code ) {
								if ( isset( $all[ $code ] ) ) {
									$ordered[ $code ] = $all[ $code ];
								}
							}

							foreach ( $all as $code => $data ) {
								if ( ! isset( $ordered[ $code ] ) ) {
									$ordered[ $code ] = $data;
								}
							}
							?>

							<input type="hidden" name="kalima_settings[lang_order]" id="kalima_lang_order" value="<?php echo esc_attr( implode( ',', array_keys( $ordered ) ) ); ?>" />

							<div class="kalima-lang-grid" id="kalima-lang-grid">
								<?php foreach ( $ordered as $code => $data ) : ?>
									<?php $is_source = ( $code === $s['default_lang'] ); ?>
									<label class="kalima-lang-card<?php echo $is_source ? ' is-source' : ''; ?>" data-code="<?php echo esc_attr( $code ); ?>">
										<span class="kalima-lang-card__move">
											<button type="button" class="kalima-move" data-dir="up" aria-label="<?php esc_attr_e( 'Move up', 'kalima' ); ?>">&#9650;</button>
											<button type="button" class="kalima-move" data-dir="down" aria-label="<?php esc_attr_e( 'Move down', 'kalima' ); ?>">&#9660;</button>
										</span>
										<input type="checkbox" name="kalima_settings[enabled_langs][]" value="<?php echo esc_attr( $code ); ?>" <?php checked( in_array( $code, $enabled, true ) ); ?> <?php disabled( $is_source ); ?> />
										<span class="kalima-lang-card__flag"><?php echo esc_html( $data['flag'] ); ?></span>
										<span class="kalima-lang-card__name"><?php echo esc_html( $data['native'] ); ?></span>
										<span class="kalima-lang-card__meta">
											<?php
											echo esc_html(
												( $is_source ? __( 'source', 'kalima' ) : $data['name'] )
												. ' · ' . strtoupper( $code )
												. ' · ' . $data['dir']
											);
											?>
										</span>
									</label>
								<?php endforeach; ?>
							</div>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Switcher label', 'kalima' ); ?></th>
						<td>
							<input type="text" class="regular-text" name="kalima_settings[switcher_label]" value="<?php echo esc_attr( $s['switcher_label'] ); ?>" placeholder="<?php esc_attr_e( 'Language', 'kalima' ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="kalima_placement"><?php esc_html_e( 'Switcher position', 'kalima' ); ?></label></th>
						<td>
							<?php
							$groups = $this->placement_missing_option( $placement, $this->placement_options() );
							?>
							<select name="kalima_settings[switcher_placement]" id="kalima_placement">
								<?php foreach ( $groups as $group_index => $group ) : ?>
									<?php if ( 0 === $group_index ) : ?>
										<?php /* The fixed positions stay flat, as they always were. */ ?>
										<?php foreach ( $group['options'] as $value => $label ) : ?>
											<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $placement, $value ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									<?php else : ?>
										<optgroup label="<?php echo esc_attr( $group['label'] ); ?>">
											<?php foreach ( $group['options'] as $value => $label ) : ?>
												<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $placement, $value ); ?>><?php echo esc_html( $label ); ?></option>
											<?php endforeach; ?>
										</optgroup>
									<?php endif; ?>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Everything below the first group is discovered from the theme and the menus that exist right now — menu locations, the menus themselves (this is what a header builder holds), and widget areas. Pick one and the switcher is printed inside it. The top bar option is a full-width strip above the theme header; or drop the shortcode [kalima_switcher] wherever you prefer, or use the widget.', 'kalima' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'No translation yet', 'kalima' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="kalima_settings[fallback_english]" value="1" <?php checked( ! empty( $s['fallback_english'] ) ); ?> />
								<?php esc_html_e( 'Show English instead of an empty page', 'kalima' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'A visitor who picks a language with nothing published in it yet reads the English site — the address stays, the page still loads, and the switcher keeps their choice for when the translations arrive. Turn this off to serve only translated content, and 404 everywhere else.', 'kalima' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Top bar or globe (theme style)', 'kalima' ); ?></h2>
				<p class="description"><?php esc_html_e( 'When the switcher position is the top bar, Kalima can print language, currency and country exactly the way the theme prints its own menu - no custom design. The visitor currency and country are detected automatically from the Cloudflare country header, and a manual pick is kept for a month.', 'kalima' ); ?></p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Place', 'kalima' ); ?></th>
						<td>
							<label>
								<input type="radio" name="kalima_settings[tb_place]" value="topbar" <?php checked( isset( $s['tb_place'] ) ? $s['tb_place'] : 'topbar', 'topbar' ); ?> />
								<?php esc_html_e( 'Top bar, groups side by side', 'kalima' ); ?>
							</label><br />
							<label>
								<input type="radio" name="kalima_settings[tb_place]" value="cart-globe" <?php checked( isset( $s['tb_place'] ) ? $s['tb_place'] : 'topbar', 'cart-globe' ); ?> />
								<?php esc_html_e( 'One globe icon beside the cart, groups in its hover dropdown', 'kalima' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'The globe is the theme own translate icon. The visitor hovers it and the languages, currencies and countries stack inside one dropdown, so the top bar stays empty.', 'kalima' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Theme-native rendering', 'kalima' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="kalima_settings[tb_inherit_theme]" value="1" <?php checked( ! empty( $s['tb_inherit_theme'] ) ); ?> />
								<?php esc_html_e( 'Use the theme own menu markup and styles (recommended)', 'kalima' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'The switcher is printed as a normal theme menu with dropdowns and moved into the top bar left column. The Kalima stylesheet stays off in the header, so nothing clashes with the design.', 'kalima' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Show in the top bar', 'kalima' ); ?></th>
						<td>
							<label><input type="checkbox" name="kalima_settings[tb_show_lang]" value="1" <?php checked( ! empty( $s['tb_show_lang'] ) ); ?> /> <?php esc_html_e( 'Language dropdown', 'kalima' ); ?></label><br />
							<label><input type="checkbox" name="kalima_settings[tb_show_currency]" value="1" <?php checked( ! empty( $s['tb_show_currency'] ) ); ?> /> <?php esc_html_e( 'Currency dropdown (reads the active multi-currency currencies)', 'kalima' ); ?></label><br />
							<label><input type="checkbox" name="kalima_settings[tb_show_country]" value="1" <?php checked( ! empty( $s['tb_show_country'] ) ); ?> /> <?php esc_html_e( 'Country dropdown (shipping countries; visitor country on the trigger)', 'kalima' ); ?></label>
							<p class="description"><?php esc_html_e( 'The country list comes from the WooCommerce shipping zones - once zones are configured, the dropdown fills itself.', 'kalima' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Language by visitor country', 'kalima' ); ?></h2>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Detect the visitor', 'kalima' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="kalima_settings[geo_default]" value="1" <?php checked( $s['geo_default'], 1 ); ?> />
								<?php esc_html_e( 'Open the site in the language of the country the visitor is in', 'kalima' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Read from the Cloudflare CF-IPCountry header, with three fallbacks for other setups. Anyone who picks a language from the switcher, or who opens a /de/ link directly, always keeps that choice.', 'kalima' ); ?></p>
							<p class="description"><?php esc_html_e( 'Turn this on only once the target languages have their content translated — an empty translated page is worse than the right one in the wrong language.', 'kalima' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="kalima_geo_fallback"><?php esc_html_e( 'When the country is unknown', 'kalima' ); ?></label></th>
						<td>
							<select name="kalima_settings[geo_fallback]" id="kalima_geo_fallback">
								<option value="" <?php selected( $s['geo_fallback'], '' ); ?>><?php esc_html_e( 'Use the source language', 'kalima' ); ?></option>
								<?php foreach ( $this->kalima->active_languages() as $geo_code => $geo_data ) : ?>
									<?php if ( $geo_code === $s['default_lang'] ) { continue; } ?>
									<option value="<?php echo esc_attr( $geo_code ); ?>" <?php selected( $s['geo_fallback'], $geo_code ); ?>><?php echo esc_html( $geo_data['flag'] . ' ' . $geo_data['native'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="kalima_geo_action"><?php esc_html_e( 'What happens on arrival', 'kalima' ); ?></label></th>
						<td>
							<select name="kalima_settings[geo_action]" id="kalima_geo_action">
								<option value="redirect" <?php selected( $s['geo_action'], 'redirect' ); ?>><?php esc_html_e( 'Send them to the address for that language (recommended)', 'kalima' ); ?></option>
								<option value="silent" <?php selected( $s['geo_action'], 'silent' ); ?>><?php esc_html_e( 'Keep the address, serve that language', 'kalima' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Sending them keeps the address and the language agreeing, which is what search engines read. The second option suits a site where every language is fully translated.', 'kalima' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Translation engine', 'kalima' ); ?></h2>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="kalima_provider"><?php esc_html_e( 'Provider', 'kalima' ); ?></label></th>
						<td>
							<select name="kalima_settings[ai_provider]" id="kalima_provider">
								<?php foreach ( KALIMA_AI_Engine::providers() as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['ai_provider'], $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="kalima_key"><?php esc_html_e( 'API key', 'kalima' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" id="kalima_key" name="kalima_settings[ai_key]" value="<?php echo esc_attr( $s['ai_key'] ); ?>" autocomplete="off" />
							<p class="description"><?php esc_html_e( 'Stored in the database. Leave blank to keep the saved key.', 'kalima' ); ?></p>
							<p>
								<button type="button" class="button" id="kalima-test-key"><?php esc_html_e( 'Test connection', 'kalima' ); ?></button>
								<span id="kalima-test-result" class="kalima-test-result"></span>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="kalima_model"><?php esc_html_e( 'Model', 'kalima' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="kalima_model" name="kalima_settings[ai_model]" value="<?php echo esc_attr( $s['ai_model'] ); ?>" placeholder="<?php echo esc_attr( KALIMA_AI_Engine::default_model( $s['ai_provider'] ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Leave blank to use the provider default shown as the placeholder.', 'kalima' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Terms never to translate', 'kalima' ); ?></th>
						<td>
							<textarea class="large-text" rows="2" name="kalima_settings[preserve_terms]"><?php echo esc_textarea( $s['preserve_terms'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Brand names, model families, legal terms — comma separated.', 'kalima' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Rate and cost controls', 'kalima' ); ?></th>
						<td>
							<p>
								<label><?php esc_html_e( 'Text units per API call', 'kalima' ); ?>
									<input type="number" min="1" max="100" name="kalima_settings[units_per_call]" value="<?php echo esc_attr( $s['units_per_call'] ); ?>" />
								</label>
							</p>
							<p>
								<label><?php esc_html_e( 'Delay between calls (ms)', 'kalima' ); ?>
									<input type="number" min="0" max="5000" name="kalima_settings[delay_ms]" value="<?php echo esc_attr( $s['delay_ms'] ); ?>" />
								</label>
							</p>
							<p>
								<label><?php esc_html_e( 'Maximum API calls per bulk run', 'kalima' ); ?>
									<input type="number" min="1" max="2000" name="kalima_settings[max_calls]" value="<?php echo esc_attr( $s['max_calls'] ); ?>" />
								</label>
								<span class="description"><?php esc_html_e( 'A hard stop so a mis-click cannot spend your whole budget.', 'kalima' ); ?></span>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Translate screen
	 * ------------------------------------------------------------------ */

	/**
	 * Top-level → Translate.
	 */
	public function render_translate() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$s       = $this->kalima->settings();
		$enabled = array_keys( $this->kalima->active_languages() );

		?>
		<div class="wrap kalima-wrap">
			<h1><?php esc_html_e( 'Translate', 'kalima' ); ?></h1>

			<?php if ( '' === trim( (string) $s['ai_key'] ) ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php esc_html_e( 'No API key saved yet.', 'kalima' ); ?>
						<a href="<?php echo esc_url( admin_url( 'options-general.php?page=kalima' ) ); ?>"><?php esc_html_e( 'Add one in Settings → Languages', 'kalima' ); ?></a>
					</p>
				</div>
			<?php endif; ?>

			<div class="kalima-panel">
				<h2><?php esc_html_e( 'Bulk translation', 'kalima' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Scans pages, posts, products and product categories, then asks the provider for every missing language. Originals are never modified.', 'kalima' ); ?>
				</p>

				<p>
					<label><?php esc_html_e( 'Translate into', 'kalima' ); ?></label>
					<?php foreach ( $enabled as $code ) : ?>
						<?php if ( $code === $s['default_lang'] ) { continue; } ?>
						<label class="kalima-lang-toggle">
							<input type="checkbox" class="kalima-target-lang" value="<?php echo esc_attr( $code ); ?>" checked />
							<?php echo esc_html( $this->kalima->languages->flag( $code ) . ' ' . $this->kalima->languages->name( $code ) ); ?>
						</label>
					<?php endforeach; ?>
				</p>

				<p>
					<button type="button" class="button button-primary" id="kalima-scan"><?php esc_html_e( 'Scan for missing translations', 'kalima' ); ?></button>
					<button type="button" class="button" id="kalima-run" disabled><?php esc_html_e( 'Start translating', 'kalima' ); ?></button>
					<button type="button" class="button" id="kalima-stop" disabled><?php esc_html_e( 'Stop', 'kalima' ); ?></button>
				</p>

				<div id="kalima-progress" class="kalima-progress" hidden>
					<div class="kalima-progress__bar"><span id="kalima-bar"></span></div>
					<p id="kalima-status" class="kalima-status"></p>
					<ol id="kalima-log" class="kalima-log"></ol>
				</div>
			</div>

			<div class="kalima-panel">
				<h2><?php esc_html_e( 'Per-post shortcuts', 'kalima' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Every list table (Pages, Posts, Products) and the Product categories screen now has one column per language. Click a "+ XX" badge to translate just that item.', 'kalima' ); ?>
				</p>
				<p>
					<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=page' ) ); ?>"><?php esc_html_e( 'Pages', 'kalima' ); ?></a>
					<a class="button" href="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>"><?php esc_html_e( 'Posts', 'kalima' ); ?></a>
					<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=product' ) ); ?>"><?php esc_html_e( 'Products', 'kalima' ); ?></a>
					<a class="button" href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=product_cat&post_type=product' ) ); ?>"><?php esc_html_e( 'Product categories', 'kalima' ); ?></a>
				</p>
			</div>

			<div class="kalima-panel">
				<h2><?php esc_html_e( 'Shortcode', 'kalima' ); ?></h2>
				<p><code>[kalima_switcher]</code> &nbsp;·&nbsp; <code>[kalima_switcher show_flags="no"]</code></p>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------ */

	/**
	 * Shared guard for every AJAX route.
	 *
	 * @return true|void
	 */
	private function guard() {
		check_ajax_referer( 'kalima_ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'kalima' ) ) );
		}
	}

	/**
	 * Round-trip a single word to prove the key works.
	 */
	public function ajax_test_key() {
		$this->guard();

		/*
		 * The field is a password box, so the browser will not repost the saved
		 * key. Honour whatever is typed for this probe only — nothing is written
		 * to the database until the user presses Save.
		 */
		$typed = isset( $_POST['key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['key'] ) ) ) : '';

		if ( '' !== $typed ) {
			$this->kalima->engine->override( array( 'ai_key' => $typed ) );
		}

		$result = $this->kalima->engine->test_connection();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Connected. The provider answered correctly.', 'kalima' ),
			)
		);
	}

	/**
	 * Report what is missing so the runner can plan its work.
	 */
	public function ajax_scan() {
		$this->guard();

		$target_langs = isset( $_POST['langs'] ) ? (array) wp_unslash( $_POST['langs'] ) : array();
		$target_langs = array_map( 'sanitize_key', $target_langs );

		$enabled = array_keys( $this->kalima->active_languages() );
		$target_langs = array_values( array_intersect( $target_langs, $enabled ) );

		$default = (string) $this->kalima->get( 'default_lang', 'en' );
		$missing = array(
			'posts' => array(),
			'terms' => array(),
		);

		// --- Posts -------------------------------------------------------
		$query = new \WP_Query(
			array(
				'post_type'      => $this->kalima->core->translatable_post_types(),
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'     => KALIMA_Core::LANG,
						'value'   => $default,
						'compare' => '=',
					),
					array(
						'key'     => KALIMA_Core::LANG,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		foreach ( $query->posts as $post_id ) {
			$status = $this->kalima->core->status_map( $post_id );
			$todo   = array();

			foreach ( $target_langs as $code ) {
				if ( isset( $status[ $code ] ) && 'missing' === $status[ $code ] ) {
					$todo[] = $code;
				}
			}

			if ( empty( $todo ) ) {
				continue;
			}

			$missing['posts'][] = array(
				'id'      => (int) $post_id,
				'title'   => get_the_title( $post_id ),
				'type'    => get_post_type( $post_id ),
				'missing' => $todo,
			);
		}

		// --- Terms -------------------------------------------------------
		$terms = get_terms(
			array(
				'taxonomy'   => array( 'product_cat', 'category', 'product_tag', 'product_brand' ),
				'hide_empty' => false,
				'number'     => 500,
				'orderby'    => 'term_id',
				'order'      => 'ASC',
			)
		);

		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$source = $this->kalima->core->get_term_lang( $term->term_id );
				$todo   = array();

				if ( $source !== $default ) {
					continue;
				}

				foreach ( $target_langs as $code ) {
					if ( $code === $source ) {
						continue;
					}

					if ( ! $this->kalima->core->get_term_translation( $term->term_id, $code ) ) {
						$todo[] = $code;
					}
				}

				if ( empty( $todo ) ) {
					continue;
				}

				$missing['terms'][] = array(
					'id'       => (int) $term->term_id,
					'name'     => $term->name,
					'taxonomy' => $term->taxonomy,
					'missing'  => $todo,
				);
			}
		}

		wp_send_json_success(
			array(
				'targets' => $target_langs,
				'missing' => $missing,
				'totals'  => array(
					'posts' => count( $missing['posts'] ),
					'terms' => count( $missing['terms'] ),
				),
			)
		);
	}

	/**
	 * Translate a slice of posts.
	 */
	public function ajax_translate_posts() {
		$this->guard();

		$ids  = isset( $_POST['post_ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['post_ids'] ) ) : array();
		$lang = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';

		$enabled = array_keys( $this->kalima->active_languages() );

		if ( ! in_array( $lang, $enabled, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown target language.', 'kalima' ) ) );
		}

		$default    = (string) $this->kalima->get( 'default_lang', 'en' );
		$results    = array();
		$hard_limit = (int) $this->kalima->get( 'max_calls', 60 );

		$this->kalima->engine->reset_stats();

		foreach ( $ids as $post_id ) {
			if ( $this->kalima->engine->stats()['calls'] >= $hard_limit ) {
				$results[ $post_id ] = 'limit';
				continue;
			}

			$post = get_post( $post_id );

			if ( ! $post ) {
				$results[ $post_id ] = 'missing';
				continue;
			}

			if ( $post->post_status !== 'publish' ) {
				$results[ $post_id ] = 'skipped';
				continue;
			}

			$source = $this->kalima->core->get_lang( $post_id );

			if ( $source === $lang ) {
				$results[ $post_id ] = 'source';
				continue;
			}

			// Always translate out of the default language, never a copy of a copy.
			$origin_id = $this->kalima->core->get_translation( $this->kalima->core->get_trid( $post_id ), $default );

			if ( $origin_id > 0 && $origin_id !== (int) $post_id ) {
				$post_id = $origin_id;
			}

			if ( $this->kalima->core->get_translation( $this->kalima->core->get_trid( $post_id ), $lang ) > 0 ) {
				$results[ $post_id ] = 'exists';
				continue;
			}

			$this->kalima->core->ensure_trid( $post_id );

			$created = $this->kalima->core->create_post_translation( $post_id, $lang, true );

			if ( is_wp_error( $created ) ) {
				$results[ $post_id ] = $created->get_error_message();
			} else {
				$results[ $post_id ] = 'ok';
			}
		}

		wp_send_json_success(
			array(
				'results' => $results,
				'stats'   => $this->kalima->engine->stats(),
				'error'   => $this->kalima->engine->last_error(),
			)
		);
	}

	/**
	 * Translate a slice of terms.
	 */
	public function ajax_translate_terms() {
		$this->guard();

		$ids  = isset( $_POST['term_ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['term_ids'] ) ) : array();
		$lang = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';

		$enabled = array_keys( $this->kalima->active_languages() );

		if ( ! in_array( $lang, $enabled, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown target language.', 'kalima' ) ) );
		}

		$results = array();

		foreach ( $ids as $term_id ) {
			$created = $this->kalima->core->create_term_translation( $term_id, $lang );

			if ( is_wp_error( $created ) ) {
				$results[ $term_id ] = 'exists' === $created->get_error_code() ? 'exists' : $created->get_error_message();
			} else {
				$results[ $term_id ] = 'ok';
			}
		}

		wp_send_json_success(
			array(
				'results' => $results,
				'stats'   => $this->kalima->engine->stats(),
			)
		);
	}
}
