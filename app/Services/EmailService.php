<?php

namespace FluentComments\App\Services;

use FluentComments\App\Helpers\Arr;

/**
 * Every email this plugin is responsible for, and what the site owner has
 * done to it.
 *
 * Five of them. Three are ours, sent to the people who comment. Two are
 * WordPress's own notices to the site - we do not send those, we filter
 * them on the way out, so a site owner has one place to write all five
 * rather than two.
 *
 * ## Where "off" lives
 *
 * On/off is not stored here. Each email already had a switch before this
 * screen existed - three in our own settings, two of them core options that
 * Settings > Discussion also writes - and a second copy would be a second
 * answer. So `status` is composed: the existing switch decides whether the
 * email is sent, and this option only records whether the *content* is
 * ours or the site owner's. Turning an email off on this screen flips the
 * same switch the Settings tab shows, and the two can never disagree.
 *
 * ## Three statuses
 *
 *   system   - the built-in content below, which is what shipped
 *   active   - the subject and body the site owner wrote
 *   disabled - not sent at all
 *
 * `system` renders through the same template and the same parser as
 * `active`, from the defaults in this file. There is one body per email,
 * not one for us and one for them, so "Start from the default" hands over
 * exactly what was being sent a moment ago.
 */
class EmailService
{
    const OPTION = '_fluent_comments_email_settings';

    /**
     * Where each email's on/off switch actually lives.
     *
     * 'setting' is a key in _fluent_comments_settings, 'option' is a
     * WordPress option storing '1' or ''.
     */
    const TOGGLES = [
        'comment_approved'           => ['setting', 'email_on_comment_approval'],
        'new_comment_to_post_author' => ['setting', 'email_to_author'],
        'reply_to_participants'      => ['setting', 'email_on_reply'],
        'core_comment_notification'  => ['option', 'comments_notify'],
        'core_comment_moderation'    => ['option', 'moderation_notify'],
    ];

    /**
     * Metadata for every email, with the resolved status attached.
     *
     * @return array
     */
    public static function getEmailIndexes()
    {
        $emails = [
            'comment_approved'           => [
                'name'        => 'comment_approved',
                'title'       => __('Their comment was approved', 'fluent-comments'),
                'description' => __('Sent to the commenter when a comment that was waiting in the queue goes live.', 'fluent-comments'),
                'recipient'   => 'commenter',
                'owner'       => 'plugin',
                'toggle_note' => '',
            ],
            'reply_to_participants'      => [
                'name'        => 'reply_to_participants',
                'title'       => __('Someone replied in their thread', 'fluent-comments'),
                'description' => __('Sent to everyone above a reply in the thread, so a conversation carries on without them having to come back and check.', 'fluent-comments'),
                'recipient'   => 'commenter',
                'owner'       => 'plugin',
                'toggle_note' => '',
            ],
            'new_comment_to_post_author' => [
                'name'        => 'new_comment_to_post_author',
                'title'       => __('A comment landed on their post', 'fluent-comments'),
                'description' => __('Sent to the author of the post. On a site where the only author is you, WordPress\'s own notice below covers the same ground - turn one of the two off.', 'fluent-comments'),
                'recipient'   => 'commenter',
                'owner'       => 'plugin',
                'toggle_note' => '',
            ],
            'core_comment_notification'  => [
                'name'        => 'core_comment_notification',
                'title'       => __('A comment was posted', 'fluent-comments'),
                'description' => __('WordPress sends this to you and the post author for every approved comment. We rewrite it on the post types FluentComments runs on.', 'fluent-comments'),
                'recipient'   => 'site_admin',
                'owner'       => 'core',
                'toggle_note' => __('Switching this off is the same as clearing "Anyone posts a comment" on Settings > Discussion.', 'fluent-comments'),
            ],
            'core_comment_moderation'    => [
                'name'        => 'core_comment_moderation',
                'title'       => __('A comment is waiting for review', 'fluent-comments'),
                'description' => __('WordPress sends this when a comment is held. It carries the approve, spam and trash links, so keep those in if you customise it.', 'fluent-comments'),
                'recipient'   => 'site_admin',
                'owner'       => 'core',
                'toggle_note' => __('Switching this off is the same as clearing "A comment is held for moderation" on Settings > Discussion.', 'fluent-comments'),
            ],
        ];

        foreach ($emails as $key => $email) {
            $emails[$key]['status'] = self::getStatus($key);
        }

        return $emails;
    }

