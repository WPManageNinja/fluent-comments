<?php

namespace FluentComments\App\Hooks\Handlers;

use FluentComments\App\Services\EmailService;
use FluentComments\App\Services\Helper;
use FluentComments\App\Services\Mailer;

/**
 * The three emails FluentComments sends to the people who comment.
 *
 * None of the content lives here any more. Subject and body come from
 * EmailService, which is the same place the Emails screen reads and writes,
 * so what a site owner previews is what gets sent. This file is only about
 * who receives what, and when.
 */
class CommentNotificationHandler
{
    /**
     * Matches the cap CommentSubmission::getCommentDepth() uses for the
     * same reason: a corrupted parent chain must terminate.
     */
    const MAX_THREAD_DEPTH = 100;

    public function register()
    {
        add_action('transition_comment_status', function ($new_status, $old_status, $comment) {
            if ($new_status === 'approved' && $old_status !== 'approved') {
                $this->maybeSendApprovalNotification($comment);
            }
        }, 10, 3);

        add_action('comment_post', function ($commentId, $approved) {
            // Not a boolean. Core passes 1, 0, 'spam' or 'trash' here, and
            // the last two are truthy strings - see self::isApproved().
            if (!self::isApproved($approved)) {
                return;
            }

            $comment = get_comment($commentId);

            if (!$comment || !self::isApproved($comment->comment_approved)) {
                return; // comment is not approved
            }

            $post = get_post($comment->comment_post_ID);

            if (!$post || !Helper::isFluentCommentsPostType($post->post_type)) {
                return; // not a fluent comments post type
            }

            $this->maybeSendNewCommentNotification($comment, $post);

        }, 10, 2);

        add_action('fluent_comments/after_added_comment', [$this, 'maybeSendNewCommentNotification'], 10, 2);
    }

    public function maybeSendApprovalNotification(\WP_Comment $comment)
    {
        // this comment got approved
        if (!self::isNotifiable($comment)) {
            return;
        }

        $post = get_post($comment->comment_post_ID);

        if (!$post || !Helper::isFluentCommentsPostType($post->post_type)) {
            return; // not a fluent comments post type
        }

        // Seeded whether or not the approval email itself is on: the point
        // is that nobody is told about their own comment, and that is true
        // of the reply and post author emails too.
        $sentEmailIds = [
            $comment->comment_author_email => $comment->comment_author_email,
        ];

        if (EmailService::isEnabled('comment_approved') && !self::alreadySent($comment, 'approved')) {
            self::markSent($comment, 'approved');
            $this->sendEmail($comment, $post, 'comment_approved', [
                [
                    'email' => $comment->comment_author_email,
                    'name'  => $comment->comment_author,
                ]
            ]);
        }

        // A comment that was already public once has already generated
        // these. Re-approving after an un-approve, or clearing a false
        // positive out of spam, must not send a second round.
        if (self::alreadySent($comment, 'new')) {
            return;
        }

        self::markSent($comment, 'new');

        $this->notifyPostAuthor($comment, $post, $sentEmailIds);
        $this->notifyThread($comment, $post, $sentEmailIds);
    }

    public function maybeSendNewCommentNotification(\WP_Comment $comment, $post)
    {
        if (!self::isApproved($comment->comment_approved) || !self::isNotifiable($comment)) {
            return;
        }

        if (!$post || !Helper::isFluentCommentsPostType($post->post_type)) {
            return; // not a fluent comments post type
        }

        // The native form reaches this through both 'comment_post' and
        // 'fluent_comments/after_added_comment', so only notify once. The
        // static covers this request, the meta covers a later approval
        // transition for a comment that was already public.
        static $notified = [];

        if (isset($notified[$comment->comment_ID]) || self::alreadySent($comment, 'new')) {
            return;
        }

        $notified[$comment->comment_ID] = true;
        self::markSent($comment, 'new');

        // Nobody is told about their own comment.
        $sentEmailIds = [
            $comment->comment_author_email => $comment->comment_author_email,
        ];

        $this->notifyPostAuthor($comment, $post, $sentEmailIds);
        $this->notifyThread($comment, $post, $sentEmailIds);
    }

    /**
     * Core's comment_approved is 1, 0, 'spam' or 'trash', and the last two
     * are truthy strings - so a truthiness test mails out the contents of
     * comments that were caught by Akismet or the disallowed keys list and
     * never appeared on the site. Core makes the same comparison in
     * wp_new_comment_notify_postauthor().
     *
     * @param int|string $approved
     * @return bool
     */
    private static function isApproved($approved)
    {
        return '1' === (string)$approved;
    }

