=== FluentComments - Spam protection, AntiSpam, Ajax Enhanced Comments ===
Contributors: techjewel, wpmanageninja
Tags: comments, spam protection, better comments, ajax comments
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AJAX powered realtime comments. Designed to prevent spams, performance and make comments beautiful again 🚀

== Description ==

Fluent Comments is a better comment form and comment spam protection plugin. It is easy to use, and it works with classic themes and block (FSE) themes alike.
Designed to supercharge WordPress native comments with beautiful design, super fast, spam protection. Fluent Comments changes your site's commenting experience and provides you with user engagement features.

==Amazing Features==
- AJAX powered realtime comments
- Layered spam protection: signed submission tokens, a honeypot field, timing checks and per-IP rate limiting
- Intuitive Comment Form
- Beautiful Email Notifications for new comments to authors, admins and commenters
- Beautiful design and user experience
- Works with classic themes, block (FSE) themes and page builders
- Gutenberg block with colour and title options, plus a shortcode

==Upcoming Features==
- More Design Options

==Using with Block (FSE) Themes==
Fluent Comments works with block themes out of the box. It replaces the Comments block in your theme's templates automatically, so there is nothing to configure.

If you would rather place it yourself, turn off "Replace the theme's Comments block" in the settings and add the **Fluent Comments** block to your template, or use the `[fluent_comments]` shortcode.

== Other Plugins By WPManageNinja Team ==
<ul>
	<li><a href="https://wordpress.org/plugins/fluentform/" target="_blank">Fluent Forms – Fastest Contact Form Builder Plugin for WordPress</a></li>
	<li><a href="https://wordpress.org/plugins/ninja-tables/" target="_blank">Ninja Tables – Best WP DataTables Plugin for WordPress</a></li>
	<li><a href="https://wordpress.org/plugins/ninja-charts/" target="_blank">Ninja Charts – Best WP Charts Plugin for WordPress</a></li>
	<li><a href="https://wordpress.org/plugins/wp-payment-form/" target="_blank">WPPayForm - Stripe Payments Plugin for WordPress</a></li>
	<li><a href="https://wordpress.org/plugins/mautic-for-fluent-forms/" target="_blank">Mautic Integration For Fluent Forms</a></li>
	<li><a href="https://wordpress.org/plugins/fluentforms-pdf/" target="_blank">Fluent Forms PDF - PDF Entries for Fluent Forms</a></li>
	<li><a href="https://wordpress.org/plugins/fluent-smtp/" target="_blank">FluentSMTP - The Most Advanced SMTP, SES Plugin for WordPress</a></li>
</ul>

== CONTRIBUTE ==
If you want to contribute to this project or just report a bug, you are more than welcome. Please check repository from <a href="https://github.com/WPManageNinja/fluent-comments/">Github</a>.


== Installation ==

1. Install FluentComments either via the WordPress.org plugin repository or by uploading the files to your server.
2. Activate FluentComments from Plugins page.
3. That's it, no additional setup required. Enjoy the secure and beautiful comments in your WordPress.

== Frequently Asked Questions ==
= How does Fluent Comments prevent spam =

Fluent Comments layers several checks. Posting a comment requires a signed, short-lived token that is issued in a separate request, so a bot posting blindly to your site is turned away. On top of that there is a hidden honeypot field, a minimum time between opening the form and submitting it, and a per-IP limit on how many comments can be posted per hour. Together these stop the overwhelming majority of automated comment spam, and every threshold can be adjusted with a filter.

= I use another anti-spam or CAPTCHA plugin on my comment form =

Fluent Comments renders its own comment form, so a field another plugin wants to add, such as a CAPTCHA, is never printed on the page. Because that field cannot be filled in, its validator is skipped while a Fluent Comments submission is processed, rather than rejecting every comment on your site. Akismet keeps working normally, since it examines the comment itself and needs nothing from the form. If you want to keep another plugin's check, add its callback to the `fluent_comments/allowed_comment_hooks` filter, and render its field with the `fluent_comments/form_fields` action.

= Will it slow down my website =

Absolutely not. Fluent Comments is designed to be super fast and lightweight. It will not slow down your website.

= Is it compatible with all themes =

Yes, it is compatible with all themes. It will work with any theme.

