<?php
/**
 * Who gets one of our three emails, and - mostly - who never does.
 *
 * This file exists because of what was missing. Every rule below is a
 * question with an obvious answer that the code got wrong anyway, and the
 * reason it got away with it is that nothing here was covered: the email
 * tests next door are all SmartCodeParser and EmailService, which is
 * content, not recipients.
 *
 * The one to keep is the first. A comment's status is not a boolean.
 * Core hands 1, 0, 'spam' or 'trash' to 'comment_post', and the last two
 * are truthy strings, so `if (!$approved)` waves spam through and mails
 * its contents to the post author and to everyone above it in the thread
 * - from the site's own domain, with nothing in the moderation queue to
 * explain it. Core makes the same comparison the strict way in
 * wp_new_comment_notify_postauthor(), and so must we.
 *
 *     php tests/CommentNotificationTest.php
 */

namespace FluentComments\App\Services {

    /**
     * Stand-ins for the three services the handler leans on. None of what
     * they really do - rendering a body, resolving a post type, talking to
     * wp_mail() - is what these assertions are about.
     */
    class Helper
    {
        public static function isFluentCommentsPostType($postType)
        {
            return in_array($postType, $GLOBALS['fluent_post_types'], true);
        }
    }

    class EmailService
    {
        const TOGGLES = [
            'comment_approved'           => ['setting', 'email_on_comment_approval'],
            'reply_to_participants'      => ['setting', 'email_on_reply'],
            'new_comment_to_post_author' => ['setting', 'email_to_author'],
        ];

        public static function isEnabled($emailId)
        {
            return !empty($GLOBALS['enabled_emails'][$emailId]);
        }

        public static function render($emailId, $context)
        {
            return ['subject' => $emailId, 'body' => 'body'];
        }
    }

    class Mailer
    {
        private $subject;

        public function __construct($to, $subject, $body)
        {
            $this->subject = $subject;
        }

        public function to($email, $name = '')
        {
            $GLOBALS['pending'] = ['email' => $email, 'subject' => $this->subject];

            return $this;
        }

        public function send()
        {
            $GLOBALS['sent'][] = $GLOBALS['pending'];
        }
    }
}

namespace {

    define('ABSPATH', __DIR__);

    class WP_Comment
    {
        public $comment_ID;
        public $comment_post_ID = 1;
        public $comment_parent = 0;
        public $comment_approved = '1';
        public $comment_type = 'comment';
        public $comment_author = 'Visitor';
        public $comment_author_email = '';

        public function __construct($id, array $props = [])
        {
            $this->comment_ID = $id;
            $this->comment_author_email = 'visitor' . $id . '@example.test';

            foreach ($props as $key => $value) {
                $this->$key = $value;
            }
        }
    }

    class WP_Post
    {
        public $ID = 1;
        public $post_type = 'post';
        public $post_author = 7;
    }

    class WP_User
    {
        public $user_email = 'author@example.test';
        public $display_name = 'Post Author';
    }

    function get_comment($id)
    {
        return isset($GLOBALS['comments'][$id]) ? $GLOBALS['comments'][$id] : null;
    }

    function get_post($id)
    {
        return $GLOBALS['post'];
    }

    function get_userdata($id)
    {
        return $GLOBALS['post_author'];
    }

    function get_comment_meta($commentId, $key, $single = false)
    {
        return isset($GLOBALS['comment_meta'][$commentId][$key]) ? $GLOBALS['comment_meta'][$commentId][$key] : '';
    }

    function update_comment_meta($commentId, $key, $value)
    {
        $GLOBALS['comment_meta'][$commentId][$key] = $value;

        return true;
    }

    function add_action($tag, $callback, $priority = 10, $args = 1)
    {
    }

    require_once __DIR__ . '/../app/Hooks/Handlers/CommentNotificationHandler.php';

