/**
 * Translation for the admin screen.
 *
 * The English string is the key: `$t('Save changes')` looks 'Save changes'
 * up in the map PHP printed into `fluentCommentsVars.i18n` and falls back
 * to itself when nothing is there. That is what makes the extractor
 * possible - `i18n.node.js` scrapes every `$t()` and `$_n()` call under
 * this directory and writes `app/Services/TransStrings.php`, so what a
 * translator sees on translate.wordpress.org is what a developer typed,
 * with no key vocabulary in between and nothing to keep in sync by hand.
 *
 * Run `npm run i18n` after adding, changing or removing one.
 *
 * Exported as plain functions as well as registered on the mixin in
 * `app.js`, because `routes.js` and the `PageHeader` prop defaults need
 * them outside a component instance.
 */

function strings() {
    const vars = window.fluentCommentsVars;

    return (vars && vars.i18n) || {};
}

/**
 * @param {string} string  The English source string, which is also the key.
 * @param {...*}   args    Values for its %s / %d / %1$s placeholders.
 * @returns {string}
 */
export function $t(string, ...args) {
    const translated = strings()[string] || string;

    if (!args.length) {
        return translated;
    }

    // %s and %d are filled in order, %1$s and %2$s by position. A
    // translator often has to reorder them, which is the entire reason the
    // numbered form exists - so leave both in.
    let next = 0;

    return translated.replace(/%(\d+)\$s|%s|%d/g, (match, position) => {
        if (position) {
            const index = parseInt(position, 10) - 1;

            return index < args.length ? args[index] : match;
        }

        return next < args.length ? args[next++] : match;
    });
}

/**
 * Both forms have to be written out at the call site rather than derived,
 * because the extractor reads the source, not the runtime.
 *
 * @param {string} singular
 * @param {string} plural
 * @param {number} count
 * @returns {string}
 */
export function $_n(singular, plural, count) {
    return $t(count > 1 ? plural : singular, count);
}
