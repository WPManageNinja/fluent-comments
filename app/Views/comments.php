<?php defined('ABSPATH') or die;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- file scope variables here are template locals, and the hooks fired are WordPress core hooks.

if (post_password_required()) {
    return;
}

$comments_number = (int)get_comments_number();
?>
<div class="fluent_comments_wrap comments-area">
    <?php if ($comments_number) : ?>
        <h2 class="flc_comments-title">
            <?php
            printf(
            /* translators: %s: comment count. */
                esc_html(_n('Latest comment (%s)', 'Latest comments (%s)', $comments_number, 'fluent-comments')),
                esc_html(number_format_i18n($comments_number))
            );
            ?>
        </h2>
    <?php else : ?>
        <h2 class="flc_comments-title"><?php esc_html_e('Add your first comment to this post', 'fluent-comments'); ?></h2>
    <?php endif; ?>

    <?php if (comments_open()) : ?>
        <?php include FLUENT_COMMENTS_PLUGIN_PATH . 'app/Views/comment_form.php'; ?>
    <?php endif; ?>

    <div class="flc_comments flc_native_comments" id="comments">
        <div class="flc_comment-list">
            <?php
            wp_list_comments([
                'walker'      => new \FluentComments\App\Services\FluentWalkerComment(),
                'avatar_size' => 64,
                'style'       => 'div',
                'type'        => 'comment',
            ]);

            $comment_pagination = paginate_comments_links([
                'echo'      => false,
                'end_size'  => 0,
                'mid_size'  => 0,
                'next_text' => __('Newer Comments', 'fluent-comments') . ' <span aria-hidden="true">&rarr;</span>',
                'prev_text' => '<span aria-hidden="true">&larr;</span> ' . __('Older Comments', 'fluent-comments'),
            ]);

            if ($comment_pagination) {
                // Only the "Next" link is present when there is nothing before this page.
                $pagination_classes = (false === strpos($comment_pagination, 'prev page-numbers')) ? ' only-next' : '';
                ?>
                <nav class="pagination<?php echo esc_attr($pagination_classes); ?>"
                     aria-label="<?php esc_attr_e('Comments', 'fluent-comments'); ?>">
                    <?php echo wp_kses_post($comment_pagination); ?>
                </nav>
                <?php
            }
            ?>
        </div><!-- .flc_comment-list -->
    </div><!-- #comments -->

    <?php if (!comments_open() && $comments_number) : ?>
        <div class="comment-respond" id="respond">
            <p class="comments-closed"><?php esc_html_e('Comments are closed.', 'fluent-comments'); ?></p>
        </div>
    <?php endif; ?>
</div>
