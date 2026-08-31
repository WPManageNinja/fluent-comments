<?php

namespace FluentComments\App\Hooks\Handlers;

use FluentComments\App\Helpers\Arr;
use FluentComments\App\Services\DiscussionSettings;
use FluentComments\App\Services\EmailService;
use FluentComments\App\Services\Helper;
use FluentComments\App\Services\SmartCodeParser;

/**
 * The Emails screen's endpoints.
 *
 * Same shape as the settings ones next door: admin-ajax, logged in only,
 * nonce plus manage_options, checked by Helper::verifyAdminAjax() before
 * anything is read. Nothing here is public, so unlike the front end there
 * is a nonce and it matters.
 */
class EmailSettingsHandler
{
    public function register()
    {
        add_action('wp_ajax_fluent-comments-admin-get-emails', [$this, 'getEmails']);
        add_action('wp_ajax_fluent-comments-admin-get-email', [$this, 'getEmail']);
        add_action('wp_ajax_fluent-comments-admin-save-email', [$this, 'saveEmail']);
        add_action('wp_ajax_fluent-comments-admin-toggle-email', [$this, 'toggleEmail']);
        add_action('wp_ajax_fluent-comments-admin-preview-email', [$this, 'previewEmail']);
        add_action('wp_ajax_fluent-comments-admin-get-email-template', [$this, 'getEmailTemplate']);
        add_action('wp_ajax_fluent-comments-admin-save-email-template', [$this, 'saveEmailTemplate']);
    }

    /**
     * @return void
     */
    public function getEmails()
    {
        Helper::verifyAdminAjax();

        wp_send_json([
            'emails' => array_values(EmailService::getEmailIndexes()),
        ], 200);
    }

    /**
     * @return void
     */
    public function getEmail()
    {
        Helper::verifyAdminAjax();

        $emailId = $this->requestedEmailId();
        $indexes = EmailService::getEmailIndexes();

        wp_send_json([
            'email'           => $indexes[$emailId],
            'settings'        => EmailService::getEmailForEditing($emailId),
            'smartcodes'      => EmailService::getSmartCodes($emailId),
            'default_content' => Arr::get(EmailService::getEmailDefaults(), $emailId),
        ], 200);
    }

    /**
     * @return void
     */
    public function saveEmail()
    {
        Helper::verifyAdminAjax();

        $emailId = $this->requestedEmailId();

        // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified above; every value is sanitized below.
        $raw = isset($_POST['settings']) && is_array($_POST['settings']) ? wp_unslash($_POST['settings']) : [];
        // phpcs:enable

        $contentStatus = Arr::get($raw, 'content_status');

        if (!in_array($contentStatus, ['system', 'active'], true)) {
            wp_send_json(['message' => __('Unknown status.', 'fluent-comments')], 400);
        }

        $enabled = Arr::get($raw, 'enabled') === 'yes';

        $subject = sanitize_text_field((string)Arr::get($raw, 'email.subject', ''));
        $body = wp_kses_post((string)Arr::get($raw, 'email.body', ''));

        if ($contentStatus === 'active' && (!$subject || !$body)) {
            wp_send_json(['message' => __('A customized email needs both a subject and a body.', 'fluent-comments')], 400);
        }

        EmailService::saveEmail($emailId, $enabled, $contentStatus, [
            'subject' => $subject,
            'body'    => $body,
        ]);

        wp_send_json([
            'message'  => __('Email saved.', 'fluent-comments'),
            'settings' => EmailService::getEmailForEditing($emailId),
            // The Settings tab was handed its switches when the page
            // loaded, and this request has just moved one of them. Without
            // this it would still be holding the old value and would write
            // it back over the top on its next save.
            'toggles'  => self::currentToggles(),
        ], 200);
    }

    /**
     * On or off, from the list, without opening the email.
     *
     * Deliberately not save-email with status 'disabled': that would also
     * have to carry a subject and a body it does not have, and this is a
     * one field write to a switch that already existed.
     *
     * @return void
     */
    public function toggleEmail()
    {
        Helper::verifyAdminAjax();

        $emailId = $this->requestedEmailId();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
        $enabled = isset($_POST['enabled']) && $_POST['enabled'] === 'yes';

        EmailService::setEnabled($emailId, $enabled);

        wp_send_json([
            'message' => $enabled
                ? __('This email will be sent.', 'fluent-comments')
                : __('This email will not be sent.', 'fluent-comments'),
            'status'  => EmailService::getStatus($emailId),
            'toggles' => self::currentToggles(),
        ], 200);
    }

    /**
     * Renders what is in the editor right now, unsaved.
     *
     * @return void
     */
    public function previewEmail()
    {
        Helper::verifyAdminAjax();

        $emailId = $this->requestedEmailId();

        // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified above; sanitized on the next two lines.
        $raw = isset($_POST['email_data']) && is_array($_POST['email_data']) ? wp_unslash($_POST['email_data']) : [];
        // phpcs:enable

        $subject = sanitize_text_field((string)Arr::get($raw, 'subject', ''));
        $body = wp_kses_post((string)Arr::get($raw, 'body', ''));

        if (!$body) {
            $default = Arr::get(EmailService::getEmailDefaults(), $emailId, []);
            $subject = Arr::get($default, 'subject', '');
            $body = Arr::get($default, 'body', '');
        }

        $context = $this->sampleContext($emailId);
        $parser = new SmartCodeParser($context);

        wp_send_json([
            'rendered_email' => [
                'subject' => wp_specialchars_decode($parser->parseString($subject), ENT_QUOTES),
                'body'    => EmailService::withHtmlTemplate($parser->parseString($body)),
            ],
        ], 200);
    }

