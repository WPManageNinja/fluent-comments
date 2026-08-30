<?php

namespace FluentComments\App\Services;

/**
 * The single path every comment takes into the database, whichever
 * front end it came from.
 *
 * Everything here funnels into wp_handle_comment_submission(), which is
 * what wp-comments-post.php uses. Going through core rather than calling
 * wp_insert_comment() directly is the whole point: it is what fires
 * 'comment_post', where core's moderation and post author notification
 * emails live, along with all of core's own validation.
 *
 * What it deliberately does NOT do is run other plugins' comment form
 * validation. FluentComments renders its own form and does not fire
 * core's comment_form hooks, so a plugin that adds a CAPTCHA field never
 * gets to render one here. Letting its validator run anyway would reject
 * every comment on the site for want of a field that was never on the
 * page. So 'preprocess_comment' and 'pre_comment_on_post' are isolated
 * down to an allow list for the duration of the insert.
 *
 * Extending the form is done through our own hooks instead, which work
 * on both front ends:
 *
 *   fluent_comments/form_fields        render extra fields
 *   fluent_comments/validate_submission reject a submission
 *   fluent_comments/comment_data       filter the comment before insert
 *   fluent_comments/spam_score         nudge the spam score
 *
 * @see SpamGuard
 */
class CommentSubmission
{
    /**
     * Hooks that exist purely for third parties to hang comment form
     * validation on. Core registers nothing on either of them, so what is
     * here is always somebody else's.
     */
    const FOREIGN_HOOKS = ['preprocess_comment', 'pre_comment_on_post'];

    /**
     * True while a submission of ours is in flight, so the native comment
     * rejection knows to stand aside for it.
     *
     * @var bool
     */
    private static $inFlight = false;

    /**
     * Hook registries saved while foreign callbacks are isolated.
     *
     * @var array<string, \WP_Hook>
     */
    private static $suppressed = [];

    /**
     * Whether a submission of ours is currently being processed.
     *
     * @return bool
     */
    public static function isInFlight()
    {
        return self::$inFlight;
    }

    /**
     * Validate, screen and create a comment.
     *
     * @param int $postId
     * @param array $input {
     *     @type string $comment       The comment body.
     *     @type string $author        Author name, ignored when logged in.
     *     @type string $email         Author email, ignored when logged in.
     *     @type int    $parent        Parent comment id.
     *     @type array  $guard         The raw payload, for the spam guard.
     * }
     * @return \WP_Comment|\WP_Error
     */
    public static function handle($postId, array $input)
    {
        $postId = (int)$postId;
        $post = get_post($postId);

        $access = self::checkPostAccess($post);

        if (is_wp_error($access)) {
            return $access;
        }

        // The post type gate belongs here as much as it does on the render
        // side. Without it this endpoint is a second way into every public
        // comment-supporting post type on the site - and one that strips
        // that post type's own validators on the way through, because
        // suppressForeignHooks() runs below regardless.
        $accepts = apply_filters(
            'fluent_comments/accepts_submission',
            Helper::isFluentCommentsPostType($post->post_type)
            && post_type_supports($post->post_type, 'comments'),
            $post
        );

        if (!$accepts) {
            return new \WP_Error(
                'flc_comments_unsupported',
                __('Sorry, this post does not allow new comments.', 'fluent-comments'),
                ['status' => 403]
            );
        }

        // Core validates that a parent exists and is approved, but not that
        // it belongs to this post or that the thread has room for a reply.
        $parentId = self::resolveParentId(isset($input['parent']) ? $input['parent'] : 0, $postId);

        if (is_wp_error($parentId)) {
            return $parentId;
        }

        $guardData = isset($input['guard']) && is_array($input['guard']) ? $input['guard'] : [];

        // Our own validation slot: this is where an extender rejects a
        // submission, in place of core's 'preprocess_comment'.
        $valid = apply_filters('fluent_comments/validate_submission', true, $guardData, $post);

        if (is_wp_error($valid)) {
            return self::normalizeError($valid);
        }

        $verdict = SpamGuard::evaluate($postId, $guardData);

        if ($verdict['action'] === SpamGuard::ACTION_REJECT) {
            return $verdict['error'];
        }

        // Only ever pass core the fields a comment is made of. Anything a
        // third party plugin needs it reads from $_POST itself, inside
        // 'preprocess_comment', exactly as it would on a native submission.
        $commentData = [
            'comment_post_ID' => $postId,
            'comment'         => isset($input['comment']) ? (string)$input['comment'] : '',
            'comment_parent'  => $parentId,
            'author'          => isset($input['author']) ? (string)$input['author'] : '',
            'email'           => isset($input['email']) ? (string)$input['email'] : '',
            // Never accepted: a link in the author field is most of what
            // comment spam is actually for.
            'url'             => '',
        ];

        $commentData = apply_filters('fluent_comments/comment_data', $commentData, $guardData, $post);

        $hold = $verdict['action'] === SpamGuard::ACTION_HOLD;

        // The try opens here rather than at the insert. Everything below it
        // mutates state that outlives this function - the two hook
        // registries, two filters of our own, the in-flight flag - and
        // 'fluent_comments/before_process' hands control to somebody else's
        // code in the middle of that. A callback that throws used to skip
        // the finally altogether, leaving every other plugin's comment
        // validation stripped for the rest of the request.
        try {
            if ($hold) {
                add_filter('pre_comment_approved', [self::class, 'holdForModeration'], PHP_INT_MAX);
            }

            // Core stamps the comment with REMOTE_ADDR, which is the proxy on a
            // site that sits behind one. Record what the guard resolved instead,
            // otherwise every comment shares an address and moderating by IP
            // becomes useless.
            self::suppressForeignHooks();

            add_filter('preprocess_comment', [self::class, 'stampAuthorIp'], PHP_INT_MAX);

            self::$inFlight = true;

            do_action('fluent_comments/before_process', $post);

            $comment = wp_handle_comment_submission($commentData);
        } finally {
            self::$inFlight = false;

            remove_filter('preprocess_comment', [self::class, 'stampAuthorIp'], PHP_INT_MAX);

            if ($hold) {
                remove_filter('pre_comment_approved', [self::class, 'holdForModeration'], PHP_INT_MAX);
            }

            self::restoreForeignHooks();
        }

        if (is_wp_error($comment)) {
            return self::normalizeError($comment);
        }

        SpamGuard::recordSubmission($verdict['jti']);

        do_action('fluent_comments/after_added_comment', $comment, $post);

        return $comment;
    }