    use FluentComments\App\Hooks\Handlers\CommentNotificationHandler;

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
     * Reset the world. Every email on, one post FluentComments owns, one
     * post author, nothing sent yet.
     */
    function reset_world()
    {
        $GLOBALS['fluent_post_types'] = ['post'];
        $GLOBALS['enabled_emails'] = [
            'comment_approved'           => true,
            'reply_to_participants'      => true,
            'new_comment_to_post_author' => true,
        ];
        $GLOBALS['post'] = new WP_Post();
        $GLOBALS['post_author'] = new WP_User();
        $GLOBALS['comments'] = [];
        $GLOBALS['comment_meta'] = [];
        $GLOBALS['sent'] = [];
        $GLOBALS['pending'] = null;
    }

    /**
     * @return array the addresses mailed, sorted, deduped for readability.
     */
    function recipients()
    {
        $emails = array_map(function ($mail) {
            return $mail['email'];
        }, $GLOBALS['sent']);

        sort($emails);

        return $emails;
    }

    function subjects()
    {
        $subjects = array_map(function ($mail) {
            return $mail['subject'];
        }, $GLOBALS['sent']);

        sort($subjects);

        return $subjects;
    }

    /**
     * Post a comment the way 'comment_post' would, through the public entry
     * point the hook closure calls.
     */
    function post_comment(WP_Comment $comment)
    {
        $GLOBALS['comments'][$comment->comment_ID] = $comment;
        (new CommentNotificationHandler())->maybeSendNewCommentNotification($comment, $GLOBALS['post']);
    }

    echo "\nA comment that never went live is never emailed\n";

    $id = 100;
    foreach (['spam', 'trash', '0'] as $status) {
        reset_world();
        post_comment(new WP_Comment(++$id, ['comment_approved' => $status]));
        check(
            "'$status' sends nothing - it is not on the site, so it is not in anyone's inbox",
            $GLOBALS['sent'] === []
        );
    }

    reset_world();
    post_comment(new WP_Comment(104));
    check("'1' does reach the post author", recipients() === ['author@example.test']);

    echo "\nPingbacks and trackbacks are not somebody writing to you\n";

    $id = 110;
    foreach (['pingback', 'trackback'] as $type) {
        reset_world();
        post_comment(new WP_Comment(++$id, ['comment_type' => $type, 'comment_author_email' => '']));
        check("a $type sends nothing", $GLOBALS['sent'] === []);
    }

    reset_world();
    post_comment(new WP_Comment(113, ['comment_type' => '']));
    check('a comment stored before WP 5.5, with an empty type, still counts', recipients() === ['author@example.test']);

    echo "\nNobody is told about their own comment\n";

    reset_world();
    $GLOBALS['post_author']->user_email = 'visitor121@example.test';
    post_comment(new WP_Comment(121));
    check('the post author commenting on their own post gets nothing', $GLOBALS['sent'] === []);

    reset_world();
    $GLOBALS['comments'][122] = new WP_Comment(122);
    post_comment(new WP_Comment(123, ['comment_parent' => 122, 'comment_author_email' => 'visitor122@example.test']));
    check(
        'replying to yourself does not mail you',
        !in_array('visitor122@example.test', recipients(), true)
    );

    echo "\nAnd that holds on the approval path too, whichever emails are on\n";

    reset_world();
    // The bug this pins: the seed used to live inside the comment_approved
    // branch, so turning that one email off stopped excluding the commenter
    // from the other two.
    $GLOBALS['enabled_emails']['comment_approved'] = false;
    $GLOBALS['comments'][131] = new WP_Comment(131);
    $reply = new WP_Comment(132, ['comment_parent' => 131, 'comment_author_email' => 'visitor131@example.test']);
    $GLOBALS['comments'][132] = $reply;
    (new CommentNotificationHandler())->maybeSendApprovalNotification($reply);
    check(
        'with the approval email off, a self reply still does not mail its own author',
        !in_array('visitor131@example.test', recipients(), true)
    );

    echo "\nOnly approved ancestors are thread participants\n";

