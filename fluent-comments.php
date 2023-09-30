<?php
defined('ABSPATH') or die;

/*
Plugin Name:  Fluent Comments
Plugin URI:   https://github.com/WPManageNinja/fluent-comments
Description:  Simple Comments Plugin for WordPress to fight with spams and trolls
Version:      1.0.0
Author:       WPManageNinja Team
Author URI:   https://wpmanageninja.com
License:      GPLv2 or later
License URI:  https://www.gnu.org/licenses/gpl-2.0.html
Text Domain:  fluent-comments
Domain Path:  /languages
*/

define('FLUENT_COMMENTS_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('FLUENT_COMMENTS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FLUENT_COMMENTS_VERSION', '1.0.0');

class FluentCommentsPlugin
{

    public function boot()
    {
        $this->registerAutoLoad();
    }

    private function registerAutoLoad()
    {
        require_once FLUENT_COMMENTS_PLUGIN_PATH . 'app/Helpers/Arr.php';
        require_once FLUENT_COMMENTS_PLUGIN_PATH . 'app/Hooks/Handlers/ShortcodeHandler.php';
        require_once FLUENT_COMMENTS_PLUGIN_PATH . 'app/Http/Controllers/CommentsController.php';
        require_once FLUENT_COMMENTS_PLUGIN_PATH . 'app/Services/Router.php';
        require_once FLUENT_COMMENTS_PLUGIN_PATH . 'app/Services/FluentWalkerComment.php';

        add_action('rest_api_init', function () {
            require_once FLUENT_COMMENTS_PLUGIN_PATH . 'app/Http/routes.php';
        });

        require_once FLUENT_COMMENTS_PLUGIN_PATH . 'app/Hooks/hooks.php';

        add_action('init', function () {
            load_plugin_textdomain( 'fluent-comments', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
        });
    }
}


add_action('plugins_loaded', function () {
    (new FluentCommentsPlugin())->boot();
});
