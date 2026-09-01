import { mount } from 'svelte';
import App from './comments.svelte';
import { seedRenderedLogin } from './session';
import '../sass/app.scss';

const isTrue = (value) => value !== '0' && value !== 'false' && value !== undefined;

/**
 * The first page of comments is rendered into the document by PHP so that
 * a cached page needs no request at all to show its comments.
 */
const readBootstrap = (id) => {
    if (!id) {
        return null;
    }

    const node = document.getElementById(id);

    if (!node) {
        return null;
    }

    try {
        return JSON.parse(node.textContent);
    } catch (e) {
        return null;
    }
};

document.querySelectorAll('.fluent_dynamic_comments').forEach((elem) => {
    const postId = elem.dataset.post_id;

    if (!postId) {
        return;
    }

    const props = {
        documentId: postId,
        showAvatars: isTrue(elem.dataset.show_avatars),
        showTitle: isTrue(elem.dataset.show_title),
        titleWithComments: elem.dataset.title_with_comments || '',
        titleNoComments: elem.dataset.title_no_comments || '',
        bootstrap: readBootstrap(elem.dataset.bootstrap)
    };

    // Before mount, so the first thing drawn is already the right branch on
    // a site that requires login - and so a reply form created later opens
    // with it too. See Frontend::renderApp(); the session corrects it.
    if (props.bootstrap) {
        seedRenderedLogin(postId, {
            mustLogIn: !!props.bootstrap.must_log_in,
            message: props.bootstrap.login_message || ''
        });
    }

    // PHP rendered the first page into this container as real markup, so
    // that anything not running a script - crawlers, link previews - sees
    // the comments rather than a spinner. In a browser it is scaffolding:
    // clear it and let Svelte own the DOM from here. Frontend::renderCommentList()
    // matches CommentBlock.svelte element for element so the swap does not
    // reflow the page.
    elem.innerHTML = '';
    mount(App, { target: elem, props });
});
