import App from './comments.svelte';
import '../sass/app.scss';

const dynamicComments = document.querySelectorAll('.fluent_dynamic_comments');
if (dynamicComments.length) {
    dynamicComments.forEach((item, index) => {
        const elem = dynamicComments[index];
        let postId = elem.dataset.post_id;
        if (postId) {
            elem.innerHTML = '';

            new App({
                target: elem,
                props: {
                    documentId: postId
                }
            });
        }
    });
}

if (window.flc_post_id) {
    const el = document.getElementById('comments');
    if (el) {
        setTimeout(() => {
            new App({
                target: el,
                props: {
                    el: el,
                    documentId: window.flc_post_id,
                    lazyReplace: true
                }
            });
        }, 1500);
    }
}
