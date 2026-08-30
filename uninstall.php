<?php
/**
 * Removes everything Fluent Comments stored when the plugin is deleted.
 *
 * Comments themselves are WordPress data and are deliberately left alone.
 */

defined('WP_UNINSTALL_PLUGIN') or die;

if (!function_exists('fluent_comments_delete_plugin_data')) {
    function fluent_comments_delete_plugin_data()
    {
        global $wpdb;

        delete_option('_fluent_comments_settings');

        // Direct queries: there is no API for deleting a meta key or a set of
        // transients across every user, and this runs once on uninstall.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        // Legacy: the dismissible placement notice was removed in 2.1.1 and
        // nothing writes this key any more, but installs that saw it still
        // carry the per-user flag.
        $wpdb->query(
            "DELETE FROM {$wpdb->usermeta} WHERE meta_key = '_fluent_comments_dismissed_block_notice'"
        );

        // Rate limit counters are transients, so clear the leftovers.
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '\_transient\_flc\_rl\_%'
                OR option_name LIKE '\_transient\_timeout\_flc\_rl\_%'"
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }
}

if (is_multisite()) {
    $fluent_comments_site_ids = get_sites(['fields' => 'ids', 'number' => 0]);

    foreach ($fluent_comments_site_ids as $fluent_comments_site_id) {
        switch_to_blog($fluent_comments_site_id);
        fluent_comments_delete_plugin_data();
        restore_current_blog();
    }
} else {
    fluent_comments_delete_plugin_data();
}
