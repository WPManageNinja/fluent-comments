<?php
defined('ABSPATH') or die;

/*
Plugin Name:  FluentComments
Plugin URI:   https://github.com/WPManageNinja/fluent-comments
Description:  AJAX comments with layered spam protection and no CAPTCHA, plus a full email notification system.
Version:      2.1.1
Author:       WPManageNinja Team
Author URI:   https://wpmanageninja.com
License:      GPLv2 or later
License URI:  https://www.gnu.org/licenses/gpl-2.0.html
Text Domain:  fluent-comments
Domain Path:  /languages
Requires at least: 6.5
Requires PHP: 7.4
*/

define('FLUENT_COMMENTS_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('FLUENT_COMMENTS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FLUENT_COMMENTS_VERSION', '2.1.1');

class FluentCommentsPlugin
{

    public function boot()
    {
        $this->registerTextDomain();
        $this->registerAutoLoad();
    }

    /**
     * Translations that ship inside the plugin's own languages/ directory.
     *
     * Without this, only the translations WordPress.org installs into
     * WP_LANG_DIR/plugins load: the just-in-time loader knows nothing about
     * a plugin's own folder unless the plugin registers it. Our JS
     * translations already point at that folder - wp_set_script_translations()
     * in BlockHandler is handed the path outright - so leaving this out made
     * a bundled .mo work for the block and not for anything in PHP.
     *
     * On init, not on plugins_loaded: loading a text domain before init is
     * deprecated as of WordPress 6.7 and notices on every request.
     */
    private function registerTextDomain()
    {
        add_action('init', function () {
            load_plugin_textdomain(
                'fluent-comments',
                false,
                dirname(plugin_basename(__FILE__)) . '/languages'
            );
        });
    }

    private function registerAutoLoad()
    {
        require_once FLUENT_COMMENTS_PLUGIN_PATH . 'app/Helpers/Arr.php';
        require_once FLUENT_COMMENTS_PLUGIN_PATH . 'app/Services/Helper.php';
        require_once FLUENT_COMMENTS_PLUGIN_PATH . 'app/Services/SpamGuard.php';
        require_once FLUENT_COMMENTS_PLUGIN_PATH . 'app/Services/CommentsRepository.php';
        require_once FLUENT_COMMENTS_PLUGIN_PATH . 'app/Services/CommentSubmission.php';
        require_once FLUENT_COMMENTS_PLUGIN_PATH . 'app/Services/Frontend.php';
        require_once FLUENT_COMMENTS_PLUGIN_PATH . 'app/Services/Mailer.php';
        require_once FLUENT_COMMENTS_PLUGIN_PATH . 'app/Services/SmartCodeParser.php';
        require_once FLUENT_COMMENTS_PLUGIN_PATH . 'app/Services/EmailService.php';
        require_once FLUENT_COMMENTS_PLUGIN_PATH . 'app/Services/TemplateScanner.php';
        require_once FLUENT_COMMENTS_PLUGIN_PATH . 'app/Services/DiscussionSettings.php';
        require_once FLUENT_COMMENTS_PLUGIN_PATH . 'app/Hooks/Handlers/AdminSettingsHandler.php';
        require_once FLUENT_COMMENTS_PLUGIN_PATH . 'app/Hooks/Handlers/EmailSettingsHandler.php';
        require_once FLUENT_COMMENTS_PLUGIN_PATH . 'app/Hooks/Handlers/CommentsHandler.php';
        require_once FLUENT_COMMENTS_PLUGIN_PATH . 'app/Hooks/Handlers/CommentNotificationHandler.php';
        require_once FLUENT_COMMENTS_PLUGIN_PATH . 'app/Hooks/Handlers/CoreEmailHandler.php';
        require_once FLUENT_COMMENTS_PLUGIN_PATH . 'app/Hooks/Handlers/BlockHandler.php';

        require_once FLUENT_COMMENTS_PLUGIN_PATH . 'app/Hooks/hooks.php';
    }
}


add_action('plugins_loaded', function () {
    (new FluentCommentsPlugin())->boot();
});
