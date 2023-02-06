<?php

namespace FluentComments\App\Http\Controllers;

use FluentComments\App\Helpers\Arr;

class CommentsController
{

    public static function getComments(\WP_REST_Request $request)
    {
        $postId = (int)$request->get_param('id');

        $comments = get_comments([
            'post_id'      => $postId,
            'status'       => 'approve',
            'hierarchical' => 'threaded',
            'orderby'      => 'comment_ID',
            'order'        => 'DESC'
        ]);

        $formattedComments = [];
        foreach ($comments as $comment) {
            $formattedComments[] = self::formatComment($comment);
        }

        return [
            'comments' => $formattedComments,
            'count'    => get_comments([
                'post_id' => $postId,
                'status'  => 'approve',
                'count'   => true
            ])
        ];
    }

    public static function addComment(\WP_REST_Request $request)
    {
        $postId = (int)$request->get_param('id');
        $post = get_post($postId);

        if (!$post) {
            return new \WP_Error(423, 'Invalid Post');
        }

        $content = wp_kses_post($request->get_param('content'));
        if (!$content) {
            return new \WP_Error(423, 'Please provide contents');
        }

        $commentData = [
            'comment_parent'     => $request->get_param('parent_id'),
            'comment_date_gmt'   => date('Y-m-d H:i:s'),
            'comment_author_url' => '',
            'comment_content'    => $content,
            'comment_author_IP'  => self::getIp(),
            'comment_post_ID'    => $postId,
            'comment_type'       => 'comment',
            'comment_agent'      => sanitize_text_field($_SERVER['HTTP_USER_AGENT'])
        ];

        $userId = get_current_user_id();

        if ($userId) {
            $user = get_user_by('ID', $userId);
            $name = trim($user->first_name . ' ' . $user->last_name);
            if (!$name) {
                $name = $user->display_name;
            }
            $commentData['comment_author_email'] = $user->user_email;
            $commentData['comment_author'] = $name;
            $commentData['user_id'] = $user->ID;
        } else {
            $authorName = sanitize_text_field($request->get_param('name'));
            $email = sanitize_text_field($request->get_param('email'));
            if (!$authorName || !$email || !is_email($email)) {
                return new \WP_Error(423, 'valid name and email is required');
            }
            $commentData['comment_author_email'] = $email;
            $commentData['comment_author'] = $authorName;
        }


        $check_comment_lengths = wp_check_comment_data_max_lengths($commentData);

        if (is_wp_error($check_comment_lengths)) {
            $error_code = $check_comment_lengths->get_error_code();
            return new \WP_Error(
                $error_code,
                __('Comment field exceeds maximum length allowed.'),
                array('status' => 400)
            );
        }

        $commentData['comment_approved'] = wp_allow_comment($commentData, true);

        if (is_wp_error($commentData['comment_approved'])) {
            $error_code = $commentData['comment_approved']->get_error_code();
            $error_message = $commentData['comment_approved']->get_error_message();

            if ('comment_duplicate' === $error_code) {
                return new \WP_Error(
                    $error_code,
                    $error_message,
                    array('status' => 409)
                );
            }

            if ('comment_flood' === $error_code) {
                return new \WP_Error(
                    $error_code,
                    $error_message,
                    array('status' => 400)
                );
            }

            return $commentData['comment_approved'];
        }

        $prepared_comment = apply_filters('rest_pre_insert_comment', $commentData, $request);
        if (is_wp_error($prepared_comment)) {
            return $prepared_comment;
        }

        $comment_id = wp_insert_comment(wp_filter_comment(wp_slash((array)$prepared_comment)));

        if (!$comment_id) {
            return new \WP_Error(
                'rest_comment_failed_create',
                __('Creating comment failed.'),
                array('status' => 500)
            );
        }

        $comment = get_comment($comment_id);

        return [
            'message'           => 'Your comment has been added',
            'formatted_comment' => self::formatComment($comment)
        ];
    }

    /**
     * @param $comment \WP_Comment
     * @return array
     */
    protected static function formatComment($comment)
    {
        $data = [
            'ID'         => $comment->comment_ID,
            'parent_id'  => $comment->comment_parent,
            'avatar'     => get_avatar_url($comment->comment_author_email),
            'human_date' => human_time_diff(strtotime($comment->comment_date_gmt)) . ' ago',
            'author'     => $comment->comment_author,
            'content'    => apply_filters('comment_text', $comment->comment_content, $comment),
            'date'       => $comment->comment_date_gmt,
            'children'   => []
        ];

        $childs = $comment->get_children([
            'orderby' => 'comment_ID',
            'order'   => 'DESC',
            'status' => 'approve'
        ]);

        if ($childs) {
            $childs = array_reverse($childs);
            foreach ($childs as $item) {
                $data['children'][] = self::formatComment($item);
            }
        }

        return $data;
    }

    /**
     * Get the visitor's IP address
     *
     * @since 1.0
     */
    protected static function getIp()
    {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            // check ip from share internet.
            $ip = filter_var(wp_unslash($_SERVER['HTTP_CLIENT_IP']), FILTER_VALIDATE_IP);
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // to check ip is pass from proxy.
            $ips = explode(',', wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']));
            if (is_array($ips)) {
                $ip = filter_var($ips[0], FILTER_VALIDATE_IP);
            } else {
                $ip = filter_var(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']), FILTER_VALIDATE_IP);
            }
        } else {
            $ip = filter_var(wp_unslash($_SERVER['REMOTE_ADDR']), FILTER_VALIDATE_IP);
        }

        return $ip;
    }
}


