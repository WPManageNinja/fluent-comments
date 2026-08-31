<?php

namespace FluentComments\App\Services;

/**
 * Single home for the public facing app: asset loading, the JS config
 * payload and the markup the Svelte app mounts into.
 *
 * The block, the shortcode and the block theme takeover all render through
 * here so the three entry points can not drift apart.
 *
 * Everything this class puts into the page is written on the assumption
 * that the page will be served from a full page cache to somebody else.
 * Nothing that varies by visitor goes into the HTML: no nonce, no user
 * identity, no cookie derived values. Those come from getSessionPayload()
 * over admin-ajax, which is never cached.
 */
class Frontend
{
    /**
     * Script handles that Vite builds as ES modules.
     *
     * @var string[]
     */
    const MODULE_HANDLES = [
        'fluent_comments',
        'fluent_comments_native',
        'fluent_comments_admin',
    ];

    /**
     * Vite emits the bundles as ES modules, so the script tags have to say
     * so. Loaded as classic scripts the browser fails to parse them and the
     * app silently never boots.
     *
     * @return void
     */
    public static function register()
    {
        add_filter('script_loader_tag', [self::class, 'addModuleType'], 10, 2);
    }

    /**
     * @param string $tag
     * @param string $handle
     * @return string
     */
    public static function addModuleType($tag, $handle)
    {
        if (!in_array($handle, self::MODULE_HANDLES, true)) {
            return $tag;
        }

        if (strpos($tag, ' type=') !== false) {
            return preg_replace('/ type=([\'"])[^\'"]*\1/', ' type="module"', $tag, 1);
        }

        return str_replace('<script ', '<script type="module" ', $tag);
    }

