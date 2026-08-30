<script>
    import { untrack } from 'svelte';
    import { ajax, errorMessage } from './ajax.js';
    import CommentForm from './CommentForm.svelte';
    import CommentBlock from './CommentBlock.svelte';

    let {
        documentId,
        showAvatars = true,
        showTitle = true,
        titleWithComments = '',
        titleNoComments = '',
        bootstrap = null
    } = $props();

    const vars = window.fluentCommentVars;
    const i18n = vars.i18n;

    // $derived rather than a const: showAvatars is a prop, and reading one
    // into plain module state captures its first value and never updates.
    // Fixed at mount() today, but the warning is the compiler pointing at a
    // real trap for whoever makes this component reactive later.
    const avatarsEnabled = $derived(showAvatars && vars.show_avatars !== false);

    let comments = $state([]);
    let commentsCount = $state(0);
    let commentsOpen = $state(false);
    let maxDepth = $state(vars.max_depth || 1);
    // The first page is rendered into the document by PHP, so there is
    // normally nothing to wait for and nothing to fetch. Only a page that
    // arrived without one (an older cached copy, say) falls back to REST.
    let loading = $state(true);
    let loadingMore = $state(false);
    let hasMore = $state(false);
    let page = $state(1);
    let loadError = $state('');

    const title = $derived(
        commentsCount
            ? (titleWithComments || i18n.title_with_comments).replace('{count}', commentsCount)
            : (titleNoComments || i18n.title_no_comments)
    );

    /**
     * The server paginates by offset. Anything prepended since the first
     * page was drawn - this visitor's own comment, or somebody else's -
     * shifts that window, so the next page can repeat a comment we already
     * hold. Two of the same ID in a keyed {#each} corrupts the list rather
     * than throwing, so merge on ID instead of concatenating.
     */
    function mergeComments(existing, incoming) {
        const seen = new Set(existing.map((comment) => comment.ID));

        return [...existing, ...incoming.filter((comment) => !seen.has(comment.ID))];
    }

    function applyResponse(response, append) {
        comments = append ? mergeComments(comments, response.comments) : response.comments;
        commentsCount = parseInt(response.count, 10) || 0;
        commentsOpen = !!response.comments_open;
        hasMore = !!response.has_more;
        maxDepth = response.max_depth || maxDepth;
        page = response.page || 1;
    }

    function loadComments(nextPage = 1, append = false) {
        return ajax(
            'fluent_comment_list',
            { comment_post_ID: documentId, page: nextPage },
            { method: 'GET' }
        )
            .then((response) => {
                applyResponse(response, append);
                loadError = '';
            })
            .catch((error) => {
                loadError = errorMessage(error, i18n.generic_error);
            });
    }

    function loadMore() {
        if (loadingMore) return;

        loadingMore = true;
        loadComments(page + 1, true).finally(() => {
            loadingMore = false;
        });
    }

    function handleNewComment(newComment) {
        comments = [newComment, ...comments];
        commentsCount++;
    }

    function increaseCount() {
        commentsCount++;
    }

    $effect(() => {
        untrack(() => {
            if (bootstrap) {
                applyResponse(bootstrap, false);
                loading = false;
                return;
            }

            loadComments().finally(() => {
                loading = false;
            });
        });
    });
</script>

<div class="fluent_comments_wrap comments-area">
    {#if showTitle}
        <h2 class="flc_comments-title">{loading ? i18n.loading : title}</h2>
    {/if}

    {#if !loading}
        {#if loadError}
            <p class="flc_error">{loadError}</p>
        {/if}

        {#if commentsOpen}
            <CommentForm
                oncreated={handleNewComment}
                {documentId}
                showAvatar={avatarsEnabled}
            />
        {:else}
            <p class="flc_comments_closed">{i18n.comments_closed}</p>
        {/if}

        <ul class="flc_comment-list">
            {#each comments as comment (comment.ID)}
                <CommentBlock
                    oncommentcountchanged={increaseCount}
                    {documentId}
                    {comment}
                    {maxDepth}
                    {commentsOpen}
                    showAvatar={avatarsEnabled}
                />
            {/each}
        </ul>

        {#if hasMore}
            <div class="flc_load_more">
                <button class="flc_button" disabled={loadingMore} onclick={loadMore}>
                    {loadingMore ? i18n.loading : i18n.load_more}
                </button>
            </div>
        {/if}
    {/if}
</div>
