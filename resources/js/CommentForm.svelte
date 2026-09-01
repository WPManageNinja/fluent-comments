<script>
    import { ajax, errorMessage } from './ajax';
    import { getSession, invalidateSession, readHashedCookie, renderedLogin } from './session';
    import { identityError } from './validate.js';
    import { autosizeTextArea } from './autosize';

    let { documentId, threadId, willScroll, oncreated, showAvatar = true } = $props();

    const vars = window.fluentCommentVars;
    const i18n = vars.i18n;
    const minTokenAge = (vars.token_min_age || 2) * 1000;
    const honeypotField = vars.honeypot || 'flc_hp';

    const formId = $derived(`comment_form_${documentId}_${threadId || 0}`);

    let isOpen = $state(false);
    let isSubmitting = $state(false);
    let error = $state('');
    let notice = $state('');
    let resizeFrame = null;

    // What the server rendered into the page about this visitor. A guess -
    // the page may have been cached for somebody else - but the right thing
    // to open with, and the session below corrects it.
    const guess = renderedLogin(documentId);

    // Who the visitor is only becomes known once the session is fetched,
    // which happens on intent. Until then the form renders in its neutral,
    // cacheable state: a default avatar and no personal fields.
    let me = $state(null);
    let loginMessage = $state(guess?.message || '');
    // The one branch that cannot render neutral: there is no form that is
    // right for both the reader who must log in and the member who need
    // not. So it opens on the rendered guess rather than on nothing, and
    // the session - never cached, and the authority - moves it if the guess
    // belonged to somebody else. Anything else means a form that is typed
    // into and then vanishes.
    let loginRequired = $state(!!guess?.mustLogIn);
    let sessionResolved = $state(false);
    // Distinct from sessionResolved, which is true even when the request
    // failed. Only a session that actually answered tells us anything about
    // who is asking.
    let sessionOk = $state(false);

    // Markup contributed through the fluent_comments/form_fields action.
    // It travels with the session rather than in the (cached) page, so
    // anything per-request inside it is fresh.
    let fieldsHtml = $state('');
    let fieldsEl = $state(null);

    const isLoggedIn = $derived(!!me);
    const avatar = $derived(me?.avatar || vars.default_avatar);

    // The submission token proves the visitor asked for the form and kept
    // the cookie that came with it. It is single use, so it is dropped
    // after every comment that actually gets created.
    let token = null;
    let tokenIssuedAt = 0;
    let sessionRequest = null;

    let form = $state({
        content: '',
        name: readHashedCookie('comment_author'),
        email: readHashedCookie('comment_author_email'),
        honeypot: ''
    });

    $effect(() => {
        if (!willScroll) return;

        const el = document.getElementById(formId);
        if (!el) return;

        el.scrollIntoView({ behavior: 'smooth', block: 'center' });

        setTimeout(() => {
            el.querySelector('textarea')?.focus({ preventScroll: true });
        }, 100);
    });

    function loadSession() {
        if (token) {
            return Promise.resolve();
        }

        if (sessionRequest) {
            return sessionRequest;
        }

        sessionRequest = getSession(documentId)
            .then((session) => {
                token = session.token;
                tokenIssuedAt = Date.now();
                sessionOk = true;
                me = session.me || null;
                // Both directions: this confirms the rendered guess or
                // replaces it. login_message is present exactly when the
                // site requires login and this visitor is not signed in, so
                // it is the signal as well as the wording.
                loginRequired = !!session.login_message;
                loginMessage = session.login_message || loginMessage;
                fieldsHtml = session.fields_html || '';
            })
            .catch(() => {
                token = null;
            })
            .finally(() => {
                sessionResolved = true;
                sessionRequest = null;
            });

        return sessionRequest;
    }

    /**
     * Intent, one step earlier than focus.
     *
     * Nothing is fetched on load - a visitor who only reads the comments
     * costs the site nothing - but reaching for the comment area is as good
     * a signal as landing in it, and the head start is usually the whole
     * round trip. It is also the only thing that ever corrects a rendered
     * guess the page cache handed to the wrong visitor, which is why the
     * handler sits on the wrapper: when the notice is all that is showing,
     * the notice is the only thing there is to reach for.
     */
    function prefetchSession() {
        loadSession();
    }

    function handleOpen() {
        isOpen = true;
        loadSession();
    }

    function resizeTextArea(event) {
        if (resizeFrame) cancelAnimationFrame(resizeFrame);

        const el = event.target;
        resizeFrame = requestAnimationFrame(() => autosizeTextArea(el));
    }

    // Injected after load, so anything that binds on DOMContentLoaded has
    // already missed it. This is how an extender gets told to initialise.
    $effect(() => {
        if (!fieldsHtml || !fieldsEl) return;

        document.dispatchEvent(
            new CustomEvent('fluent-comments:fields-rendered', {
                detail: { container: fieldsEl, postId: documentId, threadId: threadId || 0 }
            })
        );
    });

    /**
     * Everything an extender put in the slot, ready to submit.
     */
    function collectExtraFields() {
        const values = {};

        if (!fieldsEl) {
            return values;
        }

        fieldsEl.querySelectorAll('input, select, textarea').forEach((input) => {
            if (!input.name) return;

            if ((input.type === 'checkbox' || input.type === 'radio') && !input.checked) {
                return;
            }

            values[input.name] = input.value;
        });

        return values;
    }

    const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

    async function handleSubmit(event) {
        event.preventDefault();

        if (!form.content.trim()) {
            error = i18n.content_required;
            return;
        }

        isSubmitting = true;
        error = '';
        notice = '';

        try {
            await loadSession();

            // Only once the session has answered, because until then we do
            // not know whether this visitor is signed in - and a signed in
            // one is never asked for either field. The server checks this
            // again either way; this is only to save a round trip.
            if (sessionOk && !isLoggedIn) {
                const identity = identityError(form, i18n);

                if (identity) {
                    error = identity;
                    return;
                }
            }

            // A comment submitted sooner after the token was issued than a
            // person could plausibly type scores against itself, so absorb
            // the difference here rather than penalising a fast typist.
            const age = Date.now() - tokenIssuedAt;
            if (token && age < minTokenAge) {
                await wait(minTokenAge - age);
            }

            // Core's own field names: CommentSubmission hands them to
            // wp_handle_comment_submission(), which expects core's.
            const payload = {
                ...collectExtraFields(),
                comment_post_ID: documentId,
                comment: form.content,
                author: form.name,
                email: form.email,
                comment_parent: threadId || 0,
                _flc_token: token || '',
                [honeypotField]: form.honeypot
            };

            const response = await ajax('fluent_comment_post', payload);

            // The token has been spent; the next comment needs a new one.
            token = null;
            invalidateSession(documentId);

            if (response.approved) {
                oncreated?.(response.formatted_comment);
            } else {
                notice = response.message || i18n.awaiting_moderation;
            }

            form.content = '';
            isOpen = false;
        } catch (err) {
            // Whatever went wrong, the token may or may not have survived
            // it. Start clean rather than replaying a possibly spent one.
            token = null;
            invalidateSession(documentId);
            error = errorMessage(err, i18n.generic_error);
        } finally {
            isSubmitting = false;
        }
    }
