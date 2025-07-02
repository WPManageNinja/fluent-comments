<?php

namespace FluentComments\App\Hooks\Handlers;

use FluentComments\App\Services\Helper;

class CommentNotificationHandler
{
    public function register()
    {
        add_action('transition_comment_status', function ($new_status, $old_status, $comment) {
            if ($new_status === 'approved' && $old_status !== 'approved') {
                $this->maybeSendApprovalNotification($comment);
            }
        }, 10, 3);


        add_action('fluent_comments/after_added_comment', [$this, 'maybeSendNewCommentNotification'], 10, 2);

    }

    public function maybeSendApprovalNotification(\WP_Comment $comment)
    {
        // this comment got approved
        $post = get_post($comment->comment_post_ID);

        if ($post && !Helper::isFluentCommentsPostType($post->post_type)) {
            return; // not a fluent comments post type
        }

        // we send an approval email to the author of the comment

    }

    public function maybeSendNewCommentNotification(\WP_Comment $comment, $post)
    {
        if (!$comment->comment_approved) {
            return; // comment is not approved
        }


    }


}
