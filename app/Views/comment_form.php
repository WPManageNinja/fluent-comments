<?php
$userAvatar = 'https://secure.gravatar.com/avatar/?s=96&d=mm&r=g';
$currentUser = false;
if (get_current_user_id()) {
    $currentUser = get_user_by('ID', get_current_user_id());
    $userAvatar = get_avatar_url($currentUser->user_email);
}
global $post;

$commenter = wp_get_current_commenter();

?>
<div class="flc_comment_respond" id="respond">
    <div class="flc_respond">
            <div class="flc_comment_wrap">
                <div class="flc_author_placeholder">
                    <div class="flc_comment_author">
                        <img src="<?php echo esc_url($userAvatar); ?>"/>
                    </div>
                </div>
                <div class="flc_comment_form">
                    <form id="flc_comment_form" method="POST">
                        <input type="hidden" name="comment_post_ID" value="<?php echo (int) $post->ID; ?>" />
                        <input type="hidden" name="comment_parent" id="comment_parent" value="0">
                        <input type="hidden" name="action" value="fluent_comment_post" />
                        <div class="flc_form_field flc_textarea">
                            <div class="flc_comment_input">
                                <textarea class="flc_content_textarea" name="comment" title="Enter your comment here..." placeholder="Enter your comment here..."></textarea>
                            </div>
                        </div>
                        <div style="display: none" class="flc_comment_meta">
                        <?php if (!$currentUser): ?>
                            <div class="flc_row flc_person_form_fields">
                                <div class="flc_form_field">
                                    <label for="flc_person_name">Full Name</label>
                                    <input value="<?php echo esc_attr( $commenter['comment_author'] ); ?>" placeholder="Your Name" name="author" id="flc_person_name" type="text" class="flc_input_text"/>
                                </div>
                                <div class="flc_form_field">
                                    <label for="flc_person_email">Email Address</label>
                                    <input value="<?php echo esc_attr( $commenter['comment_author_email'] ) ?>" placeholder="Your Email Address" name="email" id="flc_person_email" type="email" class="flc_input_text"/>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php

                        do_action( 'comment_form_after_fields' );

                        $submitField = '<div class="flc_submit"><button class="btn flc_button">Submit Comment</button></div>';
                        echo apply_filters( 'comment_form_submit_field', $submitField, [] );

                        do_action( 'comment_form', $post->ID );
                        ?>
                    </div>
                    </form>
                </div>
            </div>
    </div>
</div>
