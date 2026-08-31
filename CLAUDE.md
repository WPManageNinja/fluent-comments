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
php tests/SpamGuardTest.php        # token + scoring logic
php tests/HookIsolationTest.php    # foreign comment-hook isolation
php tests/NativeRejectionTest.php  # who gets rejected, and who never does
php tests/CommentEmailTest.php     # smartcode escaping + where "is this sent" lives
php tests/CommentNotificationTest.php  # who receives an email, and who never does
php tests/RequiredIdentityTest.php     # our own name + email rule, independent of core
php tests/PayloadCapTest.php           # the ceiling on nodes in one list response
php tests/ServerRenderTest.php         # the first page as indexable HTML
```

```bash
npm run i18n          # rescrape $t() from the Vue admin into TransStrings.php
```

```bash
./build.sh            # the release zip, into builds/
./build.sh --pot      # ...regenerating languages/fluent-comments.pot first
./build.sh --help     # the rest of the flags
```

All run standalone against a stubbed WordPress surface — no install needed.

No PHPUnit setup. No Composer autoloader — all PHP files are manually `require_once`d in `fluent-comments.php`.

## Architecture

FluentComments is a WordPress plugin that replaces native comments with AJAX-driven, spam-protected commenting. It has **three separate frontend layers** built with different frameworks:

### Backend (PHP)

- **Entry point:** `fluent-comments.php` → boots on `plugins_loaded`, loads all PHP files manually
- **Namespace:** `FluentComments\App\*`
- **Hook registration:** `app/Hooks/hooks.php` instantiates four handlers that each call `->register()`
- **AJAX actions registered in `CommentsHandler::register()`
- **Key handlers:**
  - `CommentsHandler.php` — comment template override, asset enqueuing, AJAX endpoints, spam token validation, shortcode
  - `BlockHandler.php` — Gutenberg block registration with server-side render callback
  - `AdminSettingsHandler.php` — admin page under Comments menu, settings AJAX
  - `EmailSettingsHandler.php` — the Emails screen's AJAX (list, edit, preview, template design)
  - `CommentNotificationHandler.php` — who receives which of our three emails, and when
  - `CoreEmailHandler.php` — rewrites WordPress's own two comment notices on the way out

### One Comment Submission Path

There are two *entry points* but only one path into the database, and keeping it that way is the most important constraint in this codebase:

- **Svelte UI** (block/shortcode/block themes) and the **classic template** both post to `admin-ajax.php?action=fluent_comment_post` → `CommentsHandler::handleAjaxComment()`, with the same field names (`comment`, `author`, `email`, `comment_parent`).

Both normalize their payload and hand it to **`CommentSubmission::handle()`**, which is the only place a comment is created. It calls `wp_handle_comment_submission()` — the same function `wp-comments-post.php` uses.

**Never call `wp_insert_comment()` directly here.** It does not fire `comment_post`, where core's moderation and post-author notification emails hook, and it skips all of core's validation. An earlier version of the REST path did, and silently lost both.

`CommentSubmission` additionally enforces what core does not: that a reply's parent belongs to the same post, that the thread is within `thread_comments_depth`, and that a logged out commenter gave a name and a valid email address.

**That last one is ours, and it has no switch.** Core's `require_name_email` can be turned off; ours cannot, so the option is deliberately absent from `DiscussionSettings::BOOLEANS` and from the settings screen. `CommentSubmission::validateIdentity()` never reads it. Core's copy is left exactly where it was, still owned by `Settings › Discussion` and still governing core's own form on post types we do not handle. The reason is that every one of our three emails is addressed by `comment_author_email`: an anonymous comment silently opts its author out of the reply notifications the rest of the thread gets, and leaves nothing to moderate on. `resources/js/validate.js` is the same rule client side, shared by both front ends so it is written once — but it only saves a round trip, it never decides.

### We Own the Form — Foreign Hooks Are Isolated

FluentComments renders its own comment form and **does not fire core's `comment_form` hooks**, so a plugin that would add a CAPTCHA field never gets to render one. Running its validator anyway would reject every comment on the site over a field that was never on the page.

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

### Emails

Five of them, one screen, and `EmailService` is the single place they are
defined. Three are ours, sent to the people who comment; two are core's own
notices to the site, which we filter rather than send.

| Id | Sent by | To |
|---|---|---|
| `comment_approved` | us | the commenter, when their held comment goes live |
| `reply_to_participants` | us | everyone above a reply in the thread |
| `new_comment_to_post_author` | us | the post author |
| `core_comment_notification` | WordPress | you and the post author, every approved comment |
| `core_comment_moderation` | WordPress | you, when a comment is held |

**On/off is not stored in the email option.** Each of the five already had a
switch before the Emails screen existed — three keys in `_fluent_comments_settings`,
and `comments_notify` / `moderation_notify`, which `Settings › Discussion` also
writes. A second copy would be a second answer, so `EmailService::TOGGLES` maps
each email to the switch it already had and `status` is *composed*: the switch
says whether it is sent, `_fluent_comments_email_settings` only records whether
the content is ours or the site owner's. Same in-place philosophy as
`DiscussionSettings`.

**The Emails list is the only place they are switched.** These five switches were
briefly on the Settings tab as well — a "Notifications" card writing exactly the
same options. Two screens describing one value in two vocabularies ("A comment
landed on their post" over there, a row reading "Off" over here), so the card is
gone and the row is the setting. The list writes through `toggle-email`, which
touches only the switch; a switch that needs a save button is a switch people
leave half set.

**Keep the two halves apart when writing.** `saveEmail($emailId, $enabled,
$contentStatus, $email)` takes them separately, and `getEmailForEditing()` hands
them back separately as `enabled` / `content_status`, which is why the edit
screen is a switch plus a two-way radio rather than one three-way control. Folding
them into one meant disabling an email also reset it to the default content, and
meant a customised body was invisible while the email was off. `status` stays
composed for the list, which only needs to *read* it.

Three read statuses: `system` (the default content), `active` (theirs),
`disabled`. `system` means something different on either side of the list,
deliberately —
for our three it renders the built-in body through the same parser and template
as `active`, so there is one body per email rather than one for us and one for
them. For the two core ones it means **leave WordPress alone**: those notices
predate the plugin on every site that installs it, and quietly turning a plain
text notice into an HTML one on upgrade is not a change anyone asked for. The
editor still loads our HTML default so "start from the default" has something to
hand over.

`CoreEmailHandler` hooks core's `comment_notification_*` / `comment_moderation_*`
filters rather than replacing `wp_notify_postauthor()` / `wp_notify_moderator()`,
so core keeps its recipient list and its own gating. It is scoped to
FluentComments post types — a site that switched the plugin on for `post` alone
should not find its other notifications rewritten. Escape hatch:
`fluent_comments/rewrite_core_email`.

**Smartcodes.** `SmartCodeParser` fills `{{group.field}}` and `##group.field##`
from a context of `comment`, `post`, `receiver`, `site`. The delimiter decides
the escaping: `{{}}` is HTML escaped for text, `##...##` is attribute escaped for
an `href`, and anything in `URL_KEYS` goes through `esc_url()` either way. The
one field allowed markup is `comment.content`, through `wp_kses_post()`. A
comment body, author name and author URL are whatever a visitor typed and end up
in an email a site owner reads in an HTML client, so none of it is trusted. An
unknown code is left standing rather than blanked — a blank looks like an empty
value, which is the harder of the two to notice. `{{receiver.name|there}}`
supplies a fallback. Our three emails render **per recipient**, because
`{{receiver.name}}` differs for each of them.