    /**
     * @return void
     */
    public function getEmailTemplate()
    {
        Helper::verifyAdminAjax();

        $context = $this->sampleContext('comment_approved');
        $parser = new SmartCodeParser($context);
        $sample = $parser->parseString(EmailService::getDefaultEmailBody('comment_approved'));

        wp_send_json([
            'settings'        => EmailService::getTemplateSettings(),
            'defaults'        => EmailService::getTemplateDefaults(),
            // Rendered with the footer as saved, so the preview shows the
            // real fallback (site name and URL) when nothing is written.
            // The browser repaints colours and footer text as they change.
            'default_content' => EmailService::withHtmlTemplate($sample),
        ], 200);
    }

    /**
     * @return void
     */
    public function saveEmailTemplate()
    {
        Helper::verifyAdminAjax();

        // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified above; every field is sanitized in the loops below.
        $raw = isset($_POST['settings']) && is_array($_POST['settings']) ? wp_unslash($_POST['settings']) : [];
        // phpcs:enable

        $defaults = EmailService::getTemplateDefaults();
        $settings = EmailService::getTemplateSettings();

        foreach (['body_bg', 'content_bg', 'content_color', 'accent_color', 'highlight_bg', 'highlight_color', 'footer_content_color'] as $key) {
            if (!array_key_exists($key, $raw)) {
                continue;
            }

            $color = $this->sanitizeColor($raw[$key]);
            $settings[$key] = $color ? $color : $defaults[$key];
        }

        if (array_key_exists('logo', $raw)) {
            $settings['logo'] = esc_url_raw(trim((string)$raw['logo']));
        }

        if (array_key_exists('footer_text', $raw)) {
            $settings['footer_text'] = wp_kses_post((string)$raw['footer_text']);
        }

        foreach (['from_name', 'reply_to_name'] as $key) {
            if (array_key_exists($key, $raw)) {
                $settings[$key] = sanitize_text_field((string)$raw[$key]);
            }
        }

        foreach (['from_email', 'reply_to_email'] as $key) {
            if (!array_key_exists($key, $raw)) {
                continue;
            }

            $email = sanitize_email(trim((string)$raw[$key]));

            if ($email && !is_email($email)) {
                wp_send_json(['message' => __('That is not a valid email address.', 'fluent-comments')], 400);
            }

            $settings[$key] = $email;
        }

        EmailService::saveTemplateSettings($settings);

        wp_send_json([
            'message'  => __('Email template saved.', 'fluent-comments'),
            'settings' => EmailService::getTemplateSettings(),
        ], 200);
    }

    /**
     * The switches the Settings tab also shows, as they stand now.
     *
     * @return array
     */
    private static function currentToggles()
    {
        return [
            'settings'   => Arr::only(Helper::getCommentSettings(), [
                'email_on_comment_approval',
                'email_on_reply',
                'email_to_author',
            ]),
            'discussion' => Arr::only(DiscussionSettings::get(), [
                'comments_notify',
                'moderation_notify',
            ]),
        ];
    }

    /**
     * A real comment where there is one, an invented one where there is not.
     *
     * Preferring a real comment is worth the query: a preview is only
     * useful if it shows how the email handles the length and the tone of
     * what people on this site actually write.
     *
     * @param string $emailId
     * @return array
     */
    private function sampleContext($emailId)
    {
        $comments = get_comments([
            'number'  => 1,
            'status'  => 'approve',
            'orderby' => 'comment_date_gmt',
            'order'   => 'DESC',
        ]);

        $comment = $comments ? $comments[0] : null;
        $post = $comment ? get_post($comment->comment_post_ID) : null;

        if (!$comment || !$post) {
            $posts = get_posts(['numberposts' => 1, 'post_status' => 'publish']);
            $post = $posts ? $posts[0] : null;

            $comment = new \WP_Comment((object)[
                'comment_ID'          => 0,
                'comment_post_ID'     => $post ? $post->ID : 0,
                'comment_author'      => __('Jane Doe', 'fluent-comments'),
                'comment_author_email' => 'jane@example.com',
                'comment_author_url'  => 'https://example.com',
                'comment_author_IP'   => '203.0.113.4',
                'comment_content'     => __('This is what a comment looks like in your email. It is here so you can see how the spacing, the colors and the type hold up against real text rather than a single short line.', 'fluent-comments'),
                'comment_date'        => current_time('mysql'),
                'comment_date_gmt'    => current_time('mysql', 1),
                'comment_approved'    => '1',
                'comment_parent'      => 0,
                'user_id'             => 0,
            ]);
        }

        $user = wp_get_current_user();

        return [
            'comment'  => $comment,
            'post'     => $post,
            // Left out of the core emails on purpose: they go to a list of
            // people, so the editor does not offer recipient codes there.
            'receiver' => strpos($emailId, 'core_') === 0 ? [] : [
                'name'  => $user->display_name,
                'email' => $user->user_email,
            ],
        ];
    }

    /**
     * A hex or rgb()/rgba() value, or '' for anything else.
     *
     * @param mixed $value
     * @return string
     */
    private function sanitizeColor($value)
    {
        if (!is_scalar($value)) {
            return '';
        }

        $value = trim((string)$value);

        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $value)) {
            return $value;
        }

        if (preg_match('/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*(0|1|0?\.\d+))?\s*\)$/', $value)) {
            return $value;
        }

        return '';
    }

    /**
     * @return string
     */
    private function requestedEmailId()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Helper::verifyAdminAjax() ran before every caller reaches this.
        $emailId = isset($_REQUEST['email_id']) ? sanitize_key(wp_unslash($_REQUEST['email_id'])) : '';

        if (!$emailId || !isset(EmailService::TOGGLES[$emailId])) {
            wp_send_json(['message' => __('Unknown email.', 'fluent-comments')], 400);
        }

        return $emailId;
    }
}
