<?php

namespace FluentComments\App\Hooks\Handlers;

use FluentComments\App\Helpers\Arr;
use FluentComments\App\Services\Helper;

class AdminSettingsHandler
{
    const PAGE_SLUG = 'fluent-comments';

    const DISMISS_META_KEY = '_fluent_comments_dismissed_block_notice';

    public function register()
    {
        add_action('admin_menu', [$this, 'addAdminMenu']);
        add_action('wp_ajax_fluent-comments-admin-save-settings', [$this, 'saveSettingsAjax']);
        add_action('admin_notices', [$this, 'maybeShowBlockThemeNotice']);
        add_action('admin_init', [$this, 'maybeDismissBlockThemeNotice']);
    }

    public function addAdminMenu()
    {
        add_submenu_page(
            'edit-comments.php',
            __('Fluent Comments', 'fluent-comments'),
            __('Fluent Comments', 'fluent-comments'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'renderAdminPage']
        );
    }

    public function renderAdminPage()
    {
        wp_enqueue_style(
            'fluent_comments_admin',
            FLUENT_COMMENTS_PLUGIN_URL . 'dist/css/admin_app.css',
            [],
            FLUENT_COMMENTS_VERSION
        );

        wp_enqueue_script(
            'fluent_comments_admin',
            FLUENT_COMMENTS_PLUGIN_URL . 'dist/js/admin_app.js',
            ['jquery'],
            FLUENT_COMMENTS_VERSION,
            true
        );

        $postTypesWithComments = [];
        $globalDisabled = ['product', 'attachment', 'page'];

        foreach (get_post_types([], 'objects') as $postType) {
            if (in_array($postType->name, $globalDisabled, true)) {
                continue;
            }

            if (!$postType->public || !post_type_supports($postType->name, 'comments')) {
                continue;
            }

            $postTypesWithComments[$postType->name] = [
                'name'        => $postType->name,
                'title'       => $postType->label,
                'description' => $postType->description,
            ];
        }

        $settings = Helper::getCommentSettings();
        $settings['post_types'] = array_values(array_intersect($settings['post_types'], array_keys($postTypesWithComments)));

        wp_localize_script('fluent_comments_admin', 'fluentCommentsVars', [
            'ajax_url'            => admin_url('admin-ajax.php'),
            'nonce'               => wp_create_nonce('fluent_comment_admin_nonce'),
            'version'             => FLUENT_COMMENTS_VERSION,
            'comments_post_types' => array_values($postTypesWithComments),
            'settings'            => $settings,
            'using_block_theme'   => Helper::isBlockTheme() ? 'yes' : 'no',
        ]);

        echo '<div class="wrap"><div id="fluent_comment_app"></div></div>';
    }

    public function saveSettingsAjax()
    {
        $this->verifyAjaxRequest();

        // The nonce and capability are verified in verifyAjaxRequest() above,
        // and every value below is sanitized individually.
        // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $raw = isset($_POST['settings']) && is_array($_POST['settings'])
            ? wp_unslash($_POST['settings'])
            : [];
        // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        if (empty($raw)) {
            wp_send_json(['message' => __('Settings cannot be empty.', 'fluent-comments')], 400);
        }

        $defaults = Helper::getDefaultSettings();
        $raw = Arr::only($raw, array_keys($defaults));

        $postTypes = isset($raw['post_types']) && is_array($raw['post_types']) ? $raw['post_types'] : [];
        $postTypes = array_map('sanitize_key', array_filter($postTypes, 'is_scalar'));

        $settings = [
            'post_types' => array_values(array_unique(array_filter($postTypes, 'post_type_exists'))),
        ];

        foreach (['reject_native_comments', 'block_theme_takeover', 'email_on_comment_approval', 'email_on_reply', 'email_to_author'] as $key) {
            $settings[$key] = (isset($raw[$key]) && $raw[$key] === 'yes') ? 'yes' : 'no';
        }

        update_option('_fluent_comments_settings', $settings);

        wp_send_json(['message' => __('Settings saved successfully.', 'fluent-comments')], 200);
    }

    /**
     * Block themes render the core Comments block. If the takeover is off,
     * the plugin is effectively inert on the front end, so say so.
     *
     * @return void
     */
    public function maybeShowBlockThemeNotice()
    {
        if (!current_user_can('manage_options') || !Helper::isBlockTheme()) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if ($screen && strpos($screen->id, self::PAGE_SLUG) !== false) {
            return;
        }

        if (Helper::isBlockThemeTakeoverEnabled()) {
            return;
        }

        $settings = Helper::getCommentSettings();

        if (empty($settings['post_types'])) {
            return;
        }

        if (get_user_meta(get_current_user_id(), self::DISMISS_META_KEY, true)) {
            return;
        }

        $settingsUrl = admin_url('edit-comments.php?page=' . self::PAGE_SLUG);
        $dismissUrl = wp_nonce_url(
            add_query_arg('fluent_comments_dismiss_notice', '1'),
            'fluent_comments_dismiss_notice'
        );
        ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php esc_html_e('Fluent Comments', 'fluent-comments'); ?>:</strong>
                <?php esc_html_e('You are using a block theme and the Comments block replacement is turned off, so your posts are still using the default WordPress comment form.', 'fluent-comments'); ?>
            </p>
            <p>
                <a class="button button-primary" href="<?php echo esc_url($settingsUrl); ?>">
                    <?php esc_html_e('Review settings', 'fluent-comments'); ?>
                </a>
                <a class="button" href="<?php echo esc_url($dismissUrl); ?>">
                    <?php esc_html_e('Dismiss', 'fluent-comments'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * @return void
     */
    public function maybeDismissBlockThemeNotice()
    {
        if (empty($_GET['fluent_comments_dismiss_notice'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';

        if (!wp_verify_nonce($nonce, 'fluent_comments_dismiss_notice')) {
            return;
        }

        update_user_meta(get_current_user_id(), self::DISMISS_META_KEY, 1);
    }

    private function verifyAjaxRequest()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json(['message' => __('You do not have permission to do this action.', 'fluent-comments')], 403);
        }

        $nonce = isset($_REQUEST['__nonce']) ? sanitize_text_field(wp_unslash($_REQUEST['__nonce'])) : '';

        if (!wp_verify_nonce($nonce, 'fluent_comment_admin_nonce')) {
            wp_send_json(['message' => __('Invalid nonce.', 'fluent-comments')], 403);
        }
    }
}
