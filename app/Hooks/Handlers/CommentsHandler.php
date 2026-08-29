<?php

namespace FluentComments\App\Hooks\Handlers;

use FluentComments\App\Services\CommentSubmission;
use FluentComments\App\Services\CommentsRepository;
use FluentComments\App\Services\Frontend;
use FluentComments\App\Services\Helper;
use FluentComments\App\Services\SpamGuard;

class CommentsHandler
{
    public function register()
    {
        add_filter('comments_template', [$this, 'maybeSwapCommentsTemplate'], PHP_INT_MAX - 1);

        add_action('wp_enqueue_scripts', [$this, 'maybeEnqueueNativeAssets']);

        add_action('wp_ajax_fluent_comment_post', [$this, 'handleAjaxComment']);
        add_action('wp_ajax_nopriv_fluent_comment_post', [$this, 'handleAjaxComment']);

        add_action('wp_ajax_fluent_comment_session', [$this, 'handleAjaxSession']);
        add_action('wp_ajax_nopriv_fluent_comment_session', [$this, 'handleAjaxSession']);

        add_action('wp_ajax_fluent_comment_list', [$this, 'handleAjaxList']);
        add_action('wp_ajax_nopriv_fluent_comment_list', [$this, 'handleAjaxList']);

        add_shortcode('fluent_comments', [$this, 'handleShortcode']);

        add_action('pre_comment_on_post', [$this, 'maybeRejectNativeComment']);
    }

    /**
     * Swap the theme's comments template for ours on classic themes.
     *
     * @param string $file
     * @return string
     */
    public function maybeSwapCommentsTemplate($file)
    {
        $post = get_post();

        if ($post && Helper::isHandlingComments($post)) {
            return FLUENT_COMMENTS_PLUGIN_PATH . 'app/Views/comments.php';
        }

        return $file;
    }

    /**
     * Assets for the native (classic theme) comment template.
     *
     * @return void
     */
    public function maybeEnqueueNativeAssets()
    {
        if (is_admin() || !is_singular() || Helper::isBlockTheme()) {
            return;
        }

        $post = get_post();

        if (!$post || !Helper::isHandlingComments($post) || !post_type_supports($post->post_type, 'comments')) {
            return;
        }

        wp_deregister_script('comment-reply');

        wp_enqueue_style(
            'fluent_comments',
            FLUENT_COMMENTS_PLUGIN_URL . 'dist/css/app.css',
            [],
            FLUENT_COMMENTS_VERSION
        );

        if (!comments_open($post)) {
            return;
        }

        wp_enqueue_script(
            'fluent_comments_native',
            FLUENT_COMMENTS_PLUGIN_URL . 'dist/js/native-comments.js',
            [],
            FLUENT_COMMENTS_VERSION,
            true
        );

        // Nothing here may vary by visitor: this markup goes into the page
        // cache and is served to everybody.
        wp_localize_script('fluent_comments_native', 'fluentCommentPublic', [
            'ajaxurl'  => admin_url('admin-ajax.php'),
            'honeypot' => SpamGuard::getHoneypotField(),
            'min_age'  => SpamGuard::getMinAge(),
            'i18n'     => [
                'network_error' => __('Network error. Please try again.', 'fluent-comments'),
                'generic_error' => __('Something went wrong. Please try again.', 'fluent-comments'),
            ],
        ]);
    }

    /**
     * Block native comment submissions, but only for posts where we have
     * actually rendered a replacement form.
     *
     * @param int $postId
     * @return void
     */
    public function maybeRejectNativeComment($postId)
    {
        // Our own submissions reach core through wp_handle_comment_submission(),
        // which fires this action, so let ours through.
        if (CommentSubmission::isInFlight() || SpamGuard::isTrustedUser()) {
            return;
        }

        $post = get_post($postId);

        if (!$post || !Helper::willRejectNativeComments($post)) {
            return;
        }

        wp_die(
            '<p>' . esc_html__('Direct comments are disabled. Please go back and use the comment form on the post.', 'fluent-comments') . '</p>',
            esc_html__('Comment Submission Failure', 'fluent-comments'),
            [
                'response'  => 403,
                'back_link' => true,
            ]
        );
    }

    /**
     * admin-ajax endpoint used by the native comment form.
     *
     * @return void
     */
    public function handleAjaxComment()
    {
        if (!$this->isPostRequest()) {
            $this->sendError(__('Invalid request method.', 'fluent-comments'), 405);
        }

        // Nonce verification does not apply here: comments are posted by
        // logged out visitors, so there is no session to tie a nonce to.
        // CSRF is covered by the submission token, which is bound to an
        // HttpOnly SameSite=Lax cookie that a cross site post can not send.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $data = wp_unslash($_POST);

        $postId = isset($data['comment_post_ID']) ? absint($data['comment_post_ID']) : 0;

        $comment = CommentSubmission::handle($postId, [
            'comment' => isset($data['comment']) ? $data['comment'] : '',
            'author'  => isset($data['author']) ? $data['author'] : '',
            'email'   => isset($data['email']) ? $data['email'] : '',
            'parent'  => isset($data['comment_parent']) ? $data['comment_parent'] : 0,
            'guard'   => $data,
        ]);

        if (is_wp_error($comment)) {
            $this->sendError($comment->get_error_message(), CommentSubmission::errorStatus($comment));
        }

        $approved = '1' === (string)$comment->comment_approved;

        wp_send_json([
            'comment_id'        => (int)$comment->comment_ID,
            'approved'          => $approved,
            'message'           => $approved
                ? __('Your comment has been added.', 'fluent-comments')
                : __('Your comment is awaiting moderation.', 'fluent-comments'),
            // The Svelte app renders from the structured comment, the
            // classic template injects the markup. One endpoint, both.
            'formatted_comment' => CommentsRepository::formatComment($comment),
            'comment_preview'   => $this->commentPreview($comment),
        ], 200);
    }

