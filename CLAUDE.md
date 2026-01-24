# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Build Commands

**All frontend assets - from project root:**
```bash
pnpm install
pnpm run build        # Production build (Vite + wp-scripts)
pnpm run dev          # Development with watch (Vite only)
```

**Individual builds:**
```bash
pnpm run build:main   # Vite build only (Svelte + Vue)
pnpm run build:block  # WordPress block only (@wordpress/scripts)
pnpm run dev:block    # Block development with watch
```

No test suite is configured.

## Architecture

Fluent Comments is a WordPress plugin that enhances native comments with AJAX loading and spam protection.

### Backend (PHP)

- **Entry point:** `fluent-comments.php` - Plugin bootstrap
- **Namespace:** `FluentComments\App\*`
- **Structure:**
  - `app/Hooks/Handlers/` - WordPress hook handlers
    - `CommentsHandler.php` - Main comment functionality
    - `AdminSettingsHandler.php` - Admin settings
    - `CommentNotificationHandler.php` - Email notifications
    - `BlockHandler.php` - Gutenberg block registration
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

- **Gutenberg Block** (`resources/block/`)
  - `block.json` - Block metadata and attributes
  - `editor.jsx` - Block editor UI with React
  - `editor.scss` - Editor preview styles
  - Built with `@wordpress/scripts` for proper WordPress dependencies
  - Supports color customization, avatars toggle, border radius

### Build Output

All compiled assets go to `/dist/`:
- `js/app.js` - Svelte frontend
- `js/native-comments.js` - Native comment AJAX handler
- `js/admin_app.js` - Vue admin
- `css/app.css` - Public styles (with CSS custom properties)
- `css/admin_app.css` - Admin styles
- `block/editor.jsx.js` - Gutenberg block editor (built with @wordpress/scripts)
- `block/editor.jsx.css` - Block editor styles
- `block/editor.jsx.asset.php` - WordPress dependencies manifest

## Key Implementation Details

- **Build system:** Vite for Svelte/Vue, @wordpress/scripts for Gutenberg block
- **Spam protection:** Uses AES-256-CBC encrypted tokens with 5-minute expiry (LOGGED_IN_SALT/KEY)
- **Settings:** Stored in `_fluent_comments_settings` option
- **REST endpoints:** `/wp-json/fluent-comments/comments/{id}` (GET/POST, public access)
- **FSE theme support:** Use `[fluent_comments]` shortcode or **Fluent Comments** block
- **CSS Variables:** All styles use CSS custom properties for easy theming (see `resources/sass/app.scss`)

## Gutenberg Block

The **Fluent Comments** block can be added to any post/page in the block editor with these options:

- **Display Settings:**
  - Show/hide title
  - Custom title text
  - Show/hide avatars
  - Border radius control

- **Color Settings:**
  - Primary color
  - Background color
  - Text color
  - Comment card background