**The template.** `app/Views/email_template.php` is the frame, driven by
`_fluent_comments_email_settings['template']` — logo, colours, footer, From and
Reply-To. The colours land twice on purpose: inline on the structure, which every
mail client honours, and in a head `<style>` for the pieces inside the body that
a site owner can edit and we therefore cannot reach with an inline style (a
blockquote they add, a `.fcom_btn` they paste). FluentAuth solves the same
problem with a vendored Emogrifier; that is 60KB of library for a plugin with no
autoloader, to inline styles onto markup we already control, so **do not add it**.
`Mailer::setDefaultHeaders()` reads From/Reply-To from the same settings and
leaves both empty unless filled in, so by default `wp_mail()` still decides —
which is what an SMTP plugin expects.

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

The admin has its own separate actions under the `fluent-comments-admin-` prefix — `save-settings`, `scan-templates`, `get-emails`, `get-email`, `save-email`, `toggle-email`, `preview-email`, `get-email-template`, `save-email-template` — logged-in only, nonce plus `manage_options`, all gated by `Helper::verifyAdminAjax()`.

The REST layer (`app/Http/`, `Router.php`) was deleted. Three reasons, in order of weight:

1. **A REST route can only recognise a logged-in visitor if the request carries a `wp_rest` nonce** — and a nonce printed into a full-page-cached document is stale by definition. Worse, `rest_cookie_check_errors` treats a *stale* nonce as a hard **403**, not a downgrade, so a visitor who logged out in another tab mid-compose lost their comment. admin-ajax authenticates by cookie alone. There is now no nonce anywhere in the plugin.
2. **Both entry points share one transport, one request context, and one auth mechanism.** They previously differed in all three, which is the drift that caused the original REST-path bugs.
3. **Tighter CORS.** `send_origin_headers()` echoes an origin only from `get_allowed_http_origins()`; the REST API echoes *any* Origin with `Access-Control-Allow-Credentials: true`.

