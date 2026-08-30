<?php
/**
 * FluentComments always wants a name and a working email address from a
 * logged out commenter.
 *
 * This is our rule, not WordPress's. Core has require_name_email and a site
 * can switch it off; ours has no switch, which is why the option is no
 * longer on the settings screen. Core's copy is left alone and still
 * governs core's own form on post types we do not handle - so the thing to
 * guard here is that our answer does not depend on it.
 *
 *     php tests/RequiredIdentityTest.php
 */

define('ABSPATH', __DIR__);

$GLOBALS['current_user_id'] = 0;
$GLOBALS['options'] = [];

function get_current_user_id()
{
    return $GLOBALS['current_user_id'];
}

function get_option($name, $default = false)
{
    return array_key_exists($name, $GLOBALS['options']) ? $GLOBALS['options'][$name] : $default;
}

function __($text, $domain = '')
{
    return $text;
}

/** Close enough to core's for what is asserted: a dot in the domain, no spaces. */
function is_email($email)
{
    return (bool)preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/', (string)$email);
}

class WP_Error
{
    public $code;
    public $message;
    public $data;

    public function __construct($code = '', $message = '', $data = '')
    {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }

    public function get_error_code()
    {
        return $this->code;
    }
}

function is_wp_error($thing)
{
    return $thing instanceof WP_Error;
}

// The real class. Nothing in it resolves another service at load time -
// SpamGuard and the rest are only named inside method bodies - so the four
// stubs above are the whole of the WordPress surface it needs to declare.
require_once __DIR__ . '/../app/Services/CommentSubmission.php';

use FluentComments\App\Services\CommentSubmission;

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

/**
 * @return string '' when accepted, otherwise the WP_Error code.
 */
function refuses(array $data)
{
    $result = CommentSubmission::validateIdentity($data);

    return is_wp_error($result) ? $result->get_error_code() : '';
}

$complete = ['author' => 'Ada', 'email' => 'ada@example.test'];

echo "\nA logged out visitor gives both, or the comment does not go in\n";

check('a name and a valid email is accepted', refuses($complete) === '');
check('no name is refused', refuses(['author' => '', 'email' => 'ada@example.test']) === 'require_name_email');
check('no email is refused', refuses(['author' => 'Ada', 'email' => '']) === 'require_name_email');
check('neither is refused', refuses(['author' => '', 'email' => '']) === 'require_name_email');
check('both keys missing entirely is refused', refuses([]) === 'require_name_email');

echo "\nWhitespace is not a name\n";

check('a spaces-only name is refused', refuses(['author' => '   ', 'email' => 'ada@example.test']) === 'require_name_email');
check('a spaces-only email is refused', refuses(['author' => 'Ada', 'email' => "  \t "]) === 'require_name_email');
check('but a padded pair is accepted, trimmed', refuses(['author' => ' Ada ', 'email' => ' ada@example.test ']) === '');

echo "\nThe email has to be one\n";

foreach (['ada', 'ada@', '@example.test', 'ada @example.test', 'ada@example'] as $bad) {
    check("'$bad' is refused as an address", refuses(['author' => 'Ada', 'email' => $bad]) === 'require_valid_email');
}

echo "\nAnd none of it depends on core's option, which is the whole point\n";

foreach (['1' => 'on', '' => 'off'] as $value => $label) {
    $GLOBALS['options']['require_name_email'] = $value;

    check("with core's require_name_email $label, a nameless comment is still refused",
        refuses(['author' => '', 'email' => 'ada@example.test']) === 'require_name_email');
    check("with core's require_name_email $label, a complete one is still accepted", refuses($complete) === '');
}

$GLOBALS['options'] = [];

echo "\nA logged in commenter is never asked\n";

$GLOBALS['current_user_id'] = 7;

check('because core fills both from their account', refuses([]) === '');
check('and whatever was posted is not held against them', refuses(['author' => '', 'email' => 'not-an-email']) === '');

$GLOBALS['current_user_id'] = 0;

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
