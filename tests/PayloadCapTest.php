<?php
/**
 * The ceiling on how many comments one response will format.
 *
 * per_page caps the top level at 100, but 'threaded' pulls every descendant
 * of those 100 along with them, and each one costs an avatar URL, the
 * comment_text filters and a human readable date before it is even encoded.
 * One post with a runaway thread is otherwise an uncached request that
 * formats tens of thousands of nodes.
 *
 * This is a safety valve, not pagination. The thing worth guarding is that
 * it never fires for anybody real: a normal thread must come back whole,
 * with no flag and nothing missing.
 *
 *     php tests/PayloadCapTest.php
 */

namespace FluentComments\App\Services {
    /**
     * getPayload() asks Helper for two numbers and nothing else.
     */
    class Helper
    {
        public static function getPerPage()
        {
            return 20;
        }

        public static function getMaxDepth()
        {
            return 5;
        }
    }
}

namespace {

define('ABSPATH', __DIR__);

$GLOBALS['filters'] = [];
$GLOBALS['top_level'] = [];

function apply_filters($tag, $value)
{
    return array_key_exists($tag, $GLOBALS['filters']) ? $GLOBALS['filters'][$tag] : $value;
}

function __($text, $domain = '')
{
    return $text;
}

function get_avatar_url($comment, $args = [])
{
    return 'https://example.test/avatar.png';
}

function human_time_diff($from, $to = null)
{
    return '3 days';
}

function get_comments($args = [])
{
    if (!empty($args['count'])) {
        return count($GLOBALS['top_level']);
    }

    return $GLOBALS['top_level'];
}

function comments_open($postId)
{
    return true;
}

class WP_Comment
{
    public $comment_ID;
    public $comment_parent = 0;
    public $comment_approved = '1';
    public $comment_author = 'Visitor';
    public $comment_content = 'Hello';
    public $comment_date_gmt = '2026-01-01 00:00:00';

    private $children = [];

    public function __construct($id, array $children = [])
    {
        $this->comment_ID = $id;
        $this->children = $children;
    }

    public function get_children($args = [])
    {
        return $this->children;
    }
}

require_once __DIR__ . '/../app/Services/CommentsRepository.php';

use FluentComments\App\Services\CommentsRepository;

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
 * A comment with $count replies hanging off it, one per child.
 */
function withReplies($id, $count)
{
    $children = [];

    for ($i = 1; $i <= $count; $i++) {
        $children[] = new WP_Comment($id * 100000 + $i);
    }

    return new WP_Comment($id, $children);
}

/**
 * A chain $depth long: 1 -> 2 -> 3 ...
 */
function chain($depth)
{
    $node = new WP_Comment($depth);

    for ($i = $depth - 1; $i >= 1; $i--) {
        $node = new WP_Comment($i, [$node]);
    }

    return $node;
}

function countNodes(array $node)
{
    $total = 1;

    foreach ($node['children'] as $child) {
        $total += countNodes($child);
    }

    return $total;
}

echo "\nA normal thread comes back whole\n";

$formatted = CommentsRepository::formatComment(withReplies(1, 20));
check('a comment with 20 replies keeps all 20', count($formatted['children']) === 20);
check('and the replies carry the right depth', $formatted['children'][0]['depth'] === 2);
check('while the parent stays at the depth it was given', $formatted['depth'] === 1);

$deep = CommentsRepository::formatComment(chain(30));
check('a 30 deep chain survives intact', countNodes($deep) === 30);

echo "\nThe valve only opens past the ceiling\n";

$cap = CommentsRepository::MAX_PAYLOAD_NODES;

$underCap = CommentsRepository::formatComment(withReplies(1, $cap - 10));
check("a tree of " . ($cap - 9) . " nodes is untouched", countNodes($underCap) === $cap - 9);

$overCap = CommentsRepository::formatComment(withReplies(1, $cap * 3));
check('a tree three times the ceiling is cut', countNodes($overCap) < $cap * 3);
check('and cut to the ceiling, not below it', countNodes($overCap) === $cap);
check('the parent itself always survives', $overCap['ID'] === 1);
check('and the replies that did fit are whole comments', isset($overCap['children'][0]['content']));

echo "\nThe budget is shared by the page, not handed out per comment\n";

// The real risk is a page of top level comments each carrying a large
// thread. If every one got its own budget the page total would be a
// multiple of the ceiling, so this goes through getPayload(), which is
// where the budget is actually owned.
$GLOBALS['top_level'] = [withReplies(1, 800), withReplies(2, 800), withReplies(3, 800)];

$payload = CommentsRepository::getPayload(1, 1, 20);

$total = 0;
foreach ($payload['comments'] as $node) {
    $total += countNodes($node);
}

check('every top level comment is still returned', count($payload['comments']) === 3);
check('the budget is shared, not handed out per comment', $total < 3 * $cap);
check(
    'so the page is bounded by the ceiling plus one node per top level comment',
    $total <= $cap + count($payload['comments'])
);
check('and the third thread is the one that lost its replies', $payload['comments'][2]['children'] === []);
check('and the response says it was cut', $payload['truncated'] === true);

$GLOBALS['top_level'] = [withReplies(1, 5), withReplies(2, 5)];
$small = CommentsRepository::getPayload(1, 1, 20);

$smallTotal = 0;
foreach ($small['comments'] as $node) {
    $smallTotal += countNodes($node);
}

check('an ordinary page is whole', $smallTotal === 12);
check('and is not flagged as cut', $small['truncated'] === false);

$GLOBALS['top_level'] = [];

echo "\nThe ceiling is filterable\n";

$GLOBALS['filters']['fluent_comments/max_payload_nodes'] = 5;
$tiny = CommentsRepository::formatComment(withReplies(1, 50));
check('a site can lower it', countNodes($tiny) === 5);

$GLOBALS['filters']['fluent_comments/max_payload_nodes'] = $cap * 4;
$big = CommentsRepository::formatComment(withReplies(1, $cap * 2));
check('and a site with real thousand reply threads can raise it', countNodes($big) === $cap * 2 + 1);

$GLOBALS['filters'] = [];

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);

}
