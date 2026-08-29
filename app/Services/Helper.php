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
            'block_theme_takeover'      => 'yes',
            'email_on_comment_approval' => 'no',
            'email_on_reply'            => 'no',
            'email_to_author'           => 'no'
        ];
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
     * Whether the plugin replaces the core Comments block on block themes.
     *
     * @return bool
     */
    public static function isBlockThemeTakeoverEnabled()
    {
        return self::getSetting('block_theme_takeover', 'yes') === 'yes';
    }

    /**
     * Whether the plugin actually renders a comment form for this post.
     *
     * On classic themes the comments template is swapped, so the answer is
     * yes for every enabled post type. On block themes it is only yes when
     * the core Comments block is taken over, otherwise the theme keeps
     * rendering the native form and we must not get in its way.
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

        $isHandling = !self::isBlockTheme() || self::isBlockThemeTakeoverEnabled();

        return (bool)apply_filters('fluent_comments/is_handling_comments', $isHandling, $post);
    }

    /**
     * Native comment submissions are only rejected when we have put our own
     * form on the page. Rejecting without a replacement locks the site out
     * of commenting entirely.
     *
     * @param \WP_Post|int|null $post
     * @return bool
     */
    public static function willRejectNativeComments($post = null)
    {
        if (!self::isHandlingComments($post)) {
            return false;
        }

        return self::getSetting('reject_native_comments', 'yes') === 'yes';
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
}
