<?php

namespace FluentComments\App\Services;

use FluentComments\App\Helpers\Arr;

class Helper
{
    public static function getCommentSettings()
    {
        $defaults = [
            'post_types'                => ['post'],
            'reject_native_comments'    => 'yes',
            'email_on_comment_approval' => 'no',
            'email_on_reply'            => 'no',
            'email_to_author'           => 'no'
        ];

        $settings = get_option('_fluent_comments_settings', []);

        if (empty($settings)) {
            return $defaults;
        }

        $settings = wp_parse_args($settings, $defaults);

        return $settings;
    }

    public static function isFluentCommentsPostType($postType)
    {
        $settings = self::getCommentSettings();
        return in_array($postType, Arr::get($settings, 'post_types', []));
    }

    public static function willRejectNativeComments($postType)
    {
        $settings = self::getCommentSettings();
        return in_array($postType, Arr::get($settings, 'post_types', [])) && Arr::get($settings, 'reject_native_comments', 'yes') === 'yes';
    }

}