    reset_world();
    $GLOBALS['comments'][141] = new WP_Comment(141, ['comment_author_email' => 'top@example.test']);
    $GLOBALS['comments'][142] = new WP_Comment(142, [
        'comment_parent'       => 141,
        'comment_approved'     => 'spam',
        'comment_author_email' => 'victim@example.test',
    ]);
    post_comment(new WP_Comment(143, ['comment_parent' => 142]));
    check(
        'an address planted on a spam comment is never subscribed to the thread',
        !in_array('victim@example.test', recipients(), true)
    );
    check('while the approved ancestor above it still is', in_array('top@example.test', recipients(), true));

    reset_world();
    $GLOBALS['comments'][151] = new WP_Comment(151, ['comment_author_email' => '']);
    post_comment(new WP_Comment(152, ['comment_parent' => 151]));
    check('an ancestor with no address is skipped rather than mailed', recipients() === ['author@example.test']);

    echo "\nA corrupted parent chain terminates\n";

    reset_world();
    $GLOBALS['comments'][161] = new WP_Comment(161, ['comment_parent' => 162, 'comment_author_email' => 'a@example.test']);
    $GLOBALS['comments'][162] = new WP_Comment(162, ['comment_parent' => 161, 'comment_author_email' => 'b@example.test']);
    post_comment(new WP_Comment(163, ['comment_parent' => 161]));
    check('a two comment cycle returns instead of hanging the worker', true);
    check('and both of its members are still notified once', recipients() === [
        'a@example.test', 'author@example.test', 'b@example.test',
    ]);

    echo "\nApproving the same comment twice does not send it twice\n";

    reset_world();
    $GLOBALS['comments'][171] = new WP_Comment(171, ['comment_author_email' => 'top@example.test']);
    $reply = new WP_Comment(172, ['comment_parent' => 171]);
    $GLOBALS['comments'][172] = $reply;

    (new CommentNotificationHandler())->maybeSendApprovalNotification($reply);
    $first = subjects();
    check('the first approval sends all three', $first === [
        'comment_approved', 'new_comment_to_post_author', 'reply_to_participants',
    ]);

    $GLOBALS['sent'] = [];
    (new CommentNotificationHandler())->maybeSendApprovalNotification($reply);
    check('un-approving and approving again sends nothing', $GLOBALS['sent'] === []);

    echo "\nA held comment tells nobody until it is approved\n";

    reset_world();
    $held = new WP_Comment(181, ['comment_approved' => '0']);
    $GLOBALS['comments'][181] = $held;
    post_comment($held);
    check('held on arrival, nothing goes out', $GLOBALS['sent'] === []);

    $held->comment_approved = '1';
    (new CommentNotificationHandler())->maybeSendApprovalNotification($held);
    check('approved later, the commenter and the post author both hear', subjects() === [
        'comment_approved', 'new_comment_to_post_author',
    ]);

    echo "\nA post type FluentComments does not own is not ours to email about\n";

    reset_world();
    $GLOBALS['fluent_post_types'] = ['page'];
    post_comment(new WP_Comment(191));
    check('nothing is sent for a post type that was never enabled', $GLOBALS['sent'] === []);

    echo "\nA switched off email is never sent, even called directly\n";

    reset_world();
    $GLOBALS['enabled_emails']['new_comment_to_post_author'] = false;
    (new CommentNotificationHandler())->sendEmail(
        new WP_Comment(201),
        $GLOBALS['post'],
        'new_comment_to_post_author',
        [['email' => 'author@example.test', 'name' => 'Post Author']]
    );
    check('sendEmail() honours the switch rather than trusting its caller', $GLOBALS['sent'] === []);

    echo "\nOne comment, two hooks, one email\n";

    reset_world();
    // The native form arrives through 'comment_post' and again through
    // 'fluent_comments/after_added_comment' in the same request.
    $twice = new WP_Comment(211);
    post_comment($twice);
    $once = recipients();
    (new CommentNotificationHandler())->maybeSendNewCommentNotification($twice, $GLOBALS['post']);
    check('the second hook in the same request adds nothing', recipients() === $once);

    echo "\n$passed passed, $failed failed\n";
    exit($failed ? 1 : 0);
}
