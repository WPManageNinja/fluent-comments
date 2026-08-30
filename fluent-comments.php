<?php
defined('ABSPATH') or die;

/*
Plugin Name:  FluentComments
Plugin URI:   https://github.com/WPManageNinja/fluent-comments
Description:  AJAX comments with layered spam protection and no CAPTCHA, plus a full email notification system.
Version:      2.1.0
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
define('FLUENT_COMMENTS_VERSION', '2.1.0');

class FluentCommentsPlugin
{

    public function boot()
    {
        $this->registerAutoLoad();
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
        require_once FLUENT_COMMENTS_PLUGIN_PATH . 'app/Services/FluentWalkerComment.php';
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
