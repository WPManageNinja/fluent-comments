import { mount } from 'svelte';
import App from './comments.svelte';
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

    elem.innerHTML = '';
    mount(App, { target: elem, props });
});
