document.addEventListener('DOMContentLoaded', () => {
    const commentForm = document.getElementById('flc_comment_form');

    if (!commentForm) {
        return;
    }

    const commentHandler = {
        init(commentForm) {
            this.commentForm = commentForm;
            this.textArea = commentForm.querySelector('.flc_content_textarea');
            this.initTextArea();
            this.registerFormSubmit();
            this.initChildForm();
        },

        toggleLoading(submitBtn) {
            submitBtn.classList.toggle('flc_loading');
            submitBtn.disabled = !submitBtn.disabled;
        },

        maybeGetSecurityToken() {
            if(window._fluent_comment_s_token) {
                return false;
            }

            setTimeout(() => {
                // return if this.commentForm has class flc_tokenizing
                if (this.commentForm.classList.contains('flc_tokenizing')) {
                    return false;
                }
                // add class to this.commentForm
                this.commentForm.classList.add('flc_tokenizing');

                const commentPostId = this.commentForm.querySelector('input[name="comment_post_ID"]').value;

                const request = new XMLHttpRequest();

                request.open('POST', window.fluentCommentPublic.ajaxurl, true);
                request.responseType = 'json';

                var that = this;

                request.onload = function () {
                    if (this.status === 200) {
                        window._fluent_comment_s_token = this.response.token;
                    } else {
                        window._fluent_comment_s_token = null;
                    }
                };

                // convert data to FormData
                const formData = new FormData();
                formData.append('action', 'fluent_comment_comment_token');
                formData.append('comment_post_ID', commentPostId);

                request.send(formData);

            }, 2000);
        },

        initTextArea() {
            if (this.textArea) {
                this.textArea.addEventListener('focus', () => {
                    this.maybeGetSecurityToken();
                    this.commentForm.querySelector(".flc_comment_meta").style.display = "block";
                });

                this.textArea.addEventListener('input', () => {
                    this.resizeTextArea();
                });
            }
        },

        resizeTextArea() {
            let element = this.textArea;
            element.style.height = "76px";

            if (element.scrollHeight < 300) {
                element.style.height = (element.scrollHeight) + "px";
            } else {
                element.style.height = "300px";
            }
        },

        closeForm(form) {
            form.querySelector('.flc_content_textarea').value = '';
            form.querySelector('.flc_content_textarea').style.height = '76px';
            form.querySelector('.flc_comment_meta').style.display = 'hidden';
            document.getElementById('comment_parent').value = 0;
            if(this.parent_comment_id) {
                const fragment = document.createDocumentFragment();
                // Append desired element to the fragment:
                fragment.appendChild(document.getElementById('respond'));
                const refElement = document.getElementById('comments');
                const parent = refElement.parentNode;
                parent.insertBefore(fragment, refElement);
            }
        },

        registerFormSubmit() {
            document.getElementById('flc_comment_form').addEventListener('submit', (event) => {
                event.preventDefault();
                const submitBtn = event.target.querySelector('.flc_button');
                this.toggleLoading(submitBtn);

                event.target.querySelectorAll('.error.text-danger').forEach(e => {
                    e.remove();
                });

                const form = event.target;

                const data = new FormData(event.target);

                data.append('_fluent_comment_s_token', window._fluent_comment_s_token);

                const request = new XMLHttpRequest();

                request.open('POST', window.fluentCommentPublic.ajaxurl, true);
                request.responseType = 'json';

                var that = this;

                request.onload = function () {
                    if (this.status === 200) {
                        that.appendComment(this.response.comment_preview);
                        that.closeForm(form);
                    } else {
                        let genericError = this.response.error;

                        if (!genericError && this.response.message) {
                            genericError = this.response.message;
                        } else if (genericError && this.response.data.status === 403) {
                            genericError = this.response.message;
                        }

                        if (genericError) {
                            let el = document.createElement("div");
                            el.classList.add('error', 'text-danger');
                            el.innerHTML = genericError;

                            form.appendChild(el);
                        } else {
                            for (const property in this.response) {
                                const field = document.getElementById('flt_' + property);
                                if (field) {
                                    let el = document.createElement("div");
                                    el.classList.add('error', 'text-danger');
                                    el.innerHTML = Object.values(this.response[property])[0];
                                    field.parentNode.insertBefore(el, field.nextSibling);
                                    field.parentNode.parentNode.classList.add('is-error');
                                }
                            }
                        }
                    }
                    that.toggleLoading(submitBtn);

                    window._fluent_comment_s_token = null;
                };

                request.send(data);

            });
        },

        appendComment(html, submitFormRef) {
            if(!this.parent_comment_id) {
                document.querySelector('.flc_comment-list').insertAdjacentHTML('afterbegin', html)
            } else {
                document.getElementById('comment-'+this.parent_comment_id).insertAdjacentHTML('beforeend', html);
            }
        },

        initChildForm() {
            var that = this;
            window.initChildComment = function (el) {
                const commentId = el.dataset.comment_id;
                that.parent_comment_id = commentId;
                document.getElementById('comment_parent').value = commentId;
                const fragment = document.createDocumentFragment();

                // Append desired element to the fragment:
                fragment.appendChild(document.getElementById('respond'));
                document.getElementById('comment-'+commentId).appendChild(fragment);
                setTimeout(() => {
                    that.textArea.focus();
                }, 500);
            }
        }
    };

    commentHandler.init(commentForm);
});
