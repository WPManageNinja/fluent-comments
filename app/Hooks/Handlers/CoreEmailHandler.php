<?php

namespace FluentComments\App\Hooks\Handlers;

use FluentComments\App\Services\EmailService;
use FluentComments\App\Services\Helper;

/**
 * WordPress's two comment notices, rewritten on the way out.
 *
 * We do not send these - core does, from wp_notify_postauthor() and
 * wp_notify_moderator() - so this hooks the filters core offers on their
 * subject, body and headers rather than replacing the functions. That
 * keeps core's recipient list, its own gating, and any other plugin's
 * involvement intact; only the words change.
 *
 * ## Nothing happens until somebody asks
 *
 * A core email is only rewritten at status `active`. `system` deliberately
 * means "leave WordPress alone", not "send our version of it": these
 * notices predate the plugin on every site that installs it, and quietly
 * turning a plain text notice into an HTML one on upgrade is not a change
 * anyone asked for. The editor still starts from our HTML default, so
 * "Start from the default" gives a site owner something to work from.
 *
 * ## Scope
 *
 * Only on the post types FluentComments runs on. A site that switched
 * FluentComments on for `post` alone should not find its product review
 * notifications rewritten too.
 */
class CoreEmailHandler
{
    public function register()
    {
        add_filter('comment_notification_subject', [$this, 'notificationSubject'], 20, 2);
        add_filter('comment_notification_text', [$this, 'notificationText'], 20, 2);
        add_filter('comment_notification_headers', [$this, 'notificationHeaders'], 20, 2);

        add_filter('comment_moderation_subject', [$this, 'moderationSubject'], 20, 2);
        add_filter('comment_moderation_text', [$this, 'moderationText'], 20, 2);
        add_filter('comment_moderation_headers', [$this, 'moderationHeaders'], 20, 2);
    }

    /**
     * @param string $subject
     * @param int $commentId
     * @return string
     */
    public function notificationSubject($subject, $commentId)
    {
        return $this->subject('core_comment_notification', $subject, $commentId);
    }

    /**
     * @param string $message
     * @param int $commentId
     * @return string
     */
    public function notificationText($message, $commentId)
    {
        return $this->body('core_comment_notification', $message, $commentId);
    }

    /**
     * @param string $headers
     * @param int $commentId
     * @return string
     */
    public function notificationHeaders($headers, $commentId)
    {
        return $this->headers('core_comment_notification', $headers, $commentId);
    }

    /**
     * @param string $subject
     * @param int $commentId
     * @return string
     */
    public function moderationSubject($subject, $commentId)
    {
        return $this->subject('core_comment_moderation', $subject, $commentId);
    }

    /**
     * @param string $message
     * @param int $commentId
     * @return string
     */
    public function moderationText($message, $commentId)
    {
        return $this->body('core_comment_moderation', $message, $commentId);
    }

    /**
     * @param string $headers
     * @param int $commentId
     * @return string
     */
    public function moderationHeaders($headers, $commentId)
    {
        return $this->headers('core_comment_moderation', $headers, $commentId);
    }

    /**
     * @param string $emailId
     * @param string $original
     * @param int $commentId
     * @return string
     */
    private function subject($emailId, $original, $commentId)
    {
        $context = $this->context($emailId, $commentId);

        if (!$context) {
            return $original;
        }

        $subject = EmailService::renderSubject($emailId, $context);

        return $subject ? $subject : $original;
    }

    /**
     * @param string $emailId
     * @param string $original
     * @param int $commentId
     * @return string
     */
    private function body($emailId, $original, $commentId)
    {
        $context = $this->context($emailId, $commentId);

        if (!$context) {
            return $original;
        }

        $body = EmailService::renderBody($emailId, $context);

        return $body ? $body : $original;
    }

    /**
     * The body we substituted is HTML, so the Content-Type core wrote has
     * to change with it. From and Reply-To follow the template settings
     * when they are filled in, and core's own From stays when they are not.
     *
     * @param string $emailId
     * @param string $original
     * @param int $commentId
     * @return string
     */
    private function headers($emailId, $original, $commentId)
    {
        if (!$this->context($emailId, $commentId)) {
            return $original;
        }

        $config = EmailService::getTemplateSettings();
        $hasFrom = !empty($config['from_email']) && is_email($config['from_email']);
        $hasReplyTo = !empty($config['reply_to_email']) && is_email($config['reply_to_email']);

        $kept = [];

        foreach (preg_split('/\r\n|\r|\n/', (string)$original) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (stripos($line, 'content-type:') === 0) {
                continue; // replaced below
            }

            if ($hasFrom && stripos($line, 'from:') === 0) {
                continue;
            }

            if ($hasReplyTo && stripos($line, 'reply-to:') === 0) {
                continue;
            }

            $kept[] = $line;
        }

        // getMailHeaders() adds the Content-Type and whichever of From and
        // Reply-To the template settings define.
        return implode("\n", EmailService::getMailHeaders($kept)) . "\n";
    }

    /**
     * The render context, or nothing when this comment is none of our
     * business.
     *
     * @param string $emailId
     * @param int $commentId
     * @return array|null
     */
    private function context($emailId, $commentId)
    {
        if (EmailService::getStatus($emailId) !== 'active') {
            return null;
        }

        $comment = get_comment($commentId);

        if (!$comment) {
            return null;
        }

        $post = get_post($comment->comment_post_ID);

        if (!$post || !Helper::isFluentCommentsPostType($post->post_type)) {
            return null;
        }

        /**
         * Whether to rewrite one of core's comment notices.
         *
         * @param bool $rewrite
         * @param string $emailId
         * @param \WP_Comment $comment
         */
        if (!apply_filters('fluent_comments/rewrite_core_email', true, $emailId, $comment)) {
            return null;
        }

        // No receiver: core sends one body to a list of people, so there is
        // no single name to greet. The editor leaves the recipient codes
        // out of the list for these two for the same reason.
        return [
            'comment'  => $comment,
            'post'     => $post,
            'receiver' => [],
        ];
    }
}
