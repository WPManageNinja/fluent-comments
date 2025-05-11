<?php

namespace FluentComments\App\Services;

use FluentComments\App\Helpers\Arr;

class Helper
{
    public static function getCommentSettings()
    {
        return [
            'post_types' => [
                'post'
            ]
        ];
    }

    public static function isFluentCommentsPostTyepe($postType)
    {
        $settings = self::getCommentSettings();
        return in_array($postType, Arr::get($settings, 'post_types', []));
    }
}
