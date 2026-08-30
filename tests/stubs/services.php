<?php
/**
 * Stand-ins for the two services CommentsHandler::maybeRejectNativeComment()
 * consults before it turns a submission away. The real ones need a WordPress
 * session; all this test cares about is the answer they give.
 */

namespace FluentComments\App\Services;

class CommentSubmission
{
    public static function isInFlight()
    {
        return $GLOBALS['in_flight'];
    }
}

class SpamGuard
{
    /**
     * The real one is current_user_can('moderate_comments'), filtered.
     */
    public static function isTrustedUser()
    {
        return $GLOBALS['trusted'];
    }
}
