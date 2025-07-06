<?php

namespace FluentComments\App\Hooks\Handlers;

use FluentComments\App\Services\Helper;
use FluentComments\App\Services\Mailer;

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

        if (!$post || !Helper::isFluentCommentsPostType($post->post_type)) {
            return; // not a fluent comments post type
        }

        $settings = Helper::getCommentSettings();
        $sentEmailIds = [];
        if ($settings['email_on_comment_approval'] === 'yes') {
            $sentEmailIds[$comment->comment_author_email] = $comment->comment_author_email;
            $this->sendEmail($comment, $post, 'approved', [
                [
                    'email' => $comment->comment_author_email,
                    'name'  => $comment->comment_author,
                ]
            ]);
        }

        // sent to post author
        if ($settings['email_to_author'] === 'yes') {
            $author = get_userdata($post->post_author);
            if ($author) {
                $email = $author->user_email;
                if (!isset($sentEmailIds[$email])) {
                    $sentEmailIds[$email] = $email;
                    $this->sendEmail($comment, $post, 'to_post_author', [
                        [
                            'email' => $email,
                            'name'  => $author->display_name,
                        ]
                    ]);
                }
            }
        }

        // sent to comment parents
        if ($comment->comment_parent && $settings['email_on_reply'] === 'yes') {
            $parentComments = $this->getCommentParents($comment);

            $receivers = [];
            foreach ($parentComments as $emailid => $parentComment) {
                if (!isset($sentEmailIds[$emailid])) {
                    $receivers[] = $parentComment;
                }
            }

            if ($receivers) {
                $this->sendEmail($comment, $post, 'to_comment_parents', $receivers);
            }
        }
    }

    public function maybeSendNewCommentNotification(\WP_Comment $comment, $post)
    {
        if (!$comment->comment_approved) {
            return; // comment is not approved
        }

        if (!Helper::isFluentCommentsPostType($post->post_type)) {
            return; // not a fluent comments post type
        }

        $settings = Helper::getCommentSettings();
        $sentEmailIds = [];

        $sentEmailIds[$comment->comment_author_email] = $comment->comment_author_email;

        // sent to post author
        if ($settings['email_to_author'] === 'yes') {
            $author = get_userdata($post->post_author);
            if ($author) {
                $email = $author->user_email;
                if (!isset($sentEmailIds[$email])) {
                    $sentEmailIds[$email] = $email;
                    $this->sendEmail($comment, $post, 'to_post_author', [
                        [
                            'email' => $email,
                            'name'  => $author->display_name,
                        ]
                    ]);
                }
            }
        }

        // sent to comment parents
        if ($comment->comment_parent && $settings['email_on_reply'] === 'yes') {
            $parentComments = $this->getCommentParents($comment);

            $receivers = [];
            foreach ($parentComments as $emailid => $parentComment) {
                if (!isset($sentEmailIds[$emailid])) {
                    $receivers[] = $parentComment;
                }
            }

            if ($receivers) {
                $this->sendEmail($comment, $post, 'to_comment_parents', $receivers);
            }
        }

    }

    public function sendEmail($comment, $post, $type, $receivers = [])
    {
        if ($type == 'approved') {
            // Send approval notification
            $subject = sprintf(__('Comment Approved: %s', 'fluent-comments'), $post->post_title);
            $emailBody = $this->getEmailBody($comment, $post, $type);
        } else if ($type == 'to_post_author') {
            // Send notification to post author
            $subject = sprintf(__('New Comment on Your Post: %s', 'fluent-comments'), $post->post_title);
            $emailBody = $this->getEmailBody($comment, $post, $type);
        } else if ($type == 'to_comment_parents') {
            // Send notification to comment parents
            $subject = sprintf(__('New Reply to Your Comment in: %s', 'fluent-comments'), $post->post_title);
            $emailBody = $this->getEmailBody($comment, $post, $type);
        } else {
            return; // unknown type
        }

        $emailBody = (string)$this->wrapBody($emailBody);

        foreach ($receivers as $receiver) {
            $body = str_replace('{{receiver_name}}', $receiver['name'], $emailBody);
            $mailer = new Mailer($receiver['email'], $subject, $body);
            if ($receiver['name']) {
                $mailer->to($receiver['email'], $receiver['name']);
            }

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

    private function getEmailBody(\WP_Comment $comment, $post, $type)
    {
        if ($type == 'approved') {
            ob_start();
            ?>
            <p style="font-size:16px;color:rgb(55,65,81);margin-bottom:16px;margin-top:0px;line-height:24px"><?php esc_html_e(sprintf('Hi %s,', '{{receiver_name}}')); ?></p>
            <p style="font-size:16px;color:rgb(55,65,81);margin-bottom:16px;margin-top:0px;line-height:24px"><?php echo wp_kses_post(sprintf(__('Your comment on "%s" has been approved and is now live on our site.', 'fluent-comments'), '<b>' . $post->post_title . '</b>')); ?></p>

            <table align="center" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation"
                   style="background-color:rgb(249,250,251);border-radius:8px;padding:20px;margin-bottom:24px">
                <tbody>
                <tr>
                    <td>
                        <p style="font-size:14px;color:rgb(75,85,99);margin-bottom:8px;margin-top:0px;font-weight:600;line-height:24px">
                            <?php esc_html_e('Your Comment:', 'fluent-comments'); ?></h3>
                        </p>
                        <p style="font-size:14px;color:rgb(21,128,61);margin-bottom:0px;margin-top:0px;font-style:italic;line-height:24px"><?php echo esc_html($comment->comment_content); ?></p>
                    </td>
                </tr>
                </tbody>
            </table>

            <div style="text-align: center;">
                <table align="center" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation"
                       style="text-align:center;margin-bottom:32px">
                    <tbody>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url(get_the_permalink($post)); ?>#comment-<?php echo $comment->comment_ID; ?>"
                               style="background-color:rgb(37,99,235);color:rgb(255,255,255);padding-left:24px;padding-right:24px;padding-top:12px;padding-bottom:12px;border-radius:6px;font-size:16px;font-weight:600;text-decoration-line:none;box-sizing:border-box;display:inline-block"
                               target="_blank"><?php esc_html_e('View Your Comment', 'fluent-comments'); ?></a></td>
                    </tr>
                    </tbody>
                </table>
            </div>
            <p style="font-size:16px;color:rgb(55,65,81);margin-bottom:16px;margin-top:0px;line-height:24px"><?php esc_html_e('Thank you for engaging with our content! We appreciate your thoughtful contribution to the discussion.', 'fluent-comments'); ?></p>
            <p style="font-size:16px;color:rgb(55,65,81);margin-bottom:16px;margin-top:0px;line-height:24px"><?php esc_html_e('Keep the conversation going by sharing your thoughts on other posts too.', 'fluent-comments'); ?></p>
            <?php
            return ob_get_clean();
        }

        if ($type == 'to_post_author') {
            ob_start();
            ?>
            <p style="font-size:16px;color:rgb(55,65,81);margin-bottom:16px;margin-top:0px;line-height:24px"><?php esc_html_e(sprintf('Hi %s,', '{{receiver_name}}')); ?></p>
            <p style="font-size:16px;color:rgb(55,65,81);margin-bottom:16px;margin-top:0px;line-height:24px"><?php echo wp_kses_post(sprintf(__('You have received a new comment on your post "%s"', 'fluent-comments'), '<b>' . $post->post_title . '</b>')); ?></p>

            <table align="center" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation"
                   style="background-color:rgb(249,250,251);border-radius:8px;padding:20px;margin-bottom:24px">
                <tbody>
                <tr>
                    <td>
                        <p style="font-size:14px;color:rgb(75,85,99);margin-bottom:8px;margin-top:0px;font-weight:600;line-height:24px">
                            <?php echo esc_html(sprintf(__('Comment from: %s', 'fluent-comments'), $comment->comment_author)); ?>
                        </p>
                        <p style="font-size:14px;color:rgb(55,65,81);font-style:italic;margin-bottom:0px;margin-top:0px;line-height:24px">
                            <?php echo wp_kses_post($comment->comment_content); ?>
                        </p>
                    </td>
                </tr>
                </tbody>
            </table>

            <div style="text-align: center;">
                <table align="center" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation"
                       style="text-align:center;margin-bottom:32px">
                    <tbody>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url(get_the_permalink($post)); ?>#comment-<?php echo $comment->comment_ID; ?>"
                               style="background-color:rgb(37,99,235);color:rgb(255,255,255);padding-left:24px;padding-right:24px;padding-top:12px;padding-bottom:12px;border-radius:6px;font-size:16px;font-weight:600;text-decoration-line:none;box-sizing:border-box;display:inline-block"
                               target="_blank"><?php esc_html_e('View the Comment', 'fluent-comments'); ?></a></td>
                    </tr>
                    </tbody>
                </table>
            </div>

            <p style="font-size:16px;color:rgb(55,65,81);margin-bottom:16px;margin-top:0px;line-height:24px"><?php esc_html_e('Engaging with your readers helps build a strong community around your content. Consider replying to keep the conversation going!', 'fluent-comments'); ?></p>
            <p style="font-size:16px;color:rgb(55,65,81);margin-bottom:16px;margin-top:0px;line-height:24px"><?php esc_html_e('Keep the conversation going by sharing your thoughts on other posts too.', 'fluent-comments'); ?></p>
            <?php
            return ob_get_clean();
        }

        if ($type == 'to_comment_parents') {
            ob_start();
            ?>
            <p style="font-size:16px;color:rgb(55,65,81);margin-bottom:16px;margin-top:0px;line-height:24px"><?php esc_html_e(sprintf('Hi %s,', '{{receiver_name}}')); ?></p>
            <p style="font-size:16px;color:rgb(55,65,81);margin-bottom:16px;margin-top:0px;line-height:24px"><?php echo wp_kses_post(sprintf(__('There\'s a new comment in the discussion on "%1$s" by %2$s, where you previously participated.', 'fluent-comments'), '<b>' . $post->post_title . '</b>', $comment->comment_author)); ?></p>

            <table align="center" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation"
                   style="border-left-width:4px;border-color:rgb(34,197,94);padding-left:16px;margin-bottom:24px;background-color:rgb(240,253,244);border-top-right-radius:8px;border-bottom-right-radius:8px;padding-top:16px;padding-bottom:16px">
                <tbody>
                <tr>
                    <td>
                        <p style="font-size:14px;color:rgb(75,85,99);margin-bottom:8px;margin-top:0px;font-weight:600;line-height:24px">
                            <?php echo esc_html(sprintf(__('Latest comment by %s', 'fluent-comments'), $comment->comment_author)); ?>                        </p>
                        <p style="font-size:14px;color:rgb(55,65,81);font-style:italic;margin-bottom:0px;margin-top:0px;line-height:24px">
                            <?php echo wp_kses_post($comment->comment_content); ?>
                        </p>
                    </td>
                </tr>
                </tbody>
            </table>

            <p style="font-size:16px;color:rgb(55,65,81);margin-bottom:16px;margin-top:0px;line-height:24px"><?php esc_html_e('The conversation is continuing, and your insights might be valuable to the discussion. Join back in and share your thoughts!', 'fluent-comments'); ?></p>
            <div style="text-align: center;">
                <table align="center" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation"
                       style="text-align:center;margin-bottom:32px">
                    <tbody>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url(get_the_permalink($post)); ?>#comment-<?php echo $comment->comment_ID; ?>"
                               style="background-color:rgb(37,99,235);color:rgb(255,255,255);padding-left:24px;padding-right:24px;padding-top:12px;padding-bottom:12px;border-radius:6px;font-size:16px;font-weight:600;text-decoration-line:none;box-sizing:border-box;display:inline-block"
                               target="_blank"><?php esc_html_e('View the Comment', 'fluent-comments'); ?></a></td>
                    </tr>
                    </tbody>
                </table>
            </div>

            <table align="center" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation"
                   style="background-color:rgb(249,250,251);border-radius:8px;padding:16px;margin-bottom:24px">
                <tbody>
                <tr>
                    <td>
                        <p style="font-size:14px;color:rgb(55,65,81);margin-bottom:8px;margin-top:0px;font-weight:600;line-height:24px">
                            <?php esc_html_e('💡 Why we\'re notifying you:', 'fluent-comments'); ?>
                        </p>
                        <p style="font-size:14px;color:rgb(75,85,99);margin-bottom:0px;margin-top:0px;line-height:24px">
                            <?php esc_html_e('You\'re receiving this because you previously commented on this post. We believe ongoing discussions create more value for everyone involved.'); ?>
                        </p></td>
                </tr>
                </tbody>
            </table>
            <?php
            return ob_get_clean();
        }

        return '';
    }

    private function wrapBody($body)
    {
        ob_start();
        ?>
        <html dir="ltr" lang="en">
        <head>
            <meta content="text/html; charset=UTF-8" http-equiv="Content-Type">
            <meta name="x-apple-disable-message-reformatting">
        </head>
        <body
            style='background-color:rgb(243,244,246);font-family:ui-sans-serif, system-ui, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji";padding-top:40px;padding-bottom:40px'>
        <table align="center" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation"
               style="background-color:rgb(255,255,255);border-radius:8px;padding-left:32px;padding-right:32px;padding-top:40px;padding-bottom:40px;margin-left:auto;margin-right:auto;max-width:600px">
            <tbody>
            <tr style="width:100%">
                <td>
                    <table align="center" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
                        <tbody>
                        <tr>
                            <td>
                                <?php
                                echo $body; //phpcs:ignore
                                ?>
                                <hr style="border-color:rgb(209,213,219);margin-top:32px;margin-bottom:32px;width:100%;border:none;border-top:1px solid #eaeaea">
                                <p style="font-size:12px;color:rgb(107,114,128);margin-bottom:8px;margin-top:0px;line-height:24px">
                                    <?php esc_html_e('Best regards,', 'fluent-comments'); ?><br>
                                    <?php echo sprintf(__('The %s Team', 'fluent-comments'), get_bloginfo('name')); ?>
                                </p>
                                <p style="font-size:12px;color:rgb(107,114,128);margin-bottom:16px;margin-top:0px;line-height:24px">
                                    <a href="<?php echo esc_url(home_url()); ?>"
                                       style="color:rgb(37,99,235);text-decoration-line:none"
                                       target="_blank"><?php echo esc_url(home_url()); ?></a></p>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </td>
            </tr>
            </tbody>
        </table>
        </body>
        <grammarly-desktop-integration data-grammarly-shadow-root="true"></grammarly-desktop-integration>
        </html>
        <?php
        return ob_get_clean();
    }

}
