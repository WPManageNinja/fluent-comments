<?php defined('ABSPATH') or die;

(new \FluentComments\App\Hooks\Handlers\CommentsHandler())->register();

(new \FluentComments\App\Hooks\Handlers\AdminSettingsHandler())->register();