    /**
     * system | active | disabled.
     *
     * @param string $emailId
     * @return string
     */
    public static function getStatus($emailId)
    {
        if (!isset(self::TOGGLES[$emailId])) {
            return 'system';
        }

        if (!self::isEnabled($emailId)) {
            return 'disabled';
        }

        $stored = Arr::get(self::getStoredEmails(), $emailId . '.status', 'system');

        return $stored === 'active' ? 'active' : 'system';
    }

    /**
     * Whether the switch this email already had is on.
     *
     * @param string $emailId
     * @return bool
     */
    public static function isEnabled($emailId)
    {
        if (!isset(self::TOGGLES[$emailId])) {
            return false;
        }

        list($kind, $key) = self::TOGGLES[$emailId];

        if ($kind === 'option') {
            return (bool)get_option($key);
        }

        return Helper::getSetting($key, 'no') === 'yes';
    }

    /**
     * @param string $emailId
     * @param bool $enabled
     * @return void
     */
    public static function setEnabled($emailId, $enabled)
    {
        if (!isset(self::TOGGLES[$emailId])) {
            return;
        }

        list($kind, $key) = self::TOGGLES[$emailId];

        if ($kind === 'option') {
            update_option($key, $enabled ? '1' : '');
            return;
        }

        // The stored row, not the defaults-merged one. Writing the merged
        // array would materialise all five keys on an install that has never
        // saved the settings screen, and Helper::isConfigured() reads exactly
        // that to decide whether the setup notice has been answered. Flipping
        // one email switch would then silence a notice about post types and
        // native rejection that the owner had never seen. getCommentSettings()
        // parses this against the defaults, so a partial row still resolves.
        $settings = get_option('_fluent_comments_settings', []);

        if (!is_array($settings)) {
            $settings = [];
        }

        $settings[$key] = $enabled ? 'yes' : 'no';

        update_option('_fluent_comments_settings', $settings);
    }

    /**
     * The subject and body actually sent for this email, before parsing.
     *
     * @param string $emailId
     * @return array{subject: string, body: string}
     */
    public static function getEmailContent($emailId)
    {
        $defaults = Arr::get(self::getEmailDefaults(), $emailId, ['subject' => '', 'body' => '']);

        if (self::getStatus($emailId) !== 'active') {
            return $defaults;
        }

        $stored = Arr::get(self::getStoredEmails(), $emailId . '.email', []);

        return [
            'subject' => Arr::get($stored, 'subject') ? $stored['subject'] : $defaults['subject'],
            'body'    => Arr::get($stored, 'body') ? $stored['body'] : $defaults['body'],
        ];
    }

    /**
     * What the editor loads.
     *
     * Handed over as the two things it really is, rather than the composed
     * three value status the list shows: whether it is sent, and whose
     * words are in it. Those are stored in two different places and answer
     * two different questions, and an editor that folded them back into one
     * control would have to hide a customised body behind "off".
     *
     * The draft is the stored one if there is one and the default
     * otherwise, so picking "Your own" starts from what is being sent
     * rather than from an empty box.
     *
     * @param string $emailId
     * @return array{enabled: string, content_status: string, email: array}
     */
    public static function getEmailForEditing($emailId)
    {
        $defaults = Arr::get(self::getEmailDefaults(), $emailId, ['subject' => '', 'body' => '']);
        $stored = Arr::get(self::getStoredEmails(), $emailId . '.email', []);

        return [
            'enabled'        => self::isEnabled($emailId) ? 'yes' : 'no',
            'content_status' => Arr::get(self::getStoredEmails(), $emailId . '.status') === 'active' ? 'active' : 'system',
            'email'          => [
                'subject' => Arr::get($stored, 'subject') ? $stored['subject'] : $defaults['subject'],
                'body'    => Arr::get($stored, 'body') ? $stored['body'] : $defaults['body'],
            ],
        ];
    }

