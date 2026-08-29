/**
 * The one way this plugin talks to the server.
 *
 * Everything goes to admin-ajax. There is no REST route and no nonce
 * anywhere: admin-ajax authenticates by cookie alone, so a visitor who
 * arrived on a page served from a full page cache is still recognised,
 * and there is no nonce sitting in that cached HTML going stale. (A stale
 * wp_rest nonce is a hard 403, not a soft downgrade — which is what made
 * the REST route the wrong home for anything a cached page has to reach.)
 *
 * CSRF is covered by the submission token and the HttpOnly SameSite=Lax
 * cookie it is bound to, not by a nonce.
 */

const config = window.fluentCommentVars || window.fluentCommentPublic || {};

const ajaxUrl = config.ajax_url || config.ajaxurl;

const toFormData = (fields) => {
    const body = new FormData();

    Object.keys(fields).forEach((key) => {
        if (fields[key] !== undefined && fields[key] !== null) {
            body.append(key, fields[key]);
        }
    });

    return body;
};

/**
 * @param {string} action  The wp_ajax_* action, without the prefix.
 * @param {object|FormData} fields
 * @param {{method?: 'GET'|'POST'}} options
 * @returns {Promise<object>}
 */
export function ajax(action, fields = {}, { method = 'POST' } = {}) {
    let url = ajaxUrl;
    let body = null;

    if (method === 'GET') {
        const query = new URLSearchParams({ action });

        Object.keys(fields).forEach((key) => {
            if (fields[key] !== undefined && fields[key] !== null) {
                query.append(key, fields[key]);
            }
        });

        url = `${ajaxUrl}?${query.toString()}`;
    } else {
        body = fields instanceof FormData ? fields : toFormData(fields);
        // set(), not append(): a FormData built from the classic form
        // already carries an action field from the markup.
        body.set('action', action);
    }

    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open(method, url, true);
        xhr.responseType = 'json';
        xhr.withCredentials = true;

        xhr.onload = () => {
            if (xhr.status >= 200 && xhr.status < 300) {
                resolve(xhr.response);
            } else {
                reject(xhr.response);
            }
        };

        xhr.onerror = () => reject(null);
        xhr.send(body);
    });
}

/**
 * Every endpoint answers an error as { message }. A null rejection is the
 * network itself failing, which needs a different sentence.
 */
export const errorMessage = (error, fallback) => (error && error.message) || fallback;
