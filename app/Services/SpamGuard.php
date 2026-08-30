<?php

namespace FluentComments\App\Services;

/**
 * Shared spam / abuse protection for every comment submission.
 *
 * The guard hands out a short lived token that has to be fetched in its own
 * request, and binds that token to a cookie it sets at the same time. A
 * submission therefore has to prove three things: it asked for a token, it
 * kept the cookie that came with it, and it is not replaying a token that
 * has already been spent.
 *
 * Because the cookie is HttpOnly and SameSite=Lax, neither a cross origin
 * script nor a cross site form post can produce a matching pair, which is
 * what makes the token a CSRF defence as well. The binding is where the
 * security lives, not the fact that a token was handed out at all.
 *
 * Tokens are issued over admin-ajax, which sends Access-Control-Allow-Origin
 * only for the site's own origins. (A REST route would echo *any* Origin
 * back with credentials, which is why none of this lives on one.)
 *
 * Signals that are merely suspicious rather than conclusive add to a score
 * instead of rejecting outright, and a comment that scores over the hold
 * threshold goes to the moderation queue. Holding a false positive costs
 * the site a moderation click; rejecting one costs it a reader.
 *
 * Every threshold is filterable so a site can loosen or tighten it.
 */
class SpamGuard
{
    const TOKEN_VERSION = 'v3';

    const TOKEN_FIELD = '_flc_token';

    const SESSION_COOKIE = 'flc_sid';

    const ACTION_ALLOW = 'allow';

    const ACTION_HOLD = 'hold';

    const ACTION_REJECT = 'reject';

    /**
     * Score at or above which a comment is held for moderation.
     */
    const HOLD_THRESHOLD = 30;

    /**
     * Resolved once per request so the cookie we set during a token request
     * is visible to the rest of that same request.
     *
     * @var string|null
     */
    private static $sessionId = null;

    /* ---------------------------------------------------------------------
     * Visitor session
     * ------------------------------------------------------------------ */

    /**
     * The visitor's session id, or an empty string when they have no cookie.
     *
     * @return string
     */
    public static function getSessionId()
    {
        if (self::$sessionId !== null) {
            return self::$sessionId;
        }

        self::$sessionId = '';

        if (!empty($_COOKIE[self::SESSION_COOKIE])) {
            $candidate = sanitize_text_field(wp_unslash($_COOKIE[self::SESSION_COOKIE]));

            // Anything that is not our own format is treated as absent
            // rather than trusted, so a forged cookie can not seed a bucket.
            if (preg_match('/^[a-f0-9]{32}$/', $candidate)) {
                self::$sessionId = $candidate;
            }
        }

        return self::$sessionId;
    }

