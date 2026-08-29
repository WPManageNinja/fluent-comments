# FluentComments

AJAX powered, spam-protected comments for WordPress. Replaces the native comment experience with a faster, better looking one that works on classic themes, block (FSE) themes and page builders.

[Plugin on WordPress.org](https://wordpress.org/plugins/fluent-comments/)

## Features

- AJAX comment posting and loading, with paginated "load more"
- Layered spam protection: HMAC signed submission tokens, honeypot field, minimum form fill time and per-IP rate limiting
- Gutenberg block with colour, title and avatar options
- `[fluent_comments]` shortcode
- Automatic replacement of the core Comments block on block themes
- Email notifications for comment approval, replies and post authors
- Fully themeable through `--fcom-*` CSS custom properties

## Requirements

- WordPress 6.5+
- PHP 7.4+

## Development

```bash
pnpm install
pnpm run build        # production build (Vite + wp-scripts)
pnpm run dev          # watch the Svelte and Vue bundles
pnpm run dev:block    # watch the Gutenberg block
```

Compiled assets land in `/dist` and are not committed. Run a build before packaging a release.

## Architecture

Three front-end layers share one PHP back end:

| Layer | Stack | Entry point | Output |
| --- | --- | --- | --- |
| Public comments UI | Svelte 5 | `resources/js/app.js` | `dist/js/app.js`, `dist/css/app.css` |
| Classic theme form | Vanilla JS | `resources/js/native-comments.js` | `dist/js/native-comments.js` |
| Admin settings | Vue 3 + Element Plus | `resources/admin/src/app.js` | `dist/js/admin_app.js`, `dist/css/admin_app.css` |
| Gutenberg block | React (wp-scripts) | `resources/block/editor.jsx` | `dist/block/` |

### Comment submission paths

Comments arrive one of two ways, both guarded by `SpamGuard`:

1. **REST** — used by the block, the shortcode and the block theme takeover.
   `GET|POST /wp-json/fluent-comments/comments/{id}` and `GET /wp-json/fluent-comments/comments/{id}/token`.
2. **admin-ajax** — used by the classic theme comment template, via the
   `fluent_comment_post` and `fluent_comment_comment_token` actions.

Both require a signed token fetched in a separate request, which a cross-origin page cannot read, so the token doubles as a CSRF token.

### Filters

| Filter | Default | Purpose |
| --- | --- | --- |
| `fluent_comments/token_min_age` | `2` | Seconds that must pass between issuing a token and using it |
| `fluent_comments/token_max_age` | `3600` | Token lifetime in seconds |
| `fluent_comments/max_comments_per_hour` | `10` | Comments one IP may post per hour |
| `fluent_comments/max_tokens_per_hour` | `60` | Tokens one IP may request per hour |
| `fluent_comments/trust_proxy_headers` | `false` | Trust `X-Forwarded-For` when resolving the visitor IP |
| `fluent_comments/is_trusted_user` | `current_user_can('moderate_comments')` | Who bypasses the spam checks |
| `fluent_comments/is_handling_comments` | computed | Whether the plugin renders the form for a post |
| `fluent_comments/comments_per_page` | `comments_per_page` option | Comments loaded per request |
| `fluent_comments/frontend_vars` | — | The config payload handed to the JS app |

### Actions

`fluent_comments/before_process`, `fluent_comments/after_added_comment`, `fluent_comments/mail_headers`

## Contributing

Bug reports and pull requests are welcome at
<https://github.com/WPManageNinja/fluent-comments>.

## License

GPLv2 or later.
