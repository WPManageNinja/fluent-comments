<?php defined('ABSPATH') or die;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- file scope variables here are template locals, and the hooks fired are WordPress core hooks.

global $post;

if (!$post) {
    return;
}

$showAvatars = (bool)get_option('show_avatars');
// The generic avatar, never this visitor's. get_avatar_url() on a user
// id returns a hash of their email address, and this markup goes into
// the page cache - so the logged in visitor who happened to prime the
// cache would have their gravatar served to everybody after them. The
// script swaps in the real one from the session, which is uncached.
$defaultAvatar = get_avatar_url('', ['default' => get_option('avatar_default', 'mystery')]);

// Whether this visitor is signed in is NOT decided here, and nothing below
// branches on it. This markup goes into the page cache and is then served
// to everybody, so a branch taken for whoever happened to prime the cache
// becomes a branch taken for all of them: the login notice shown to signed
// in readers, or the name and email fields missing for anonymous ones, who
// then cannot comment at all.
//
// So one neutral form is rendered, with both the login notice and the
// identity fields present but hidden, and the script reveals whichever the
// session says applies. The session is the only thing here that knows who
// is asking, and it is never cached. Nothing is lost without JavaScript:
// the form posts through admin-ajax either way, so a browser that never
// runs the script was never going to submit a comment.
$loginMessage = sprintf(
/* translators: %1$s: opening link tag, %2$s: closing link tag. */
    __('You must be %1$slogged in%2$s to post a comment.', 'fluent-comments'),
    '<a class="flc_login_link" href="' . esc_url(wp_login_url(get_permalink())) . '">',
    '</a>'
);

// The commenter's saved name and email are deliberately NOT printed here
// either, for the same reason. They come from the comment_author_* cookies,
// and the script fills the fields from those, in the browser.
// The same strings the Svelte form renders. Both forms are the one form
// as far as a reader is concerned, so the wording comes from one place -
// this file used to say "Enter your comment here" against the other's
// "Write your comment here", on the same field of the same plugin.
$strings = \FluentComments\App\Services\Frontend::getStrings();
$commentPlaceholder = $strings['comment_placeholder'];
$honeypotField = \FluentComments\App\Services\SpamGuard::getHoneypotField();
?>
<div class="flc_comment_respond" id="respond">
    <p class="flc_comment_login_required" data-flc_login_required hidden><?php echo wp_kses_post($loginMessage); ?></p>
    <div class="flc_respond" data-flc_form_wrap>
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
                        <label for="flc_<?php echo esc_attr($honeypotField); ?>"><?php echo esc_html($strings['honeypot_label']); ?></label>
                        <input type="text" name="<?php echo esc_attr($honeypotField); ?>"
                               id="flc_<?php echo esc_attr($honeypotField); ?>" value="" tabindex="-1"
                               autocomplete="off"/>
                    </div>
                    <div style="display: none" class="flc_comment_meta">
                        <?php // Hidden until the session says this visitor is signed out. Revealed rather than removed: see the note at the top of this file. ?>
                            <div class="flc_row flc_person_form_fields" data-flc_identity_fields hidden>
                                <div class="flc_form_field">
                                    <label class="flc_sr-only"
                                           for="flc_person_name"><?php esc_html_e('Full Name', 'fluent-comments'); ?></label>
                                    <input value="" data-flc_prefill="comment_author"
                                           placeholder="<?php echo esc_attr($strings['name_placeholder']); ?>" name="author"
                                           id="flc_person_name" type="text" class="flc_input_text"/>
                                </div>
                                <div class="flc_form_field">
                                    <label class="flc_sr-only"
                                           for="flc_person_email"><?php esc_html_e('Email Address', 'fluent-comments'); ?></label>
                                    <input value="" data-flc_prefill="comment_author_email"
                                           placeholder="<?php echo esc_attr($strings['email_placeholder']); ?>"
                                           name="email" id="flc_person_email" type="email" class="flc_input_text"/>
                                </div>
                            </div>
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