    /**
     * Return the visitor's session id, creating and setting one if needed.
     *
     * @return string
     */
    public static function ensureSessionId()
    {
        $sessionId = self::getSessionId();

        if ($sessionId) {
            return $sessionId;
        }

        try {
            $sessionId = bin2hex(random_bytes(16));
        } catch (\Exception $e) {
            $sessionId = md5(wp_generate_password(32, true, true) . microtime(true));
        }

        self::$sessionId = $sessionId;

        // A visitor who blocks cookies still gets a token, it just can not
        // be bound to them. verifyToken() scores that instead of failing.
        if (!headers_sent()) {
            setcookie(self::SESSION_COOKIE, $sessionId, [
                'expires'  => time() + self::getMaxAge(),
                'path'     => COOKIEPATH ? COOKIEPATH : '/',
                'domain'   => COOKIE_DOMAIN,
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        return $sessionId;
    }

    /* ---------------------------------------------------------------------
     * Honeypot
     * ------------------------------------------------------------------ */

    /**
     * The honeypot input's name.
     *
     * Derived from the site's salt so it differs per install: a fixed name
     * is trivially added to a bot's ignore list once, everywhere.
     *
     * @return string
     */
    public static function getHoneypotField()
    {
        static $field = null;

        if ($field === null) {
            $field = 'flc_' . substr(hash_hmac('sha256', 'honeypot_field', self::getKey()), 0, 12);
        }

        return $field;
    }

    /* ---------------------------------------------------------------------
     * Tokens
     * ------------------------------------------------------------------ */

    /**
     * Issue a signed, session bound token for a post.
     *
     * @param int $postId
     * @return string
     */
    public static function issueToken($postId)
    {
        $sessionId = self::ensureSessionId();

        try {
            $jti = bin2hex(random_bytes(8));
        } catch (\Exception $e) {
            $jti = md5(wp_generate_password(24, true, true) . microtime(true));
        }

        $payload = implode('|', [
            self::TOKEN_VERSION,
            time(),
            (int)$postId,
            $jti,
            self::hashSession($sessionId),
        ]);

        return $payload . '|' . self::sign($payload);
    }

    /**
     * Validate a token against the post and visitor it was issued for.
     *
     * @param string $token
     * @param int $postId
     * @return array{jti:string,score:int}|\WP_Error
     */
    public static function verifyToken($token, $postId)
    {
        if (!is_string($token) || $token === '') {
            return new \WP_Error(
                'flc_token_missing',
                __('Your session has expired. Please reload the page and try again.', 'fluent-comments'),
                ['status' => 403]
            );
        }

        $parts = explode('|', $token);

        if (count($parts) !== 6) {
            return self::invalidToken();
        }

        list($version, $issuedAt, $tokenPostId, $jti, $sessionHash, $signature) = $parts;

        $payload = implode('|', [$version, $issuedAt, $tokenPostId, $jti, $sessionHash]);

        if (!hash_equals(self::sign($payload), $signature)) {
            return self::invalidToken();
        }

        if ($version !== self::TOKEN_VERSION) {
            return self::invalidToken();
        }

        if ((int)$tokenPostId !== (int)$postId) {
            return self::invalidToken();
        }

        if (self::isTokenSpent($jti)) {
            return self::invalidToken();
        }

        $score = 0;
        $sessionId = self::getSessionId();

        if (!hash_equals(self::hashSession($sessionId), $sessionHash)) {
            if ($sessionId !== '') {
                // This visitor has a session of their own and it is not the
                // one the token was minted for, so the token was lifted.
                return self::invalidToken();
            }

            // No cookie came back at all, so there was nothing to bind to.
            // That is a browser refusing cookies as often as it is a bot,
            // and a rejection here is a dead end the visitor can not get
            // out of by reloading, so hold the comment instead.
            $score += 40;
        }

        $age = time() - (int)$issuedAt;

        if ($age > self::getMaxAge()) {
            return new \WP_Error(
                'flc_token_expired',
                __('Your session has expired. Please reload the page and try again.', 'fluent-comments'),
                ['status' => 403]
            );
        }

        if ($age < self::getMinAge()) {
            // Faster than a person can read the form and type into it. Both
            // clients wait this out, so a real visitor never lands here.
            $score += 40;
        }

        return ['jti' => $jti, 'score' => $score];
    }

    /**
     * Spend a token so it can not be replayed.
     *
     * Only called once a comment has actually been created, so a visitor who
     * mistypes their email does not have to fetch a fresh token to retry.
     *
     * @param string $jti
     * @return void
     */
    public static function spendToken($jti)
    {
        if (!$jti) {
            return;
        }

        set_transient(self::spentTokenKey($jti), 1, self::getMaxAge());
    }

    /**
     * @param string $jti
     * @return bool
     */
    private static function isTokenSpent($jti)
    {
        return (bool)get_transient(self::spentTokenKey($jti));
    }

    /**
     * @param string $jti
     * @return string
     */
    private static function spentTokenKey($jti)
    {
        return 'flc_spent_' . md5($jti);
    }

    /* ---------------------------------------------------------------------
     * Evaluation
     * ------------------------------------------------------------------ */

    /**
     * Weigh every signal for a submission and decide what to do with it.
     *
     * @param int $postId
     * @param array $data The unslashed request payload.
     * @return array{action:string,score:int,jti:string,error:\WP_Error|null}
     */
    public static function evaluate($postId, $data)
    {
        if (self::isTrustedUser()) {
            return self::verdict(self::ACTION_ALLOW, 0, '');
        }

        // Hidden from humans, so any value at all is conclusive.
        if (!empty($data[self::getHoneypotField()])) {
            return self::rejection(new \WP_Error(
                'flc_rejected',
                __('Your comment could not be posted.', 'fluent-comments'),
                ['status' => 403]
            ));
        }

        $token = isset($data[self::TOKEN_FIELD]) ? $data[self::TOKEN_FIELD] : '';
        $verified = self::verifyToken($token, $postId);

        if (is_wp_error($verified)) {
            return self::rejection($verified);
        }

        $score = (int)$verified['score'];

        // The tight limit follows the visitor's session, so a shared exit IP
        // (a company network, a CDN that does not rewrite REMOTE_ADDR) does
        // not put every reader on the site into one bucket.
        $sessionLimit = self::checkRateLimit(
            'post_' . self::rateLimitIdentity(),
            self::getMaxCommentsPerHour(),
            HOUR_IN_SECONDS
        );

        if (is_wp_error($sessionLimit)) {
            return self::rejection($sessionLimit);
        }

        // A bot can mint a fresh session by dropping its cookies, so a much
        // looser per-IP ceiling sits behind it to catch that.
        $ipLimit = self::checkRateLimit(
            'post_ip_' . self::getIp(),
            self::getMaxCommentsPerIpPerHour(),
            HOUR_IN_SECONDS
        );

        if (is_wp_error($ipLimit)) {
            return self::rejection($ipLimit);
        }

        $score = (int)apply_filters('fluent_comments/spam_score', $score, $data, $postId);

        $action = $score >= self::HOLD_THRESHOLD ? self::ACTION_HOLD : self::ACTION_ALLOW;

        return self::verdict($action, $score, $verified['jti']);
    }

    /**
     * Count a comment that was actually created.
     *
     * @param string $jti Token to spend, if the submission carried one.
     * @return void
     */
    public static function recordSubmission($jti = '')
    {
        self::spendToken($jti);
        self::recordHit('post_' . self::rateLimitIdentity(), HOUR_IN_SECONDS);
        self::recordHit('post_ip_' . self::getIp(), HOUR_IN_SECONDS);
    }

    /**
     * Count a token that was actually issued.
     *
     * @return void
     */
    public static function recordTokenIssued()
    {
        self::recordHit('token_' . self::getIp(), HOUR_IN_SECONDS);
    }

    /**
     * Guard the token endpoint itself.
     *
     * Keyed by IP because the first request of a visit has no cookie yet,
     * so the ceiling is set high enough that a shared exit IP never trips
     * it during normal reading.
     *
     * @return true|\WP_Error
     */
    public static function checkTokenRateLimit()
    {
        return self::checkRateLimit(
            'token_' . self::getIp(),
            self::getMaxTokensPerHour(),
            HOUR_IN_SECONDS
        );
    }

    /**
     * @return bool
     */
    public static function isTrustedUser()
    {
        return (bool)apply_filters('fluent_comments/is_trusted_user', current_user_can('moderate_comments'));
    }

    /**
     * Whatever identifies this visitor best: their session if they keep
     * cookies, their IP if they do not.
     *
     * @return string
     */
    private static function rateLimitIdentity()
    {
        $sessionId = self::getSessionId();

        return $sessionId ? 's' . $sessionId : 'i' . self::getIp();
    }

    /**
     * @param string $action
     * @param int $score
     * @param string $jti
     * @return array
     */
    private static function verdict($action, $score, $jti)
    {
        return [
            'action' => $action,
            'score'  => $score,
            'jti'    => $jti,
            'error'  => null,
        ];
    }

    /**
     * @param \WP_Error $error
     * @return array
     */
    private static function rejection($error)
    {
        return [
            'action' => self::ACTION_REJECT,
            'score'  => 100,
            'jti'    => '',
            'error'  => $error,
        ];
    }

    /* ---------------------------------------------------------------------
     * Rate limiting
     * ------------------------------------------------------------------ */

    /**
     * Read a counter without touching it.
     *
     * Checking and recording are deliberately separate: a visitor who
     * mistypes their email ten times should not be locked out for an hour,
     * so only the actions that succeed are counted.
     *
     * @param string $bucket
     * @param int $limit
     * @param int $window In seconds.
     * @return true|\WP_Error
     */
    public static function checkRateLimit($bucket, $limit, $window)
    {
        if ($limit <= 0 || self::isTrustedUser()) {
            return true;
        }

        $entry = self::getRateLimitEntry($bucket, $window);

        if ($entry['count'] >= $limit) {
            return new \WP_Error(
                'flc_rate_limited',
                __('You are posting too quickly. Please slow down and try again later.', 'fluent-comments'),
                ['status' => 429]
            );
        }

        return true;
    }

    /**
     * Count one completed action against a limit.
     *
     * @param string $bucket
     * @param int $window In seconds.
     * @return void
     */
    public static function recordHit($bucket, $window)
    {
        if (self::isTrustedUser()) {
            return;
        }

        $entry = self::getRateLimitEntry($bucket, $window);
        $entry['count']++;

        // The window is anchored to the first hit so it can not be pushed
        // forward indefinitely by a steady trickle of requests.
        $remaining = $window - (time() - $entry['started']);

        set_transient(
            self::getRateLimitKey($bucket),
            $entry,
            $remaining > 0 ? $remaining : $window
        );
    }

    /**
     * @param string $bucket
     * @param int $window
     * @return array{count:int,started:int}
     */
    private static function getRateLimitEntry($bucket, $window)
    {
        $entry = get_transient(self::getRateLimitKey($bucket));

        if (!is_array($entry) || !isset($entry['count'], $entry['started'])) {
            return ['count' => 0, 'started' => time()];
        }

        if (time() - (int)$entry['started'] >= $window) {
            return ['count' => 0, 'started' => time()];
        }

        return ['count' => (int)$entry['count'], 'started' => (int)$entry['started']];
    }

    /**
     * @param string $bucket
     * @return string
     */
    private static function getRateLimitKey($bucket)
    {
        return 'flc_rl_' . md5($bucket);
    }

    /**
     * The visitor's IP address.
     *
     * Proxy headers are only read when the site opts in, because they are
     * set by the client on a site that is not actually behind a proxy and
     * would then defeat the per-IP ceiling entirely. Sites that need it
     * (Cloudflare, a load balancer) turn it on:
     *
     *     add_filter('fluent_comments/trust_proxy_headers', '__return_true');
     *
     * Leaving it off is safe by design: the limits that matter follow the
     * visitor's session cookie, and the IP is only the coarse backstop.
     *
     * Two things to understand before turning it on:
     *
     * 1. Turning it on trusts the header, not the proxy. Nothing here checks
     *    that the request actually arrived from your load balancer, so if the
     *    origin is reachable directly - or the proxy appends to an existing
     *    X-Forwarded-For rather than replacing it, or does not strip
     *    CF-Connecting-IP - a client can put whatever it likes in that header
     *    and rotate its apparent address on every request. Only switch it on
     *    when the origin accepts connections from the proxy alone, and
     *    narrow 'fluent_comments/proxy_ip_headers' to the single header your
     *    proxy actually sets rather than leaving all three enabled.
     *
     * 2. Leaving it off behind a proxy that does not rewrite REMOTE_ADDR
     *    puts every visitor in one bucket. The per-session limit is
     *    unaffected, which is why this is a backstop rather than a lockout,
     *    but the per-IP ceiling then applies to the whole site at once. Most
     *    managed hosts and the Cloudflare plugin restore the real address
     *    into REMOTE_ADDR for you, in which case there is nothing to do here.
     *
     * @return string
     */
    public static function getIp()
    {
        static $ip = null;

        if ($ip !== null) {
            return $ip;
        }

        $ip = '';

        if (apply_filters('fluent_comments/trust_proxy_headers', false)) {
            $headers = apply_filters('fluent_comments/proxy_ip_headers', [
                'HTTP_CF_CONNECTING_IP',
                'HTTP_X_REAL_IP',
                'HTTP_X_FORWARDED_FOR',
            ]);

            foreach ($headers as $header) {
                if (empty($_SERVER[$header])) {
                    continue;
                }

                $candidates = explode(',', sanitize_text_field(wp_unslash($_SERVER[$header])));
                $candidate = filter_var(trim($candidates[0]), FILTER_VALIDATE_IP);

                if ($candidate) {
                    $ip = $candidate;
                    break;
                }
            }
        }

        if (!$ip && !empty($_SERVER['REMOTE_ADDR'])) {
            $ip = filter_var(sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])), FILTER_VALIDATE_IP);
        }

        $ip = $ip ? $ip : '0.0.0.0';

        return $ip;
    }

