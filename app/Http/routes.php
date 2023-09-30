<?php defined('ABSPATH') or die;

$router = new \FluentComments\App\Services\Router('fluent-comments');

$permissions = ['public'];

$router->get('comments/{id}', ['\FluentComments\App\Http\Controllers\CommentsController', 'getComments'], $permissions)
    ->post('comments/{id}', ['\FluentComments\App\Http\Controllers\CommentsController', 'addComment'], $permissions);
