import { ajax } from './ajax';
import { getSession, invalidateSession, readHashedCookie } from './session';

document.addEventListener('DOMContentLoaded', () => {
    const commentForm = document.getElementById('flc_comment_form');
    if (!commentForm) return;

    const config = window.fluentCommentPublic || {};
    const strings = config.i18n || {};

    const TOKEN_FIELD = '_flc_token';
    const MIN_TOKEN_AGE = (config.min_age || 2) * 1000;

    const CommentHandler = {
        form: null,
        textArea: null,
        submitBtn: null,
        metaSection: null,
        commentList: null,
        parentInput: null,
        postIdInput: null,
        parentCommentId: null,
        resizeTimeout: null,
        token: null,
        tokenIssuedAt: 0,
        sessionRequest: null,

        init(form) {
            this.form = form;
            this.textArea = form.querySelector('.flc_content_textarea');
            this.submitBtn = form.querySelector('.flc_button');
            this.metaSection = form.querySelector('.flc_comment_meta');
            this.commentList = document.querySelector('.flc_comment-list');
            this.parentInput = document.getElementById('comment_parent');
            this.postIdInput = form.querySelector('input[name="comment_post_ID"]');

            this.prefillAuthor();
            this.bindEvents();
            this.exposeChildCommentHandler();
        },

        /**
         * The saved name and email are not printed into the markup, because
         * the markup is cached and shared. Fill them from the visitor's own
         * cookies here instead.
         */
        prefillAuthor() {
            this.form.querySelectorAll('[data-flc_prefill]').forEach((input) => {
                const value = readHashedCookie(input.dataset.flc_prefill);
                if (value) {
                    input.value = value;
                }
            });
        },

        bindEvents() {
            if (this.textArea) {
                this.textArea.addEventListener('focus', this.handleTextAreaFocus.bind(this));
                this.textArea.addEventListener('input', this.handleTextAreaInput.bind(this));
            }

            this.form.addEventListener('submit', this.handleSubmit.bind(this));

            // Reply links are rendered by the walker, so listen on the document
            // rather than wiring an inline handler onto every comment.
            document.addEventListener('click', (event) => {
                const link = event.target.closest?.('.fls_child_comment_reply');
                if (!link) return;

                event.preventDefault();
                this.startChildComment(link);
            });
        },

        handleTextAreaFocus() {
            this.loadSession();
            if (this.metaSection) {
                this.metaSection.style.display = 'block';
            }
        },

        handleTextAreaInput() {
            if (this.resizeTimeout) {
                cancelAnimationFrame(this.resizeTimeout);
            }
            this.resizeTimeout = requestAnimationFrame(() => this.resizeTextArea());
        },

        resizeTextArea() {
            const el = this.textArea;
            el.style.height = '76px';
            el.style.height = Math.min(el.scrollHeight, 300) + 'px';
        },

        /**
         * The token comes from its own request so a blind POST never has
         * one, and it is bound to an HttpOnly cookie set by that same
         * response, so a cross site post can not produce a matching pair.
         */
        loadSession() {
            if (this.token) {
                return Promise.resolve();
            }

            if (this.sessionRequest) {
                return this.sessionRequest;
            }

            const postId = this.postIdInput.value;

            this.sessionRequest = getSession(postId)
                .then((session) => {
                    this.token = session.token;
                    this.tokenIssuedAt = Date.now();
                    this.renderExtraFields(session.fields_html);
                })
                .catch(() => {
                    this.token = null;
                })
                .finally(() => {
                    this.sessionRequest = null;
                });

            return this.sessionRequest;
        },

        /**
         * Fields contributed through fluent_comments/form_fields. They
         * arrive with the session rather than in the (cached) page, so
         * anything per-request in them is fresh.
         */
        renderExtraFields(html) {
            const slot = this.form.querySelector('.flc_extra_fields');

            if (!slot || !html || slot.dataset.rendered) {
                return;
            }

            slot.innerHTML = html;
            slot.dataset.rendered = '1';

            // Injected after load, so anything binding on DOMContentLoaded
            // has already missed it. This is how it gets told.
            document.dispatchEvent(
                new CustomEvent('fluent-comments:fields-rendered', {
                    detail: { container: slot, form: this.form }
                })
            );
        },

        async handleSubmit(event) {
            event.preventDefault();

            this.toggleLoading(true);
            this.clearErrors();

            const postId = this.postIdInput.value;

            try {
                await this.loadSession();

                const age = Date.now() - this.tokenIssuedAt;
                if (this.token && age < MIN_TOKEN_AGE) {
                    await new Promise((resolve) => setTimeout(resolve, MIN_TOKEN_AGE - age));
                }

                const formData = new FormData(this.form);
                formData.append(TOKEN_FIELD, this.token || '');

                const response = await ajax('fluent_comment_post', formData);

                // Tokens are single use.
                this.token = null;
                invalidateSession(postId);

                this.appendComment(response.comment_preview);
                this.resetForm();
            } catch (response) {
                this.token = null;
                invalidateSession(postId);

                if (response) {
                    this.showError(response.message || strings.generic_error);
                } else {
                    this.showError(strings.network_error);
                }
            } finally {
                this.toggleLoading(false);
            }
        },

        toggleLoading(loading) {
            this.submitBtn.classList.toggle('flc_loading', loading);
            this.submitBtn.disabled = loading;
        },

        clearErrors() {
            this.form.querySelectorAll('.error.text-danger').forEach((el) => el.remove());
            this.form.querySelectorAll('.is-error').forEach((el) => el.classList.remove('is-error'));
        },

        showError(message) {
            const errorEl = document.createElement('div');
            errorEl.className = 'error text-danger';
            errorEl.setAttribute('role', 'alert');
            errorEl.textContent = message || '';
            this.form.appendChild(errorEl);
        },

        appendComment(html) {
            if (!this.parentCommentId) {
                this.commentList.insertAdjacentHTML('afterbegin', html);
            } else {
                const parentComment = document.getElementById('comment-' + this.parentCommentId);
                if (parentComment) {
                    parentComment.insertAdjacentHTML('beforeend', html);
                }
            }
        },

        resetForm() {
            this.textArea.value = '';
            this.textArea.style.height = '76px';

            if (this.metaSection) {
                this.metaSection.style.display = 'none';
            }

            if (this.parentInput) {
                this.parentInput.value = 0;
            }

            if (this.parentCommentId) {
                this.moveFormToOriginalPosition();
                this.parentCommentId = null;
            }
        },

        moveFormToOriginalPosition() {
            const respond = document.getElementById('respond');
            const comments = document.getElementById('comments');
            if (respond && comments?.parentNode) {
                comments.parentNode.insertBefore(respond, comments);
            }
        },

        startChildComment(el) {
            const commentId = el.dataset.comment_id;
            this.parentCommentId = commentId;

            if (this.parentInput) {
                this.parentInput.value = commentId;
            }

            const respond = document.getElementById('respond');
            const targetComment = document.getElementById('comment-' + commentId);

            if (respond && targetComment) {
                targetComment.appendChild(respond);
                setTimeout(() => this.textArea.focus(), 100);
            }
        },

        // Kept so pages cached with the 2.0 markup keep working.
        exposeChildCommentHandler() {
            window.initChildComment = (el) => this.startChildComment(el);
        }
    };

    CommentHandler.init(commentForm);
});
