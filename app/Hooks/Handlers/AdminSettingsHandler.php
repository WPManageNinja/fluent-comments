<?php

namespace FluentComments\App\Hooks\Handlers;

use FluentComments\App\Helpers\Arr;
use FluentComments\App\Services\Helper;

class AdminSettingsHandler
{
    public function register()
    {
        add_action('admin_menu', [$this, 'addAdminMenu']);
        add_action('wp_ajax_fluent-comments-admin-save-settings', [$this, 'saveSettingsAjax']);
    }

    public function addAdminMenu()
    {
        add_submenu_page(
            'edit-comments.php',
            __('Fluent Comments', 'fluent-comments'),
            __('Fluent Comments', 'fluent-comments'),
            'manage_options',
            'fluent-comments',
            [$this, 'renderAdminPage']
        );
    }

    public function renderAdminPage()
    {

        wp_enqueue_script('fluent_comments_admin', FLUENT_COMMENTS_PLUGIN_URL . 'dist/admin_app.js', ['jquery'], FLUENT_COMMENTS_VERSION, true);

        $post_types_with_comments = array();
        // Get all registered post types
        $post_types = get_post_types(array(), 'objects');
        $globalDisabled = ['product', 'attachment', 'page'];
        foreach ($post_types as $post_type) {
            // Check if the post type supports comments
            if (!in_array($post_type->name, $globalDisabled) && $post_type->public && post_type_supports($post_type->name, 'comments')) {
                // Add to array with post type name as key and label as value
                $post_types_with_comments[$post_type->name] = [
                    'name'        => $post_type->name,
                    'title'       => $post_type->label,
                    'description' => $post_type->description,
                ];
            }
        }

        $settings = Helper::getCommentSettings();
        $settings['post_types'] = array_intersect($settings['post_types'], array_keys($post_types_with_comments));

        wp_localize_script('fluent_comments_admin', 'fluentCommentsVars', array(
            'ajax_url'            => admin_url('admin-ajax.php'),
            'nonce'               => wp_create_nonce('fluent_comment_admin_nonce'),
            'version'             => FLUENT_COMMUNITY_PLUGIN_VERSION,
            'comments_post_types' => array_values($post_types_with_comments),
            'settings'            => $settings,
            'using_block_theme'   => function_exists('wp_is_block_theme') && wp_is_block_theme() ? 'yes' : 'no',
        ));

        // Render the admin page content here
        echo '<div class="wrap">';
        echo '<div id="fluent_comment_app"></div>';
        echo '</div>';
    }

    public function saveSettingsAjax()
    {
        $this->verifyAjaxRequest();
        $settings = isset($_POST['settings']) ? $_POST['settings'] : [];

        if (empty($settings)) {
            wp_send_json_error(array('message' => __('Settings cannot be empty.', 'fluent-comments')), 400);
        }

        $prevSettings = Helper::getCommentSettings();
        $settings = Arr::only($settings, array_keys($prevSettings));

        $settings['post_types'] = array_filter($settings['post_types'], function ($postType) {
            return post_type_exists($postType);
        });

        $settings['reject_native_comments'] = isset($settings['reject_native_comments']) && $settings['reject_native_comments'] === 'yes' ? 'yes' : 'no';
        $settings['email_on_comment_approval'] = isset($settings['email_on_comment_approval']) && $settings['email_on_comment_approval'] === 'yes' ? 'yes' : 'no';
        $settings['email_on_reply'] = isset($settings['email_on_reply']) && $settings['email_on_reply'] === 'yes' ? 'yes' : 'no';
        $settings['email_to_author'] = isset($settings['email_to_author']) && $settings['email_to_author'] === 'yes' ? 'yes' : 'no';

        update_option('_fluent_comments_settings', $settings);

        wp_send_json(array('message' => __('Settings saved successfully.', 'fluent-comments')), 200);

    }

    private function verifyAjaxRequest()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json(array('message' => __('You do not have permission to do this action.', 'fluent-comments')), 403);
        }

        $nonce = isset($_REQUEST['__nonce']) ? sanitize_text_field($_REQUEST['__nonce']) : '';
        if (!wp_verify_nonce($nonce, 'fluent_comment_admin_nonce')) {
            wp_send_json(array('message' => __('Invalid nonce.', 'fluent-')), 403);
        }
    }
}
