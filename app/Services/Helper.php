<?php

namespace FluentComments\App\Services;

use FluentComments\App\Helpers\Arr;

class Helper
{
    public static function getCommentSettings()
    {
        $defaults = self::getDefaultSettings();

        $settings = get_option('_fluent_comments_settings', []);

        if (!is_array($settings) || empty($settings)) {
            return $defaults;
        }

        return wp_parse_args($settings, $defaults);
    }

    /**
     * @return array
     */
    public static function getDefaultSettings()
    {
        return [
            'post_types'                => ['post'],
            'reject_native_comments'    => 'yes',
            'email_on_comment_approval' => 'no',
            'email_on_reply'            => 'no',
            'email_to_author'           => 'no'
        ];
    }

    /**
     * Whether the site owner has ever saved the settings screen.
     *
     * There is no activation hook, so a fresh install has no option row and
     * getCommentSettings() quietly serves the defaults - which already put
     * FluentComments in charge of `post` with native rejection on. That is a
     * live plugin nobody has looked at yet, so the absence of the row is what
     * the setup notice keys on. Saving writes every key, so a saved option is
     * never empty.
     *
     * @return bool
     */
    public static function isConfigured()
    {
        $settings = get_option('_fluent_comments_settings', null);

        return is_array($settings) && !empty($settings);
    }

    /**
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function getSetting($key, $default = null)
    {
        return Arr::get(self::getCommentSettings(), $key, $default);
    }

    public static function isFluentCommentsPostType($postType)
    {
        return in_array($postType, Arr::get(self::getCommentSettings(), 'post_types', []), true);
    }

    /**
     * Whether the active theme is a block (full site editing) theme.
     *
     * @return bool
     */
    public static function isBlockTheme()
    {
        if (function_exists('wp_is_block_theme')) {
            return (bool)wp_is_block_theme();
        }

        if (function_exists('gutenberg_is_fse_theme')) {
            return (bool)gutenberg_is_fse_theme();
        }

        return false;
    }

    /**
     * Whether the plugin puts its own form on this post by itself.
     *
     * Classic themes get the comments template swapped, so yes for every
     * enabled post type. Block themes are placed by hand, so no. This
     * governs rendering only: whether native submissions are rejected is a
     * separate question, answered by willRejectNativeComments().
     *
     * @param \WP_Post|int|null $post
     * @return bool
     */
    public static function isHandlingComments($post = null)
    {
        $post = get_post($post);

        if (!$post || !self::isFluentCommentsPostType($post->post_type)) {
            return false;
        }

        $isHandling = !self::isBlockTheme();

        return (bool)apply_filters('fluent_comments/is_handling_comments', $isHandling, $post);
    }

    /**
     * Whether a comment that did not come through our form is rejected.
     *
     * This is the contract the site owner opts into: pick the post types,
     * switch spam protection on, and anything posted to core's endpoint
     * without our fields is turned away. It is deliberately independent of
     * the theme. On a block theme that means the posts stay closed until
     * the FluentComments block or the shortcode is actually placed, which
     * is why the admin notice says so in as many words.
     *
     * @param \WP_Post|int|null $post
     * @return bool
     */
    public static function willRejectNativeComments($post = null)
    {
        $post = get_post($post);

        if (!$post || !self::isFluentCommentsPostType($post->post_type)) {
            return false;
        }

        $reject = self::getSetting('reject_native_comments', 'yes') === 'yes';

        return (bool)apply_filters('fluent_comments/reject_native_comments', $reject, $post);
    }

    /**
     * Maximum reply depth, honouring the WordPress discussion settings.
     *
     * @return int
     */
    public static function getMaxDepth()
    {
        if (!get_option('thread_comments')) {
            return 1;
        }

        $depth = (int)get_option('thread_comments_depth', 5);

        return $depth > 0 ? $depth : 1;
    }

    /**
     * How many top level comments to load per request.
     *
     * @return int
     */
    public static function getPerPage()
    {
        $perPage = (int)get_option('comments_per_page', 20);

        if ($perPage < 1) {
            $perPage = 20;
        }

        return (int)apply_filters('fluent_comments/comments_per_page', $perPage);
    }

    /**
     * Renders a file from app/Views and hands back the markup.
     *
     * @param string $view
     * @param array $data
     * @return string
     */
    public static function loadView($view, $data = [])
    {
        $file = FLUENT_COMMENTS_PLUGIN_PATH . 'app/Views/' . $view . '.php';

        if (!file_exists($file)) {
            return '';
        }

        extract($data); //phpcs:ignore WordPress.PHP.DontExtract.extract_extract

        ob_start();
        include $file;

        return (string)ob_get_clean();
    }

    /**
     * The gate on every admin AJAX action.
     *
     * Both halves are needed and neither is enough on its own: the
     * capability says this user is allowed to change settings, the nonce
     * says this user meant to. Sends the response and exits on failure, so
     * a caller can treat returning as success.
     *
     * @return void
     */
    public static function verifyAdminAjax()
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