== Screenshots ==
1. Comment List
2. Admin Panel
3. Notification Email on Comment Approval
4. Notification Email to Post Author
5. Notification Email to Commenters

== Changelog ==

= 2.1.0 (Date: Aug 29, 2026) =
**Please read before updating**
* The plugin's own REST API routes have been removed. Every request now goes through admin-ajax, which authenticates by cookie and needs no nonce, so a comment form served from a full page cache can no longer fail with a stale security token. If you built anything against the 2.0.0 REST routes, move it to the `fluent_comment_session`, `fluent_comment_post` and `fluent_comment_list` AJAX actions.
* Fluent Comments renders its own comment form and no longer fires the core `comment_form` hooks, which means another plugin's CAPTCHA or extra field is never rendered on the page. Running that plugin's validator anyway would reject every comment on your site over a field nobody could fill in, so those validators are skipped for the duration of a Fluent Comments submission. Akismet is unaffected: it inspects the comment itself and needs nothing from the form. See the FAQ if you rely on another anti-spam plugin.
* Added an extension API that works on both the classic and the block front end: the `fluent_comments/form_fields` action to render your own fields, `fluent_comments/validate_submission` to reject a submission, `fluent_comments/comment_data` to adjust the comment, and `fluent_comments/spam_score` to nudge the spam score.

**Block (FSE) theme support**
* Fluent Comments now replaces the Comments block in block theme templates automatically. No shortcode or template editing required.
* Fixed: activating the plugin on a block theme could leave posts with no working comment form. Native comment submissions are now only rejected where Fluent Comments actually renders a replacement.
* Added a "Replace the theme's Comments block" setting, plus an admin notice when it is turned off.

**Spam protection**
* Reworked the token system: HMAC signed tokens replace the previous AES tokens, and the block, shortcode and classic front ends now share a single protected submission path. Previously the block path had no spam protection at all on block themes.
* Tokens are bound to a session cookie and are single use, so one cannot be replayed or lifted from another visitor.
* Added a honeypot field, a minimum form fill time and rate limiting. Only comments that are actually created count toward the limit, so a mistyped email never locks anyone out.
* Rate limits are keyed per session first and per IP only as a backstop, so visitors sharing an office or CDN exit address are not counted as one person.
* Suspicious submissions are now held for moderation and scored rather than rejected outright. Only a conclusive failure is turned away.
* Comment posting is now POST only and tokens are fetched in a separate request, which closes a CSRF hole in the AJAX endpoint.
* Comments on draft, pending and private posts are no longer readable by visitors who cannot see the post.

**Fixes**
* Fixed: the settings screen was blank because its script and stylesheet were loaded from the wrong path.
* Fixed: comments submitted through the block or shortcode on a classic theme always failed with "Invalid Security Token".
* Fixed: comment timestamps were stored using server local time instead of UTC.
* Fixed: post authors received two notification emails for every comment posted through the classic form.
* Fixed: the block's "Show Avatars" option had no effect on the front end.
* Fixed: the comment form was shown on posts with comments closed.
* Fixed: the block rendered its title twice.
* Fixed: reply depth now follows the WordPress discussion settings instead of being hard coded to two levels.
* Fixed: a reply could be attached to a comment belonging to a different post.
* Fixed: pingbacks and trackbacks no longer appear in the comment list.

**Improvements**
* Comments are now paginated with a "Load more" button instead of loading every comment at once.
* The first page of comments is rendered into the page itself, so a visitor who only reads never triggers an uncached request.
* The whole front-end interface is translatable. Previously every string in the block and shortcode view was hard coded English.
* Added an uninstall routine that removes the plugin's options and transients.
* Escaping, sanitizing and internationalisation cleanups throughout.

= 2.0.0 (Date: Jul 07, 2025) =
- Added support for FSE Themes
- Added Email Notification for Admins, Authors and Commenters
- Added option to enable/disable email notification
- Advanced spam protection with cryptographic tokens

= 1.0.1 (Date: May 18, 2024) =
* Added shortcode support for FSE Themes
* Fixed minor CSS issues
* Checking Logged in requirement for comment form

= 1.0.0 (Date: Sep 30, 2023) =
* Initial release
