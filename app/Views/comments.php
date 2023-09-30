<?php defined('ABSPATH') or die;
if (post_password_required()) {
    return;
}
$comments_number = get_comments_number();
$commentOrder = get_option('comment_order', 'desc');
?>
<div class="fluent_comments_wrap comments-area">
    <?php if ($comments) : ?>
        <?php if ($commentOrder == 'asc'): ?>
            <?php
            $title_output = '<h2 class="comments-title">';
            if (1 == $comments_number) {
                $title_output .= esc_html__('One Comment', 'fluent-comments');
            } else {
                $title_output .= sprintf(
                /* translators: 1: comment count number */
                    esc_html(_nx('%1$s Comment', '%1$s Comments', $comments_number, 'comments title', 'fluent-comments')),
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    number_format_i18n($comments_number)
                );
            }
            $title_output .= '</h2>';
            echo wp_kses_post($title_output);
            ?>
        <?php else: ?>
            <h2 class="flc_comments-title"><?php echo __('Latest comments', 'fluent-comments'); ?>
                (<?php echo absint($comments_number); ?>)</h2>
        <?php endif; ?>
    <?php else: ?>
        <h2 class="flc_comments-title"><?php echo __('Add your first comment to this post', 'fluent-comments'); ?></h2>
    <?php endif; ?>

    <?php include FLUENT_COMMENTS_PLUGIN_PATH . 'app/Views/comment_form.php'; ?>

    <div class="flc_comments flc_native_comments" id="comments">
        <div class="flc_comment-list">
            <?php
            wp_list_comments(
                array(
                    'walker'      => new \FluentComments\App\Services\FluentWalkerComment(),
                    'avatar_size' => 64,
                    'style'       => 'div',
                )
            );

            $comment_pagination = paginate_comments_links(
                array(
                    'echo'      => false,
                    'end_size'  => 0,
                    'mid_size'  => 0,
                    'next_text' => __('Newer Comments', 'fluent-comments') . ' <span aria-hidden="true">&rarr;</span>',
                    'prev_text' => '<span aria-hidden="true">&larr;</span> ' . __('Older Comments', 'fluent-comments'),
                )
            );

            if ($comment_pagination) {
                $pagination_classes = '';

                // If we're only showing the "Next" link, add a class indicating so.
                if (false === strpos($comment_pagination, 'prev page-numbers')) {
                    $pagination_classes = ' only-next';
                }
                ?>

                <nav class="pagination pagination <?php esc_attr_e($pagination_classes); ?>"
                     aria-label="<?php esc_attr_e('Comments', 'fluent-comments'); ?>">
                    <?php echo wp_kses_post($comment_pagination); ?>
                </nav>

                <?php
            }
            ?>

        </div><!-- .comments-inner -->
    </div><!-- comments -->

    <?php if (!comments_open()) { ?>
        <div class="comment-respond" id="respond">
            <p class="comments-closed"><?php _e('Comments are closed.', 'fluent-comments'); ?></p>
        </div>
    <?php } ?>
</div>
