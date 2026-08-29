<?php
/**
 * CommentSubmission's isolation of foreign comment validation hooks,
 * exercised against a stubbed WordPress surface.
 *
 *     php tests/HookIsolationTest.php
 */

$GLOBALS['wp_filter'] = [];
$GLOBALS['allow_list'] = ['Akismet::auto_check_comment'];

/**
 * The parts of WP_Hook this code actually touches.
 */
class WP_Hook
{
    public $callbacks = [];

    public function add_filter($hook_name, $callback, $priority, $accepted_args)
    {
        $this->callbacks[$priority][] = [
            'function'      => $callback,
            'accepted_args' => $accepted_args,
        ];
    }

    public function names()
    {
        $names = [];
        foreach ($this->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                $fn = $callback['function'];
                if (is_string($fn)) {
                    $names[] = $fn;
                } elseif (is_array($fn)) {
                    $names[] = (is_object($fn[0]) ? get_class($fn[0]) : $fn[0]) . '::' . $fn[1];
                } else {
                    $names[] = 'closure';
                }
            }
        }
        sort($names);
        return $names;
    }
}

function apply_filters($tag, $value)
{
    if ($tag === 'fluent_comments/allowed_comment_hooks') {
        return $GLOBALS['allow_list'];
    }
    return $value;
}

class Akismet
{
    public static function auto_check_comment($c) { return $c; }
}

class Some_Captcha_Plugin
{
    public function verify($c) { return $c; }
}

function some_captcha_check($c) { return $c; }

require __DIR__ . '/../app/Services/CommentSubmission.php';

use FluentComments\App\Services\CommentSubmission;

$passed = 0;
$failed = 0;

function check($label, $condition)
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ok   $label\n";
    } else {
        $failed++;
        echo "  FAIL $label\n";
    }
}

function invoke($method)
{
    $m = new ReflectionMethod(CommentSubmission::class, $method);
    $m->setAccessible(true);
    return $m->invoke(null);
}

function buildHooks()
{
    $captcha = new Some_Captcha_Plugin();

    $preprocess = new WP_Hook();
    $preprocess->add_filter('preprocess_comment', ['Akismet', 'auto_check_comment'], 1, 1);
    $preprocess->add_filter('preprocess_comment', [$captcha, 'verify'], 10, 1);
    $preprocess->add_filter('preprocess_comment', 'some_captcha_check', 10, 1);
    $preprocess->add_filter('preprocess_comment', function ($c) { return $c; }, 20, 1);

    $preOnPost = new WP_Hook();
    $preOnPost->add_filter('pre_comment_on_post', 'some_captcha_check', 10, 1);

    $GLOBALS['wp_filter'] = [
        'preprocess_comment'  => $preprocess,
        'pre_comment_on_post' => $preOnPost,
        // An unrelated hook, to prove we only touch the two.
        'comment_post'        => (function () {
            $h = new WP_Hook();
            $h->add_filter('comment_post', 'wp_new_comment_notify_moderator', 10, 1);
            return $h;
        })(),
    ];
}

echo "\nIsolating foreign validators\n";
buildHooks();
$before = $GLOBALS['wp_filter']['preprocess_comment']->names();
check('starts with four preprocess_comment callbacks', count($before) === 4);

invoke('suppressForeignHooks');

$during = $GLOBALS['wp_filter']['preprocess_comment']->names();
check('keeps Akismet, which needs no rendered field', $during === ['Akismet::auto_check_comment']);
check('drops the captcha plugin object callback', !in_array('Some_Captcha_Plugin::verify', $during, true));
check('drops the captcha plugin function callback', !in_array('some_captcha_check', $during, true));
check('drops closures, which can never be allow listed', !in_array('closure', $during, true));
check('empties pre_comment_on_post', $GLOBALS['wp_filter']['pre_comment_on_post']->names() === []);
check(
    'leaves unrelated hooks alone',
    $GLOBALS['wp_filter']['comment_post']->names() === ['wp_new_comment_notify_moderator']
);

echo "\nRestoring\n";
invoke('restoreForeignHooks');
check('puts every callback back', $GLOBALS['wp_filter']['preprocess_comment']->names() === $before);
check('puts pre_comment_on_post back', $GLOBALS['wp_filter']['pre_comment_on_post']->names() === ['some_captcha_check']);

echo "\nThe allow list is extensible\n";
buildHooks();
$GLOBALS['allow_list'] = ['Akismet::auto_check_comment', 'Some_Captcha_Plugin::verify'];
invoke('suppressForeignHooks');
check(
    'an opted-in plugin survives',
    $GLOBALS['wp_filter']['preprocess_comment']->names() === ['Akismet::auto_check_comment', 'Some_Captcha_Plugin::verify']
);
invoke('restoreForeignHooks');
$GLOBALS['allow_list'] = ['Akismet::auto_check_comment'];

echo "\nNothing registered at all\n";
$GLOBALS['wp_filter'] = [];
invoke('suppressForeignHooks');
invoke('restoreForeignHooks');
check('is a no-op rather than a fatal', $GLOBALS['wp_filter'] === []);

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