    /**
     * Send a borderline comment to the moderation queue.
     *
     * Anything already marked spam or trash by core or another plugin keeps
     * that status: this only ever downgrades an approval, never upgrades.
     *
     * @param int|string|\WP_Error $approved
     * @return int|string|\WP_Error
     */
    public static function holdForModeration($approved)
    {
        if (is_wp_error($approved) || 'spam' === $approved || 'trash' === $approved) {
            return $approved;
        }

        return 0;
    }

    /**
     * Strip other plugins' callbacks off core's comment validation hooks
     * for the duration of our insert.
     *
     * We render our own form and do not fire core's comment_form hooks, so
     * a plugin that would have added a CAPTCHA field never got the chance.
     * Running its validator regardless would reject every comment on the
     * site over a field that was never on the page. Rendering its field
     * without running its validator would be worse still: a CAPTCHA that
     * looks like it protects the form and does not.
     *
     * Akismet is on the allow list because it validates the comment itself
     * rather than a field it had to render, so it needs nothing from the
     * form. Add to the list to keep another one:
     *
     *     add_filter('fluent_comments/allowed_comment_hooks', function ($allowed) {
     *         $allowed[] = 'My_Plugin::check_comment';
     *         return $allowed;
     *     });
     *
     * @return void
     */
    private static function suppressForeignHooks()
    {
        global $wp_filter;

        $allowed = (array)apply_filters('fluent_comments/allowed_comment_hooks', [
            'Akismet::auto_check_comment',
        ]);

        self::$suppressed = [];

        foreach (self::FOREIGN_HOOKS as $hook) {
            if (empty($wp_filter[$hook]) || !($wp_filter[$hook] instanceof \WP_Hook)) {
                continue;
            }

            self::$suppressed[$hook] = $wp_filter[$hook];

            $kept = new \WP_Hook();

            foreach ($wp_filter[$hook]->callbacks as $priority => $callbacks) {
                foreach ($callbacks as $callback) {
                    if (in_array(self::callbackName($callback['function']), $allowed, true)) {
                        $kept->add_filter($hook, $callback['function'], $priority, $callback['accepted_args']);
                    }
                }
            }

            $wp_filter[$hook] = $kept;
        }
    }

    /**
     * Put the hook registries back exactly as they were.
     *
     * @return void
     */
    private static function restoreForeignHooks()
    {
        global $wp_filter;

        foreach (self::$suppressed as $hook => $original) {
            $wp_filter[$hook] = $original;
        }

        self::$suppressed = [];
    }

    /**
     * A stable, matchable name for a registered callback.
     *
     * Closures are unnameable and so can never be allow listed; a plugin
     * that registers one should hook fluent_comments/validate_submission
     * instead.
     *
     * @param mixed $callback
     * @return string
     */
    private static function callbackName($callback)
    {
        if (is_string($callback)) {
            return $callback;
        }

        if (is_array($callback) && count($callback) === 2) {
            $class = is_object($callback[0]) ? get_class($callback[0]) : (string)$callback[0];

            return $class . '::' . $callback[1];
        }

        if (is_object($callback) && !($callback instanceof \Closure)) {
            return get_class($callback) . '::__invoke';
        }

        return '';
    }