    /**
     * @param string $emailId
     * @param bool $enabled whether it is sent - writes the switch this
     *                      email already had, not a copy of it
     * @param string $contentStatus 'system' or 'active'
     * @param array $email
     * @return void
     */
    public static function saveEmail($emailId, $enabled, $contentStatus, $email = [])
    {
        if (!isset(self::TOGGLES[$emailId])) {
            return;
        }

        self::setEnabled($emailId, (bool)$enabled);

        $settings = self::getRawOption();
        $current = Arr::get($settings, 'emails.' . $emailId, []);

        // A draft outlives being switched back to the default or off.
        // Somebody trying the WordPress version for a week should not lose
        // the copy they wrote when they switch back.
        if (!empty($email['subject']) || !empty($email['body'])) {
            $current['email'] = [
                'subject' => (string)Arr::get($email, 'subject', ''),
                'body'    => (string)Arr::get($email, 'body', ''),
            ];
        }

        $current['status'] = $contentStatus === 'active' ? 'active' : 'system';

        $settings['emails'][$emailId] = $current;

        update_option(self::OPTION, $settings, false);
    }

    /**
     * @return array
     */
    public static function getStoredEmails()
    {
        return Arr::get(self::getRawOption(), 'emails', []);
    }

    /**
     * Logo, colours, footer and the addresses the mail is sent from.
     *
     * @return array
     */
    public static function getTemplateSettings()
    {
        $stored = Arr::get(self::getRawOption(), 'template', []);

        return wp_parse_args(is_array($stored) ? $stored : [], self::getTemplateDefaults());
    }

    /**
     * @return array
     */
    public static function getTemplateDefaults()
    {
        return [
            'logo'                 => '',
            'body_bg'              => '#f3f4f6',
            'content_bg'           => '#ffffff',
            'content_color'        => '#374151',
            'accent_color'         => '#2563eb',
            'highlight_bg'         => '#f9fafb',
            'highlight_color'      => '#374151',
            'footer_content_color' => '#6b7280',
            'footer_text'          => '',
            'from_name'            => '',
            'from_email'           => '',
            'reply_to_name'        => '',
            'reply_to_email'       => '',
        ];
    }

    /**
     * @param array $settings
     * @return void
     */
    public static function saveTemplateSettings($settings)
    {
        $raw = self::getRawOption();
        $raw['template'] = $settings;

        update_option(self::OPTION, $raw, false);
    }

    /**
     * @return array
     */
    private static function getRawOption()
    {
        $settings = get_option(self::OPTION, []);

        if (!is_array($settings)) {
            $settings = [];
        }

        if (!isset($settings['emails']) || !is_array($settings['emails'])) {
            $settings['emails'] = [];
        }

        return $settings;
    }

    /**
     * The From / Reply-To headers the template settings ask for.
     *
     * @param array $headers
     * @return array
     */
    public static function getMailHeaders($headers = [])
    {
        if (!is_array($headers)) {
            $headers = [];
        }

        $headers[] = 'Content-Type: text/html; charset=UTF-8';

        $config = self::getTemplateSettings();

        if (!empty($config['from_email']) && is_email($config['from_email'])) {
            $headers[] = $config['from_name']
                ? 'From: ' . self::headerSafe($config['from_name']) . ' <' . $config['from_email'] . '>'
                : 'From: <' . $config['from_email'] . '>';
        }

        if (!empty($config['reply_to_email']) && is_email($config['reply_to_email'])) {
            $headers[] = $config['reply_to_name']
                ? 'Reply-To: ' . self::headerSafe($config['reply_to_name']) . ' <' . $config['reply_to_email'] . '>'
                : 'Reply-To: <' . $config['reply_to_email'] . '>';
        }

        return $headers;
    }

