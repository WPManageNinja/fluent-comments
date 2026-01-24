<script>
    import { rest } from './functions';

    let { documentId, threadId, willScroll, oncreated } = $props();

    const formId = $derived(`comment_form_${documentId}_${threadId || 0}`);
    const { me, login_message, user_avatar } = window.fluentCommentVars;
    const isLoggedIn = !!me;

    let isOpen = $state(false);
    let isSubmitting = $state(false);
    let error = $state('');
    let resizeFrame = null;

    let form = $state({
        content: '',
        name: '',
        email: ''
    });

    // Scroll to form and focus textarea when replying
    $effect(() => {
        if (!willScroll) return;

        const el = document.getElementById(formId);
        if (!el) return;

        el.scrollIntoView({ behavior: 'smooth', block: 'center' });

        setTimeout(() => {
            el.querySelector('textarea')?.focus({ preventScroll: true });
        }, 100);
    });

    function handleOpen() {
        isOpen = true;
    }

    function resizeTextArea(event) {
        if (resizeFrame) cancelAnimationFrame(resizeFrame);

        resizeFrame = requestAnimationFrame(() => {
            const el = event.target;
            el.style.height = '5px';
            el.style.height = Math.min(el.scrollHeight, 300) + 'px';
        });
    }

    function handleSubmit(event) {
        event.preventDefault();

        if (!form.content) {
            alert('Please provide comment content first');
            return;
        }

        const submitData = { ...form };
        if (threadId) {
            submitData.parent_id = threadId;
        }

        isSubmitting = true;
        error = '';

        rest.post('comments/' + documentId, submitData)
            .then(response => {
                oncreated?.(response.formatted_comment);
                form.content = '';
                isOpen = false;
            })
            .catch(err => {
                error = err.response?.message || 'An error occurred';
            })
            .finally(() => {
                isSubmitting = false;
            });
    }
</script>

<div id={formId} class="fluent_comments_form">
    {#if login_message && !isLoggedIn}
        <div class="flc_login_message">
            <p>{@html login_message}</p>
        </div>
    {:else}
        <div class="flc_respond">
            <div class="flc_comment_wrap">
                <div class="flc_author_placeholder">
                    <div class="flc_comment_author">
                        <img alt="" src={user_avatar} />
                    </div>
                </div>
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
                                title="Write your comment here..."
                                placeholder="Write your comment here..."
                            ></textarea>
                        </div>
                    </div>
                    {#if isOpen}
                        {#if !isLoggedIn}
                            <div class="flc_row flc_person_form_fields">
                                <div class="flc_form_field">
                                    <input
                                        placeholder="Your Name"
                                        id="{formId}_name"
                                        bind:value={form.name}
                                        type="text"
                                        class="flc_input_text"
                                    />
                                </div>
                                <div class="flc_form_field">
                                    <input
                                        placeholder="Your Email Address"
                                        id="{formId}_email"
                                        bind:value={form.email}
                                        type="email"
                                        class="flc_input_text"
                                    />
                                </div>
                            </div>
                        {/if}
                        <div class="flc_submit">
                            <button class="flc_button" disabled={isSubmitting} onclick={handleSubmit}>
                                {isSubmitting ? 'Submitting' : 'Submit Comment'}
                            </button>
                            {#if error}
                                <p class="flc_error">{@html error}</p>
                            {/if}
                        </div>
                    {/if}
                </div>
            </div>
        </div>
    {/if}
</div>
