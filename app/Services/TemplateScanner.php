<?php

namespace FluentComments\App\Services;

/**
 * On a block theme nothing is placed automatically, so the admin screen
 * would otherwise be asking the site owner to take our word for it. This
 * resolves the template each selected post type actually renders through
 * and looks for our block or our shortcode inside it.
 *
 * Templates nest: a Comments block usually lives in a template part, and
 * sometimes inside a pattern, so the walk follows both.
 */
class TemplateScanner
{
    const BLOCK_NAME = 'fluent-comments/comments';

    const SHORTCODE = 'fluent_comments';

    const CORE_COMMENT_BLOCKS = ['core/comments', 'core/post-comments'];

    /**
     * How deep template parts and patterns are followed before we give up.
     * Real templates nest two or three levels; anything past this is a
     * cycle we have not otherwise caught.
     */
    const MAX_DEPTH = 6;

    /**
     * One report per post type the site owner has enabled.
     *
     * Nothing is cached. The only caller is the settings screen asking over
     * ajax as it opens, which is exactly the moment a stale answer would be
     * worst - the site owner is looking at it because they just edited a
     * template. A cache here would need invalidating from the site editor,
     * from a theme switch and from our own save, and would still be read by
     * somebody who wants it fresh.
     *
     * @return array
     */
    public static function scan()
    {
        if (!Helper::isBlockTheme() || !function_exists('get_block_templates')) {
            return [];
        }

        $postTypes = Helper::getSetting('post_types', []);

        if (empty($postTypes) || !is_array($postTypes)) {
            return [];
        }

        $templates = self::getTemplatesBySlug();
        $results = [];

        foreach ($postTypes as $postType) {
            $object = get_post_type_object($postType);

            if (!$object) {
                continue;
            }

            $results[] = self::scanPostType($postType, $object->label, $templates);
        }

        return $results;
    }

    /**
     * @param string $postType
     * @param string $label
     * @param array $templates
     * @return array
     */
    private static function scanPostType($postType, $label, $templates)
    {
        $report = [
            'post_type'         => $postType,
            'label'             => $label,
            'template'          => '',
            'template_title'    => '',
            'edit_url'          => '',
            'has_block'         => false,
            'has_shortcode'     => false,
            'has_core_comments' => false,
        ];

        $template = self::resolveTemplate($postType, $templates);

        if (!$template) {
            return $report;
        }

        $report['template'] = $template->slug;
        $report['template_title'] = !empty($template->title) ? $template->title : $template->slug;
        // The postType/postId form, not the newer p=/wp_template/<id> path:
        // it is the only one WordPress 6.5 understands, and later versions
        // redirect it to the new one anyway.
        $report['edit_url'] = add_query_arg(
            [
                'postType' => 'wp_template',
                'postId'   => rawurlencode($template->id),
                'canvas'   => 'edit',
            ],
            admin_url('site-editor.php')
        );

        $found = self::walk($template->content, 0, []);

        return array_merge($report, $found);
    }

    /**
     * Which template a single entry of this post type renders through,
     * following the same hierarchy the front end would.
     *
     * @param string $postType
     * @param array $templates
     * @return \WP_Block_Template|null
     */
    private static function resolveTemplate($postType, $templates)
    {
        $slug = $postType === 'page' ? 'page' : 'single-' . $postType;

        $hierarchy = function_exists('get_template_hierarchy')
            ? get_template_hierarchy($slug)
            : [$slug, 'single', 'singular', 'index'];

        foreach ($hierarchy as $candidate) {
            if (isset($templates[$candidate])) {
                return $templates[$candidate];
            }
        }

        return null;
    }

    /**
     * @return array Slug => template. A customised template shadows the
     *               theme file of the same slug, which is the order
     *               get_block_templates() already returns them in.
     */
    private static function getTemplatesBySlug()
    {
        $bySlug = [];

        foreach (get_block_templates([], 'wp_template') as $template) {
            if (empty($template->slug) || isset($bySlug[$template->slug])) {
                continue;
            }

            $bySlug[$template->slug] = $template;
        }

        return $bySlug;
    }

