<?php

namespace FluentComments\App\Hooks\Handlers;

class BlockHandler
{
    public function register()
    {
        add_action('init', [$this, 'registerBlock']);
    }

    public function registerBlock()
    {
        // Register the block - WordPress handles script/style registration via block.json
        register_block_type(
            FLUENT_COMMENTS_PLUGIN_PATH . 'resources/block/block.json',
            [
                'render_callback' => [$this, 'renderBlock'],
            ]
        );
    }

    public function renderBlock($attributes, $content, $block)
    {
        // Get the post ID - works in both post editor and site editor
        $postId = get_the_ID();

        if (!$postId) {
            global $post;
            if ($post) {
                $postId = $post->ID;
            }
        }

        // In site editor context, show a placeholder
        if (!$postId || is_admin()) {
            return $this->renderPlaceholder($attributes);
        }

        // Check if post type supports comments
        $postType = get_post_type($postId);
        if (!$postType || !post_type_supports($postType, 'comments')) {
            return '';
        }

        // Build inline CSS variables from attributes
        $cssVars = $this->buildCssVariables($attributes);
        $wrapperClass = 'fluent-comments-block';

        if (!empty($attributes['align'])) {
            $wrapperClass .= ' align' . $attributes['align'];
        }

        // Get comment count and determine title
        $commentCount = (int) get_comments_number($postId);
        $title = $this->getTitle($attributes, $commentCount);

        // Enqueue frontend assets
        $this->enqueueAssets();

        ob_start();
        ?>
        <div class="<?php echo esc_attr($wrapperClass); ?>"<?php echo $cssVars ? ' style="' . esc_attr($cssVars) . '"' : ''; ?>>
            <?php if (!empty($attributes['showTitle']) && $title): ?>
                <h2 class="flc_comments-title"><?php echo esc_html($title); ?></h2>
            <?php endif; ?>
            <div
                data-post_id="<?php echo esc_attr($postId); ?>"
                data-show-avatars="<?php echo !empty($attributes['showAvatars']) ? '1' : '0'; ?>"
                class="fluent_dynamic_comments"
            >
                <h3 style="text-align: center;"><?php esc_html_e('Loading..', 'fluent-comments'); ?></h3>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function getTitle($attributes, $commentCount)
    {
        if (empty($attributes['showTitle'])) {
            return '';
        }

        if ($commentCount > 0) {
            $title = !empty($attributes['titleWithComments'])
                ? $attributes['titleWithComments']
                : __('Latest comments ({count})', 'fluent-comments');
            return str_replace('{count}', $commentCount, $title);
        }

        return !empty($attributes['titleNoComments'])
            ? $attributes['titleNoComments']
            : __('Add your first comment to this post', 'fluent-comments');
    }

    private function renderPlaceholder($attributes)
    {
        $cssVars = $this->buildCssVariables($attributes);
        $wrapperClass = 'fluent-comments-block';

        if (!empty($attributes['align'])) {
            $wrapperClass .= ' align' . $attributes['align'];
        }

        // Show sample title with placeholder count for editor preview
        $title = $this->getTitle($attributes, 3);

        ob_start();
        ?>
        <div class="<?php echo esc_attr($wrapperClass); ?>"<?php echo $cssVars ? ' style="' . esc_attr($cssVars) . '"' : ''; ?>>
            <?php if (!empty($attributes['showTitle']) && $title): ?>
                <h2 class="flc_comments-title"><?php echo esc_html($title); ?></h2>
            <?php endif; ?>
            <div class="fluent_dynamic_comments">
                <p style="text-align: center; padding: 40px 20px; background: #f5f5f5; border-radius: 4px;">
                    <?php esc_html_e('Fluent Comments will appear here on the frontend.', 'fluent-comments'); ?>
                </p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function buildCssVariables($attributes)
    {
        $vars = [];

        if (!empty($attributes['primaryColor'])) {
            $vars[] = '--fcom-text-link: ' . $attributes['primaryColor'];
        }

        if (!empty($attributes['textColor'])) {
            $vars[] = '--fcom-primary-text: ' . $attributes['textColor'];
        }

        if (!empty($attributes['cardBackgroundColor'])) {
            $vars[] = '--fcom-secondary-content-bg: ' . $attributes['cardBackgroundColor'];
        }

        if (isset($attributes['borderRadius'])) {
            $vars[] = '--fcom-radius: ' . intval($attributes['borderRadius']) . 'px';
        }

        return implode('; ', $vars);
    }

    private function enqueueAssets()
    {
        static $loaded = false;

        if ($loaded) {
            return;
        }

        $loaded = true;

        wp_enqueue_script(
            'fluent_comments',
            FLUENT_COMMENTS_PLUGIN_URL . 'dist/js/app.js',
            [],
            FLUENT_COMMENTS_VERSION,
            true
        );

        $vars = [
            'slug'          => 'fluent-comments',
            'nonce'         => wp_create_nonce('fluent-comments'),
            'rest'          => [
                'base_url'  => esc_url_raw(rest_url()),
                'url'       => rest_url('fluent-comments'),
                'nonce'     => wp_create_nonce('wp_rest'),
                'namespace' => 'fluent-comments',
                'version'   => '1',
            ],
            'i18n'          => [
                'Dashboard' => __('Dashboard', 'fluent-comments'),
                'Docs'      => __('Docs', 'fluent-comments'),
            ],
            'user_avatar'   => 'https://secure.gravatar.com/avatar/?s=96&d=mm&r=g',
            'require_login' => get_option('comment_registration') && !get_current_user_id(),
        ];

        if (get_current_user_id()) {
            $currentUser = wp_get_current_user();
            $name = trim($currentUser->first_name . ' ' . $currentUser->last_name);
            if (!$name) {
                $name = $currentUser->display_name;
            }

            $vars['me'] = [
                'id'        => $currentUser->ID,
                'full_name' => $name,
                'email'     => $currentUser->user_email,
                'avatar'    => get_avatar_url($currentUser->user_email),
            ];
            $vars['user_avatar'] = $vars['me']['avatar'];
        } elseif ($vars['require_login']) {
            $vars['login_message'] = sprintf(
                __('You must be %1$slogged in%2$s to post a comment.', 'fluent-comments'),
                '<a class="flc_login_link" href="' . wp_login_url(get_permalink()) . '">',
                '</a>'
            );
        }

        wp_localize_script('fluent_comments', 'fluentCommentVars', $vars);
    }
}
