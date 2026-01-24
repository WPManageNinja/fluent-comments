document.addEventListener('DOMContentLoaded', () => {
    const commentForm = document.getElementById('flc_comment_form');
    if (!commentForm) return;

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

        init(form) {
            this.form = form;
            this.textArea = form.querySelector('.flc_content_textarea');
            this.submitBtn = form.querySelector('.flc_button');
            this.metaSection = form.querySelector('.flc_comment_meta');
            this.commentList = document.querySelector('.flc_comment-list');
            this.parentInput = document.getElementById('comment_parent');
            this.postIdInput = form.querySelector('input[name="comment_post_ID"]');

            this.bindEvents();
            this.exposeChildCommentHandler();
        },

        request(data, onSuccess, onError) {
            const formData = data instanceof FormData ? data : this.objectToFormData(data);

            const xhr = new XMLHttpRequest();
            xhr.open('POST', window.fluentCommentPublic.ajaxurl, true);
            xhr.responseType = 'json';

            xhr.onload = () => {
                if (xhr.status === 200) {
                    onSuccess?.(xhr.response);
                } else {
                    onError?.(xhr.response);
                }
            };

            xhr.onerror = () => onError?.(null);

            xhr.send(formData);
        },

        objectToFormData(obj) {
            const formData = new FormData();
            for (const key in obj) {
                formData.append(key, obj[key]);
            }
            return formData;
        },

        bindEvents() {
            if (this.textArea) {
                this.textArea.addEventListener('focus', this.handleTextAreaFocus.bind(this));
                this.textArea.addEventListener('input', this.handleTextAreaInput.bind(this));
            }
            this.form.addEventListener('submit', this.handleSubmit.bind(this));
        },

        handleTextAreaFocus() {
            this.maybeGetSecurityToken();
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

        maybeGetSecurityToken() {
            if (window._fluent_comment_s_token || this.form.classList.contains('flc_tokenizing')) {
                return;
            }

            setTimeout(() => {
                if (this.form.classList.contains('flc_tokenizing')) return;

                this.form.classList.add('flc_tokenizing');

                this.request(
                    {
                        action: 'fluent_comment_comment_token',
                        comment_post_ID: this.postIdInput.value,
                        comment_time: Date.now()
                    },
                    (response) => {
                        window._fluent_comment_s_token = response.token;
                        this.form.classList.remove('flc_tokenizing');
                    },
                    () => {
                        window._fluent_comment_s_token = null;
                        this.form.classList.remove('flc_tokenizing');
                    }
                );
            }, 2000);
        },

        handleSubmit(event) {
            event.preventDefault();

            this.toggleLoading(true);
            this.clearErrors();

            const formData = new FormData(this.form);
            formData.append('_fluent_comment_s_token', window._fluent_comment_s_token || '');
            formData.append('comment_time', Date.now());

            this.request(
                formData,
                (response) => {
                    this.appendComment(response.comment_preview);
                    this.resetForm();
                    this.toggleLoading(false);
                    window._fluent_comment_s_token = null;
                    this.maybeGetSecurityToken();
                },
                (response) => {
                    if (response) {
                        this.handleError(response);
                    } else {
                        this.showError('Network error. Please try again.');
                    }
                    this.toggleLoading(false);
                }
            );
        },

        toggleLoading(loading) {
            this.submitBtn.classList.toggle('flc_loading', loading);
            this.submitBtn.disabled = loading;
        },

        clearErrors() {
            this.form.querySelectorAll('.error.text-danger').forEach(el => el.remove());
            this.form.querySelectorAll('.is-error').forEach(el => el.classList.remove('is-error'));
        },

        handleError(response) {
            const message = response?.message || response?.error;

            if (message || response?.data?.status === 403) {
                this.showError(message || 'An error occurred.');
                return;
            }

            for (const field in response) {
                const input = document.getElementById('flt_' + field);
                if (input) {
                    const errorEl = document.createElement('div');
                    errorEl.className = 'error text-danger';
                    errorEl.innerHTML = Object.values(response[field])[0];
                    input.parentNode.insertBefore(errorEl, input.nextSibling);
                    input.parentNode.parentNode.classList.add('is-error');
                }
            }
        },

        showError(message) {
            const errorEl = document.createElement('div');
            errorEl.className = 'error text-danger';
            errorEl.innerHTML = message;
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

        exposeChildCommentHandler() {
            window.initChildComment = (el) => {
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
            };
        }
    };

    CommentHandler.init(commentForm);
});