    /**
     * Read a page of comments. Used for "load more" only: the first page
     * is rendered into the document, so this is never hit on page load.
     *
     * @return void
     */
    public function handleAjaxList()
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- public read of approved comments on a public post.
        $postId = isset($_GET['comment_post_ID']) ? absint($_GET['comment_post_ID']) : 0;
        $page = isset($_GET['page']) ? absint($_GET['page']) : 1;
        $perPage = isset($_GET['per_page']) ? absint($_GET['per_page']) : 0;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $access = CommentSubmission::checkPostAccess(get_post($postId));

        if (is_wp_error($access)) {
            $this->sendError($access->get_error_message(), CommentSubmission::errorStatus($access));
        }

        wp_send_json(CommentsRepository::getPayload($postId, $page, $perPage), 200);
    }

    /**
     * Hand out a submission token and everything else about the visitor
     * that must not be baked into a cached page.
     *
     * This is on admin-ajax rather than the REST API on purpose: cookie
     * authentication works here without a nonce, so a logged in visitor is
     * recognised even though the page they came from was served from cache.
     *
     * @return void
     */
    public function handleAjaxSession()
    {
        if (!$this->isPostRequest()) {
            $this->sendError(__('Invalid request method.', 'fluent-comments'), 405);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- public endpoint for logged out visitors; rate limited below.
        $postId = isset($_POST['comment_post_ID']) ? absint($_POST['comment_post_ID']) : 0;
        $post = $postId ? get_post($postId) : null;

        if (!$post) {
            $this->sendError(__('Invalid post id.', 'fluent-comments'), 404);
        }

        $rateLimit = SpamGuard::checkTokenRateLimit();

        if (is_wp_error($rateLimit)) {
            $this->sendError($rateLimit->get_error_message(), 429);
        }

        SpamGuard::recordTokenIssued();

        wp_send_json(Frontend::getSessionPayload($postId), 200);
    }

    /**
     * @return string
     */
    public function handleShortcode()
    {
        $post = get_post();

        if (!$post || !post_type_supports($post->post_type, 'comments')) {
            return '';
        }

        if (post_password_required($post)) {
            return '';
        }

        return Frontend::renderApp($post->ID);
    }

    /**
     * Kept for backward compatibility with 2.0.x.
     *
     * @param int $postId
     * @return string
     */
    public function render($postId)
    {
        return Frontend::renderApp($postId);
    }

    /**
     * @return bool
     */
    private function isPostRequest()
    {
        return isset($_SERVER['REQUEST_METHOD']) && strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD']))) === 'POST';
    }

    /**
     * @param string $message
     * @param int $status
     * @return void
     */
    private function sendError($message, $status = 403)
    {
        wp_send_json(['message' => $message], $status);
    }

    /**
     * Markup for a freshly posted comment, injected by the native form.
     *
     * @param \WP_Comment $comment
     * @return string
     */
    private function commentPreview($comment)
    {
        ob_start();

        $comment_author = get_comment_author($comment);
        ?>
        <div id="comment-<?php echo (int)$comment->comment_ID; ?>" class="flc_comment fls_new_comment">
            <article class="flc_body">
                <?php if (get_option('show_avatars')) : ?>
                    <div class="flc_avatar">
                        <div class="flc_comment_author">
                            <?php echo wp_kses_post(get_avatar($comment, 64)); ?>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="flc_comment__details">
                    <div class="crayons-card">
                        <div class="comment__header">
                            <b class="fn"><?php echo esc_html($comment_author); ?></b>
                        </div>
                        <div class="flc_comment-content">
                            <?php
                            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook.
                            echo wp_kses_post(wpautop(apply_filters('get_comment_text', $comment->comment_content, $comment)));
                            if ('1' !== (string)$comment->comment_approved) {
                                ?>
                                <p class="comment-awaiting-moderation"><?php esc_html_e('Your comment is awaiting moderation.', 'fluent-comments'); ?></p>
                                <?php
                            }
                            ?>
                        </div>
                    </div>
                </div>
            </article>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * @deprecated 2.1.0 Use Helper::isBlockTheme().
     *
     * @return bool
     */
    public function isFseTheme()
    {
        return Helper::isBlockTheme();
    }
}
