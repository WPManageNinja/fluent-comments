<script>
    import {onMount, createEventDispatcher} from 'svelte';
    import {rest} from './functions';

    export let documentId;
    export let threadId;
    export let willScroll;

    let formId = 'comment_form_' + documentId + '_' + (threadId || 0);

    let isOpen = false;
    let isSubmitting = false;

    const dispatch = createEventDispatcher();

    let isLoggedIn = !!window.fluentCommentVars.me;

    let login_message = window.fluentCommentVars.login_message;

    let userAvatar = window.fluentCommentVars.user_avatar;
    let error = '';

    onMount(() => {
        if (!willScroll) {
            return;
        }

        const el = document.getElementById(formId);
        if (!el) return;
        el.scrollIntoView({
            behavior: 'smooth',
            block: 'center'
        });
        setTimeout(() => {
            document.querySelector('#' + formId + ' textarea').focus({preventScroll: true});
        }, 1000);
    });

    function handleOpen(even) {
        isOpen = true;
    }

    function resizeTextArea(event) {
        let element = event.target;
        element.style.height = "5px";

        if (element.scrollHeight < 300) {
            element.style.height = (element.scrollHeight) + "px";
        } else {
            element.style.height = "300px";
        }
    }

    const form = {
        content: '',
        name: '',
        email: ''
    }

    function handleSubmit(event) {
        event.preventDefault();

        if (!form.content) {
            alert('Please provide comment content first');
            return;
        }

        if (threadId) {
            form.parent_id = threadId;
        }

        isSubmitting = true;
        error = '';
        rest.post('comments/' + documentId, form)
            .then(response => {
                dispatch('created', response.formatted_comment);
                form.content = '';
                isOpen = false;
            })
            .catch(errors => {
                error = errors.response.message;
            })
            .finally(() => {
                isSubmitting = false;
            });
    }
</script>
<div id="{formId}" class="fluent_comments_form">
    {#if login_message && !isLoggedIn}
        <div class="flc_login_message">
            <p>{@html login_message}</p>
        </div>
    { :else }
        <div class="flc_respond">
            <div class="flc_comment_wrap">
                <div class="flc_author_placeholder">
                    <div class="flc_comment_author">
                        <img alt="" src="{userAvatar}"/>
                    </div>
                </div>
                <div class="flc_comment_form">
                    <div class="flc_form_field flc_textarea">
                        <div class="flc_comment">
                        <textarea class="flc_content_textarea {isOpen ? 'flc_text_active' : ''}"
                                  bind:value={form.content}
                                  on:input={resizeTextArea} on:focus={handleOpen} name="comment"
                                  title="Enter your comment here..."
                                  placeholder="Enter your comment here..."></textarea>
                        </div>
                    </div>
                    {#if isOpen}
                        {#if !isLoggedIn}
                            <div class="flc_row flc_person_form_fields">
                                <div class="flc_form_field">
                                    <label for="{formId}_name">Full Name</label>
                                    <input placeholder="Your Name" id="{formId}_name" bind:value={form.name} type="text"
                                           class="flc_input_text"/>
                                </div>
                                <div class="flc_form_field">
                                    <label for="{formId}_email">Email Address</label>
                                    <input placeholder="Your Email Address" id="{formId}_email" bind:value={form.email}
                                           type="email" class="flc_input_text"/>
                                </div>
                            </div>
                        {/if}
                        <div class="flc_submit">
                            <button class="flc_button" disabled="{isSubmitting}" on:click={handleSubmit}>
                                {#if isSubmitting}
                                    Submitting
                                {:else}
                                    Submit Comment
                                {/if}
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
