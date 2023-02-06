<?php defined('ABSPATH') or die;

// Autoload plugin.
require_once(__DIR__.'/autoload.php');

if (! function_exists('fluentGitDb')) {
    /**
     * @return \FluentGitDb\QueryBuilder\QueryBuilderHandler
     */
    function fluentGitDb()
    {
        static $FluentGitDb;

        if (! $FluentGitDb) {
            global $wpdb;

            $connection = new \FluentGitDb\Connection($wpdb, ['prefix' => $wpdb->prefix]);

            $FluentGitDb = new \FluentGitDb\QueryBuilder\QueryBuilderHandler($connection);
        }

        return $FluentGitDb;
    }
}
