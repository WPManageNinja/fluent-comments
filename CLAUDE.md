# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Build Commands

**All frontend assets - from project root:**
```bash
pnpm install
pnpm run build        # Production build
pnpm run dev          # Development with watch
```

No test suite is configured.

## Architecture

Fluent Comments is a WordPress plugin that enhances native comments with AJAX functionality and spam protection.

### Backend (PHP)

- **Entry point:** `fluent-comments.php` - Plugin bootstrap
- **Namespace:** `FluentComments\App\*`
- **Structure:**
  - `app/Hooks/Handlers/` - WordPress hook handlers (CommentsHandler, AdminSettingsHandler, CommentNotificationHandler)
  - `app/Http/` - REST API (routes.php, Controllers/CommentsController.php)
  - `app/Services/` - Helper, Router, Mailer, FluentWalkerComment
  - `app/Views/` - PHP templates for comments rendering

### Frontend

- **Svelte 5** for public-facing comments (`resources/js/`)
  - `app.js` - Entry point, mounts to `.fluent_dynamic_comments`
  - `comments.svelte`, `CommentForm.svelte`, `CommentBlock.svelte`
  - `functions.js` - REST API wrapper
  - Uses Svelte 5 runes syntax (`$props`, `$state`, `$effect`)

- **Vue 3** for admin dashboard (`resources/admin/src/`)
  - Uses Element Plus UI framework with auto-import
  - `Dashboard.vue` - Settings management

### Build Output

All compiled assets go to `/dist/`:
- `js/app.js` - Svelte frontend
- `js/native-comments.js` - Native comment AJAX handler
- `js/admin_app.js` - Vue admin
- `css/app.css` - Public styles
- `css/admin_app.css` - Admin styles

## Key Implementation Details

- **Spam protection:** Uses AES-256-CBC encrypted tokens with 5-minute expiry (LOGGED_IN_SALT/KEY)
- **Settings:** Stored in `_fluent_comments_settings` option
- **REST endpoints:** `/wp-json/fluent-comments/comments/{id}` (GET/POST, public access)
- **FSE theme support:** Use `[fluent_comments]` shortcode as workaround
