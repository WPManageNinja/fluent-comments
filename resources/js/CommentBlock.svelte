<script>
    import CommentBlock from './CommentBlock.svelte';
    import CommentForm from './CommentForm.svelte';
    import {createEventDispatcher} from 'svelte';

    export let comment;
    export let documentId;
    export let hideReply;

    const dispatch = createEventDispatcher();

    let showingForm = false;
    function showCommentForm(event) {
        event.preventDefault();
        showingForm = !showingForm;
    }

    function handleNewComment(event) {
        comment.children = [...comment.children, event.detail];
        showingForm = false;
        dispatch('commentCountChanged', 1);
    }

</script>
<li class="flc_comment" id="comment_{comment.ID}">
    <article class="flc_body">
        <div class="flc_avatar">
            <div class="flc_comment_author">
                <img alt="" src="{comment.avatar}" loading="lazy" decoding="async"/>
            </div>
        </div>
        <div class="flc_comment__details">
            <div class="crayons-card">
                <div class="comment__header">
                    <b class="fn"><a href="#comment_{comment.ID}" class="url">{comment.author}</a></b>
                    <span class="color-base-30 px-2 m:pl-0" role="presentation">•</span>
                    <time datetime="{comment.date}">{comment.human_date}</time>
                </div>
                <div class="comment-content">
                    {@html comment.content}
                </div>
            </div>
            <div class="comment_footer">
                {#if !hideReply}
                <a on:click={showCommentForm} href="/">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" role="img" aria-labelledby="aofsvzulrjn0pike8w11tb2s19ofq3x0" class="crayons-icon reaction-icon not-reacted"><title id="aofsvzulrjn0pike8w11tb2s19ofq3x0">Comment button</title><path d="M10.5 5h3a6 6 0 110 12v2.625c-3.75-1.5-9-3.75-9-8.625a6 6 0 016-6zM12 15.5h1.5a4.501 4.501 0 001.722-8.657A4.5 4.5 0 0013.5 6.5h-3A4.5 4.5 0 006 11c0 2.707 1.846 4.475 6 6.36V15.5z"></path></svg>
                    <span class="reply_text">Reply</span>
                </a>
                {/if}
            </div>
        </div>
    </article>
    {#if comment.children && comment.children.length}
        <ul class="flc_comment-list flc_child_comments">
            {#each comment.children as childComment (childComment.ID)}
                <CommentBlock hideReply="{true}" documentId="{documentId}" comment="{childComment}" />
            {/each}
        </ul>
    {/if}

    {#if showingForm}
        <div class="flc_child_form">
            <CommentForm willScroll="{true}" threadId="{comment.ID}" on:created={handleNewComment} documentId="{documentId}" />
        </div>
    {/if}
</li>