The cost, accepted knowingly: `admin-ajax.php` defines `WP_ADMIN`, so **`is_admin()` is true** during a submit, and it fires `admin_init`. The classic path always worked this way, so this makes behaviour consistent rather than introducing something new. It does mean `comment_text` filters run in an admin context — watch for third-party filters that gate on `!is_admin()`.

### Caching Is a Hard Constraint

Assume every page is served from a full-page cache to somebody else. This shapes several decisions that otherwise look odd:

- **Nothing visitor-specific goes into HTML.** No user identity, no `wp_get_current_commenter()` values, no token. All of it comes from `Frontend::getSessionPayload()` via `fluent_comment_session`, which is never cached.
- **The session is fetched on intent, not on load.** A visitor who only reads costs the site zero uncached requests.
- **The first page of comments is server-rendered twice**, by `Frontend::renderApp()`: into a `<script type="application/json">` block that `app.js` hydrates from, and as real HTML through `Frontend::renderCommentList()`. Fetching it on mount would turn every view of every post into an uncached WordPress boot, which is what the JSON is for. The HTML is for everything that never runs the script — a `<script>` block is data, not content, so without it a crawler saw a comments section reading "Loading…". The classic template never had this problem; `wp_list_comments()` writes real markup.

  `renderCommentList()` matches `CommentBlock.svelte` element for element, and `app.js` empties the container before mounting. **If you change one, change the other**: they are two renderers over the one array `CommentsRepository` returns, and when they disagree the page visibly reflows the moment the script runs. Verified at 1865px on both sides. The cost is ~885 bytes gzipped for a page of comments, because the markup is nearly all repeated structure.
- The native template's name/email fields are filled by JS from the `comment_author_*` cookies (`readHashedCookie` in `session.js`), never printed server-side.

### The Setup Notice

There is no activation hook, so a fresh install has **no `_fluent_comments_settings` row** and `Helper::getCommentSettings()` serves the defaults — which already hand `post` to FluentComments with `reject_native_comments` on. A site owner who has never opened the screen therefore has a plugin actively changing how comments work, and on a block theme has posts that take no comments at all until the block is placed. So `AdminSettingsHandler::maybeShowSetupNotice()` runs on `admin_notices` until they save once, and says which defaults are live.

`Helper::isConfigured()` is the whole test: the option row exists. Saving writes every key, so a saved option is never empty, and the notice retires itself on the first save.

**It is not dismissible, and that is the point.** Saving once is the only thing that clears it. A dismiss link would let someone buy silence without ever having seen what the defaults are doing to their comments, which is the single outcome the notice exists to prevent — and the state it clears is real configuration, not "I read this". Nothing writes a dismissal key any more; `uninstall.php` still deletes the 2.1.0 one because installs that saw the old placement notice still carry it.

**Keep it cheap — one autoloaded option and one user meta.** It renders on every admin page, which is exactly why it must not ask anything that costs a query. In particular it does not ask whether the block has been placed: that is `TemplateScanner`, and the reason the previous notice needed a transient. It says *that* the question exists and points at the screen that answers it.

