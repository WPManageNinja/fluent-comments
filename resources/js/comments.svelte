<script>
    import {rest} from './functions.js';
    import CommentForm from './CommentForm.svelte';
    import CommentBlock from './CommentBlock.svelte';
    import {onMount} from 'svelte';

    export let documentId;
    export let lazyReplace;
    export let el;

    function handleNewComment(event) {
        comments = [event.detail, ...comments];
        commentsCount++;
    }

    function increaseComment() {
        commentsCount++;
    }

    if(lazyReplace) {
        for (let childItem of el.children) {
            childItem.classList.add('flc_temp');
        }
    }

    let comments = [];
    let commentsCount = 0;

    let loading = true;

    onMount(() => {
        rest.get('comments/' + documentId)
            .then(response => {
                if(lazyReplace) {
                    document.querySelectorAll('.flc_temp').forEach(el => el.remove());
                }
                comments = response.comments;
                commentsCount = parseInt(response.count);
            })
            .catch(errors => {
                console.log(errors);
            })
            .finally(() => {
                loading = false;
            });
    });
</script>

<div class="fluent_comments_wrap comments-area">
    {#if commentsCount}
        <h2 class="flc_comments-title">Latest comments ({commentsCount})</h2>
    {:else if loading }
        <h2 class="flc_comments-title">Loading....</h2>
    {:else}
        <h2 class="flc_comments-title">Add your first comment to this post</h2>
    {/if}
    {#if !loading }
    <CommentForm on:created={handleNewComment} documentId="{documentId}"/>
    <ul class="flc_comment-list">
        {#each comments as comment (comment.ID)}
            <CommentBlock on:commentCountChanged={increaseComment} documentId="{documentId}" comment="{comment}"/>
        {/each}
    </ul>
    {/if}
</div>