    /**
     * @param string $content
     * @param int $depth
     * @param array $seen Template part and pattern ids already walked.
     * @return array
     */
    private static function walk($content, $depth, $seen)
    {
        $found = [
            'has_block'         => false,
            'has_shortcode'     => false,
            'has_core_comments' => false,
        ];

        if (!is_string($content) || $content === '' || $depth > self::MAX_DEPTH) {
            return $found;
        }

        // A shortcode can sit in a core/shortcode block or be typed straight
        // into a paragraph, and either way core expands it: do_shortcode()
        // runs over the template (block-template.php) and over every part
        // (_wp_apply_block_content_filters). So test the raw content.
        //
        // Not has_shortcode(), which returns false for a tag that is not in
        // the global registry yet. That would make the answer depend on
        // whether our own init hook had run before the scan.
        if (self::containsShortcode($content)) {
            $found['has_shortcode'] = true;
        }

        foreach (parse_blocks($content) as $block) {
            $found = self::mergeFindings($found, self::inspectBlock($block, $depth, $seen));
        }

        return $found;
    }

    /**
     * @param array $block
     * @param int $depth
     * @param array $seen
     * @return array
     */
    private static function inspectBlock($block, $depth, $seen)
    {
        $found = [
            'has_block'         => false,
            'has_shortcode'     => false,
            'has_core_comments' => false,
        ];

        $name = isset($block['blockName']) ? $block['blockName'] : '';

        if ($name === self::BLOCK_NAME) {
            $found['has_block'] = true;
        }

        if (in_array($name, self::CORE_COMMENT_BLOCKS, true)) {
            $found['has_core_comments'] = true;
        }

        if ($name === 'core/template-part') {
            $found = self::mergeFindings($found, self::walkTemplatePart($block, $depth, $seen));
        }

        if ($name === 'core/pattern') {
            $found = self::mergeFindings($found, self::walkPattern($block, $depth, $seen));
        }

        if (!empty($block['innerBlocks'])) {
            foreach ($block['innerBlocks'] as $inner) {
                $found = self::mergeFindings($found, self::inspectBlock($inner, $depth, $seen));
            }
        }

        return $found;
    }

    /**
     * @param array $block
     * @param int $depth
     * @param array $seen
     * @return array
     */
    private static function walkTemplatePart($block, $depth, $seen)
    {
        $slug = isset($block['attrs']['slug']) ? $block['attrs']['slug'] : '';

        if (!$slug || !function_exists('get_block_template')) {
            return [];
        }

        $theme = isset($block['attrs']['theme']) ? $block['attrs']['theme'] : get_stylesheet();
        $id = $theme . '//' . $slug;

        if (isset($seen[$id])) {
            return [];
        }

        $seen[$id] = true;
        $part = get_block_template($id, 'wp_template_part');

        if (!$part || empty($part->content)) {
            return [];
        }

        return self::walk($part->content, $depth + 1, $seen);
    }

    /**
     * @param array $block
     * @param int $depth
     * @param array $seen
     * @return array
     */
    private static function walkPattern($block, $depth, $seen)
    {
        $slug = isset($block['attrs']['slug']) ? $block['attrs']['slug'] : '';

        if (!$slug || !class_exists('\WP_Block_Patterns_Registry')) {
            return [];
        }

        $key = 'pattern:' . $slug;

        if (isset($seen[$key])) {
            return [];
        }

        $seen[$key] = true;
        $pattern = \WP_Block_Patterns_Registry::get_instance()->get_registered($slug);

        if (!$pattern || empty($pattern['content'])) {
            return [];
        }

        return self::walk($pattern['content'], $depth + 1, $seen);
    }

    /**
     * @param string $content
     * @return bool
     */
    private static function containsShortcode($content)
    {
        if (strpos($content, '[' . self::SHORTCODE) === false) {
            return false;
        }

        if (!function_exists('get_shortcode_regex')) {
            return true;
        }

        // Passing the tag explicitly builds the pattern from that name
        // rather than from whatever happens to be registered, and keeps
        // [fluent_comments_something_else] from matching.
        $pattern = get_shortcode_regex([self::SHORTCODE]);

        return (bool)preg_match('/' . $pattern . '/', $content);
    }

    /**
     * @param array $found
     * @param array $addition
     * @return array
     */
    private static function mergeFindings($found, $addition)
    {
        foreach (['has_block', 'has_shortcode', 'has_core_comments'] as $key) {
            if (!empty($addition[$key])) {
                $found[$key] = true;
            }
        }

        return $found;
    }
}
