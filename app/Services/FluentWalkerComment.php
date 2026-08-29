<?php

namespace FluentComments\App\Services;

class FluentWalkerComment extends \Walker_Comment
{
    /**
     * Outputs a comment in the HTML5 format.
     *
     * @see wp_list_comments()
     *
     * @param \WP_Comment $comment Comment to display.
     * @param int $depth Depth of the current comment.
     * @param array $args An array of arguments.
     */
    protected function html5_comment($comment, $depth, $args)
    {
        $comment_author = get_comment_author($comment);
        $args['depth'] = $depth;
        $icon_title_id = 'flc-reply-icon-' . (int)$comment->comment_ID;
        ?>
        <div id="comment-<?php comment_ID(); ?>" class="flc_comment">
            <article class="flc_body">
                <?php if (get_option('show_avatars')) : ?>
                    <div class="flc_avatar">
                        <div class="flc_comment_author">
                            <?php echo wp_kses_post(get_avatar($comment, $args['avatar_size'])); ?>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="flc_comment__details">
                    <div class="crayons-card">
                        <div class="comment__header">
                            <b class="fn"><?php echo esc_html($comment_author); ?></b>
                            <span class="flc_dot" role="presentation">&bull;</span>
                            <?php
                            $comment_timestamp = sprintf(
                            /* translators: 1: comment date, 2: comment time. */
                                __('%1$s at %2$s', 'fluent-comments'),
                                get_comment_date('', $comment),
                                get_comment_time()
                            );

                            printf(
                                '<a href="%s"><time datetime="%s" title="%s">%s</time></a>',
                                esc_url(get_comment_link($comment, $args)),
                                esc_attr(get_comment_time('c')),
                                esc_attr($comment_timestamp),
                                esc_html($comment_timestamp)
                            );

                            if (get_edit_comment_link()) {
                                printf(
                                    ' <span class="flc_dot" aria-hidden="true">&bull;</span> <a target="_blank" rel="noopener" class="comment-edit-link" href="%s">%s</a>',
                                    esc_url(get_edit_comment_link()),
                                    esc_html__('Edit', 'fluent-comments')
                                );
                            }
                            ?>
                        </div>
                        <div class="flc_comment-content">
                            <?php
                            comment_text();
                            if ('0' === $comment->comment_approved) {
                                ?>
                                <p class="comment-awaiting-moderation"><?php esc_html_e('Your comment is awaiting moderation.', 'fluent-comments'); ?></p>
                                <?php
                            }
                            ?>
                        </div>
                    </div>
                    <?php if ($this->canReplyToComment($args)) : ?>
                        <div class="comment_footer">
                            <a class="fls_child_comment_reply" data-comment_id="<?php comment_ID(); ?>"
                               href="#comment-<?php comment_ID(); ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" role="img"
                                     aria-labelledby="<?php echo esc_attr($icon_title_id); ?>"
                                     class="crayons-icon reaction-icon not-reacted">
                                    <title id="<?php echo esc_attr($icon_title_id); ?>"><?php esc_html_e('Reply', 'fluent-comments'); ?></title>
                                    <path d="M10.5 5h3a6 6 0 110 12v2.625c-3.75-1.5-9-3.75-9-8.625a6 6 0 016-6zM12 15.5h1.5a4.501 4.501 0 001.722-8.657A4.5 4.5 0 0013.5 6.5h-3A4.5 4.5 0 006 11c0 2.707 1.846 4.475 6 6.36V15.5z"></path>
                                </svg>
                                <span class="reply_text"><?php esc_html_e('Reply', 'fluent-comments'); ?></span>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </article>
        <?php
    }

    protected function comment($comment, $depth, $args)
    {
        $this->html5_comment($comment, $depth, $args);
    }

    private function isCommentOpen()
    {
        static $isOpen = null;

        if ($isOpen !== null) {
            return $isOpen;
        }

        $isOpen = comments_open();

        return $isOpen;
    }

    /**
     * wp_list_comments() passes max_depth as -1 when threading is disabled.
     *
     * @param array $args
     * @return bool
     */
    private function canReplyToComment($args)
    {
        if (!$this->isCommentOpen()) {
            return false;
        }

        $depth = isset($args['depth']) ? (int)$args['depth'] : 1;
        $maxDepth = isset($args['max_depth']) ? (int)$args['max_depth'] : 1;

        if ($maxDepth < 1) {
            return false;
        }

        return $depth < $maxDepth;
    }
}
