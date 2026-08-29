# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Build Commands

```bash
pnpm install
pnpm run build        # Production build (Vite + wp-scripts)
pnpm run dev          # Development with watch (Vite only)
pnpm run build:main   # Vite build only (Svelte + Vue)
pnpm run build:block  # WordPress block only (@wordpress/scripts)
pnpm run dev:block    # Block development with watch
```

```bash
php tests/SpamGuardTest.php      # token + scoring logic
php tests/HookIsolationTest.php  # foreign comment-hook isolation
```

Both run standalone against a stubbed WordPress surface — no install needed.

No PHPUnit setup. No Composer autoloader — all PHP files are manually `require_once`d in `fluent-comments.php`.

## Architecture

Fluent Comments is a WordPress plugin that replaces native comments with AJAX-driven, spam-protected commenting. It has **three separate frontend layers** built with different frameworks:

### Backend (PHP)

- **Entry point:** `fluent-comments.php` → boots on `plugins_loaded`, loads all PHP files manually
- **Namespace:** `FluentComments\App\*`
- **Hook registration:** `app/Hooks/hooks.php` instantiates four handlers that each call `->register()`
- **AJAX actions registered in `CommentsHandler::register()`
- **Key handlers:**
  - `CommentsHandler.php` — comment template override, asset enqueuing, AJAX endpoints, spam token validation, shortcode
  - `BlockHandler.php` — Gutenberg block registration with server-side render callback
  - `AdminSettingsHandler.php` — admin page under Comments menu, settings AJAX
  - `CommentNotificationHandler.php` — email notifications for approvals, replies, new comments

### One Comment Submission Path

There are two *entry points* but only one path into the database, and keeping it that way is the most important constraint in this codebase:

- **Svelte UI** (block/shortcode/block themes) and the **classic template** both post to `admin-ajax.php?action=fluent_comment_post` → `CommentsHandler::handleAjaxComment()`, with the same field names (`comment`, `author`, `email`, `comment_parent`).

Both normalize their payload and hand it to **`CommentSubmission::handle()`**, which is the only place a comment is created. It calls `wp_handle_comment_submission()` — the same function `wp-comments-post.php` uses.

**Never call `wp_insert_comment()` directly here.** It does not fire `comment_post`, where core's moderation and post-author notification emails hook, and it skips all of core's validation. An earlier version of the REST path did, and silently lost both.

`CommentSubmission` additionally enforces what core does not: that a reply's parent belongs to the same post, and that the thread is within `thread_comments_depth`.

### We Own the Form — Foreign Hooks Are Isolated

Fluent Comments renders its own comment form and **does not fire core's `comment_form` hooks**, so a plugin that would add a CAPTCHA field never gets to render one. Running its validator anyway would reject every comment on the site over a field that was never on the page.

So for the duration of the insert, `CommentSubmission::suppressForeignHooks()` strips `preprocess_comment` and `pre_comment_on_post` down to an allow list (Akismet only, by default — it inspects the comment itself and needs nothing from the form). Core registers *nothing* on either hook, so only third parties are ever affected. The registries are restored in a `finally`.

Two escape hatches, both rarely correct:
- `fluent_comments/allowed_comment_hooks` — keep another plugin's callback (`'My_Plugin::check'`; closures are unnameable and can never be listed)
- `fluent_comments/render_core_form_hooks` — fire `comment_form` / `comment_form_after_fields` again. Only useful together with the filter above, or you get fields that are rendered but never checked.

### Extension API

This is the supported way to extend the form, and it works on **both** front ends:

| Hook | Type | Purpose |
|---|---|---|
| `fluent_comments/form_fields` | action | Render extra fields (`$post`) |
| `fluent_comments/validate_submission` | filter | `true` or `WP_Error` — reject a submission |
| `fluent_comments/comment_data` | filter | Adjust the comment array before it reaches core |
| `fluent_comments/spam_score` | filter | Nudge the score (`>= 30` holds for moderation) |
| `fluent_comments/before_process` / `after_added_comment` | actions | Around the insert |

Fields rendered into the slot ship with the **session payload**, not the page, so anything per-request in them stays fresh on a cached page. They are injected after load, which means **they must initialise themselves** — listen for the `fluent-comments:fields-rendered` event on `document` rather than `DOMContentLoaded`. Every named input inside the slot is submitted and arrives in the array passed to `fluent_comments/validate_submission`.

### Spam Protection (`SpamGuard`)

Layered, and scored rather than pass/fail — a suspicious comment is **held for moderation**, only a conclusive one is rejected:

- **Session cookie** `flc_sid` — HttpOnly, SameSite=Lax, set when a token is issued.
- **Submission token** — `v3|issuedAt|postId|jti|sessionDigest|hmac`, signed with `wp_salt('auth')`. Bound to the cookie above and **single-use** (`jti` burned in a transient on success). The cookie binding is what makes it a CSRF defence; the separate round trip alone would not be.
- **Honeypot** — field name derived from the site salt, so it differs per install.
- **Rate limits** — tight per *session* (10 comments/hr), loose per *IP* (100/hr) as the backstop for cookie-less floods. Keying the tight limit on the session is what stops a shared exit IP (a CDN, an office) from putting the whole site in one bucket.

Scores: unbindable session +40, token younger than `token_min_age` +40; `>= 30` holds. Filters: `fluent_comments/spam_score`, `is_trusted_user`, `token_min_age`, `token_max_age`, `max_comments_per_hour`, `max_comments_per_ip_per_hour`, `max_tokens_per_hour`, `trust_proxy_headers`, `proxy_ip_headers`, `accepts_submission`.

