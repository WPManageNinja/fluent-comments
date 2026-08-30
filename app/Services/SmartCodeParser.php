<?php

namespace FluentComments\App\Services;

/**
 * Fills the placeholders in a customised email.
 *
 * Two delimiters, and the difference matters:
 *
 *   {{comment.author}}  goes into text, so it is HTML escaped
 *   ##comment.url##     goes into an href, so it is URL or attribute escaped
 *
 * Nothing here is trusted. A comment body, its author name and its author
 * URL are whatever a visitor typed, and they end up in an email that a site
 * owner reads in a client that renders HTML - so every value is escaped on
 * the way out, by delimiter, and the one field allowed any markup at all
 * (the comment body) goes through wp_kses_post().
 *
 * An unknown code is left standing rather than blanked. Silently dropping
 * it makes a typo look like an empty value, which is the harder of the two
 * to notice in a template you only see once it has been sent.
 */
class SmartCodeParser
{
    /**
     * Resolved to a URL, and escaped as one whichever delimiter asked.
     */
    const URL_KEYS = [
        'site.url',
        'site.login_url',
        'post.url',
        'post.edit_url',
        'comment.url',
        'comment.approve_url',
        'comment.spam_url',
        'comment.trash_url',
        'comment.moderation_url',
        'comment.author_url',
    ];

    /**
     * @var array{comment: \WP_Comment|null, post: \WP_Post|null, receiver: array}
     */
    private $context;

    public function __construct($context = [])
    {
        $this->context = wp_parse_args($context, [
            'comment'  => null,
            'post'     => null,
            'receiver' => [],
        ]);
    }

    /**
     * @param string|array $template
     * @return string|array
     */
    public function parse($template)
    {
        if (is_array($template)) {
            return array_map([$this, 'parseString'], $template);
        }

        return $this->parseString($template);
    }

    /**
     * @param string $string
     * @return string
     */
    public function parseString($string)
    {
        if (!$string || !is_string($string)) {
            return '';
        }

        if (strpos($string, '{{') === false && strpos($string, '##') === false) {
            return $string;
        }

        return preg_replace_callback('/({{|##)\s*([a-z0-9_.|\- ]+?)\s*(}}|##)/i', function ($matches) {
            return $this->replace($matches);
        }, $string);
    }

    /**
     * @param array $matches
     * @return string
     */
    private function replace($matches)
    {
        $isAttribute = $matches[1] === '##';

        // "comment.author|there" - the part after the pipe is what to print
        // when the value is empty, which is the common case for an author
        // name on a logged out comment.
        $parts = explode('|', $matches[2]);
        $key = trim($parts[0]);
        $fallback = isset($parts[1]) ? trim($parts[1]) : '';

        $value = $this->resolve($key);

        if ($value === null) {
            // Not a code we know. Leave it exactly as it was written.
            return $matches[0];
        }

        if ($value === '' || $value === false) {
            $value = $fallback;
        }

        if (in_array($key, self::URL_KEYS, true)) {
            return esc_url($value);
        }

        if ($key === 'comment.content') {
            return wpautop(wp_kses_post($value));
        }

        return $isAttribute ? esc_attr($value) : esc_html($value);
    }

    /**
     * The raw value behind a code, or null when there is no such code.
     *
     * @param string $key
     * @return string|null
     */
    private function resolve($key)
    {
        $parts = explode('.', $key, 2);

        if (count($parts) !== 2) {
            return null;
        }

        list($group, $field) = $parts;

        switch ($group) {
            case 'site':
                return $this->siteValue($field);
            case 'post':
                return $this->postValue($field);
            case 'comment':
                return $this->commentValue($field);
            case 'receiver':
                return $this->receiverValue($field);
        }

        $value = apply_filters('fluent_comments/smartcode_group_' . $group, null, $field, $this->context);

        return is_scalar($value) ? (string)$value : null;
    }

    /**
     * @param string $field
     * @return string|null
     */
    private function siteValue($field)
    {
        switch ($field) {
            case 'name':
                return get_bloginfo('name');
            case 'description':
                return get_bloginfo('description');
            case 'admin_email':
                return get_option('admin_email');
            case 'url':
                return home_url();
        }

        return null;
    }

    /**
     * @param string $field
     * @return string|null
     */
    private function postValue($field)
    {
        $post = $this->context['post'];

        // The code is real even with nothing behind it, so answer '' rather
        // than null: null would print the raw {{post.title}} back at the
        // reader, which looks like a broken template rather than a missing
        // value.
        if (!$post) {
            return in_array($field, ['title', 'url', 'author_name', 'date', 'edit_url'], true) ? '' : null;
        }

        switch ($field) {
            case 'title':
                return get_the_title($post);
            case 'url':
                return (string)get_permalink($post);
            case 'edit_url':
                return (string)get_edit_post_link($post->ID, 'raw');
            case 'author_name':
                $author = get_userdata($post->post_author);
                return $author ? $author->display_name : '';
            case 'date':
                return get_the_date('', $post);
        }

        return null;
    }

    /**
     * @param string $field
     * @return string|null
     */
    private function commentValue($field)
    {
        $comment = $this->context['comment'];

        $known = [
            'content', 'author', 'author_email', 'author_url', 'author_ip', 'date',
            'time', 'id', 'url', 'approve_url', 'spam_url', 'trash_url', 'moderation_url',
        ];

        if (!$comment) {
            return in_array($field, $known, true) ? '' : null;
        }

        switch ($field) {
            case 'content':
                return $comment->comment_content;
            case 'author':
                return $comment->comment_author;
            case 'author_email':
                return $comment->comment_author_email;
            case 'author_url':
                return $comment->comment_author_url;
            case 'author_ip':
                return $comment->comment_author_IP;
            case 'date':
                return get_comment_date('', $comment);
            case 'time':
                return get_comment_time('', false, true, $comment);
            case 'id':
                return (string)$comment->comment_ID;
            case 'url':
                return (string)get_comment_link($comment);
            case 'approve_url':
                return admin_url('comment.php?action=approve&c=' . $comment->comment_ID);
            case 'spam_url':
                return admin_url('comment.php?action=spam&c=' . $comment->comment_ID);
            case 'trash_url':
                return admin_url('comment.php?action=trash&c=' . $comment->comment_ID);
            case 'moderation_url':
                return admin_url('edit-comments.php?comment_status=moderated');
        }

        return null;
    }

    /**
     * @param string $field
     * @return string|null
     */
    private function receiverValue($field)
    {
        if (!in_array($field, ['name', 'email'], true)) {
            return null;
        }

        $receiver = $this->context['receiver'];

        return isset($receiver[$field]) ? (string)$receiver[$field] : '';
    }
}