    /**
     * @param string $value
     * @return string
     */
    private static function headerSafe($value)
    {
        return trim(str_replace(['"', "\r", "\n"], '', (string)$value));
    }

    /**
     * Wraps a rendered body in the site's email template.
     *
     * @param string $body
     * @param string|null $footer
     * @return string
     */
    public static function withHtmlTemplate($body, $footer = null)
    {
        $config = self::getTemplateSettings();

        if ($footer === null) {
            $footer = $config['footer_text'];
        }

        return (string)Helper::loadView('email_template', [
            'body'            => $body,
            'footer'          => $footer,
            'template_config' => $config,
        ]);
    }

    /**
     * Renders one email end to end for a given recipient.
     *
     * @param string $emailId
     * @param array $context comment, post, receiver
     * @return array{subject: string, body: string}
     */
    public static function render($emailId, $context)
    {
        return [
            'subject' => self::renderSubject($emailId, $context),
            'body'    => self::renderBody($emailId, $context),
        ];
    }

    /**
     * @param string $emailId
     * @param array $context
     * @return string
     */
    public static function renderSubject($emailId, $context)
    {
        $subject = (new SmartCodeParser($context))->parseString(self::getEmailContent($emailId)['subject']);

        // The parser escapes for HTML because most codes land in a body.
        // A subject line is not HTML, so an apostrophe in a post title has
        // to come back out of its entity before it is sent.
        return wp_specialchars_decode($subject, ENT_QUOTES);
    }

    /**
     * @param string $emailId
     * @param array $context
     * @return string
     */
    public static function renderBody($emailId, $context)
    {
        $body = (new SmartCodeParser($context))->parseString(self::getEmailContent($emailId)['body']);

        return self::withHtmlTemplate($body);
    }

    /**
     * The smartcodes offered in the editor, grouped the way the popover
     * shows them.
     *
     * @param string $emailId
     * @return array
     */
    public static function getSmartCodes($emailId = '')
    {
        $commentCodes = [
            '{{comment.content}}'      => __('Comment', 'fluent-comments'),
            '{{comment.author}}'       => __('Comment author', 'fluent-comments'),
            '{{comment.author_email}}' => __('Comment author email', 'fluent-comments'),
            '{{comment.author_url}}'   => __('Comment author website', 'fluent-comments'),
            '{{comment.date}}'         => __('Comment date', 'fluent-comments'),
            '{{comment.time}}'         => __('Comment time', 'fluent-comments'),
            '##comment.url##'          => __('Link to the comment', 'fluent-comments'),
        ];

        $isCore = strpos($emailId, 'core_') === 0;

        if ($isCore) {
            $commentCodes['{{comment.author_ip}}'] = __('Comment author IP', 'fluent-comments');
            $commentCodes['##comment.approve_url##'] = __('Approve link', 'fluent-comments');
            $commentCodes['##comment.spam_url##'] = __('Mark as spam link', 'fluent-comments');
            $commentCodes['##comment.trash_url##'] = __('Move to trash link', 'fluent-comments');
            $commentCodes['##comment.moderation_url##'] = __('Moderation queue link', 'fluent-comments');
        }

        $groups = [];

        // Core sends one body to a list of people, so there is no single
        // recipient to name in these two.
        if (!$isCore) {
            $groups[] = [
                'key'        => 'receiver',
                'title'      => __('Recipient', 'fluent-comments'),
                'shortcodes' => [
                    '{{receiver.name}}'  => __('Their name', 'fluent-comments'),
                    '{{receiver.email}}' => __('Their email', 'fluent-comments'),
                ],
            ];
        }

        return array_merge($groups, [
            [
                'key'        => 'comment',
                'title'      => __('Comment', 'fluent-comments'),
                'shortcodes' => $commentCodes,
            ],
            [
                'key'        => 'post',
                'title'      => __('Post', 'fluent-comments'),
                'shortcodes' => [
                    '{{post.title}}'       => __('Post title', 'fluent-comments'),
                    '{{post.author_name}}' => __('Post author', 'fluent-comments'),
                    '{{post.date}}'        => __('Post date', 'fluent-comments'),
                    '##post.url##'         => __('Post URL', 'fluent-comments'),
                    '##post.edit_url##'    => __('Edit post URL', 'fluent-comments'),
                ],
            ],
            [
                'key'        => 'site',
                'title'      => __('Site', 'fluent-comments'),
                'shortcodes' => [
                    '{{site.name}}'        => __('Site title', 'fluent-comments'),
                    '{{site.description}}' => __('Site tagline', 'fluent-comments'),
                    '{{site.admin_email}}' => __('Admin email', 'fluent-comments'),
                    '##site.url##'         => __('Site URL', 'fluent-comments'),
                ],
            ],
        ]);
    }

