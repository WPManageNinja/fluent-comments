<?php

namespace FluentComments\App\Services;

/**
 * Reads comment threads into the shape the front end renders.
 *
 * This lives outside the REST controller so the same payload can be
 * rendered straight into the page. On a cached site that matters a lot:
 * fetching the first page over REST turns every single page view into an
 * uncached WordPress boot, because admin-ajax is never page cached.
 */
class CommentsRepository
{
    /**
     * A page of threaded comments plus everything the app needs to render
     * around them.
     *
     * @param int $postId
     * @param int $page
     * @param int|null $perPage
     * @return array
     */
    public static function getPayload($postId, $page = 1, $perPage = null)
    {
        $postId = (int)$postId;

        $perPage = (int)$perPage;
        if ($perPage < 1 || $perPage > 100) {
            $perPage = Helper::getPerPage();
        }

        $page = (int)$page;
        if ($page < 1) {
            $page = 1;
        }

        $baseArgs = [
            'post_id' => $postId,
            'status'  => 'approve',
            'type'    => 'comment',
        ];

        $topLevelCount = (int)get_comments(array_merge($baseArgs, [
            'parent' => 0,
            'count'  => true,
        ]));

        // 'hierarchical' => 'threaded' paginates the top level comments and
        // pre-populates every descendant in one go, so get_children() below
        // does not hit the database again.
        $comments = get_comments(array_merge($baseArgs, [
            'parent'       => 0,
            'hierarchical' => 'threaded',
            'orderby'      => 'comment_ID',
            'order'        => 'DESC',
            'number'       => $perPage,
            'offset'       => ($page - 1) * $perPage,
        ]));

        $formatted = [];
        foreach ($comments as $comment) {
            $formatted[] = self::formatComment($comment);
        }

        return [
            'comments'      => $formatted,
            'count'         => (int)get_comments(array_merge($baseArgs, ['count' => true])),
            'page'          => $page,
            'per_page'      => $perPage,
            'total_pages'   => $perPage > 0 ? (int)ceil($topLevelCount / $perPage) : 0,
            'has_more'      => ($page * $perPage) < $topLevelCount,
            'comments_open' => (bool)comments_open($postId),
            'max_depth'     => Helper::getMaxDepth(),
        ];
    }

    /**
     * @param \WP_Comment $comment
     * @param int $depth
     * @return array
     */
    public static function formatComment($comment, $depth = 1)
    {
        $data = [
            'ID'         => (int)$comment->comment_ID,
            'parent_id'  => (int)$comment->comment_parent,
            'depth'      => $depth,
            'avatar'     => get_avatar_url($comment),
            'human_date' => sprintf(
            /* translators: %s: human readable time difference, e.g. "3 days". */
                __('%s ago', 'fluent-comments'),
                human_time_diff(strtotime($comment->comment_date_gmt))
            ),
            'author'     => $comment->comment_author,
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook.
            'content'    => apply_filters('comment_text', $comment->comment_content, $comment),
            'date'       => $comment->comment_date_gmt,
            'unapproved' => '1' !== (string)$comment->comment_approved,
            'children'   => [],
        ];

        $children = $comment->get_children([
            'status' => 'approve',
        ]);

        if ($children) {
            usort($children, function ($a, $b) {
                return (int)$a->comment_ID - (int)$b->comment_ID;
            });

            foreach ($children as $child) {
                $data['children'][] = self::formatComment($child, $depth + 1);
            }
        }

        return $data;
    }
}
