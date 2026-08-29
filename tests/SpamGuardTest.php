<?php
/**
 * SpamGuard's token and scoring logic, exercised against a stubbed
 * WordPress surface so it can run without an install.
 *
 *     php tests/SpamGuardTest.php
 *
 * Exits non zero on the first failing run, so it drops straight into CI.
 */

define('HOUR_IN_SECONDS', 3600);
define('COOKIEPATH', '/');
define('COOKIE_DOMAIN', '');

$GLOBALS['transients'] = [];
$GLOBALS['is_trusted'] = false;

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

    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}

function is_wp_error($t) { return $t instanceof WP_Error; }
function __($s, $d = '') { return $s; }
function apply_filters($tag, $value) { return $value; }
function sanitize_text_field($s) { return trim(strip_tags((string)$s)); }
function wp_unslash($s) { return is_string($s) ? stripslashes($s) : $s; }
function current_user_can($cap) { return $GLOBALS['is_trusted']; }
function wp_salt($scheme = 'auth') { return 'a-fixed-test-salt-value-0123456789'; }
function is_ssl() { return false; }
// headers_sent() and setcookie() are builtins; both are harmless no-ops on CLI.
function wp_generate_password($len = 12) { return bin2hex(random_bytes((int)ceil($len / 2))); }

function get_transient($key)
{
    if (!isset($GLOBALS['transients'][$key])) {
        return false;
    }
    list($value, $expires) = $GLOBALS['transients'][$key];
    if ($expires && $expires < time()) {
        unset($GLOBALS['transients'][$key]);
        return false;
    }
    return $value;
}

function set_transient($key, $value, $ttl = 0)
{
    $GLOBALS['transients'][$key] = [$value, $ttl ? time() + $ttl : 0];
    return true;
}

require __DIR__ . '/../app/Services/SpamGuard.php';

use FluentComments\App\Services\SpamGuard;

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

/**
 * Reset the per-request statics so each scenario starts clean.
 */
function resetGuard($cookie = null)
{
    $ref = new ReflectionClass(SpamGuard::class);
    $prop = $ref->getProperty('sessionId');
    $prop->setAccessible(true);
    $prop->setValue(null, null);

    if ($cookie === null) {
        unset($_COOKIE[SpamGuard::SESSION_COOKIE]);
    } else {
        $_COOKIE[SpamGuard::SESSION_COOKIE] = $cookie;
    }
}

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
$honeypot = SpamGuard::getHoneypotField();

echo "\nHoneypot field name\n";
check('is derived from the salt, not a fixed string', $honeypot !== 'flc_hp' && strpos($honeypot, 'flc_') === 0);
check('is stable within a site', $honeypot === SpamGuard::getHoneypotField());

echo "\nHappy path (cookie kept, normal typing delay)\n";
$sid = str_repeat('ab', 16);
resetGuard($sid);
$token = SpamGuard::issueToken(42);
// Backdate so the token is older than the 2s minimum.
$parts = explode('|', $token);
check('token carries version, time, post, jti, session digest and signature', count($parts) === 6);

resetGuard($sid);
$verified = SpamGuard::verifyToken($token, 42);
check('verifies', !is_wp_error($verified));
check('scores 40 while younger than the minimum age', !is_wp_error($verified) && $verified['score'] === 40);

echo "\nTampering\n";
resetGuard($sid);
check('rejects a flipped signature', is_wp_error(SpamGuard::verifyToken(substr($token, 0, -1) . 'f', 42)));
check('rejects a token minted for another post', is_wp_error(SpamGuard::verifyToken($token, 43)));
check('rejects a truncated token', is_wp_error(SpamGuard::verifyToken('v3|1|2|3', 42)));
check('rejects an empty token', is_wp_error(SpamGuard::verifyToken('', 42)));

$forged = implode('|', ['v3', time(), 42, 'deadbeefdeadbeef', 'ffffffffffffffff', str_repeat('0', 64)]);
check('rejects a wholly forged token', is_wp_error(SpamGuard::verifyToken($forged, 42)));

echo "\nSession binding\n";
resetGuard(str_repeat('cd', 16));
check('rejects a token lifted into another visitor\'s session', is_wp_error(SpamGuard::verifyToken($token, 42)));

echo "\nVisitor whose browser refuses the cookie\n";
// The token is minted (and a cookie set) during the session request; the
// browser then never sends the cookie back on the submit.
resetGuard(null);
$noCookieToken = SpamGuard::issueToken(42);
resetGuard(null);
$verified = SpamGuard::verifyToken($noCookieToken, 42);
check('is not rejected outright', !is_wp_error($verified));
check('scores 40 (unbindable) + 40 (too fast) = 80', !is_wp_error($verified) && $verified['score'] === 80);
check('80 is over the hold threshold, so it is moderated not lost', 80 >= SpamGuard::HOLD_THRESHOLD);

resetGuard(null);
$verdict = SpamGuard::evaluate(42, [SpamGuard::TOKEN_FIELD => $noCookieToken]);
check('evaluate() holds it rather than rejecting', $verdict['action'] === SpamGuard::ACTION_HOLD);

