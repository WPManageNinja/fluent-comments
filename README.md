# FluentComments

AJAX comments for WordPress with layered spam protection and no CAPTCHA.
Replaces the native comment experience on classic themes, block (FSE) themes and
page builders.

Every feature is free. There is no pro version, no upsell and no paid add-on.

[Plugin on WordPress.org](https://wordpress.org/plugins/fluent-comments/) ·
GPLv2 or later · WordPress 6.5+ · PHP 7.4+

## Features

- **AJAX posting and loading**, with threaded replies and a "load more" pager
- **Spam protection without a CAPTCHA** — HMAC-signed single-use submission
  tokens bound to a session cookie, a salt-derived honeypot, a minimum fill
  time, and rate limits keyed per session with a per-IP backstop. Suspicious
  comments are *held for moderation*; only conclusive failures are rejected.
- **Five notification emails on one screen** — three of ours (approval, thread
  replies, post author) plus WordPress's own two, each editable, previewable
  against a real comment, and switchable. A shared template designer handles the
  logo, colours, footer and From/Reply-To.
- **Block theme support** — a Gutenberg block, plus a `[fluent_comments]`
  shortcode for page builders. The settings screen scans your templates and
  tells you where the block is still missing.
- **Built for full-page caches** — no visitor-specific markup and no nonce on
  any public endpoint, so a cached comment form never goes stale.
- **Themeable** through `--fcom-*` CSS custom properties.

## Development

```bash
pnpm install
pnpm run build        # i18n scrape + Vite + the block
pnpm run dev          # watch app.js and admin_app.js
pnpm run dev:block    # watch the Gutenberg block
./build.sh            # release zip into builds/
```

Compiled assets land in `/dist` and are not committed, so build before packaging.
Vite makes one pass over two entries (`app.js`, `admin_app.js`) that share
nothing, so no chunk is emitted. Adding an entry that shares a module with
another brings the chunk back — and WordPress enqueues these as plain script
tags with no `modulepreload`, so that costs an extra serial request on every
page that loads it. Give such an entry its own pass.

Tests are plain PHP scripts against a stubbed WordPress surface — no install, no
PHPUnit:

```bash
php tests/SpamGuardTest.php   # and the seven others in tests/
```

## Architecture

Three bundles over one PHP back end:

| Layer | Stack | Entry point | Output |
| --- | --- | --- | --- |
| Public comments UI | Svelte 5 | `resources/js/app.js` | `dist/js/app.js`, `dist/css/app.css` |
| Admin settings | Vue 3 + Element Plus | `resources/admin/src/app.js` | `dist/js/admin_app.js`, `dist/css/admin_app.css` |
| Gutenberg block | React (wp-scripts) | `resources/block/editor.jsx` | `dist/block/` |

### One front end

A classic theme, a block theme, the shortcode and the block all render the same
Svelte app. `app/Views/comments.php` — what `comments_template` is swapped for —
is a shim over `Frontend::renderApp()`, the same call the block and shortcode
make. There is no separate classic form, comment walker or second bundle; there
was, for one release, and it meant every fix had to be written twice.

### One submission path

**One path into the database.** Everything POSTs to `admin-ajax.php` with core's
field names and hands off to
`CommentSubmission::handle()`, which calls `wp_handle_comment_submission()` — the
same function `wp-comments-post.php` uses. Core's validation, moderation rules
and `comment_post` hook all behave exactly as they always did.

Everything is admin-ajax; there is **no REST API**. A REST route can only
recognise a logged-in visitor if the request carries a `wp_rest` nonce, and a
nonce printed into a full-page-cached document is stale by definition — worse,
`rest_cookie_check_errors` treats a stale nonce as a hard 403 rather than a
downgrade. admin-ajax authenticates by cookie alone.

| Action (public, `nopriv`) | Method | Purpose |
| --- | --- | --- |
| `fluent_comment_session` | POST | Token, extension fields, identity |
| `fluent_comment_post` | POST | Submit a comment |
| `fluent_comment_list` | GET | "Load more" — page 1 is rendered into the document |

These three carry no nonce; `SpamGuard` gates them instead. Admin endpoints under
the `fluent-comments-admin-` prefix are logged-in only and do use a nonce plus
`manage_options`.

## Extending

FluentComments renders its own form and does not fire core's `comment_form`
hooks, so another plugin's field is never printed — and its validator is
therefore skipped for the duration of a submission, rather than rejecting every
comment over a field that was never on the page. Use these instead; they work
identically on both front ends.

| Hook | Type | Purpose |
| --- | --- | --- |
| `fluent_comments/form_fields` | action | Render extra fields into the form |
| `fluent_comments/validate_submission` | filter | Return `true` or a `WP_Error` to reject |
| `fluent_comments/comment_data` | filter | Adjust the comment array before it reaches core |
| `fluent_comments/spam_score` | filter | Nudge the score (`>= 30` holds for moderation) |
| `fluent_comments/before_process` | action | Before the insert |
| `fluent_comments/after_added_comment` | action | After the insert |

Fields ship with the session payload rather than the cached page, so anything
per-request stays fresh — which also means they are injected after load and must
initialise themselves on the `fluent-comments:fields-rendered` event on
`document`, not `DOMContentLoaded`.

### Tuning

| Filter | Default | Purpose |
| --- | --- | --- |
| `fluent_comments/token_min_age` | `2` | Seconds that must pass between issuing a token and using it |
| `fluent_comments/token_max_age` | `HOUR_IN_SECONDS` | Token lifetime |
| `fluent_comments/max_comments_per_hour` | `10` | Comments per **session** |
| `fluent_comments/max_comments_per_ip_per_hour` | `100` | Per-IP backstop for cookie-less floods |
| `fluent_comments/max_tokens_per_hour` | `200` | Tokens one IP may request per hour |
| `fluent_comments/max_payload_nodes` | — | Ceiling on comments in one list response |
| `fluent_comments/trust_proxy_headers` | `false` | Read the visitor IP from proxy headers |
| `fluent_comments/proxy_ip_headers` | — | Narrow which headers are trusted |
| `fluent_comments/is_trusted_user` | `current_user_can('moderate_comments')` | Who bypasses the spam checks |
| `fluent_comments/accepts_submission` | — | Final say on whether a submission is taken |
| `fluent_comments/comments_per_page` | `comments_per_page` option | Comments per request |
| `fluent_comments/is_handling_comments` | computed | Whether the plugin renders the form for a post |
| `fluent_comments/reject_native_comments` | computed | Per-post override for turning away foreign submissions |
| `fluent_comments/frontend_vars` | — | The config payload handed to the JS app |
| `fluent_comments/session_payload` | — | The per-visitor payload from `fluent_comment_session` |
| `fluent_comments/allowed_comment_hooks` | Akismet | Keep another plugin's comment-hook callback |
| `fluent_comments/render_core_form_hooks` | `false` | Fire core's `comment_form` hooks again |
| `fluent_comments/rewrite_core_email` | computed | Whether to rewrite WordPress's own notices |
| `fluent_comments/mail_headers` | — | Headers on outgoing mail |

`trust_proxy_headers` trusts the header rather than verifying the proxy sent it,
so only enable it when your origin cannot be reached directly — otherwise a
visitor can set the header themselves and sidestep the per-IP limit.

## Contributing

Bug reports and pull requests are welcome at
<https://github.com/WPManageNinja/fluent-comments>. Support questions are better
on the [wordpress.org forum](https://wordpress.org/support/plugin/fluent-comments/).

`CLAUDE.md` documents the constraints that are not obvious from the code — the
caching rules, the single submission path, and the handful of decisions that
would otherwise get undone.

## License

GPLv2 or later.
