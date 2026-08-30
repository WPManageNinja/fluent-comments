<?php

namespace FluentComments\App\Hooks\Handlers;

use FluentComments\App\Services\Frontend;

class BlockHandler
{
    public function register()
    {
        add_action('init', [$this, 'registerBlock']);
    }

    public function registerBlock()
    {
        $blockType = register_block_type(
            FLUENT_COMMENTS_PLUGIN_PATH . 'resources/block/block.json',
            [
                'render_callback' => [$this, 'renderBlock'],
            ]
        );

        if (!$blockType) {
            return;
        }

        // The editor's own __() calls resolve against a JSON file rather
        // than the .mo, and only once the handle is pointed at one. The
        // handle is generated from block.json, so it is read back off the
        // registered type rather than guessed at.
        foreach ($blockType->editor_script_handles as $handle) {
            wp_set_script_translations($handle, 'fluent-comments', FLUENT_COMMENTS_PLUGIN_PATH . 'languages');
        }
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
                    <?php esc_html_e('FluentComments will appear here on the frontend.', 'fluent-comments'); ?>
                </p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