echo "\nStolen token\n";
// Another visitor, with a working session of their own, replaying it.
resetGuard(str_repeat('99', 16));
check('is rejected when the thief has their own session', is_wp_error(SpamGuard::verifyToken($noCookieToken, 42)));

echo "\nReplay\n";
resetGuard($sid);
$replayToken = SpamGuard::issueToken(42);
$first = SpamGuard::verifyToken($replayToken, 42);
check('first use verifies', !is_wp_error($first));
SpamGuard::spendToken($first['jti']);
resetGuard($sid);
check('second use is rejected once spent', is_wp_error(SpamGuard::verifyToken($replayToken, 42)));

echo "\nExpiry\n";
resetGuard($sid);
$old = SpamGuard::issueToken(42);
$p = explode('|', $old);
$p[1] = time() - (HOUR_IN_SECONDS + 60);
$payload = implode('|', array_slice($p, 0, 5));
// Re-sign so we are testing the age check, not the signature check.
$ref = new ReflectionMethod(SpamGuard::class, 'sign');
$ref->setAccessible(true);
$expired = $payload . '|' . $ref->invoke(null, $payload);
check('rejects a token older than the maximum age', is_wp_error(SpamGuard::verifyToken($expired, 42)));

// A token issued long enough ago to pass the minimum age scores nothing.
$p2 = explode('|', SpamGuard::issueToken(42));
$p2[1] = time() - 30;
$payload2 = implode('|', array_slice($p2, 0, 5));
$aged = $payload2 . '|' . $ref->invoke(null, $payload2);
resetGuard($sid);
$verified = SpamGuard::verifyToken($aged, 42);
check('a token of realistic age scores 0', !is_wp_error($verified) && $verified['score'] === 0);

echo "\nevaluate()\n";
resetGuard($sid);
$verdict = SpamGuard::evaluate(42, [SpamGuard::TOKEN_FIELD => $aged]);
check('clean submission is allowed', $verdict['action'] === SpamGuard::ACTION_ALLOW);

resetGuard($sid);
$verdict = SpamGuard::evaluate(42, [SpamGuard::TOKEN_FIELD => $aged, $honeypot => 'http://spam.example']);
check('filled honeypot is rejected', $verdict['action'] === SpamGuard::ACTION_REJECT);

resetGuard($sid);
$verdict = SpamGuard::evaluate(42, [SpamGuard::TOKEN_FIELD => $token]);
check('too-fast submission is held, not rejected', $verdict['action'] === SpamGuard::ACTION_HOLD);

resetGuard($sid);
$verdict = SpamGuard::evaluate(42, []);
check('missing token is rejected', $verdict['action'] === SpamGuard::ACTION_REJECT);

echo "\nRate limiting\n";
resetGuard($sid);
$GLOBALS['transients'] = [];
for ($i = 0; $i < SpamGuard::getMaxCommentsPerHour(); $i++) {
    $p3 = explode('|', SpamGuard::issueToken(42));
    $p3[1] = time() - 30;
    $pl = implode('|', array_slice($p3, 0, 5));
    $tok = $pl . '|' . $ref->invoke(null, $pl);

    resetGuard($sid);
    $v = SpamGuard::evaluate(42, [SpamGuard::TOKEN_FIELD => $tok]);
    if ($v['action'] !== SpamGuard::ACTION_ALLOW) {
        echo "  (stopped early at $i: {$v['action']})\n";
        break;
    }
    SpamGuard::recordSubmission($v['jti']);
}

$p4 = explode('|', SpamGuard::issueToken(42));
$p4[1] = time() - 30;
$pl4 = implode('|', array_slice($p4, 0, 5));
$tok4 = $pl4 . '|' . $ref->invoke(null, $pl4);
resetGuard($sid);
$v = SpamGuard::evaluate(42, [SpamGuard::TOKEN_FIELD => $tok4]);
check('blocks the 11th comment from one session', $v['action'] === SpamGuard::ACTION_REJECT && $v['error']->get_error_code() === 'flc_rate_limited');

// A different visitor on the same IP must not inherit that.
$otherSid = str_repeat('ef', 16);
resetGuard($otherSid);
$p5 = explode('|', SpamGuard::issueToken(42));
$p5[1] = time() - 30;
$pl5 = implode('|', array_slice($p5, 0, 5));
$tok5 = $pl5 . '|' . $ref->invoke(null, $pl5);
resetGuard($otherSid);
$v = SpamGuard::evaluate(42, [SpamGuard::TOKEN_FIELD => $tok5]);
check('a different visitor behind the same IP is unaffected', $v['action'] === SpamGuard::ACTION_ALLOW);

echo "\nTrusted users\n";
$GLOBALS['is_trusted'] = true;
resetGuard(null);
$verdict = SpamGuard::evaluate(42, []);
check('a moderator bypasses every check', $verdict['action'] === SpamGuard::ACTION_ALLOW);
$GLOBALS['is_trusted'] = false;

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
