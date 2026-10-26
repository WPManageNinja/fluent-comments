<?php
/**
 * A commenter's name cannot add recipients to a notification.
 *
 * wp_mail() splits a string To value, and the Cc, Bcc and Reply-To headers,
 * on every comma and ignores quotes. The mailer used to wrap the name in
 * quotes, so a commenter named "x,attacker@example.invalid,y" received every
 * reply notification sent to the address they typed.
 *
 *     php tests/MailerAddressTest.php
 */

namespace FluentComments\App\Services {
    // The mailer reads From/Reply-To from here; this test sets them directly.
    class EmailService
    {
        public static $settings = [];

        public static function getTemplateSettings()
        {
            return self::$settings;
        }
    }
}

namespace {
    define('ABSPATH', __DIR__);

    $GLOBALS['sent'] = [];

    function apply_filters($tag, $value)
    {
        return $value;
    }

    function is_email($email)
    {
        return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    function sanitize_email($email)
    {
        return trim((string)$email);
    }

    function wp_mail($to, $subject, $body, $headers = [])
    {
        $GLOBALS['sent'][] = compact('to', 'headers');

        return true;
    }

    require_once __DIR__ . '/../app/Services/Mailer.php';

    use FluentComments\App\Services\EmailService;
    use FluentComments\App\Services\Mailer;

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
     * The addresses core wp_mail() would send to: it explodes a string on
     * commas, then reads "Name <email>" from each piece.
     */
    function recipients_as_core_sees_them($value)
    {
        $addresses = [];
        foreach (explode(',', $value) as $piece) {
            if (preg_match('/(.*)<(.+)>/', $piece, $matches)) {
                $piece = $matches[2];
            }
            $piece = trim($piece);
            if (is_email($piece)) {
                $addresses[] = $piece;
            }
        }

        return $addresses;
    }

    function header_value($headers, $name)
    {
        foreach ($headers as $header) {
            if (stripos($header, $name . ':') === 0) {
                return trim(substr($header, strlen($name) + 1));
            }
        }

        return '';
    }

    echo "\nA commenter name\n";

    $GLOBALS['sent'] = [];
    (new Mailer('', 'Reply', 'body'))->to('victim@example.com', 'x,attacker@example.invalid,y')->send();
    check('does not add a recipient', recipients_as_core_sees_them($GLOBALS['sent'][0]['to']) === ['victim@example.com']);

    $GLOBALS['sent'] = [];
    (new Mailer('', 'Reply', 'body'))->to('jane@example.com', 'Jane Doe')->send();
    check('a plain name is kept', $GLOBALS['sent'][0]['to'] === 'Jane Doe <jane@example.com>');

    $GLOBALS['sent'] = [];
    (new Mailer('', 'Reply', 'body'))->to('jane@example.com', ' , <> " ')->send();
    check('a name of separators only falls back to the bare address', $GLOBALS['sent'][0]['to'] === 'jane@example.com');

    echo "\nThe Reply-To name from settings\n";

    EmailService::$settings = [
        'from_name'      => '',
        'from_email'     => '',
        'reply_to_name'  => 'Support, attacker@example.invalid, Team',
        'reply_to_email' => 'support@example.com',
    ];
    $GLOBALS['sent'] = [];
    (new Mailer('', 'Reply', 'body'))->to('jane@example.com')->send();
    $replyTo = header_value($GLOBALS['sent'][0]['headers'], 'Reply-To');
    check('does not add a Reply-To address', recipients_as_core_sees_them($replyTo) === ['support@example.com']);
    EmailService::$settings = [];

    echo "\n$passed passed, $failed failed\n";
    exit($failed ? 1 : 0);
}
