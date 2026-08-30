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
    public function register()
    {
        add_action('transition_comment_status', function ($new_status, $old_status, $comment) {
            if ($new_status === 'approved' && $old_status !== 'approved') {
                $this->maybeSendApprovalNotification($comment);
            }
        }, 10, 3);

        add_action('comment_post', function ($commentId, $approved) {
            if (!$approved) {
                return;
            }

            $comment = get_comment($commentId);

            if (!$comment || !$comment->comment_approved) {
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
        $post = get_post($comment->comment_post_ID);

        if (!$post || !Helper::isFluentCommentsPostType($post->post_type)) {
            return; // not a fluent comments post type
        }

        $sentEmailIds = [];

        if (EmailService::isEnabled('comment_approved')) {
            $sentEmailIds[$comment->comment_author_email] = $comment->comment_author_email;
            $this->sendEmail($comment, $post, 'comment_approved', [
                [
                    'email' => $comment->comment_author_email,
                    'name'  => $comment->comment_author,
                ]
            ]);
        }

        $this->notifyPostAuthor($comment, $post, $sentEmailIds);
        $this->notifyThread($comment, $post, $sentEmailIds);
    }

    public function maybeSendNewCommentNotification(\WP_Comment $comment, $post)
    {
        if (!$comment->comment_approved) {
            return; // comment is not approved
        }

        if (!$post || !Helper::isFluentCommentsPostType($post->post_type)) {
            return; // not a fluent comments post type
        }

        // The native form reaches this through both 'comment_post' and
        // 'fluent_comments/after_added_comment', so only notify once.
        static $notified = [];

        if (isset($notified[$comment->comment_ID])) {
            return;
        }

        $notified[$comment->comment_ID] = true;

        // Nobody is told about their own comment.
        $sentEmailIds = [
            $comment->comment_author_email => $comment->comment_author_email,
        ];

        $this->notifyPostAuthor($comment, $post, $sentEmailIds);
        $this->notifyThread($comment, $post, $sentEmailIds);
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
        if (!isset(EmailService::TOGGLES[$emailId])) {
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

        $parentId = $currentComment->comment_parent;
        // Traverse up the parent comments
        while ($parentId) {
            $nextComment = get_comment($parentId);
            if ($nextComment) {
                $parentComments[$nextComment->comment_author_email] = [
                    'email' => $nextComment->comment_author_email,
                    'name'  => $nextComment->comment_author
                ];
                $parentId = $nextComment->comment_parent;
            } else {
                break; // No more parent comments or parent not found
            }
        }

        return $parentComments;
    }
}