    /**
     * @return array
     */
    public static function getEmailDefaults()
    {
        return [
            'comment_approved'           => [
                /* translators: %s is the post title placeholder. */
                'subject' => sprintf(__('Your comment on %s is now live', 'fluent-comments'), '{{post.title}}'),
                'body'    => self::getDefaultEmailBody('comment_approved'),
            ],
            'reply_to_participants'      => [
                /* translators: %s is the post title placeholder. */
                'subject' => sprintf(__('New reply in the discussion on %s', 'fluent-comments'), '{{post.title}}'),
                'body'    => self::getDefaultEmailBody('reply_to_participants'),
            ],
            'new_comment_to_post_author' => [
                /* translators: %s is the post title placeholder. */
                'subject' => sprintf(__('New comment on %s', 'fluent-comments'), '{{post.title}}'),
                'body'    => self::getDefaultEmailBody('new_comment_to_post_author'),
            ],
            'core_comment_notification'  => [
                /* translators: 1: site title placeholder, 2: post title placeholder. */
                'subject' => sprintf(__('[%1$s] Comment on %2$s', 'fluent-comments'), '{{site.name}}', '{{post.title}}'),
                'body'    => self::getDefaultEmailBody('core_comment_notification'),
            ],
            'core_comment_moderation'    => [
                /* translators: 1: site title placeholder, 2: post title placeholder. */
                'subject' => sprintf(__('[%1$s] Please moderate a comment on %2$s', 'fluent-comments'), '{{site.name}}', '{{post.title}}'),
                'body'    => self::getDefaultEmailBody('core_comment_moderation'),
            ],
        ];
    }

