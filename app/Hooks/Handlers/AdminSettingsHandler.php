<?php

namespace FluentComments\App\Hooks\Handlers;

class AdminSettingsHandler
{
    public function register()
    {
        add_action('admin_menu', [$this, 'addAdminMenu']);
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
        // Render the admin page content here
        echo '<div class="wrap">';
        echo '<h1>' . __('Fluent Comments Settings', 'fluent-comments') . '</h1>';
        echo '<p>' . __('Settings for Fluent Comments plugin.', 'fluent-comments') . '</p>';
        // Add your settings form or other content here
        echo '</div>';
    }
}