    /**
     * Record the visitor's real address on the comment.
     *
     * @param array $commentData
     * @return array
     */
    public static function stampAuthorIp($commentData)
    {
        $commentData['comment_author_IP'] = SpamGuard::getIp();

        return $commentData;
    }

    /**
     * Comments are only public when the post itself is.
     *
     * @param \WP_Post|null $post
     * @return true|\WP_Error
     */
    public static function checkPostAccess($post)
    {
        if (!$post) {
            return new \WP_Error(
                'flc_invalid_post',
                __('Invalid post.', 'fluent-comments'),
                ['status' => 404]
            );
        }

        $canRead = function_exists('is_post_publicly_viewable')
            ? is_post_publicly_viewable($post)
            : ('publish' === $post->post_status);

        if (!$canRead && !current_user_can('read_post', $post->ID)) {
            return new \WP_Error(
                'flc_invalid_post',
                __('Invalid post.', 'fluent-comments'),
                ['status' => 404]
            );
        }

        if (post_password_required($post)) {
            return new \WP_Error(
                'flc_password_required',
                __('This post is password protected.', 'fluent-comments'),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * Validate the parent comment and keep the thread within the depth
     * allowed by the discussion settings.
     *
     * @param mixed $parentId
     * @param int $postId
     * @return int|\WP_Error
     */
    public static function resolveParentId($parentId, $postId)
    {
        $parentId = (int)$parentId;

        if (!$parentId) {
            return 0;
        }

        $parent = get_comment($parentId);

        if (!$parent || (int)$parent->comment_post_ID !== (int)$postId || '1' !== (string)$parent->comment_approved) {
            return new \WP_Error(
                'flc_invalid_parent',
                __('The comment you are replying to could not be found.', 'fluent-comments'),
                ['status' => 400]
            );
        }

        if (self::getCommentDepth($parent) >= Helper::getMaxDepth()) {
            return new \WP_Error(
                'flc_max_depth',
                __('Replies to this comment are not allowed.', 'fluent-comments'),
                ['status' => 400]
            );
        }

        return $parentId;
    }

    /**
     * 1 based depth of a comment within its thread.
     *
     * @param \WP_Comment $comment
     * @return int
     */
    public static function getCommentDepth($comment)
    {
        $depth = 1;
        $parentId = (int)$comment->comment_parent;

        // Guard against a corrupted parent chain looping forever.
        while ($parentId && $depth < 100) {
            $parent = get_comment($parentId);

            if (!$parent) {
                break;
            }

            $depth++;
            $parentId = (int)$parent->comment_parent;
        }

        return $depth;
    }

    /**
     * Give core's comment errors an HTTP status.
     *
     * wp_handle_comment_submission() puts a bare int in the error data, or
     * nothing at all, so map the codes we know and fall back sensibly.
     *
     * @param \WP_Error $error
     * @return \WP_Error
     */
    private static function normalizeError($error)
    {
        $code = $error->get_error_code();
        $data = $error->get_error_data();

        if (is_array($data) && isset($data['status'])) {
            return $error;
        }

        $statuses = [
            'comment_id_not_found'                => 404,
            'comment_on_trash'                    => 404,
            'comment_on_draft'                    => 403,
            'comment_on_password_protected'       => 403,
            'comment_closed'                      => 403,
            'not_logged_in'                       => 401,
            'comment_reply_to_unapproved_comment' => 403,
            'require_name_email'                  => 400,
            'require_valid_email'                 => 400,
            'require_valid_comment'               => 400,
            'comment_duplicate'                   => 409,
            'comment_flood'                       => 429,
            'comment_save_error'                  => 500,
        ];

        if (isset($statuses[$code])) {
            $status = $statuses[$code];
        } elseif (is_int($data) && $data >= 400 && $data < 600) {
            $status = $data;
        } else {
            $status = 400;
        }

        $message = $error->get_error_message();

        if (!$message) {
            $message = __('Your comment could not be posted.', 'fluent-comments');
        }

        return new \WP_Error($code, $message, ['status' => $status]);
    }

    /**
     * The HTTP status carried by an error from this service.
     *
     * @param \WP_Error $error
     * @return int
     */
    public static function errorStatus($error)
    {
        $data = $error->get_error_data();

        if (is_array($data) && isset($data['status'])) {
            return (int)$data['status'];
        }

        if (is_int($data) && $data >= 400 && $data < 600) {
            return $data;
        }

        return 403;
    }
}