    /**
     * Pingbacks and trackbacks arrive approved and go through the same
     * hooks, but they are not somebody writing to you: they have no author
     * email, so the "never tell someone about their own comment" seed would
     * be empty, and "X left a comment on your post" is the wrong sentence
     * for them anyway.
     *
     * @param \WP_Comment $comment
     * @return bool
     */
    private static function isNotifiable($comment)
    {
        if (!$comment) {
            return false;
        }

        $type = (string)$comment->comment_type;

        return ('' === $type || 'comment' === $type);
    }

    /**
     * @param \WP_Comment $comment
     * @param string $key
     * @return bool
     */
    private static function alreadySent($comment, $key)
    {
        return (bool)get_comment_meta($comment->comment_ID, '_fcom_sent_' . $key, true);
    }

    /**
     * Written before the mail goes out, not after: a send that throws must
     * not leave the comment eligible for a second round on the next hook.
     *
     * @param \WP_Comment $comment
     * @param string $key
     * @return void
     */
    private static function markSent($comment, $key)
    {
        update_comment_meta($comment->comment_ID, '_fcom_sent_' . $key, 1);
    }

    /**
     * @param \WP_Comment $comment
     * @param \WP_Post $post
     * @param array $sentEmailIds carried by reference, so one person never
     *                            gets two of these for the same comment
     * @return void
     */
    private function notifyPostAuthor($comment, $post, &$sentEmailIds)
    {
        if (!EmailService::isEnabled('new_comment_to_post_author')) {
            return;
        }

        $author = get_userdata($post->post_author);

        if (!$author || isset($sentEmailIds[$author->user_email])) {
            return;
        }

        $sentEmailIds[$author->user_email] = $author->user_email;

        $this->sendEmail($comment, $post, 'new_comment_to_post_author', [
            [
                'email' => $author->user_email,
                'name'  => $author->display_name,
            ]
        ]);
    }

    /**
     * @param \WP_Comment $comment
     * @param \WP_Post $post
     * @param array $sentEmailIds
     * @return void
     */
    private function notifyThread($comment, $post, &$sentEmailIds)
    {
        if (!$comment->comment_parent || !EmailService::isEnabled('reply_to_participants')) {
            return;
        }

        $receivers = [];

        foreach ($this->getCommentParents($comment) as $emailId => $parentComment) {
            if (isset($sentEmailIds[$emailId])) {
                continue;
            }

            $sentEmailIds[$emailId] = $emailId;
            $receivers[] = $parentComment;
        }

        if ($receivers) {
            $this->sendEmail($comment, $post, 'reply_to_participants', $receivers);
        }
    }

    /**
     * @param \WP_Comment $comment
     * @param \WP_Post $post
     * @param string $emailId
     * @param array $receivers
     * @return void
     */
    public function sendEmail($comment, $post, $emailId, $receivers = [])
    {
        if (!isset(EmailService::TOGGLES[$emailId]) || !EmailService::isEnabled($emailId)) {
            return;
        }

        foreach ($receivers as $receiver) {
            if (empty($receiver['email'])) {
                continue;
            }

            // Rendered per recipient rather than once: {{receiver.name}} is
            // a different value for each of them.
            $rendered = EmailService::render($emailId, [
                'comment'  => $comment,
                'post'     => $post,
                'receiver' => $receiver,
            ]);

            $mailer = new Mailer('', $rendered['subject'], $rendered['body']);
            $mailer->to($receiver['email'], $receiver['name']);
            $mailer->send();
        }
    }

    private function getCommentParents($currentComment)
    {
        $parentComments = [];
        $seen = [];

        $parentId = (int)$currentComment->comment_parent;
        // Traverse up the parent comments
        while ($parentId) {
            // An importer or a third party can leave comment_parent in a
            // cycle. Without this the walk runs until the worker times out.
            if (isset($seen[$parentId]) || count($seen) >= self::MAX_THREAD_DEPTH) {
                break;
            }

            $seen[$parentId] = true;

            $nextComment = get_comment($parentId);

            if (!$nextComment) {
                break; // No more parent comments or parent not found
            }

            // An unapproved, spam or trashed ancestor is not a thread
            // participant. Its author email is whatever the person who wrote
            // it typed, so honouring it would let anybody subscribe a third
            // party to a thread by posting a comment that never goes live.
            if (self::isApproved($nextComment->comment_approved)
                && self::isNotifiable($nextComment)
                && $nextComment->comment_author_email) {
                $parentComments[$nextComment->comment_author_email] = [
                    'email' => $nextComment->comment_author_email,
                    'name'  => $nextComment->comment_author
                ];
            }

            $parentId = (int)$nextComment->comment_parent;
        }

        return $parentComments;
    }
}