    /* ---------------------------------------------------------------------
     * Signing
     * ------------------------------------------------------------------ */

    /**
     * @param string $payload
     * @return string
     */
    private static function sign($payload)
    {
        return hash_hmac('sha256', $payload, self::getKey());
    }

    /**
     * The session id never travels inside the token itself, only a keyed
     * digest of it, so a leaked token does not leak the cookie value.
     *
     * @param string $sessionId
     * @return string
     */
    private static function hashSession($sessionId)
    {
        return substr(hash_hmac('sha256', 'sid|' . $sessionId, self::getKey()), 0, 16);
    }

    /**
     * A plugin specific key derived from the site's auth salt.
     *
     * @return string
     */
    private static function getKey()
    {
        static $key = null;

        if ($key === null) {
            $key = hash_hmac('sha256', 'fluent_comments_spam_guard', wp_salt('auth'));
        }

        return $key;
    }

    /**
     * @return \WP_Error
     */
    private static function invalidToken()
    {
        return new \WP_Error(
            'flc_token_invalid',
            __('Your comment could not be verified. Please reload the page and try again.', 'fluent-comments'),
            ['status' => 403]
        );
    }

    /* ---------------------------------------------------------------------
     * Thresholds
     * ------------------------------------------------------------------ */

    /**
     * @return int
     */
    public static function getMinAge()
    {
        return (int)apply_filters('fluent_comments/token_min_age', 2);
    }

    /**
     * @return int
     */
    public static function getMaxAge()
    {
        return (int)apply_filters('fluent_comments/token_max_age', HOUR_IN_SECONDS);
    }

    /**
     * Per visitor, per hour.
     *
     * @return int
     */
    public static function getMaxCommentsPerHour()
    {
        return (int)apply_filters('fluent_comments/max_comments_per_hour', 10);
    }

    /**
     * Per IP, per hour. The backstop for cookie-less floods, so it has to
     * stay well clear of what a shared exit IP produces legitimately.
     *
     * @return int
     */
    public static function getMaxCommentsPerIpPerHour()
    {
        return (int)apply_filters('fluent_comments/max_comments_per_ip_per_hour', 100);
    }

    /**
     * @return int
     */
    public static function getMaxTokensPerHour()
    {
        return (int)apply_filters('fluent_comments/max_tokens_per_hour', 200);
    }
}
