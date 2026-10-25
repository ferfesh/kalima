<?php
/**
 * Uninstall routine.
 *
 * Deliberately conservative: it removes the plugin's own options and the two
 * meta keys it wrote. It never deletes a post or a term, because every
 * translation is real published content the client may still want.
 *
 * @package KALIMA
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

/*
 * Plugin settings.
 */
delete_option( 'kalima_settings' );

/*
 * Meta keys. `wp_postmeta` and `wp_termmeta` are read directly so this works
 * from a CLI or WP-CLI context where the usual helpers are not loaded.
 */
$wpdb->query(
	"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_kalima_trid','_kalima_lang','_kalima_clone')"
);

$wpdb->query(
	"DELETE FROM {$wpdb->termmeta} WHERE meta_key IN ('_kalima_trid','_kalima_lang','_kalima_clone')"
);

/*
 * Translated posts keep their `post_content`; only the link to the group goes.
 * Flag them so a reinstall does not re-adopt them as new originals.
 */
$clones = $wpdb->get_col(
	"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_kalima_clone'"
);

foreach ( (array) $clones as $post_id ) {
	$wpdb->delete(
		$wpdb->postmeta,
		array(
			'post_id'  => (int) $post_id,
			'meta_key' => '_kalima_clone',
		)
	);
}

/**
 * Let other plugins clean up after us.
 */
do_action( 'kalima_uninstalled' );
