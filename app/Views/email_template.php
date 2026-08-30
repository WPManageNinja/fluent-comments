<?php
defined('ABSPATH') || exit;
/**
 * The frame every FluentComments email is rendered into.
 *
 * The colours land twice on purpose. The structure - the page ground, the
 * card, the footer - carries them as inline styles, because that is what
 * every mail client honours. The <style> block below repeats them for the
 * pieces that live inside the body, which the site owner can edit and which
 * we therefore cannot reach with an inline style: a blockquote they add, a
 * button they paste in.
 *
 * FluentAuth solves the same problem by running the whole document through
 * Emogrifier. That is 60KB of vendored library for a plugin with no
 * autoloader, to inline styles on markup we already control - so the frame
 * is inlined by hand instead, and the head block covers the rest.
 *
 * @var array $template_config
 * @var string $body
 * @var string $footer
 */

$fcomLogo = trim((string)$template_config['logo']);
$fcomAccent = $template_config['accent_color'];
?>
<!DOCTYPE html>
<html dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>" lang="<?php echo esc_attr(get_bloginfo('language')); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta content="text/html; charset=UTF-8" http-equiv="Content-Type">
    <meta name="x-apple-disable-message-reformatting">
    <style>
        body {
            margin: 0;
            padding: 0;
            line-height: 1.5;
        }

        .body_wrap {
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif, "Apple Color Emoji", "Segoe UI Emoji";
            background-color: <?php echo esc_html($template_config['body_bg']); ?>;
        }

        .content_wrap {
            background-color: <?php echo esc_html($template_config['content_bg']); ?>;
            color: <?php echo esc_html($template_config['content_color']); ?>;
        }

        p, ul, li {
            font-size: 16px;
            line-height: 24px;
            margin: 0 0 16px;
        }

        a {
            color: <?php echo esc_html($fcomAccent); ?>;
        }

        blockquote {
            background-color: <?php echo esc_html($template_config['highlight_bg']); ?>;
            color: <?php echo esc_html($template_config['highlight_color']); ?>;
            padding: 16px 20px;
            border-radius: 6px;
            border-left: 4px solid <?php echo esc_html($fcomAccent); ?>;
            margin: 24px 0;
            font-style: italic;
        }

        blockquote p {
            color: <?php echo esc_html($template_config['highlight_color']); ?>;
            font-size: 15px;
            margin: 0;
            font-style: italic;
        }

        .fcom_btn {
            background-color: <?php echo esc_html($fcomAccent); ?> !important;
            color: #ffffff !important;
        }

        hr {
            border: none;
            border-top: 1px solid #e5e7eb;
            margin: 28px 0;
        }

        .footer_table, .footer_text {
            color: <?php echo esc_html($template_config['footer_content_color']); ?>;
            font-size: 12px;
            line-height: 18px;
            text-align: center;
        }
    </style>
    <?php do_action('fluent_comments/email_head'); ?>
</head>
<body style="margin:0;padding:0;background-color:<?php echo esc_attr($template_config['body_bg']); ?>;">
<div class="body_wrap" style="background-color:<?php echo esc_attr($template_config['body_bg']); ?>;">
    <table align="center" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation"
           style="margin-left:auto;margin-right:auto;padding:32px 16px;max-width:600px">
        <tbody>
        <tr style="width:100%">
            <td>
                <?php
                /*
                 * Rendered whether or not there is a logo, so the design
                 * screen can drop one in and see it without re-rendering
                 * the whole email server side. The spacing rides on the
                 * image rather than the cell, so an empty one costs
                 * nothing.
                 */
                ?>
                <table align="center" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation"
                       style="text-align:center">
                    <tbody>
                    <tr>
                        <td class="flc_preview_logo" style="text-align:center">
                            <?php if ($fcomLogo) : ?>
                                <a href="<?php echo esc_url(home_url()); ?>" target="_blank">
                                    <img src="<?php echo esc_url($fcomLogo); ?>"
                                         alt="<?php echo esc_attr(get_bloginfo('name')); ?>"
                                         style="max-width:180px;height:auto;display:inline-block;border:0;margin-bottom:20px"/>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    </tbody>
                </table>

                <table class="content_wrap" align="center" width="100%" border="0" cellpadding="0" cellspacing="0"
                       role="presentation"
                       style="border-radius:8px;padding:32px;background-color:<?php echo esc_attr($template_config['content_bg']); ?>;color:<?php echo esc_attr($template_config['content_color']); ?>;">
                    <tbody>
                    <tr>
                        <td>
                            <?php
                            // Written by an administrator on the email screen and run
                            // through wp_kses_post() on the way in.
                            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                            echo $body;
                            ?>
                        </td>
                    </tr>
                    </tbody>
                </table>

                <table class="footer_table" align="center" width="100%" border="0" cellpadding="0" cellspacing="0"
                       role="presentation"
                       style="margin-top:24px;text-align:center;color:<?php echo esc_attr($template_config['footer_content_color']); ?>;">
                    <tbody>
                    <tr>
                        <td class="footer_text"
                            style="font-size:12px;line-height:18px;text-align:center;color:<?php echo esc_attr($template_config['footer_content_color']); ?>;">
                            <?php if (!empty($footer)) : ?>
                                <?php echo wp_kses_post($footer); ?>
                            <?php else : ?>
                                <?php echo esc_html(get_bloginfo('name')); ?><br/>
                                <a href="<?php echo esc_url(home_url()); ?>"
                                   style="color:<?php echo esc_attr($template_config['footer_content_color']); ?>;"
                                   target="_blank"><?php echo esc_html(home_url()); ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </td>
        </tr>
        </tbody>
    </table>
</div>
</body>
</html>
