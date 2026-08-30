/**
 * Client side checks, shared by the Svelte form and the classic one so the
 * rule is written once.
 *
 * None of this is the decision. CommentSubmission::validateIdentity() is,
 * and it runs on every submission whatever the browser did or did not do.
 * These exist so a visitor who forgot a field is told immediately instead
 * of after a round trip that also spends their submission token.
 */

/**
 * Deliberately looser than WordPress's is_email(), which is what actually
 * decides. A client regex that is stricter than the server rejects
 * addresses the site would have accepted, and the visitor has no way to
 * tell they are arguing with the browser rather than the site.
 *
 * @param {string} value
 * @return {boolean}
 */
export function isEmail(value) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value || '').trim());
}

/**
 * A logged out commenter needs a name and a usable email address.
 *
 * @param {{name: string, email: string}} fields
 * @param {object} i18n Strings from fluentCommentVars.
 * @return {string} An error message, or an empty string when it passes.
 */
export function identityError(fields, i18n) {
    const name = String(fields.name || '').trim();
    const email = String(fields.email || '').trim();

    if (!name || !email) {
        return i18n.identity_required;
    }

    if (!isEmail(email)) {
        return i18n.email_invalid;
    }

    return '';
}
