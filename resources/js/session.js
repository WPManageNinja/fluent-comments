/**
 * The visitor session: submission token, extension fields, and who (if
 * anyone) is logged in.
 *
 * None of this can come from the page itself. The page is cached and
 * served to everybody, so a user identity baked into it belongs to
 * whoever happened to prime the cache. It comes from admin-ajax instead,
 * which is never cached and authenticates by cookie alone.
 *
 * It is fetched on intent rather than on load, so a visitor who only ever
 * reads the comments costs the site nothing.
 */

import { ajax } from './ajax';

const pending = new Map();

/**
 * What the server rendered into this (cached) page about whether the
 * visitor must log in before commenting.
 *
 * It is a guess - the page may have been cached for somebody else - but it
 * is the right thing to open with, and every form on the page needs it,
 * including a reply form created long after mount. The session corrects it.
 */
const rendered = new Map();

export function seedRenderedLogin(postId, state) {
    rendered.set(String(postId), state);
}

export function renderedLogin(postId) {
    return rendered.get(String(postId)) || null;
}

/**
 * Fetch (once) the session for a post. Repeat callers share the promise.
 *
 * @param {number|string} postId
 * @returns {Promise<{token: string, honeypot: string, me: object|null, fields_html?: string, login_message?: string}>}
 */
export function getSession(postId) {
    const key = String(postId);

    if (!pending.has(key)) {
        const request = ajax('fluent_comment_session', {
            comment_post_ID: postId
        }).then((response) => {
            if (!response || !response.token) {
                throw response;
            }
            return response;
        });

        // A failed lookup must not be cached, or the form stays broken
        // for the rest of the visit.
        pending.set(
            key,
            request.catch((error) => {
                pending.delete(key);
                throw error;
            })
        );
    }

    return pending.get(key);
}

/**
 * Drop the cached session so the next call mints a fresh token.
 *
 * Tokens are single use, so this has to happen after every comment that
 * actually gets created.
 *
 * @param {number|string} postId
 */
export function invalidateSession(postId) {
    pending.delete(String(postId));
}

/**
 * Read one of WordPress's COOKIEHASH suffixed cookies in the browser,
 * rather than printing its value into cached HTML.
 *
 * The hash is matched explicitly so that "comment_author" does not also
 * match "comment_author_email".
 *
 * @param {string} name Base name, without the hash suffix.
 * @returns {string}
 */
export function readHashedCookie(name) {
    const match = document.cookie.match(
        new RegExp('(?:^|;\\s*)' + name + '_[0-9a-f]{32}=([^;]*)')
    );

    if (!match) {
        return '';
    }

    try {
        return decodeURIComponent(match[1].replace(/\+/g, ' '));
    } catch (e) {
        return '';
    }
}
