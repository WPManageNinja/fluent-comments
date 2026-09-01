<?php defined('ABSPATH') or die;

/**
 * The classic theme entry point: whatever the theme asked comments_template()
 * for, swapped for this by CommentsHandler::maybeSwapCommentsTemplate().
 *
 * There is nothing here but the app. A classic theme and a block theme are
 * the same comment section - one form, one list, one submission - and they
 * were two implementations of it for one release too long: a PHP form beside
 * a Svelte one, a comment walker beside two other renderers of the same
 * comment, two config globals, two response shapes off one endpoint. Every
 * fix had to be made twice and the second one was the one that got forgotten.
 *
 * So this renders exactly what the block and the shortcode render, and the
 * first page of comments still arrives as real HTML in the document, which is
 * what the classic template was rendering server side to begin with. See
 * Frontend::renderApp().
 */

$post = get_post();

if (!$post || post_password_required($post)) {
    return;
}

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per field in the renderer.
echo \FluentComments\App\Services\Frontend::renderApp($post->ID);
