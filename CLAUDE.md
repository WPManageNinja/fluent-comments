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

No test suite is configured. No Composer autoloader — all PHP files are manually `require_once`d in `fluent-comments.php`.

## Architecture

Fluent Comments is a WordPress plugin that replaces native comments with AJAX-driven, spam-protected commenting. It has **three separate frontend layers** built with different frameworks:

### Backend (PHP)

- **Entry point:** `fluent-comments.php` → boots on `plugins_loaded`, loads all PHP files manually
- **Namespace:** `FluentComments\App\*`
- **Hook registration:** `app/Hooks/hooks.php` instantiates four handlers that each call `->register()`
- **REST routes:** Loaded only on `rest_api_init` via `app/Http/routes.php`
- **Key handlers:**
  - `CommentsHandler.php` — comment template override, asset enqueuing, AJAX endpoints, spam token validation, shortcode
  - `BlockHandler.php` — Gutenberg block registration with server-side render callback
  - `AdminSettingsHandler.php` — admin page under Comments menu, settings AJAX
  - `CommentNotificationHandler.php` — email notifications for approvals, replies, new comments

### Dual Comment Submission Paths

This is the most important architectural concept: comments can be submitted through **two completely different paths** depending on theme type:

1. **REST API path** (Svelte frontend, used by block/shortcode/FSE themes):
   - `POST /wp-json/fluent-comments/comments/{id}` → `CommentsController::addComment()`
   - Validation handled in the controller directly
   - No security tokens needed

2. **AJAX path** (native PHP template, used by classic themes):
   - `native-comments.js` intercepts form submit → `admin-ajax.php?action=fluent_comment_post`
   - Two-layer AES-256-CBC security tokens: `_fluent_comment_s_token` (time+postID) and `_flc_comment_sign` (postType+postID)
   - Token requested on textarea focus after 2s delay via separate AJAX endpoint

### FSE vs Classic Theme Detection

`CommentsHandler::isFseTheme()` gates behavior throughout the plugin:
- **Classic themes:** CommentsHandler enqueues assets, swaps `comments_template`, uses AJAX path with security tokens
- **FSE themes:** BlockHandler handles everything. Token validation is skipped. Users must add the `[fluent_comments]` shortcode or the **Fluent Comments** Gutenberg block.

### Frontend

- **Svelte 5** (`resources/js/`) — public-facing comments UI
  - `app.js` mounts to `.fluent_dynamic_comments` elements; also handles lazy-replace of `#comments` when `window.flc_post_id` exists (1500ms delay)
  - Uses Svelte 5 runes syntax (`$props`, `$state`, `$effect`)
  - `functions.js` — XHR-based REST client reading from `window.fluentCommentVars.rest`
  - `CommentBlock.svelte` recurses for threaded comments but disables reply on grandchildren (`hideReply=true`)

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
- **Static guard pattern:** Both `CommentsHandler::initAssets()` and `BlockHandler::enqueueAssets()` use `static $loaded` to prevent double-enqueuing
- **Settings:** Stored in `_fluent_comments_settings` WordPress option (defaults: `post_types: ['post']`, `reject_native_comments: 'yes'`)
- **CSS theming:** All public styles use `--fcom-*` CSS custom properties defined on `:root` in `resources/sass/app.scss`; the Gutenberg block overrides these via inline styles per-instance
- **Custom hooks:** `fluent_comments/before_process`, `fluent_comments/after_added_comment`, `fluent_comments/mail_headers`
- **`Arr` helper:** Laravel-derived static utility for dot-notation array access, used throughout for safe settings retrieval
- **REST endpoints:** `GET|POST /wp-json/fluent-comments/comments/{id}` — both are public access (no capability check)