### Block vs Classic Theme Detection

`Helper::isBlockTheme()` / `Helper::isHandlingComments()` gate behavior:
- **Classic themes:** CommentsHandler enqueues assets and swaps `comments_template`.
- **Block themes:** nothing is placed automatically. The user adds the FluentComments block in the Site Editor or the `[fluent_comments]` shortcode; the settings screen's **Placement needed** panel tells them so, and warns that spam protection is already rejecting the theme's own form.

`TemplateScanner` is what keeps that from being a guess. For each enabled post type it walks `get_template_hierarchy()` to find the template that post type actually renders through, then walks that template's blocks looking for `fluent-comments/comments`, the `[fluent_comments]` shortcode, or a leftover `core/comments`. The walk follows `core/template-part` and `core/pattern`, because a Comments block almost never sits at the top level, and carries a seen-set so two parts referencing each other terminate. Shortcodes count because core runs `do_shortcode()` over both the template (`block-template.php`) and every part (`_wp_apply_block_content_filters`); detection uses `get_shortcode_regex([SHORTCODE])` rather than `has_shortcode()`, which would answer based on whether our own `init` hook had run yet. **Nothing is cached, and the scan lives on one screen.** It runs only through `scan-templates`, which the settings screen calls as it opens, again after a save, and on the Recheck button. There was briefly a 5-minute transient plus flush hooks on `save_post_wp_template{,_part}` and `switch_theme` — that existed to make the scan cheap enough for an `admin_notices` callback that asked *on every wp-admin page* whether the block had been placed. Both are gone, along with that callback. The placement question now lives only on the settings screen, which is also where a cached answer would be worst — a site owner opens it because they just edited a template. Resolving templates is a query plus a walk over theme files; keep it off the general admin page load rather than making it cheap to run there.

`isHandlingComments()` answers *rendering only*. Whether a foreign submission is rejected is `willRejectNativeComments()`, and that one is deliberately **theme-independent**: selected post type + `reject_native_comments` is the whole test. That is the contract the site owner opts into — pick the post types, switch spam protection on, and anything reaching core's endpoint without our fields is turned away. The consequence is accepted knowingly: on a block theme where the block has not been placed yet, those posts take no comments at all until it is. The settings screen's placement panel says so, and `fluent_comments/reject_native_comments` is the per-post escape hatch.

### Frontend

- **Svelte 5** (`resources/js/`) — public-facing comments UI
  - `app.js` mounts to `.fluent_dynamic_comments`, hydrating from the `data-bootstrap` JSON block
  - Uses Svelte 5 runes syntax (`$props`, `$state`, `$effect`)
  - `ajax.js` — the single XHR client for all three actions, shared by the Svelte and native bundles
  - `session.js` — memoized session fetch, `invalidateSession()` after each post (tokens are single-use), and `readHashedCookie()`
  - `CommentBlock.svelte` recurses for threaded comments, gated on `maxDepth`

