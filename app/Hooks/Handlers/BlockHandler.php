<?php

namespace FluentComments\App\Hooks\Handlers;

use FluentComments\App\Services\Frontend;
use FluentComments\App\Services\Helper;

class BlockHandler
{
    public function register()
    {
        add_action('init', [$this, 'registerBlock']);

        // On block themes the theme template renders the core Comments
        // block. Replace it, otherwise the native form stays on the page
        // and rejecting native submissions would lock the post entirely.
        add_filter('render_block', [$this, 'maybeReplaceCoreComments'], 10, 2);
    }

    public function registerBlock()
    {
        register_block_type(
            FLUENT_COMMENTS_PLUGIN_PATH . 'resources/block/block.json',
            [
                'render_callback' => [$this, 'renderBlock'],
            ]
        );
    }

    /**
     * @param array $attributes
     * @param string $content
     * @param \WP_Block|null $block
     * @return string
     */
    public function renderBlock($attributes, $content = '', $block = null)
    {
        $postId = $this->resolvePostId($block);

        // The site editor has no real post to work with.
        if (!$postId || is_admin()) {
            return $this->renderPlaceholder($attributes);
        }

        $postType = get_post_type($postId);

        if (!$postType || !post_type_supports($postType, 'comments')) {
            return '';
        }

        if (post_password_required($postId)) {
            return '';
        }

        return Frontend::renderApp($postId, $attributes);
    }

    /**
     * Swap the core Comments block for ours on the post types we handle.
     *
     * @param string $blockContent
     * @param array $block
     * @return string
     */
    public function maybeReplaceCoreComments($blockContent, $block)
    {
        if (is_admin() || empty($block['blockName'])) {
            return $blockContent;
        }

        if (!in_array($block['blockName'], ['core/comments', 'core/post-comments'], true)) {
            return $blockContent;
        }

        if (!Helper::isBlockTheme() || !Helper::isBlockThemeTakeoverEnabled()) {
            return $blockContent;
        }

        $post = get_post();

        if (!$post || !Helper::isHandlingComments($post)) {
            return $blockContent;
        }

        if (!post_type_supports($post->post_type, 'comments') || post_password_required($post)) {
            return $blockContent;
        }

        $attributes = [
            'showTitle'   => true,
            'showAvatars' => (bool)get_option('show_avatars'),
        ];

        if (!empty($block['attrs']['align'])) {
            $attributes['align'] = $block['attrs']['align'];
        }

        return Frontend::renderApp($post->ID, $attributes);
    }

    /**
     * The block can live inside a template or a query loop, where the
     * global post is not what we want.
     *
     * @param \WP_Block|null $block
     * @return int
     */
    private function resolvePostId($block)
    {
        if ($block instanceof \WP_Block && !empty($block->context['postId'])) {
            return (int)$block->context['postId'];
        }

        $postId = get_the_ID();

        if ($postId) {
            return (int)$postId;
        }

        $post = get_post();

        return $post ? (int)$post->ID : 0;
    }

    /**
     * @param array $attributes
     * @return string
     */
    private function renderPlaceholder($attributes)
    {
        $cssVars = Frontend::buildCssVariables($attributes);
        $wrapperClass = 'fluent-comments-block';

        if (!empty($attributes['align'])) {
            $wrapperClass .= ' align' . sanitize_html_class($attributes['align']);
        }

        ob_start();
        ?>
        <div class="<?php echo esc_attr($wrapperClass); ?>"<?php echo $cssVars ? ' style="' . esc_attr($cssVars) . '"' : ''; ?>>
            <div class="fluent_dynamic_comments">
                <p style="text-align: center; padding: 40px 20px; background: #f5f5f5; border-radius: 4px;">
                    <?php esc_html_e('Fluent Comments will appear here on the frontend.', 'fluent-comments'); ?>
                </p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
