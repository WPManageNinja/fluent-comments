/**
 * Grow a textarea to fit its content, capped at maxHeight.
 *
 * Measuring means collapsing the box first and reading scrollHeight back.
 * Both of those fight the page: a theme that puts `transition: all` on form
 * controls (plenty do) makes the collapse animate, so the scrollHeight read
 * returns the height the box is animating *from*, not the content height. The
 * box then ratchets down a couple of pixels per keystroke and never grows.
 * So the transition is suppressed across the measurement and restored after.
 *
 * scrollHeight is the padding box, which is the height a content-box element
 * wants minus its padding, and a border-box element wants plus its borders.
 */
export function autosizeTextArea(el, maxHeight = 300) {
    if (!el) return;

    const style = el.style;
    const previousTransition = style.transition;

    style.transition = 'none';
    style.height = 'auto';

    const computed = window.getComputedStyle(el);
    const padding =
        parseFloat(computed.paddingTop || 0) + parseFloat(computed.paddingBottom || 0);

    // offsetHeight - clientHeight is the borders (plus a horizontal scrollbar,
    // if the theme somehow produced one, which we want counted anyway).
    const adjustment =
        computed.boxSizing === 'border-box' ? el.offsetHeight - el.clientHeight : -padding;

    style.height = Math.min(el.scrollHeight + adjustment, maxHeight) + 'px';

    // Flush the final height while the transition is still off, so restoring
    // it does not animate the box from wherever it was before.
    void el.offsetHeight;
    style.transition = previousTransition;
}
