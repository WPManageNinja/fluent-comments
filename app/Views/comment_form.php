<?php defined('ABSPATH') or die;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- file scope variables here are template locals, and the hooks fired are WordPress core hooks.

global $post;

if (!$post) {
    return;
}

$showAvatars = (bool)get_option('show_avatars');
$currentUser = get_current_user_id() ? wp_get_current_user() : false;
// The generic avatar, never this visitor's. get_avatar_url() on a user
// id returns a hash of their email address, and this markup goes into
// the page cache - so the logged in visitor who happened to prime the
// cache would have their gravatar served to everybody after them. The
// script swaps in the real one from the session, which is uncached.
$defaultAvatar = get_avatar_url('', ['default' => get_option('avatar_default', 'mystery')]);

if (get_option('comment_registration') && !$currentUser) {
    printf(
        '<p class="flc_comment_login_required">%s</p>',
        wp_kses_post(
            sprintf(
            /* translators: %1$s: opening link tag, %2$s: closing link tag. */
                __('You must be %1$slogged in%2$s to post a comment.', 'fluent-comments'),
                '<a class="flc_login_link" href="' . esc_url(wp_login_url(get_permalink())) . '">',
                '</a>'
            )
        )
    );
    return;
}

// The commenter's saved name and email are deliberately NOT printed here.
// They come from the comment_author_* cookies, and this markup goes into
// the page cache, so rendering them server side hands one visitor's name
// and email address to everybody the cached copy is served to. The script
// fills the fields from the same cookies, in the browser, instead.
$commentPlaceholder = __('Enter your comment here...', 'fluent-comments');
$honeypotField = \FluentComments\App\Services\SpamGuard::getHoneypotField();
?>
<div class="flc_comment_respond" id="respond">
    <div class="flc_respond">
        <div class="flc_comment_wrap">
            <?php if ($showAvatars) : ?>
                <div class="flc_author_placeholder">
                    <div class="flc_comment_author">
                        <img data-flc_avatar src="<?php echo esc_url($defaultAvatar); ?>" alt=""/>
                    </div>
                </div>
            <?php endif; ?>
            <div class="flc_comment_form">
                <form id="flc_comment_form" method="POST">
                    <input type="hidden" name="comment_post_ID" value="<?php echo (int)$post->ID; ?>"/>
                    <input type="hidden" name="comment_parent" id="comment_parent" value="0"/>
                    <input type="hidden" name="action" value="fluent_comment_post"/>
                    <div class="flc_form_field flc_textarea">
                        <div class="flc_comment_input">
                            <textarea class="flc_content_textarea" name="comment"
                                      title="<?php echo esc_attr($commentPlaceholder); ?>"
                                      placeholder="<?php echo esc_attr($commentPlaceholder); ?>"></textarea>
                        </div>
                    </div>
                    <?php // Honeypot: hidden from humans, irresistible to bots. Its name is derived from the site salt, so it differs per install. ?>
                    <div class="flc_hp_field" aria-hidden="true">
                        <label for="flc_<?php echo esc_attr($honeypotField); ?>"><?php esc_html_e('Leave this field empty', 'fluent-comments'); ?></label>
                        <input type="text" name="<?php echo esc_attr($honeypotField); ?>"
                               id="flc_<?php echo esc_attr($honeypotField); ?>" value="" tabindex="-1"
                               autocomplete="off"/>
                    </div>
                    <div style="display: none" class="flc_comment_meta">
                        <?php if (!$currentUser) : ?>
                            <div class="flc_row flc_person_form_fields">
                                <div class="flc_form_field">
                                    <label class="flc_sr-only"
                                           for="flc_person_name"><?php esc_html_e('Full Name', 'fluent-comments'); ?></label>
                                    <input value="" data-flc_prefill="comment_author"
                                           placeholder="<?php esc_attr_e('Your Name', 'fluent-comments'); ?>" name="author"
                                           id="flc_person_name" type="text" class="flc_input_text"/>
                                </div>
                                <div class="flc_form_field">
                                    <label class="flc_sr-only"
                                           for="flc_person_email"><?php esc_html_e('Email Address', 'fluent-comments'); ?></label>
                                    <input value="" data-flc_prefill="comment_author_email"
                                           placeholder="<?php esc_attr_e('Your Email Address', 'fluent-comments'); ?>"
                                           name="email" id="flc_person_email" type="email" class="flc_input_text"/>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php
                        // Extension slot. Filled from the session request
                        // rather than rendered here, so anything per-request
                        // in it stays fresh on a cached page. Core's
                        // comment_form hooks are not fired: their validators
                        // are stripped at submission time, so firing them
                        // would render fields that are never checked. See
                        // Frontend::renderFormFields().
                        ?>
                        <div class="flc_extra_fields"></div>
                        <div class="flc_submit">
                            <input type="submit" id="submit"
                                   value="<?php esc_attr_e('Post Comment', 'fluent-comments'); ?>"
                                   class="flc_button"/>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
