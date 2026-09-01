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
        add_filter('comments_template_query_args', [$this, 'onlyApprovedComments']);

        add_action('wp_enqueue_scripts', [$this, 'maybeEnqueueAssets']);

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
     * The app, on a classic theme.
     *
     * renderApp() enqueues these itself, but it runs while the template is
     * being included - after wp_head - so the stylesheet would land in the
     * footer and the comment section would repaint in front of the reader.
     * Hooking wp_enqueue_scripts as well puts it in the head. enqueueAssets()
     * is idempotent, so the second call from renderApp() is free.
     *
     * Note there is no comments_open() check. The list is rendered by the
     * same script whether or not anybody may add to it; only the form is
     * conditional, and comments.svelte decides that from the payload.
     *
     * @return void
     */
    public function maybeEnqueueAssets()
    {
        if (is_admin() || !is_singular() || Helper::isBlockTheme()) {
            return;
        }

        $post = get_post();

        if (!$post || !Helper::isHandlingComments($post) || !post_type_supports($post->post_type, 'comments')) {
            return;
        }

        // Core's reply script moves core's form around core's markup, none of
        // which is on this page.
        wp_deregister_script('comment-reply');

        Frontend::enqueueAssets();
    }

    /**
     * Drop core's include_unapproved from the query comments_template() runs.
     *
     * Nothing of ours renders from that query any more - the template it
     * ends up including asks CommentsRepository for its own approved-only
     * payload - but comments_template() runs it before the comments_template
     * filter is even reached, and leaves the result in $wp_query->comments
     * for whatever else is on the page.
     *
     * comments_template() adds the current user, or the email address in the
     * visitor's comment cookie, to include_unapproved so that somebody can
     * see their own comment while it waits for moderation. That is the right
     * call for an uncached page and the wrong one here: it makes the query
     * vary by visitor on a page written to be cached and served to other
     * people, and one visitor's held comment is exactly the kind of thing
     * that must not travel that way.
     *
     * Nothing is really lost. A comment posted in this session is still
     * shown immediately - the app renders what the submit returned - so the
     * only case that changes is reloading the page later and finding your
     * own held comment gone from the list.
     *
     * Scoped to the posts we render, so a theme's own comment template on
     * any other post type keeps core's behaviour.
     *
     * @param array $args
     * @return array
     */
    public function onlyApprovedComments($args)
    {
        $post = get_post();

        if (!$post || !Helper::isHandlingComments($post)) {
            return $args;
        }

        unset($args['include_unapproved']);

        return $args;
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
            // The structured comment, rendered by CommentBlock.svelte. There
            // used to be a second, pre-rendered HTML copy here for the
            // classic template to inject; both front ends are the same app
            // now, so there is one shape to keep right.
            //
            // The depth has to be the real one. Left at the default of 1, a
            // reply accepted at the bottom of a thread comes back claiming
            // to be top level and renders a Reply link that the server will
            // refuse - an error the visitor can only clear by reloading.
            'formatted_comment' => CommentsRepository::formatComment(
                $comment,
                CommentSubmission::getCommentDepth($comment)
            ),
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

        // The same check the list and submit endpoints make. Without it a
        // draft or private post answers with a token and, through
        // renderFormFields(), whatever an extension echoes for it.
        $access = CommentSubmission::checkPostAccess($post);

        if (is_wp_error($access)) {
            $this->sendError($access->get_error_message(), CommentSubmission::errorStatus($access));
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
     * @deprecated 2.1.0 Use Helper::isBlockTheme().
     *
     * @return bool
     */
    public function isFseTheme()
    {
        return Helper::isBlockTheme();
    }
}