- **Vue 3** (`resources/admin/src/`) — admin settings dashboard
  - Uses Element Plus with auto-import (via `unplugin-vue-components`)
  - **Three tabs**, on `vue-router` with hash history: Settings, Emails, About. `App.vue` is the shell (brand bar + nav + `<router-view/>`); every page renders its own `PageHeader` with its own save button, because each page saves a different thing and one global button would happily post an untouched page's state over another tab's write. `meta.active` drives the nav highlight so `/emails/template` and `/emails/:id` keep the Emails tab lit.
  - The email body editor is a real `wp.editor` instance, which is why `renderAdminPage()` calls `wp_enqueue_editor()` and `wp_enqueue_media()`. `WpEditor.vue` tears the instance down on unmount — TinyMCE keeps a global registry keyed by element id, and a leftover one means the next visit initialises onto a dead node and renders nothing. Content is read back on `change`, `keyup` **and** `SetContent`: `change` alone (what WordPress binds) only fires on blur, so a body typed and saved without clicking away saved empty.
  - Previews render inside an iframe (`EmailFrame.vue`), content and colours painted in one pass — writing the content replaces the whole document, so a separate colour watcher would lose to whichever ran last. `PreviewEmail` with no `emailData` renders the **default** rather than the editor's contents (the `preview-email` endpoint falls back), which is what the edit screen shows inline at `content_status: system`. Not for the two core emails at that setting: WordPress builds those itself in plain text from strings we never see, so there is nothing of ours to render and rendering our default would be showing something that is not going out.
  - The Settings tab still POSTs the whole `discussion` object, which carries `comments_notify` and `moderation_notify` even though it no longer renders them. So `save-email` and `toggle-email` return the current `toggles` and `$syncToggles()` folds them into `window.fluentCommentsVars`. Without it, switching a core email off under Emails and then saving Settings — which still holds the page-load snapshot — puts it straight back on.
  - AJAX through jQuery to `admin-ajax.php` with action prefix `fluent-comments-admin-`
  - Global config injected as `window.fluentCommentsVars` (note: different from frontend's `fluentCommentVars`)
  - Follows the shared Fluent admin look established by FluentCart and adopted by FluentBooking: the `#f3f5fa` page ground, a white full-bleed top bar, a 1350px centred content column with a 30px gutter, and `#4f66f5` as primary. The tokens are copied into `style.scss` rather than imported, because FluentCart carries them on Tailwind and this is one page of plain SCSS. The page zeroes `#wpcontent`'s 20px padding (keeping its 160px menu margin) so the bar reaches both edges.
  - **The top bar is full bleed and has three zones**: brand on the left edge, the tabs centred, docs and the appearance switch on the right. It is a grid whose outer columns are both `1fr`, so the tabs sit centred on the *bar* rather than in whatever gap is left between the brand and the icons — the version chip changing width does not shift them. The bar carries no container cap; the pages below keep theirs, because that one is a reading column and this is chrome. Under 960px it becomes two rows, brand and icons above, tabs below.
  - **Light / dark, to the Fluent theme mode spec.** `theme.js` implements it; the contract is shared with every other Fluent plugin on the same origin, so none of it is ours to change:

    | | |
    |---|---|
    | `localStorage['fluent_theme_mode']` | `light` \| `dark` \| `system:light` \| `system:dark` |
    | `<body class="fluent_theme_dark">` | present only while dark; light adds no class at all |

    - **Three modes, not two.** Light, Dark and System, chosen from the menu behind the appearance icon in the top bar. System follows `matchMedia('(prefers-color-scheme: dark)')` and keeps following it at runtime — `watchSystem()` repaints on `change`, and only while the mode is System, because the two static modes are a choice and outrank the machine.
    - **`system:dark` is a cache, not a mode.** A media query is not always resolved when the page starts painting, so the stored value carries the last answer: `applyStoredTheme()` paints that immediately, then confirms against `matchMedia` and corrects both the class and the cache if the machine changed its mind. It runs in `app.js` **before** the app mounts. Without either half, a system-dark visitor gets a white flash on every load.
    - Default is light — no stored value, no class, nothing written until somebody picks. wp-admin's chrome is light, and a dark island inside it is not something to hand somebody unasked.
    - **Do not add a second dark class beside `fluent_theme_dark`.** The one other class `theme.js` sets is `dark` on `<html>`, which is Element Plus's own API (hence the `theme-chalk/dark/css-vars.css` import) and is never read back; it carries the couple of hundred `--el-*` values the dark block does not remap.
    - Every `--flc-*` token lives on `:root` and is re-declared under `body.fluent_theme_dark` — (0,1,1) against `:root`'s (0,1,0), so dark wins with no `!important`. **A literal hex in a rule is a bug**: it is a patch of light that survives into dark mode. Add a token instead.
    - `<body>` is also the right host for the `--el-*` remap that pulls Element Plus's neutral near-black onto the slate palette. It beats Element Plus's own `html.dark` block not on specificity — they are equal — but on proximity: for the app and for every popper teleported into `<body>`, the token declared on the nearer ancestor wins.
    - **A custom property's `var()` is substituted where it is declared, not where it is used.** `--el-bg-color: var(--flc-surface)` on `<body>` resolves to the dark hex once and inherits as that hex, so re-declaring `--flc-surface` deeper does not move it. The email editor's light sheet therefore restates the `--el-*` values as literals.
    - The email body editor keeps a **white sheet** inside the dark page: what is being edited is a light HTML email, and the furniture around it — the Visual/Text tabs, the media button, the TinyMCE toolbar — is WordPress's, which has no dark mode and cannot be given one from here. The email preview iframe is white in both themes for the same reason.
    - `color-scheme: dark` goes on `html.dark`, not on the body class: the page canvas and the document scrollbar read it from the root element, and it inherits down from there to every native control.
  - **No webfont.** FluentCart pulls Inter from Google Fonts; this screen uses the stack WordPress already sets on the admin body (`wp-admin/css/common.css`), so there is no external request. Do not add one back.
  - The logo is the plugin's own wordpress.org icon (`ps.w.org/fluent-comments/assets/icon.svg`, the same file the listing header uses), kept at `resources/images/logo.svg` and inlined into the component so it paints on the first frame — `dist/` is gitignored, so it cannot ship as a file reference. The artwork is used as published but for two edits: the gradient id is renamed to `flc_logo_gradient`, because Figma exports `paint0_linear_0_1` and a second inline SVG on the same admin page would otherwise capture the fill; and the opaque `#F5F5F5` backing rect is dropped, because it shows through the rounded corners as four light specks on a dark bar.
  - One page, no tabs. Wide main column on the left, 340px sidebar on the right, stacking below 1100px. FluentComments' own settings come first; core's follow.
  - The sidebar carries the set-and-forget core toggles, plus a **Placement needed** notice that only appears when `TemplateScanner` reports a post type with neither the block nor the shortcode. It is the *only* place that warning lives — the `admin_notices` notice is about setup, not placement, because deciding on placement means scanning. Everything placed, or a classic theme, and it is not rendered at all. It turns from amber to red once `reject_native_comments` is on, because at that point those posts cannot take comments.
  - Core options are marked with a `Core` chip at the card or group level, never per row.
  - All styles are prefixed `flc_` in `style.scss`. There is no scoped-style block in the component.
  - **wp-admin's `forms.css` beats Element Plus on specificity**, so `style.scss` carries a reset for it. Core styles fields as `input[type="text"]` — element plus attribute, (0,1,1) — while Element Plus resets its own inner field with the single class `.el-input__inner`, (0,1,0). Core wins, and its border, radius, padding and min-height land on the native input *inside* the component's own bordered wrapper: two boxes, one in the other. The reset is scoped under `#fluent_comment_app`, which is (1,0,0) and settles it. Same story for the hidden inputs behind `el-checkbox` / `el-radio` / `el-switch`, which core gives a size, a border and a dashicon tick. If a new Element Plus component looks doubled or misaligned, this is why — add its inner element to that block rather than reaching for `!important`.

- **React/JSX Gutenberg block** (`resources/block/`)
  - Built with `@wordpress/scripts` (separate webpack pipeline from Vite)
  - `save: () => null` — fully dynamic/server-rendered block
  - Editor shows placeholder comments for visual preview

### Translation

Three layers, three mechanisms, because each one has a different extractor
to satisfy — but only one rule: **no reader-facing string is written in a
`.js`, `.vue` or `.svelte` file without going through one of them.**

| Layer | How | Reaches PHP via |
|---|---|---|
| Vue admin | `$t('English string')` | `TransStrings::getStrings()`, generated |
| Svelte frontend | `i18n.some_key` off `fluentCommentVars` | `Frontend::getStrings()`, hand written |
| Gutenberg block | `__()` from `@wordpress/i18n` | `wp_set_script_translations()` |

All three end up in the `fluent-comments` text domain, which
`FluentCommentsPlugin::registerTextDomain()` loads **on `init`** from the
plugin's own `languages/` directory. Without that call only the
translations WordPress.org installs into `WP_LANG_DIR/plugins` resolve —
the just-in-time loader knows nothing about a plugin's own folder unless
the plugin registers it, so a bundled `.mo` worked for the block (whose
`wp_set_script_translations()` is handed the path outright) and for nothing
in PHP. It is on `init` and not `plugins_loaded` because loading a domain
earlier is deprecated as of WordPress 6.7 and notices on every request.

**The Vue admin follows FluentCommunity.** The English string *is* the key:
`$t('Save changes')` looks itself up in the map PHP printed into
`fluentCommentsVars.i18n` and falls back to itself when there is nothing
there, so a missing translation is the English text rather than a raw key
leaking into the UI. `$t()` and `$_n()` are registered on the global mixin
in `app.js` and also exported from `resources/admin/src/i18n.js`, because
prop defaults (`PageHeader.saveText`, `SmartCodes.buttonText`) and
`routes.js` are evaluated before there is an instance to reach them
through. `$t` fills `%s`/`%d` in order and `%1$s`/`%2$d` by position — keep
the numbered form available, reordering is exactly what it is for — and
`%%` is a literal percent that consumes no argument. As with `__()` versus
`sprintf(__())`, a call with no arguments is not formatted at all.

`$_n(singular, plural, count)` picks on `count !== 1`, which is what
WordPress's own `_n()` does: English puts zero in the plural, so `> 1`
reads "0 comment". `count` is the only value it substitutes, so a plural
sentence that needs a *different* argument — `Settings.vue`'s
`placementWarning`, which substitutes a list of post types — picks between
two `$t()` calls itself rather than coming through it.

`npm run i18n` runs `i18n.node.js`, which scrapes every `$t()` and `$_n()`
call under `resources/admin/src` and writes `app/Services/TransStrings.php`
— an array of `__()` calls, which is the only form `wp i18n make-pot` can
see, since it cannot read a `.vue` file. **That file is generated in full
every run; never edit it.** It is `require_once`d lazily inside
`renderAdminPage()` rather than with the rest of the plugin, because it is
about 150 `__()` calls and one screen wants them. `npm run build` runs the
extractor first, so a forgotten run cannot ship.

The extractor parses the string literal rather than matching one regex, so
an apostrophe (`'a post\'s own content'`) and a call broken across lines
both survive, and it warns rather than silently dropping a `$t(variable)`.
It **decodes** the escapes as it reads, then re-escapes for PHP, because
the key has to be byte-identical on both sides: `$t()` looks a string up by
its decoded value, so `$t("Clearing \"this\"")` written out with the
backslashes still in it produces a key that can never match — silently
untranslatable, and shown to a translator with the backslashes in it. An
escape with no single-quoted PHP equivalent (`\n`, `\t`, `\uXXXX` — none of
which belongs in a string a reader sees) is reported with its file and line
rather than written out wrong.
A `<!-- translators: ... -->` or `/* translators: ... */` comment on the
line directly above a call carrying a placeholder is carried into the
generated PHP; `make-pot` warns when one is missing, which is the check.

**A sentence with markup in the middle stays one string.** The link or
`<code>` is passed in as `%s` and the whole thing rendered with `v-html`
(`Settings.vue`'s footnote, `EditEmail`'s template-design link, which is a
plain `<a href="#/emails/template">` for that reason — the router is on
hash history, so it navigates the same). Splitting a sentence at a tag
hands a translator fragments they cannot reorder.

**A button cannot be spliced in that way**, because it carries a handler
and `v-html` would not wire one up. So a sentence never wraps one: the
button gets a label that reads on its own and the explanation goes beside
it as a whole sentence. `EditEmail` had both halves of this wrong — a
button followed by the fragment `- it starts from…`, and a `Reset it with`
+ `start from the default` pair with the full stop stranded in the markup.
Neither is placeable in a language that orders things differently.

**The block's `__()` calls only resolve once the handle points at a JSON
file**, which is what `BlockHandler::registerBlock()` does with
`wp_set_script_translations()` — reading the handle back off the registered
type rather than guessing at the name block.json generates. Their strings
live in the *built* bundle, so regenerating the `.pot` means building
first:

```bash
pnpm run build
wp i18n make-pot . languages/fluent-comments.pot --slug=fluent-comments \
  --exclude=node_modules,tests,resources,dist/js,dist/css
```

`resources/` is excluded because the Vue and Svelte sources there are
unreadable to `make-pot`; `dist/js` and `dist/css` because the admin
strings already arrive through `TransStrings.php` and would otherwise be
listed twice, against a minified file.

**The Svelte frontend is keyed, not string-keyed, and stays that way.** It
was already `i18n.reply` against `Frontend::getStrings()` before any of
this, and those strings ride the cache-safe `fluentCommentVars` payload.
Add a key there rather than reaching for `$t`.

### Releasing

`build.sh`, the same shape as the other Fluent plugins. It builds the
frontend, stages, and zips into `builds/`.

**The zip is a whitelist, not an exclude list.** `PAYLOAD` names every
top-level entry that ships; anything new in the repo root is left out and
*reported* rather than quietly included. Getting that backwards is how
`resources/` and `node_modules` end up in a release. `resources/` is a
source tree and stays behind except for `resources/block/block.json`, which
`register_block_type()` reads at runtime by path — it is in `PAYLOAD_FILES`
for that reason.

`.distignore` is the exclude-list equivalent for `wp dist-archive`. It is
kept for anyone reaching for that, but **`build.sh` is the release path and
its `PAYLOAD` is the authoritative answer**; two lists describing one
decision will drift.

**It deletes `dist/` before building.** `vite.config.js` sets
`emptyOutDir: false`, because the block's separate webpack pipeline writes
into the same `dist/` and would be wiped by the Vite build that runs first.
Nothing else clears it, so Vite's content-hashed chunks accumulate across
builds — the first run of this script dropped a `session-*.js` that had
been orphaned for some time and would otherwise have shipped.

**A version mismatch is fatal, not a warning.** The header, the
`FLUENT_COMMENTS_VERSION` constant and the readme's `Stable tag` are read
by three different things — WordPress, our own code, and WordPress.org —
and disagreeing means either a plugin that reports a different version
depending on who asks, or the wrong tag rolled out to every install.

The staged copy gets an `index.php` in every directory that lacks one.
These were being added by hand, which drifts: three existed across sixteen
directories, so `app/Hooks`, `app/Services`, `app/Views` and all of `dist/`
were listable on a server with directory indexes on.

### Build System

Two completely separate build pipelines:
- **Vite 6** (`vite.config.js`): Builds Svelte + Vue + native-comments.js → `dist/js/` and `dist/css/`
- **@wordpress/scripts** (webpack): Builds the Gutenberg block → `dist/block/` with WordPress dependency manifest

All compiled output goes to `/dist/`.

## Key Patterns

- **Gating function:** `Helper::isFluentCommentsPostType($postType)` is the central check for whether FluentComments is active — reads `post_types` from settings
- **Static guard pattern:** `Frontend::enqueueAssets()` uses `static $loaded` to prevent double-enqueuing
- **Reading comments:** `CommentsRepository::getPayload()` — used by both `fluent_comment_list` and the server-rendered bootstrap, so the two cannot drift
- **Settings:** Stored in `_fluent_comments_settings` WordPress option (defaults: `post_types: ['post']`, `reject_native_comments: 'yes'`). Email content and template design live separately in `_fluent_comments_email_settings`; the on/off switches for the emails stay in `_fluent_comments_settings` and in core's options — see **Emails**.
- **Core Discussion settings** (`DiscussionSettings`): a chosen handful of WordPress's own options — the word lists, the hold rules, threading, who may comment, the two core notification toggles — read and written **in place** (but not `require_name_email`, see above) via `get_option`/`update_option`, never copied into our option. Core enforces every one of them in `wp_allow_comment()`/`check_comment()` regardless of which form a comment came from, so this screen and Settings › Discussion cannot disagree. `update_option()` runs core's `sanitize_option()`, which trims and dedupes the word lists — which is why the save response returns a fresh `DiscussionSettings::get()` and the UI replaces its state with it rather than keeping what was typed. The UI marks every one of these with a `WordPress` chip.
- **Branding:** the product is **FluentComments**, one word, everywhere it is shown to a user. `fluent-comments` stays the slug, text domain, and shortcode prefix.
- **CSS theming:** All public styles use `--fcom-*` CSS custom properties defined on `:root` in `resources/sass/app.scss`; the Gutenberg block overrides these via inline styles per-instance
- **`Arr` helper:** Laravel-derived static utility for dot-notation array access, used throughout for safe settings retrieval
- **AJAX endpoints:** `fluent_comment_session`, `fluent_comment_post`, `fluent_comment_list` — all public (`nopriv`), gated by `SpamGuard` rather than by a nonce
