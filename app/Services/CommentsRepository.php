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
     * The most comments one response will format, counting replies.
     *
     * Deliberately far above anything a real page of comments reaches -
     * per_page caps the top level at 100, so this only binds when those 100
     * carry thousands of replies between them. Raise it with
     * 'fluent_comments/max_payload_nodes' if a site genuinely needs to.
     *
     * What it trims is replies. Every top level comment on the page is
     * always returned, even once the budget is spent, so the real ceiling is
     * this plus the page size - at most 1100 nodes on default settings. A
     * reply that does not fit reads as a slightly shorter thread; a top
     * level comment that did not fit would read as a comment the site had
     * deleted, which is a much worse thing to do quietly.
     */
    const MAX_PAYLOAD_NODES = 1000;

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

        // One budget for the whole page, not one per top level comment.
        // 'threaded' above already pulled every descendant out of the
        // database, so this does not save the query - what it bounds is the
        // per node formatting (an avatar URL, the comment_text filters, a
        // human readable date) and the size of the JSON that comes back. A
        // single post with a runaway thread is otherwise an uncached request
        // that formats tens of thousands of nodes.
        //
        // The ceiling is set where no real page of comments reaches it. It
        // is a safety valve, not pagination: nobody legitimate should ever
        // see 'truncated' come back true.
        $budget = (int)apply_filters('fluent_comments/max_payload_nodes', self::MAX_PAYLOAD_NODES);

        $formatted = [];
        foreach ($comments as $comment) {
            $formatted[] = self::formatNode($comment, 1, $budget);
        }

        return [
            'comments'      => $formatted,
            'truncated'     => $budget <= 0,
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
        $budget = (int)apply_filters('fluent_comments/max_payload_nodes', self::MAX_PAYLOAD_NODES);

        return self::formatNode($comment, $depth, $budget);
    }

    /**
     * @param \WP_Comment $comment
     * @param int $depth
     * @param int $budget How many more nodes may be formatted, shared by
     *                    reference across the whole tree.
     * @return array
     */
    private static function formatNode($comment, $depth, &$budget)
    {
        $budget--;

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
                if ($budget <= 0) {
                    break;
                }

                $data['children'][] = self::formatNode($child, $depth + 1, $budget);
            }
        }

        return $data;
    }
}
