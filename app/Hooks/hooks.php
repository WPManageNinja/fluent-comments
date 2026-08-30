<?php defined('ABSPATH') or die;

\FluentComments\App\Services\Frontend::register();

(new \FluentComments\App\Hooks\Handlers\CommentsHandler())->register();
(new \FluentComments\App\Hooks\Handlers\CommentNotificationHandler())->register();
(new \FluentComments\App\Hooks\Handlers\CoreEmailHandler())->register();
(new \FluentComments\App\Hooks\Handlers\BlockHandler())->register();

(new \FluentComments\App\Hooks\Handlers\AdminSettingsHandler())->register();
(new \FluentComments\App\Hooks\Handlers\EmailSettingsHandler())->register();
