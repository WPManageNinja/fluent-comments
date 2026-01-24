<script>
    import { untrack } from 'svelte';
    import { rest } from './functions.js';
    import CommentForm from './CommentForm.svelte';
    import CommentBlock from './CommentBlock.svelte';

    let { documentId, lazyReplace, el } = $props();

    let comments = $state([]);
    let commentsCount = $state(0);
    let loading = $state(true);

    function handleNewComment(newComment) {
        comments = [newComment, ...comments];
        commentsCount++;
    }

    function increaseComment() {
        commentsCount++;
    }

    // Initialize: mark existing children for removal after load
    untrack(() => {
        if (lazyReplace && el) {
            for (const child of el.children) {
                child.classList.add('flc_temp');
            }
        }
    });

    // Fetch comments once on mount
    $effect(() => {
        untrack(() => {
            rest.get('comments/' + documentId)
                .then(response => {
                    if (lazyReplace) {
                        document.querySelectorAll('.flc_temp').forEach(el => el.remove());
                    }
                    comments = response.comments;
                    commentsCount = parseInt(response.count, 10);
                })
                .finally(() => {
                    loading = false;
                });
        });
    });
</script>

<div class="fluent_comments_wrap comments-area">
    {#if commentsCount}
        <h2 class="flc_comments-title">Latest comments ({commentsCount})</h2>
    {:else if loading}
        <h2 class="flc_comments-title">Loading....</h2>
    {:else}
        <h2 class="flc_comments-title">Add your first comment to this post</h2>
    {/if}
    {#if !loading}
        <CommentForm oncreated={handleNewComment} {documentId} />
        <ul class="flc_comment-list">
            {#each comments as comment (comment.ID)}
                <CommentBlock oncommentcountchanged={increaseComment} {documentId} {comment} />
            {/each}
        </ul>
    {/if}
</div>