### There Is No REST API, and No Nonce

Everything is admin-ajax. Three actions, all with `nopriv` variants:

| Action | Method | Purpose |
|---|---|---|
| `fluent_comment_session` | POST | Token, extension fields, identity |
| `fluent_comment_post` | POST | Submit a comment |
| `fluent_comment_list` | GET | "Load more" only — page 1 is in the document |

The REST layer (`app/Http/`, `Router.php`) was deleted. Three reasons, in order of weight:

1. **A REST route can only recognise a logged-in visitor if the request carries a `wp_rest` nonce** — and a nonce printed into a full-page-cached document is stale by definition. Worse, `rest_cookie_check_errors` treats a *stale* nonce as a hard **403**, not a downgrade, so a visitor who logged out in another tab mid-compose lost their comment. admin-ajax authenticates by cookie alone. There is now no nonce anywhere in the plugin.
2. **Both entry points share one transport, one request context, and one auth mechanism.** They previously differed in all three, which is the drift that caused the original REST-path bugs.
3. **Tighter CORS.** `send_origin_headers()` echoes an origin only from `get_allowed_http_origins()`; the REST API echoes *any* Origin with `Access-Control-Allow-Credentials: true`.

The cost, accepted knowingly: `admin-ajax.php` defines `WP_ADMIN`, so **`is_admin()` is true** during a submit, and it fires `admin_init`. The classic path always worked this way, so this makes behaviour consistent rather than introducing something new. It does mean `comment_text` filters run in an admin context — watch for third-party filters that gate on `!is_admin()`.

### Caching Is a Hard Constraint

Assume every page is served from a full-page cache to somebody else. This shapes several decisions that otherwise look odd:

- **Nothing visitor-specific goes into HTML.** No user identity, no `wp_get_current_commenter()` values, no token. All of it comes from `Frontend::getSessionPayload()` via `fluent_comment_session`, which is never cached.
- **The session is fetched on intent, not on load.** A visitor who only reads costs the site zero uncached requests.
- **The first page of comments is server-rendered** into a `<script type="application/json">` by `Frontend::renderApp()` and hydrated by `app.js`. Fetching it on mount would turn every view of every post into an uncached WordPress boot.
- The native template's name/email fields are filled by JS from the `comment_author_*` cookies (`readHashedCookie` in `session.js`), never printed server-side.

### Block vs Classic Theme Detection

`Helper::isBlockTheme()` / `Helper::isHandlingComments()` gate behavior:
- **Classic themes:** CommentsHandler enqueues assets and swaps `comments_template`.
- **Block themes:** BlockHandler replaces the core Comments block (when `block_theme_takeover` is on), or the user adds the `[fluent_comments]` shortcode / Fluent Comments block.

### Frontend

- **Svelte 5** (`resources/js/`) — public-facing comments UI
  - `app.js` mounts to `.fluent_dynamic_comments`, hydrating from the `data-bootstrap` JSON block
  - Uses Svelte 5 runes syntax (`$props`, `$state`, `$effect`)
  - `ajax.js` — the single XHR client for all three actions, shared by the Svelte and native bundles
  - `session.js` — memoized session fetch, `invalidateSession()` after each post (tokens are single-use), and `readHashedCookie()`
  - `CommentBlock.svelte` recurses for threaded comments, gated on `maxDepth`

- **Vue 3** (`resources/admin/src/`) — admin settings dashboard
  - Uses Element Plus with auto-import (via `unplugin-vue-components`)
  - AJAX through jQuery to `admin-ajax.php` with action prefix `fluent-comments-admin-`
  - Global config injected as `window.fluentCommentsVars` (note: different from frontend's `fluentCommentVars`)

- **React/JSX Gutenberg block** (`resources/block/`)
  - Built with `@wordpress/scripts` (separate webpack pipeline from Vite)
  - `save: () => null` — fully dynamic/server-rendered block
  - Editor shows placeholder comments for visual preview

### Build System

Two completely separate build pipelines:
- **Vite 6** (`vite.config.js`): Builds Svelte + Vue + native-comments.js → `dist/js/` and `dist/css/`
- **@wordpress/scripts** (webpack): Builds the Gutenberg block → `dist/block/` with WordPress dependency manifest

All compiled output goes to `/dist/`.

## Key Patterns

- **Gating function:** `Helper::isFluentCommentsPostType($postType)` is the central check for whether Fluent Comments is active — reads `post_types` from settings
- **Static guard pattern:** `Frontend::enqueueAssets()` uses `static $loaded` to prevent double-enqueuing
- **Reading comments:** `CommentsRepository::getPayload()` — used by both `fluent_comment_list` and the server-rendered bootstrap, so the two cannot drift
- **Settings:** Stored in `_fluent_comments_settings` WordPress option (defaults: `post_types: ['post']`, `reject_native_comments: 'yes'`)
- **CSS theming:** All public styles use `--fcom-*` CSS custom properties defined on `:root` in `resources/sass/app.scss`; the Gutenberg block overrides these via inline styles per-instance
- **`Arr` helper:** Laravel-derived static utility for dot-notation array access, used throughout for safe settings retrieval
- **AJAX endpoints:** `fluent_comment_session`, `fluent_comment_post`, `fluent_comment_list` — all public (`nopriv`), gated by `SpamGuard` rather than by a nonce
