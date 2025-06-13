<?php

namespace FluentComments\App\Services;

use FluentComments\App\Helpers\Arr;

class Helper
{
    public static function getCommentSettings()
    {
        $defaults = [
            'post_types'             => ['post'],
            'reject_native_comments' => 'yes'
        ];

        $settings = get_option('_fluent_comments_settings', []);

        if (empty($settings)) {
            return $defaults;
        }

        $settings = wp_parse_args($settings, $defaults);

        return $settings;
    }

    public static function isFluentCommentsPostTyepe($postType)
    {
        $settings = self::getCommentSettings();
        return in_array($postType, Arr::get($settings, 'post_types', []));
    }
}
