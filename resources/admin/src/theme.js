/**
 * Light / dark for the admin screen, to the Fluent theme mode spec.
 *
 * The contract is shared with every other Fluent plugin on the same origin,
 * so none of the three names below is ours to change:
 *
 *   localStorage `fluent_theme_mode`  light | dark | system:light | system:dark
 *   <body> class `fluent_theme_dark`  present only while dark
 *
 * `system:dark` is a *cache*, not a mode. A media query is not always
 * resolved at the moment the page starts painting, so the stored value
 * carries the last answer: paint that immediately, then confirm against
 * matchMedia and correct if the machine changed its mind since. Without it
 * a system-dark visitor gets a white flash on every load.
 *
 * Element Plus keys its own dark tokens on `html.dark`, which is its API
 * rather than a second theme switch of ours, so that class is set alongside
 * and never read back. Everything the app itself paints hangs off the body
 * class.
 */
const STORAGE_KEY = 'fluent_theme_mode';
const DARK_CLASS = 'fluent_theme_dark';

function prefersDark() {
    return !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
}

function read() {
    try {
        return window.localStorage.getItem(STORAGE_KEY) || '';
    } catch (e) {
        return '';
    }
}

function write(value) {
    try {
        window.localStorage.setItem(STORAGE_KEY, value);
    } catch (e) {
        // Private mode, or storage full. The theme still applies for this
        // page load, which is the part that matters.
    }
}

function paint(isDark) {
    document.body.classList.toggle(DARK_CLASS, isDark);
    document.documentElement.classList.toggle('dark', isDark);
}

/**
 * The mode a person chose, with the two system values folded back into one.
 * Light unless asked otherwise: wp-admin's chrome is light, and a dark
 * island inside it is not something to hand somebody unasked.
 */
export function getMode() {
    const stored = read();

    if (stored === 'dark') {
        return 'dark';
    }

    if (stored === 'system:dark' || stored === 'system:light') {
        return 'system';
    }

    return 'light';
}

export function isDark() {
    return document.body.classList.contains(DARK_CLASS);
}

/**
 * Paint what is stored. Called before the app mounts.
 */
export function applyStoredTheme() {
    const stored = read();

    if (stored === 'dark') {
        paint(true);
        return 'dark';
    }

    if (stored !== 'system:dark' && stored !== 'system:light') {
        paint(false);
        return 'light';
    }

    // The cached answer first, so the first frame is already right...
    paint(stored === 'system:dark');

    // ...then the real one, which is usually the same and occasionally not.
    const dark = prefersDark();
    paint(dark);
    write(dark ? 'system:dark' : 'system:light');

    return 'system';
}

export function setMode(mode) {
    if (mode === 'system') {
        const dark = prefersDark();
        write(dark ? 'system:dark' : 'system:light');
        paint(dark);
        return 'system';
    }

    const dark = mode === 'dark';
    write(dark ? 'dark' : 'light');
    paint(dark);

    return dark ? 'dark' : 'light';
}

/**
 * Follow the machine while the mode is `system` - somebody on a sunset
 * schedule should not have to reload the screen at dusk. Ignored in the two
 * static modes, which are a choice and outrank the system.
 */
export function watchSystem(onChange) {
    if (!window.matchMedia) {
        return;
    }

    const query = window.matchMedia('(prefers-color-scheme: dark)');

    const handler = (event) => {
        if (getMode() !== 'system') {
            return;
        }

        write(event.matches ? 'system:dark' : 'system:light');
        paint(event.matches);

        if (onChange) {
            onChange(event.matches);
        }
    };

    // addListener is the Safari < 14 spelling, and wp-admin still runs there.
    if (query.addEventListener) {
        query.addEventListener('change', handler);
    } else if (query.addListener) {
        query.addListener(handler);
    }
}
