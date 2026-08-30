<?php

namespace FluentComments\App\Services;

/**
 * A handful of core's Discussion settings, surfaced on our screen.
 *
 * These are WordPress options and stay WordPress options: core enforces
 * every one of them, in wp_allow_comment() and check_comment(), whether or
 * not a comment came through us. Nothing here is a second copy. We read
 * and write the real thing, so Settings > Discussion and this screen can
 * never disagree, and update_option() runs core's own sanitize_option()
 * on the way in.
 *
 * The selection is deliberately small: the ones that decide whether a
 * comment is held, and that are hard to find or easy to misread where core
 * puts them. Everything else stays on options-discussion.php.
 */
class DiscussionSettings
{
    /**
     * Stored by core as '1' or '', which is what a checkbox on
     * options-discussion.php posts.
     */
    const BOOLEANS = [
        'require_name_email',
        'comment_registration',
        'close_comments_for_old_posts',
        'thread_comments',
        'comment_moderation',
        'comment_previously_approved',
        'comments_notify',
        'moderation_notify',
    ];

    /**
     * Option => [default, minimum, maximum].
     */
    const INTEGERS = [
        'close_comments_days_old' => [14, 0, 3650],
        'thread_comments_depth'   => [5, 2, 10],
        'comment_max_links'       => [2, 0, 100],
    ];

    /**
     * One word or phrase per line, matched against the comment.
     */
    const TEXTS = [
        'moderation_keys',
        'disallowed_keys',
    ];

    /**
     * @return array
     */
    public static function get()
    {
        $values = [];

        foreach (self::BOOLEANS as $option) {
            $values[$option] = get_option($option) ? 'yes' : 'no';
        }

        foreach (self::INTEGERS as $option => $bounds) {
            list($default) = $bounds;
            $values[$option] = (int)get_option($option, $default);
        }

        foreach (self::TEXTS as $option) {
            $values[$option] = (string)get_option($option, '');
        }

        return $values;
    }

    /**
     * @param array $raw
     * @return void
     */
    public static function save($raw)
    {
        if (!is_array($raw)) {
            return;
        }

        foreach (self::BOOLEANS as $option) {
            if (!array_key_exists($option, $raw)) {
                continue;
            }

            update_option($option, $raw[$option] === 'yes' ? '1' : '');
        }

        foreach (self::INTEGERS as $option => $bounds) {
            if (!array_key_exists($option, $raw)) {
                continue;
            }

            list($default, $min, $max) = $bounds;
            $value = is_scalar($raw[$option]) ? (int)$raw[$option] : $default;

            update_option($option, (string)max($min, min($max, $value)));
        }

        foreach (self::TEXTS as $option) {
            if (!array_key_exists($option, $raw) || !is_string($raw[$option])) {
                continue;
            }

            // sanitize_option() splits on newlines, trims, and dedupes these
            // two, so hand it the raw text and let it do that.
            update_option($option, $raw[$option]);
        }
    }
}