</script>

<div
    id={formId}
    class="fluent_comments_form"
    onpointerenter={prefetchSession}
    onpointerdown={prefetchSession}
>
    {#if loginRequired}
        <div class="flc_login_message">
            {#if loginMessage}
                <!-- eslint-disable-next-line svelte/no-at-html-tags -- built and escaped in PHP -->
                <p>{@html loginMessage}</p>
            {:else}
                <p>{i18n.login_required}</p>
            {/if}
        </div>
    {:else}
        <div class="flc_respond">
            <div class="flc_comment_wrap">
                {#if showAvatar}
                    <div class="flc_author_placeholder">
                        <div class="flc_comment_author">
                            <img alt="" src={avatar} />
                        </div>
                    </div>
                {/if}
                <div class="flc_comment_form">
                    <div class="flc_form_field flc_textarea">
                        <div class="flc_comment">
                            <textarea
                                class="flc_content_textarea"
                                class:flc_text_active={isOpen}
                                bind:value={form.content}
                                oninput={resizeTextArea}
                                onfocus={handleOpen}
                                name="comment"
                                title={i18n.comment_placeholder}
                                placeholder={i18n.comment_placeholder}
                            ></textarea>
                        </div>
                    </div>

                    <div class="flc_hp_field" aria-hidden="true">
                        <label for="{formId}_hp">{i18n.honeypot_label}</label>
                        <input
                            id="{formId}_hp"
                            name={honeypotField}
                            bind:value={form.honeypot}
                            type="text"
                            tabindex="-1"
                            autocomplete="off"
                        />
                    </div>

                    {#if isOpen}
                        <!-- Only once the session has said this visitor is signed
                             out. Rendering these on the way to that answer is
                             how a signed in commenter came to watch a name and
                             an email field appear and then vanish under them:
                             the page cannot know who is asking, so the form
                             asks for nothing until it has been told. A session
                             that fails to answer resolves as signed out, which
                             is the state that still lets somebody comment. -->
                        {#if sessionResolved && !isLoggedIn}
                            <div class="flc_row flc_person_form_fields">
                                <div class="flc_form_field">
                                    <label class="flc_sr-only" for="{formId}_name">{i18n.name_placeholder}</label>
                                    <input
                                        placeholder={i18n.name_placeholder}
                                        id="{formId}_name"
                                        bind:value={form.name}
                                        type="text"
                                        class="flc_input_text"
                                    />
                                </div>
                                <div class="flc_form_field">
                                    <label class="flc_sr-only" for="{formId}_email">{i18n.email_placeholder}</label>
                                    <input
                                        placeholder={i18n.email_placeholder}
                                        id="{formId}_email"
                                        bind:value={form.email}
                                        type="email"
                                        class="flc_input_text"
                                    />
                                </div>
                            </div>
                        {/if}
                        {#if fieldsHtml}
                            <!-- eslint-disable-next-line svelte/no-at-html-tags -- rendered by the site's own fluent_comments/form_fields callbacks -->
                            <div class="flc_extra_fields" bind:this={fieldsEl}>{@html fieldsHtml}</div>
                        {/if}

                        <div class="flc_submit">
                            <button class="flc_button" disabled={isSubmitting} onclick={handleSubmit}>
                                {isSubmitting ? i18n.submitting : i18n.submit}
                            </button>
                        </div>
                    {/if}

                    {#if notice}
                        <p class="flc_notice" role="status">{notice}</p>
                    {/if}

                    {#if error}
                        <p class="flc_error" role="alert">{error}</p>
                    {/if}
                </div>
            </div>
        </div>
    {/if}
</div>
