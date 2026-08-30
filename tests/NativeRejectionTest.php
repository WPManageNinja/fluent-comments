<?php
/**
 * The contract the site owner opts into: pick the post types, switch spam
 * protection on, and anything that reaches core's endpoint without our
 * fields is rejected. Theme has no say in it.
 *
 * Rendering is the separate question, and there the theme decides.
 *
 *     php tests/NativeRejectionTest.php
 */

$GLOBALS['settings'] = [];
$GLOBALS['is_block_theme'] = false;
$GLOBALS['posts'] = [];

function get_option($name, $default = false)
{
    return $name === '_fluent_comments_settings' ? $GLOBALS['settings'] : $default;
}

function wp_parse_args($args, $defaults)
{
    return array_merge($defaults, $args);
}

function apply_filters($tag, $value)
{
    return $value;
}

function wp_is_block_theme()
{
    return $GLOBALS['is_block_theme'];
}

function get_post($post = null)
{
    if (is_object($post)) {
        return $post;
    }

    return isset($GLOBALS['posts'][$post]) ? $GLOBALS['posts'][$post] : null;
}

class WP_Post
{
    public $ID;
    public $post_type;

    public function __construct($id, $postType)
    {
        $this->ID = $id;
        $this->post_type = $postType;
    }
}

function esc_html__($text, $domain = '')
{
    return $text;
}

function wp_die($message, $title = '', $args = [])
{
    $GLOBALS['died'] = true;
}

require_once __DIR__ . '/../app/Helpers/Arr.php';
require_once __DIR__ . '/../app/Services/Helper.php';
require_once __DIR__ . '/stubs/services.php';
require_once __DIR__ . '/../app/Hooks/Handlers/CommentsHandler.php';

use FluentComments\App\Hooks\Handlers\CommentsHandler;
use FluentComments\App\Services\Helper;

/**
 * @param int $postId
 * @return bool Whether the submission was turned away.
 */
function diesOnNativePost($postId)
{
    $GLOBALS['died'] = false;
    (new CommentsHandler())->maybeRejectNativeComment($postId);

    return $GLOBALS['died'];
}

$GLOBALS['posts'] = [
    1 => new WP_Post(1, 'post'),
    2 => new WP_Post(2, 'page'),
];

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

function configure($postTypes, $reject, $blockTheme)
{
    $GLOBALS['settings'] = [
        'post_types'             => $postTypes,
        'reject_native_comments' => $reject,
    ];
    $GLOBALS['is_block_theme'] = $blockTheme;
}

echo "\nRejection follows the post type and the setting, not the theme\n";

foreach ([false, true] as $blockTheme) {
    $theme = $blockTheme ? 'block theme' : 'classic theme';

    configure(['post'], 'yes', $blockTheme);
    check("rejects a selected post type on a $theme", Helper::willRejectNativeComments(1) === true);
    check("leaves an unselected post type alone on a $theme", Helper::willRejectNativeComments(2) === false);

    configure(['post'], 'no', $blockTheme);
    check("respects the setting being off on a $theme", Helper::willRejectNativeComments(1) === false);

    configure([], 'yes', $blockTheme);
    check("rejects nothing when no post type is selected on a $theme", Helper::willRejectNativeComments(1) === false);
}

echo "\nRendering is the separate question, and there the theme decides\n";

configure(['post'], 'yes', false);
check('classic themes render our form by themselves', Helper::isHandlingComments(1) === true);

configure(['post'], 'yes', true);
check('block themes wait to be placed by hand', Helper::isHandlingComments(1) === false);
check(
    'and reject the native form anyway, which is the point',
    Helper::willRejectNativeComments(1) === true
);

echo "\nA moderator is never our spammer\n";

configure(['post'], 'yes', false);

$GLOBALS['trusted'] = false;
$GLOBALS['in_flight'] = false;
$GLOBALS['died'] = false;

check('a visitor posting to core is turned away', diesOnNativePost(1) === true);

$GLOBALS['trusted'] = true;
check('a user who can moderate_comments is let through', diesOnNativePost(1) === false);

$GLOBALS['trusted'] = false;
$GLOBALS['in_flight'] = true;
check('our own submission is let through', diesOnNativePost(1) === false);
$GLOBALS['in_flight'] = false;

echo "\nA missing post is never rejected\n";
configure(['post'], 'yes', false);
check('an unknown id is a no-op', Helper::willRejectNativeComments(999) === false);
check('a null post is a no-op', Helper::willRejectNativeComments(null) === false);

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
