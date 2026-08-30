<?php
/**
 * The email layer: what a placeholder turns into, and where "is this sent"
 * is actually stored.
 *
 * The second half is the one worth guarding. On/off for these five emails
 * lived in two existing places before the Emails screen existed - three
 * keys in our own settings and two WordPress options - and the screen was
 * built to write those in place rather than keep a third copy. A
 * regression there does not throw; it quietly gives two screens two
 * different answers to the same question.
 *
 *     php tests/CommentEmailTest.php
 */

define('ABSPATH', __DIR__);
define('FLUENT_COMMENTS_PLUGIN_PATH', __DIR__ . '/../');

$GLOBALS['options'] = [];

function get_option($name, $default = false)
{
    return array_key_exists($name, $GLOBALS['options']) ? $GLOBALS['options'][$name] : $default;
}

function update_option($name, $value, $autoload = null)
{
    $GLOBALS['options'][$name] = $value;

    return true;
}

function wp_parse_args($args, $defaults = [])
{
    return array_merge($defaults, (array)$args);
}

function apply_filters($tag, $value)
{
    return $value;
}

function do_action($tag)
{
}

function __($text, $domain = '')
{
    return $text;
}

function esc_html($text)
{
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

function esc_attr($text)
{
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

/** Close enough to core's for what is being asserted: no dangerous scheme survives. */
function esc_url($url)
{
    $url = trim((string)$url);

    if ($url === '') {
        return '';
    }

    if (!preg_match('#^(https?://|mailto:|/|\#)#i', $url)) {
        return '';
    }

    return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
}

function wp_kses_post($text)
{
    return preg_replace('#<script[^>]*>.*?</script>#is', '', (string)$text);
}

function wpautop($text)
{
    return '<p>' . $text . '</p>';
}

function wp_specialchars_decode($text, $flags = null)
{
    return htmlspecialchars_decode((string)$text, ENT_QUOTES);
}

function is_email($email)
{
    return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
}

function is_rtl()
{
    return false;
}

function get_bloginfo($what = '')
{
    return $what === 'name' ? 'Test Site' : '';
}

function home_url($path = '')
{
    return 'https://example.test' . $path;
}

function admin_url($path = '')
{
    return 'https://example.test/wp-admin/' . $path;
}

function get_the_title($post = null)
{
    return $post ? $post->post_title : '';
}

function get_permalink($post = null)
{
    return $post ? 'https://example.test/?p=' . $post->ID : false;
}

function get_edit_post_link($id, $context = '')
{
    return 'https://example.test/wp-admin/post.php?post=' . $id . '&action=edit';
}

function get_the_date($format = '', $post = null)
{
    return '1 January 2026';
}

function get_userdata($id)
{
    return null;
}

function get_comment_date($format = '', $comment = null)
{
    return '2 January 2026';
}

function get_comment_time($format = '', $gmt = false, $translate = true, $comment = null)
{
    return '10:30 am';
}

function get_comment_link($comment = null)
{
    return 'https://example.test/?p=1#comment-' . $comment->comment_ID;
}

class WP_Post
{
    public $ID = 1;
    public $post_title = '';
    public $post_type = 'post';
    public $post_author = 1;
}

class WP_Comment
{
    public $comment_ID = 7;
    public $comment_post_ID = 1;
    public $comment_author = '';
    public $comment_author_email = '';
    public $comment_author_url = '';
    public $comment_author_IP = '203.0.113.4';
    public $comment_content = '';
    public $comment_parent = 0;
}

require_once __DIR__ . '/../app/Helpers/Arr.php';
require_once __DIR__ . '/../app/Services/Helper.php';
require_once __DIR__ . '/../app/Services/SmartCodeParser.php';
require_once __DIR__ . '/../app/Services/EmailService.php';

use FluentComments\App\Services\EmailService;
use FluentComments\App\Services\SmartCodeParser;

$passed = 0;
$failed = 0;

function check($label, $condition)
{
    global $passed, $failed;

    if ($condition) {
        $passed++;
        echo "  ok   $label\n";
        return;
    }

    $failed++;
    echo "  FAIL $label\n";
}

function reset_options()
{
    $GLOBALS['options'] = [];
}

function sample_context()
{
    $post = new WP_Post();
    $post->post_title = "Jane's <b>post</b>";

    $comment = new WP_Comment();
    $comment->comment_author = '<script>alert(1)</script>Mallory';
    $comment->comment_author_email = 'mallory@example.com';
    $comment->comment_content = 'Nice post <script>alert(1)</script> really';
    $comment->comment_author_url = 'javascript:alert(1)';

    return [
        'comment'  => $comment,
        'post'     => $post,
        'receiver' => ['name' => 'Ann', 'email' => 'ann@example.com'],
    ];
}

// ---------------------------------------------------------------------------

echo "\nPlaceholders are filled in, and nothing a visitor typed is trusted\n";

$parser = new SmartCodeParser(sample_context());

check(
    'a comment author is HTML escaped',
    strpos($parser->parseString('{{comment.author}}'), '&lt;script&gt;') === 0
);

check(
    'a comment body keeps its markup but loses its script',
    strpos($parser->parseString('{{comment.content}}'), '<script') === false
        && strpos($parser->parseString('{{comment.content}}'), 'Nice post') !== false
);

check(
    'an author website with a javascript: scheme is blanked',
    $parser->parseString('##comment.author_url##') === ''
);

check(
    'a comment link comes through as a URL',
    $parser->parseString('##comment.url##') === 'https://example.test/?p=1#comment-7'
);

check(
    'the recipient is named',
    $parser->parseString('Hi {{receiver.name}},') === 'Hi Ann,'
);

check(
    'an empty value falls back to what follows the pipe',
    (new SmartCodeParser(['receiver' => ['name' => '']]))->parseString('Hi {{receiver.name|there}},') === 'Hi there,'
);

check(
    'an unknown code is left standing rather than blanked',
    $parser->parseString('{{nonsense.thing}}') === '{{nonsense.thing}}'
);

check(
    'a string with no codes in it is returned untouched',
    $parser->parseString('Plain text') === 'Plain text'
);

check(
    'a post title is escaped for text',
    $parser->parseString('{{post.title}}') === 'Jane&#039;s &lt;b&gt;post&lt;/b&gt;'
);

echo "\nA subject line is not HTML, so it comes back out of its entities\n";

reset_options();
$GLOBALS['options']['_fluent_comments_settings'] = ['email_on_comment_approval' => 'yes'];

check(
    'an apostrophe in a post title survives the round trip',
    strpos(EmailService::renderSubject('comment_approved', sample_context()), "Jane's") !== false
);

echo "\nStatus is composed from the switch each email already had\n";

reset_options();
check(
    'our own email is off when its setting is off',
    EmailService::getStatus('comment_approved') === 'disabled'
);

$GLOBALS['options']['_fluent_comments_settings'] = ['email_on_comment_approval' => 'yes'];
check(
    'and on the default once the setting is on',
    EmailService::getStatus('comment_approved') === 'system'
);

reset_options();
check(
    "a core email follows WordPress's own option",
    EmailService::getStatus('core_comment_moderation') === 'disabled'
);

$GLOBALS['options']['moderation_notify'] = '1';
check(
    'which is the same option Settings > Discussion writes',
    EmailService::getStatus('core_comment_moderation') === 'system'
);

echo "\nSaving writes that same switch, not a second copy of it\n";

reset_options();
EmailService::saveEmail('comment_approved', true, 'active', ['subject' => 'Hi', 'body' => '<p>Hello</p>']);

check(
    'turning an email on flips our own setting',
    get_option('_fluent_comments_settings')['email_on_comment_approval'] === 'yes'
);
check('and records that the content is customised', EmailService::getStatus('comment_approved') === 'active');
check(
    'so the customised subject is what gets sent',
    EmailService::getEmailContent('comment_approved')['subject'] === 'Hi'
);

reset_options();
EmailService::saveEmail('core_comment_notification', true, 'active', ['subject' => 'New one', 'body' => '<p>x</p>']);
check(
    "a core email writes WordPress's option",
    get_option('comments_notify') === '1'
);

echo "\nThe switch and the wording are two questions, not one\n";

reset_options();
EmailService::saveEmail('comment_approved', true, 'active', ['subject' => 'Hi', 'body' => '<p>Hello</p>']);

// What the row switch in the list does, and nothing else.
EmailService::setEnabled('comment_approved', false);

check(
    'switching it off from the list flips only the switch',
    get_option('_fluent_comments_settings')['email_on_comment_approval'] === 'no'
);
check('so the composed status reads disabled', EmailService::getStatus('comment_approved') === 'disabled');
check(
    'the draft survives being switched off',
    EmailService::getEmailForEditing('comment_approved')['email']['subject'] === 'Hi'
);
check(
    'and so does the fact that it was customised',
    EmailService::getEmailForEditing('comment_approved')['content_status'] === 'active'
);

EmailService::setEnabled('comment_approved', true);
check(
    'so switching it back on returns it customised, not to the default',
    EmailService::getStatus('comment_approved') === 'active'
);
check(
    'and the customised subject is what gets sent again',
    EmailService::getEmailContent('comment_approved')['subject'] === 'Hi'
);

check(
    'the editor is handed the switch separately from the wording',
    EmailService::getEmailForEditing('comment_approved')['enabled'] === 'yes'
);

EmailService::setEnabled('comment_approved', false);
check(
    'which stays readable while the email is off',
    EmailService::getEmailForEditing('comment_approved')['enabled'] === 'no'
        && EmailService::getEmailForEditing('comment_approved')['content_status'] === 'active'
);

echo "\nThe default is what gets sent until somebody says otherwise\n";

reset_options();
$GLOBALS['options']['_fluent_comments_settings'] = ['email_on_reply' => 'yes'];
$defaults = EmailService::getEmailDefaults();

check(
    'at status system the built in body is used',
    EmailService::getEmailContent('reply_to_participants')['body'] === $defaults['reply_to_participants']['body']
);

EmailService::saveEmail('reply_to_participants', true, 'system', ['subject' => 'Draft', 'body' => '<p>Draft</p>']);
check(
    'even with a draft saved alongside it',
    EmailService::getEmailContent('reply_to_participants')['body'] === $defaults['reply_to_participants']['body']
);
check(
    'while the editor still loads the draft',
    EmailService::getEmailForEditing('reply_to_participants')['email']['body'] === '<p>Draft</p>'
);

check('every email has a default subject', count(array_filter(array_column($defaults, 'subject'))) === 5);
check('every email has a default body', count(array_filter(array_column($defaults, 'body'))) === 5);

echo "\nAn unknown email id is not a thing that can be written\n";

reset_options();
EmailService::saveEmail('made_up', true, 'active', ['subject' => 'x', 'body' => 'y']);
check('saveEmail ignores it', get_option(EmailService::OPTION) === false);
check('getStatus answers system rather than throwing', EmailService::getStatus('made_up') === 'system');
check('isEnabled answers no', EmailService::isEnabled('made_up') === false);

echo "\nThe template frame carries the configured colours\n";

reset_options();
EmailService::saveTemplateSettings(array_merge(EmailService::getTemplateDefaults(), [
    'content_bg' => '#101010',
    'from_email' => 'hello@example.com',
    'from_name'  => 'Test "Site"',
]));

$html = EmailService::withHtmlTemplate('<p>Body here</p>');

check('the body is inside it', strpos($html, '<p>Body here</p>') !== false);
check('the colour is inlined, not only in a stylesheet', substr_count($html, '#101010') >= 2);
check('the footer falls back to the site name', strpos($html, 'Test Site') !== false);

$headers = EmailService::getMailHeaders();
check('the content type says HTML', in_array('Content-Type: text/html; charset=UTF-8', $headers, true));
check(
    'a quote in a From name cannot start a second header',
    in_array('From: Test Site <hello@example.com>', $headers, true)
);

echo "\nThe two core emails offer no recipient codes\n";

$groups = array_column(EmailService::getSmartCodes('core_comment_moderation'), 'key');
check('because core sends one body to a list of people', !in_array('receiver', $groups, true));
check('and the moderation links are there instead', strpos(
    json_encode(EmailService::getSmartCodes('core_comment_moderation')),
    'comment.approve_url'
) !== false);

$ourGroups = array_column(EmailService::getSmartCodes('comment_approved'), 'key');
check('our own emails do offer them', in_array('receiver', $ourGroups, true));
check('and no moderation links', strpos(
    json_encode(EmailService::getSmartCodes('comment_approved')),
    'comment.approve_url'
) === false);

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