    /**
     * @param string $type
     * @return string
     */
    public static function getDefaultEmailBody($type)
    {
        switch ($type) {
            case 'comment_approved':
                return self::view(
                    /* translators: %s is the recipient's name placeholder. */
                    sprintf(__('Hi %s,', 'fluent-comments'), '{{receiver.name|there}}'),
                    [
                        /* translators: %s is the post title placeholder. */
                        sprintf(__('Your comment on <strong>%s</strong> has been approved and is now live.', 'fluent-comments'), '{{post.title}}'),
                    ],
                    '{{comment.content}}',
                    __('View your comment', 'fluent-comments'),
                    '##comment.url##',
                    [__('Thanks for taking the time to write it.', 'fluent-comments')]
                );

            case 'reply_to_participants':
                return self::view(
                    /* translators: %s is the recipient's name placeholder. */
                    sprintf(__('Hi %s,', 'fluent-comments'), '{{receiver.name|there}}'),
                    [
                        /* translators: 1: comment author placeholder, 2: post title placeholder. */
                        sprintf(__('%1$s replied in a discussion you took part in on <strong>%2$s</strong>.', 'fluent-comments'), '{{comment.author}}', '{{post.title}}'),
                    ],
                    '{{comment.content}}',
                    __('Read the reply', 'fluent-comments'),
                    '##comment.url##',
                    [__('You are getting this because you commented on this post.', 'fluent-comments')]
                );

            case 'new_comment_to_post_author':
                return self::view(
                    /* translators: %s is the recipient's name placeholder. */
                    sprintf(__('Hi %s,', 'fluent-comments'), '{{receiver.name|there}}'),
                    [
                        /* translators: 1: comment author placeholder, 2: post title placeholder. */
                        sprintf(__('%1$s left a comment on your post <strong>%2$s</strong>.', 'fluent-comments'), '{{comment.author}}', '{{post.title}}'),
                    ],
                    '{{comment.content}}',
                    __('View the comment', 'fluent-comments'),
                    '##comment.url##',
                    [__('Replying keeps the conversation going.', 'fluent-comments')]
                );

            case 'core_comment_notification':
                return self::view(
                    __('Hi there,', 'fluent-comments'),
                    [
                        /* translators: %s is the post title placeholder. */
                        sprintf(__('A new comment was posted on <strong>%s</strong>.', 'fluent-comments'), '{{post.title}}'),
                        /* translators: 1: comment author placeholder, 2: comment author email placeholder. */
                        sprintf(__('From %1$s (%2$s)', 'fluent-comments'), '{{comment.author}}', '{{comment.author_email}}'),
                    ],
                    '{{comment.content}}',
                    __('View the comment', 'fluent-comments'),
                    '##comment.url##',
                    [
                        /* translators: %s is the trash link placeholder. */
                        sprintf(__('Move it to the trash: %s', 'fluent-comments'), '<a href="##comment.trash_url##">##comment.trash_url##</a>'),
                    ]
                );

            case 'core_comment_moderation':
                return self::view(
                    __('Hi there,', 'fluent-comments'),
                    [
                        /* translators: %s is the post title placeholder. */
                        sprintf(__('A comment on <strong>%s</strong> is waiting for your review.', 'fluent-comments'), '{{post.title}}'),
                        /* translators: 1: comment author placeholder, 2: comment author email placeholder, 3: author IP placeholder. */
                        sprintf(__('From %1$s (%2$s) at %3$s', 'fluent-comments'), '{{comment.author}}', '{{comment.author_email}}', '{{comment.author_ip}}'),
                    ],
                    '{{comment.content}}',
                    __('Approve it', 'fluent-comments'),
                    '##comment.approve_url##',
                    [
                        /* translators: %s is the spam link placeholder. */
                        sprintf(__('Mark as spam: %s', 'fluent-comments'), '<a href="##comment.spam_url##">##comment.spam_url##</a>'),
                        /* translators: %s is the trash link placeholder. */
                        sprintf(__('Move to the trash: %s', 'fluent-comments'), '<a href="##comment.trash_url##">##comment.trash_url##</a>'),
                        /* translators: %s is the moderation queue link placeholder. */
                        sprintf(__('See everything waiting: %s', 'fluent-comments'), '<a href="##comment.moderation_url##">##comment.moderation_url##</a>'),
                    ]
                );
        }

        return '';
    }

    /**
     * One shape for all five: a greeting, some lines, the comment in a
     * quote, a button, then a closing line or two.
     *
     * The markup is deliberately plain - paragraphs, a blockquote and an
     * anchor rather than nested tables. It has to survive a round trip
     * through TinyMCE once a site owner edits it, and it has to be
     * restyleable from the template screen, which the tables it replaced
     * were not.
     *
     * @param string $greeting
     * @param array $lines
     * @param string $quote
     * @param string $buttonText
     * @param string $buttonUrl
     * @param array $closing
     * @return string
     */
    private static function view($greeting, $lines, $quote, $buttonText, $buttonUrl, $closing = [])
    {
        $html = '<p>' . $greeting . '</p>';

        foreach ($lines as $line) {
            $html .= '<p>' . $line . '</p>';
        }

        if ($quote) {
            $html .= '<blockquote>' . $quote . '</blockquote>';
        }

        if ($buttonText && $buttonUrl) {
            $html .= '<p class="align-center" style="text-align: center;" align="center">'
                . '<a class="fcom_btn" href="' . $buttonUrl . '"'
                . ' style="color:#ffffff;background-color:#2563eb;font-size:15px;border-radius:6px;'
                . 'text-decoration:none;font-weight:600;padding:12px 24px;display:inline-block;">'
                . $buttonText . '</a></p>';
        }

        foreach ($closing as $line) {
            $html .= '<p>' . $line . '</p>';
        }

        return $html;
    }
}