    /**
     * Render the mount point for the comments app.
     *
     * @param int $postId
     * @param array $attributes Block attributes, when rendered as a block.
     * @return string
     */
    public static function renderApp($postId, $attributes = [])
    {
        $postId = (int)$postId;

        if (!$postId) {
            return '';
        }

        self::enqueueAssets();

        $attributes = wp_parse_args($attributes, [
            'showTitle'           => true,
            'showAvatars'         => true,
            'titleWithComments'   => '',
            'titleNoComments'     => '',
            'align'               => '',
            'primaryColor'        => '',
            'textColor'           => '',
            'cardBackgroundColor' => '',
        ]);

        $wrapperClass = 'fluent-comments-block';
        if (!empty($attributes['align'])) {
            $wrapperClass .= ' align' . sanitize_html_class($attributes['align']);
        }

        $cssVars = self::buildCssVariables($attributes);
        $strings = self::getStrings();

        $titleWithComments = $attributes['titleWithComments'] ? $attributes['titleWithComments'] : $strings['title_with_comments'];
        $titleNoComments = $attributes['titleNoComments'] ? $attributes['titleNoComments'] : $strings['title_no_comments'];

        // The first page of comments is rendered into the document rather
        // than fetched. admin-ajax is never page cached, so fetching it on
        // mount would turn every view of every post into an uncached
        // WordPress boot. Served from cache these comments are exactly as
        // fresh as the rest of the page around them.
        $bootstrap = CommentsRepository::getPayload($postId, 1);

        $commentCount = (int)$bootstrap['count'];
        $placeholderTitle = $commentCount
            ? str_replace('{count}', number_format_i18n($commentCount), $titleWithComments)
            : $titleNoComments;

        $bootstrapId = 'flc_bootstrap_' . $postId;

        ob_start();
        ?>
        <div class="<?php echo esc_attr($wrapperClass); ?>"<?php echo $cssVars ? ' style="' . esc_attr($cssVars) . '"' : ''; ?>>
            <script type="application/json" id="<?php echo esc_attr($bootstrapId); ?>">
                <?php
                // JSON_HEX_TAG keeps a "</script>" inside a comment from
                // closing this element.
                echo wp_json_encode($bootstrap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                ?>
            </script>
            <div
                class="fluent_dynamic_comments"
                data-post_id="<?php echo esc_attr($postId); ?>"
                data-bootstrap="<?php echo esc_attr($bootstrapId); ?>"
                data-show_avatars="<?php echo empty($attributes['showAvatars']) ? '0' : '1'; ?>"
                data-show_title="<?php echo empty($attributes['showTitle']) ? '0' : '1'; ?>"
                data-title_with_comments="<?php echo esc_attr($titleWithComments); ?>"
                data-title_no_comments="<?php echo esc_attr($titleNoComments); ?>"
            >
                <?php
                // Real markup, not a spinner. app.js empties this container
                // before mounting, so a browser ends up with the Svelte
                // version either way - but a crawler that never runs the
                // script still gets the comments.
                //
                // The wrapper and the heading are here rather than outside
                // because that is where comments.svelte puts them. Matching
                // its structure is what keeps the swap from moving the page
                // around under the reader.
                ?>
                <div class="fluent_comments_wrap comments-area">
                    <?php if (!empty($attributes['showTitle'])) : ?>
                        <h2 class="flc_comments-title"><?php echo esc_html($placeholderTitle); ?></h2>
                    <?php endif; ?>
                    <?php
                    echo self::renderCommentList( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per field in the renderer.
                        $bootstrap['comments'],
                        (int)$bootstrap['max_depth'],
                        !empty($bootstrap['comments_open']),
                        !empty($attributes['showAvatars'])
                    );
                    ?>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * The first page of comments, as HTML, for the document.
     *
     * The payload has always been in the page - CommentsRepository fills a
     * <script type="application/json"> block so that mounting costs no
     * uncached request. But a script block is data, not content: to anything
     * that does not run JavaScript the post read "Loading...", and the
     * comments were invisible. Google renders JS eventually; the crawlers
     * behind AI answers and link previews largely do not, and comments are
     * exactly the long tail text worth having indexed.
     *
     * So the same payload is also rendered here, and app.js empties the
     * container before mounting over it. The classic template never had this
     * problem - wp_list_comments() writes real markup - so this is the block
     * and shortcode path catching up with it.
     *
     * The markup deliberately matches CommentBlock.svelte element for
     * element. Anything else and the page visibly reflows the moment the
     * script runs. Both are driven by the one array CommentsRepository
     * returns, so there is one source of truth for the data even though
     * there are two renderers for it.
     *
     * @param array $comments Formatted comments from CommentsRepository.
     * @param int $maxDepth
     * @param bool $commentsOpen
     * @param bool $showAvatars
     * @param int $depth
     * @return string
     */
    public static function renderCommentList($comments, $maxDepth, $commentsOpen, $showAvatars, $depth = 1)
    {
        if (!$comments) {
            return '';
        }

        $strings = self::getStrings();
        $classes = $depth > 1 ? 'flc_comment-list flc_child_comments' : 'flc_comment-list';

        $out = '<ul class="' . esc_attr($classes) . '">';

        foreach ($comments as $comment) {
            $id = (int)$comment['ID'];
            $commentDepth = isset($comment['depth']) ? (int)$comment['depth'] : $depth;
            $canReply = $commentsOpen && $commentDepth < $maxDepth;

            $out .= '<li class="flc_comment" id="comment_' . $id . '">';
            $out .= '<article class="flc_body">';

            if ($showAvatars) {
                $out .= '<div class="flc_avatar"><div class="flc_comment_author">'
                    . '<img alt="" src="' . esc_url($comment['avatar']) . '" loading="lazy" decoding="async" />'
                    . '</div></div>';
            }

            $out .= '<div class="flc_comment__details"><div class="crayons-card">';
            $out .= '<div class="comment__header">';
            $out .= '<b class="fn"><a href="#comment_' . $id . '" class="url">' . esc_html($comment['author']) . '</a></b>';
            $out .= '<span class="flc_dot" role="presentation">&bull;</span>';
            $out .= '<time datetime="' . esc_attr($comment['date']) . '">' . esc_html($comment['human_date']) . '</time>';
            $out .= '</div>';

            // Already through the comment_text filters, which is core's own
            // kses pass - the same content the JSON block carries.
            $out .= '<div class="flc_comment-content">' . wp_kses_post($comment['content']);

            if (!empty($comment['unapproved'])) {
                $out .= '<p class="comment-awaiting-moderation">' . esc_html($strings['awaiting_moderation']) . '</p>';
            }

            $out .= '</div></div>';

            if ($canReply) {
                $out .= '<div class="comment_footer"><a href="#reply">'
                    . '<svg xmlns="http://www.w3.org/2000/svg" width="24" fill="currentColor" height="24" role="img"'
                    . ' aria-labelledby="reply-icon-' . $id . '" class="crayons-icon reaction-icon not-reacted">'
                    . '<title id="reply-icon-' . $id . '">' . esc_html($strings['reply']) . '</title>'
                    . '<path d="M10.5 5h3a6 6 0 110 12v2.625c-3.75-1.5-9-3.75-9-8.625a6 6 0 016-6zM12 15.5h1.5a4.501'
                    . ' 4.501 0 001.722-8.657A4.5 4.5 0 0013.5 6.5h-3A4.5 4.5 0 006 11c0 2.707 1.846 4.475 6 6.36V15.5z">'
                    . '</path></svg>'
                    . '<span class="reply_text">' . esc_html($strings['reply']) . '</span>'
                    . '</a></div>';
            }

            $out .= '</div></article>';

            if (!empty($comment['children'])) {
                $out .= self::renderCommentList(
                    $comment['children'],
                    $maxDepth,
                    $commentsOpen,
                    $showAvatars,
                    $commentDepth + 1
                );
            }

            $out .= '</li>';
        }

        return $out . '</ul>';
    }

    /**
     * Register and localize the public assets. Safe to call repeatedly.
     *
     * @return void
     */
    public static function enqueueAssets()
    {
        static $loaded = false;

        if ($loaded) {
            return;
        }

        $loaded = true;

        wp_enqueue_style(
            'fluent_comments',
            FLUENT_COMMENTS_PLUGIN_URL . 'dist/css/app.css',
            [],
            FLUENT_COMMENTS_VERSION
        );

        wp_enqueue_script(
            'fluent_comments',
            FLUENT_COMMENTS_PLUGIN_URL . 'dist/js/app.js',
            [],
            FLUENT_COMMENTS_VERSION,
            true
        );

        wp_localize_script('fluent_comments', 'fluentCommentVars', self::getVars());
    }

    /**
     * The config payload handed to the Svelte app.
     *
     * Cache safe by construction: every value here is either a site setting
     * or a translation. See getSessionPayload() for the rest.
     *
     * @return array
     */
    public static function getVars()
    {
        $vars = [
            'slug'          => 'fluent-comments',
            'ajax_url'      => esc_url_raw(admin_url('admin-ajax.php')),
            'default_avatar' => get_avatar_url('', ['default' => get_option('avatar_default', 'mystery')]),
            'show_avatars'   => (bool)get_option('show_avatars'),
            'max_depth'      => Helper::getMaxDepth(),
            'per_page'       => Helper::getPerPage(),
            'token_min_age'  => SpamGuard::getMinAge(),
            'honeypot'       => SpamGuard::getHoneypotField(),
            // Whether login is required at all is a site setting. Whether
            // this particular visitor is logged in is not, and arrives with
            // the session payload.
            'require_login'  => (bool)get_option('comment_registration'),
            'i18n'           => self::getStrings(),
        ];

        return apply_filters('fluent_comments/frontend_vars', $vars);
    }

    /**
     * Everything about this visitor that must never touch a cached page.
     *
     * There is no nonce here, and none on any public request. Every one of
     * them goes to admin-ajax, which authenticates by cookie alone, so
     * nothing needs one. CSRF is covered by the submission token and the
     * HttpOnly SameSite=Lax cookie it is bound to. (The admin settings
     * screen is a different matter and does use nonces.)
     *
     * @param int $postId
     * @return array
     */
    public static function getSessionPayload($postId)
    {
        $postId = (int)$postId;

        $payload = [
            'token'      => SpamGuard::issueToken($postId),
            'honeypot'   => SpamGuard::getHoneypotField(),
            'me'         => null,
            // Extra fields travel with the session rather than in the page,
            // so anything per-request in them is fresh even on a page that
            // was served from cache.
            'fields_html' => self::renderFormFields($postId),
        ];

        if (get_current_user_id()) {
            $currentUser = wp_get_current_user();
            $name = trim($currentUser->first_name . ' ' . $currentUser->last_name);

            if (!$name) {
                $name = $currentUser->display_name;
            }

            $payload['me'] = [
                'id'        => $currentUser->ID,
                'full_name' => $name,
                'avatar'    => get_avatar_url($currentUser->ID),
            ];
        } elseif (get_option('comment_registration')) {
            $payload['login_message'] = sprintf(
            /* translators: %1$s: opening link tag, %2$s: closing link tag. */
                __('You must be %1$slogged in%2$s to post a comment.', 'fluent-comments'),
                '<a class="flc_login_link" href="' . esc_url(wp_login_url(get_permalink($postId))) . '">',
                '</a>'
            );
        }

        return apply_filters('fluent_comments/session_payload', $payload, $postId);
    }

    /**
     * Markup for any extra fields an extender wants on the comment form.
     *
     * This is the one place to add a field, and it reaches both front
     * ends: the classic template prints it inline, the Svelte form gets it
     * with the session payload and injects it.
     *
     *     add_action('fluent_comments/form_fields', function ($post) {
     *         echo '<div class="my-captcha" data-sitekey="..."></div>';
     *     });
     *
     * Whatever is rendered here must initialise itself: on the Svelte form
     * it is injected after the page has loaded, so anything that runs on
     * DOMContentLoaded will have already missed it. Listen for the
     * 'fluent-comments:fields-rendered' event on document instead. Every
     * input inside is submitted, and read back from the payload passed to
     * 'fluent_comments/validate_submission'.
     *
     * Core's own comment_form hooks are deliberately not fired: their
     * validators are stripped at submission time (see CommentSubmission),
     * so firing them would render fields that are never checked. A site
     * that knows what it is doing can turn them back on.
     *
     * @param int $postId
     * @return string
     */
    public static function renderFormFields($postId)
    {
        $post = get_post($postId);

        if (!$post) {
            return '';
        }

        ob_start();

        do_action('fluent_comments/form_fields', $post);

        if (apply_filters('fluent_comments/render_core_form_hooks', false, $post)) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hooks.
            do_action('comment_form_after_fields');
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hooks.
            do_action('comment_form', $post->ID);
        }

        return ob_get_clean();
    }

    /**
     * Every string the JS app can render.
     *
     * @return array
     */
    public static function getStrings()
    {
        return [
            'title_with_comments'  => __('Latest comments ({count})', 'fluent-comments'),
            'title_no_comments'    => __('Add your first comment to this post', 'fluent-comments'),
            'loading'              => __('Loading…', 'fluent-comments'),
            'comment_placeholder'  => __('Write your comment here…', 'fluent-comments'),
            'name_placeholder'     => __('Your Name', 'fluent-comments'),
            'email_placeholder'    => __('Your Email Address', 'fluent-comments'),
            'submit'               => __('Submit Comment', 'fluent-comments'),
            'submitting'           => __('Submitting', 'fluent-comments'),
            'reply'                => __('Reply', 'fluent-comments'),
            'load_more'            => __('Load more comments', 'fluent-comments'),
            'comments_closed'      => __('Comments are closed.', 'fluent-comments'),
            'awaiting_moderation'  => __('Your comment is awaiting moderation.', 'fluent-comments'),
            'content_required'     => __('Please write your comment first.', 'fluent-comments'),
            // The server decides this, in CommentSubmission::validateIdentity().
            // These two only save the visitor a round trip.
            'identity_required'    => __('Please enter your name and email address.', 'fluent-comments'),
            'email_invalid'        => __('Please enter a valid email address.', 'fluent-comments'),
            'generic_error'        => __('Something went wrong. Please try again.', 'fluent-comments'),
            'honeypot_label'       => __('Leave this field empty', 'fluent-comments'),
            'login_required'       => __('You must be logged in to post a comment.', 'fluent-comments'),
        ];
    }

    /**
     * Turn block colour attributes into scoped CSS custom properties.
     *
     * @param array $attributes
     * @return string
     */
    public static function buildCssVariables($attributes)
    {
        $map = [
            'primaryColor'        => '--fcom-text-link',
            'textColor'           => '--fcom-primary-text',
            'cardBackgroundColor' => '--fcom-secondary-content-bg',
        ];

        $vars = [];

        foreach ($map as $attribute => $property) {
            if (empty($attributes[$attribute])) {
                continue;
            }

            $value = self::sanitizeCssValue($attributes[$attribute]);

            if ($value !== '') {
                $vars[] = $property . ': ' . $value;
            }
        }

        if (isset($attributes['borderRadius'])) {
            $vars[] = '--fcom-radius: ' . intval($attributes['borderRadius']) . 'px';
        }

        return implode('; ', $vars);
    }

    /**
     * Colours come from the block editor, but attributes can be forged in
     * post content, so only keep characters a colour value can contain.
     *
     * @param string $value
     * @return string
     */
    private static function sanitizeCssValue($value)
    {
        if (!is_scalar($value)) {
            return '';
        }

        return trim(preg_replace('/[^a-zA-Z0-9#(),.%\s\-]/', '', (string)$value));
    }
}
