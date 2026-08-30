<?php
/**
 * The first page of comments, rendered as HTML for the document.
 *
 * The payload was always in the page, but inside a
 * <script type="application/json"> block - data, not content. Anything that
 * does not run JavaScript, which includes the crawlers behind AI answers and
 * link previews, saw a post whose comments section read "Loading...". The
 * classic template never had this problem because wp_list_comments() writes
 * real markup; this is the block and shortcode path catching up.
 *
 * Two things are worth pinning. The comment body is the only field allowed
 * markup and everything else is attacker supplied, so the escaping has to
 * hold. And the markup has to match CommentBlock.svelte, because app.js
 * throws this away and mounts over it - if the two disagree, the page moves
 * under the reader the moment the script runs.
 *
 *     php tests/ServerRenderTest.php
 */

namespace FluentComments\App\Services {
    class CommentsRepository
    {
    }
}

namespace {

define('ABSPATH', __DIR__);

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

function esc_url($url)
{
    $url = trim((string)$url);

    if ($url === '' || !preg_match('#^(https?://|/)#i', $url)) {
        return '';
    }

    return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
}

/** Close enough: strips what core's kses would strip here. */
function wp_kses_post($text)
{
    $text = preg_replace('#<script[^>]*>.*?</script>#is', '', (string)$text);

    return preg_replace('#\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|\S+)#i', '', $text);
}

function apply_filters($tag, $value)
{
    return $value;
}

function wp_parse_args($args, $defaults = [])
{
    return array_merge($defaults, (array)$args);
}

function get_option($name, $default = false)
{
    return $default;
}

require_once __DIR__ . '/../app/Services/Frontend.php';

use FluentComments\App\Services\Frontend;

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

function comment($id, array $overrides = [])
{
    return array_merge([
        'ID'         => $id,
        'parent_id'  => 0,
        'depth'      => 1,
        'avatar'     => 'https://example.test/avatar.png',
        'human_date' => '3 days ago',
        'author'     => 'Ada Lovelace',
        'content'    => '<p>A perfectly ordinary comment.</p>',
        'date'       => '2026-01-01 00:00:00',
        'unapproved' => false,
        'children'   => [],
    ], $overrides);
}

function render($comments, $maxDepth = 5, $open = true, $avatars = true)
{
    return Frontend::renderCommentList($comments, $maxDepth, $open, $avatars);
}

echo "\nThe comments are in the HTML, which is the whole point\n";

$html = render([comment(1), comment(2, ['author' => 'Grace Hopper'])]);

check('the body text is present as content, not as data', strpos($html, 'A perfectly ordinary comment.') !== false);
check('both authors are named', strpos($html, 'Ada Lovelace') !== false && strpos($html, 'Grace Hopper') !== false);
// Not the word - the avatar carries a legitimate loading="lazy". What must
// be gone is the spinner the container used to hold instead of comments.
check('and the "Loading" placeholder is gone', strpos($html, 'flc_loading_placeholder') === false);
check('an empty thread renders nothing at all', render([]) === '');

echo "\nThe markup matches CommentBlock.svelte, so the mount does not move the page\n";

check('the list is a ul.flc_comment-list', strpos($html, '<ul class="flc_comment-list">') === 0);
check('each comment is an li.flc_comment', substr_count($html, '<li class="flc_comment" id="comment_') === 2);
check('the id form is comment_N, as the component writes it', strpos($html, 'id="comment_1"') !== false);
check('the body is an article.flc_body', strpos($html, '<article class="flc_body">') !== false);
check('the card and header classes are there', strpos($html, '<div class="crayons-card">') !== false && strpos($html, '<div class="comment__header">') !== false);
check(
    'the content class is the one app.scss actually styles',
    strpos($html, '<div class="flc_comment-content">') !== false
);

echo "\nEverything a visitor typed is escaped\n";

$nasty = render([comment(1, [
    'author'  => '<script>alert(1)</script>Eve" onload="x',
    'content' => '<p>hi</p><script>alert(2)</script><img src=x onerror="alert(3)">',
    'avatar'  => 'javascript:alert(4)',
    'date'    => '" onmouseover="alert(5)',
])]);

check('an author name cannot open a tag', strpos($nasty, '<script>alert(1)') === false);
check('an author name cannot break out of the markup', strpos($nasty, 'onload="x') === false);
check('a script in the body is stripped', strpos($nasty, 'alert(2)') === false);
check('an inline handler in the body is stripped', strpos($nasty, 'onerror') === false);
check('a javascript: avatar never becomes a src', strpos($nasty, 'javascript:') === false);
// The word survives as inert text inside the attribute value; what matters
// is that the quote is escaped, so it cannot close the attribute and start
// a new one.
check(
    'a datetime attribute cannot be broken out of',
    strpos($nasty, '<time datetime="&quot; onmouseover=&quot;') !== false
        && strpos($nasty, '" onmouseover="') === false
);
check('but the body keeps the markup it is allowed', strpos($nasty, '<p>hi</p>') !== false);

echo "\nReplies nest, and stop where the setting says\n";

$threaded = render([
    comment(1, ['children' => [
        comment(2, ['depth' => 2, 'children' => [
            comment(3, ['depth' => 3]),
        ]]),
    ]]),
]);

check('children render inside the parent li', substr_count($threaded, '<li class="flc_comment"') === 3);
check('nested lists carry the child class', strpos($threaded, '<ul class="flc_comment-list flc_child_comments">') !== false);
check('the deepest comment is still rendered', strpos($threaded, 'id="comment_3"') !== false);

$atMax = render([comment(1, ['depth' => 5])], 5);
check('a comment at max depth offers no reply link', strpos($atMax, 'reply_text') === false);

$belowMax = render([comment(1, ['depth' => 4])], 5);
check('one above it does', strpos($belowMax, 'reply_text') !== false);

$closed = render([comment(1)], 5, false);
check('and a closed thread offers none at any depth', strpos($closed, 'reply_text') === false);

echo "\nAvatars follow the block setting\n";

check('shown when asked for', strpos(render([comment(1)], 5, true, true), 'flc_avatar') !== false);
check('and omitted when not', strpos(render([comment(1)], 5, true, false), 'flc_avatar') === false);

echo "\nA held comment says so\n";

$held = render([comment(1, ['unapproved' => true])]);
check('the awaiting moderation line is rendered', strpos($held, 'comment-awaiting-moderation') !== false);
check('and an approved one has none', strpos($html, 'comment-awaiting-moderation') === false);

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);

}
