<?php

namespace GrocersList\Blocks;

/**
 * What a content gate hides, and where a post is cut for it.
 *
 * A gate hides everything below it in the post (mode "below") or only the
 * blocks inside it ("inside"). A gate saved before there was a mode hides
 * the blocks inside it when it holds any, and everything below it when it
 * holds nothing but empty paragraphs, as a freshly inserted gate does: mode()
 * is that rule, and blocks/src/content-gate/mode.ts follows it in the editor.
 *
 * "Below" means something only at the top level of a post's content. There,
 * before do_blocks() renders anything, cut() moves every block after the
 * first such gate inside it, so the gate's own render decides who sees them,
 * exactly as for the blocks inside a gate: ContentGateBlock::render() is the
 * one place that says who sees what, and for a visitor the moved blocks are
 * never rendered at all. A gate inside another block, or inside a synced
 * pattern, hides only its own inner blocks, whatever its mode says.
 * GateContent and GatedRecipeLinks read a post's gates through the same
 * cut; a post's automatic excerpt is made only from what is above the gate;
 * and the pages of a post split with page breaks after the gate stay on the
 * page the gate is on, where the gate hides them.
 */
class GateRegion
{
    public const BELOW = 'below';
    public const INSIDE = 'inside';

    /**
     * The attribute cut() sets on the gate it moved the rest of the post
     * into. Never saved; it only tells the gate's render that it hides
     * everything below it.
     */
    public const CUT = 'cut';

    /**
     * What every delimiter of a gate holds, whatever its white space:
     * WordPress's block parser reads one from "<!--", any white space and
     * "wp:", and before 6.8 nothing rewrites the delimiters before the cut.
     * Content without it holds no gate and is not parsed.
     */
    public const DELIMITER = 'wp:grocerslist/content-gate';

    private const PAGE_BREAK = '<!--nextpage-->';

    /** @var array<int, int> The posts whose automatic excerpt is being made now, innermost last (0 for none). */
    private array $excerpts = [];

    public function register(): void
    {
        add_filter('the_content', [$this, 'cutContent'], 8);
        add_filter('get_the_excerpt', [$this, 'startExcerpt'], 9, 2);
        add_filter('get_the_excerpt', [$this, 'endExcerpt'], 11, 2);
        add_filter('content_pagination', [$this, 'pages'], 10, 2);
    }

    /**
     * A gate's mode: its own when that is "below" or "inside", else what a
     * gate saved without one does.
     *
     * @param array<string, mixed> $attrs
     * @param array<int, mixed> $innerBlocks Parsed blocks.
     */
    public static function mode(array $attrs, array $innerBlocks): string
    {
        $mode = $attrs['mode'] ?? null;
        if ($mode === self::BELOW || $mode === self::INSIDE) {
            return $mode;
        }

        foreach ($innerBlocks as $block) {
            if (is_array($block) && !self::isEmpty($block)) {
                return self::INSIDE;
            }
        }

        return self::BELOW;
    }

    /**
     * The top-level blocks of a post's content with every block after the
     * first gate whose mode is "below" moved inside that gate, after its
     * own inner blocks, and the gate marked CUT; null when there is no such
     * gate. Text between blocks (classic content) moves as text. The gate's
     * own empty paragraphs (a placeholder saved before there was a mode) go:
     * they hide nothing, and revealed they would only be an empty line.
     *
     * @param array<int, mixed> $blocks Parsed blocks.
     * @return array<int, mixed>|null
     */
    public static function cut(array $blocks): ?array
    {
        $blocks = array_values($blocks);
        $at = self::belowGateIndex($blocks);
        if ($at === null) {
            return null;
        }

        $gate = $blocks[$at];
        $own = isset($gate['innerBlocks']) && is_array($gate['innerBlocks']) ? array_values($gate['innerBlocks']) : [];
        $content = isset($gate['innerContent']) && is_array($gate['innerContent']) && $gate['innerContent'] !== []
            ? $gate['innerContent']
            : array_fill(0, count($own), null);
        $inner = [];
        $chunks = [];
        $index = 0;
        foreach ($content as $chunk) {
            if (is_string($chunk)) {
                $chunks[] = $chunk;
                continue;
            }

            $block = $own[$index] ?? null;
            $index++;
            if (is_array($block) && !self::isEmpty($block)) {
                $inner[] = $block;
                $chunks[] = null;
            }
        }

        foreach (array_slice($blocks, $at + 1) as $block) {
            if (!is_array($block)) {
                continue;
            }

            if (($block['blockName'] ?? null) === null) {
                $chunks[] = isset($block['innerHTML']) && is_string($block['innerHTML']) ? $block['innerHTML'] : '';
                continue;
            }

            $inner[] = $block;
            $chunks[] = null;
        }

        $attrs = isset($gate['attrs']) && is_array($gate['attrs']) ? $gate['attrs'] : [];
        $gate['attrs'] = array_merge($attrs, ['mode' => self::BELOW, self::CUT => true]);
        $gate['innerBlocks'] = $inner;
        $gate['innerContent'] = $chunks;
        $gate['innerHTML'] = implode('', array_filter($chunks, 'is_string'));

        return array_merge(array_slice($blocks, 0, $at), [$gate]);
    }

    /**
     * the_content, before do_blocks(): the post cut at its first top-level
     * "below" gate. The markup of the blocks it hides stays in the string,
     * inside the gate, which renders none of it for a visitor.
     *
     * @param mixed $content
     * @return mixed
     */
    public function cutContent($content)
    {
        if (!is_string($content)
            || strpos($content, self::DELIMITER) === false
            || !function_exists('parse_blocks')
            || !function_exists('serialize_blocks')) {
            return $content;
        }

        $cut = self::cut(parse_blocks($content));

        return $cut === null ? $content : serialize_blocks($cut);
    }

    /**
     * get_the_excerpt, before wp_trim_excerpt() makes an automatic excerpt
     * at 10: while it does, pages() gives it the post only down to its gate.
     * wp_trim_excerpt() leaves out the gate block, but not the blocks below
     * it, which would put up to 55 of the words the gate hides in archives,
     * feeds, embeds and the REST API's excerpt.rendered.
     *
     * @param mixed $text
     * @param mixed $post WP_Post, from WordPress 4.5.
     * @return mixed
     */
    public function startExcerpt($text, $post = null)
    {
        $gated = is_string($text) && trim($text) === ''
            && is_object($post) && isset($post->ID, $post->post_content)
            && strpos((string) $post->post_content, self::DELIMITER) !== false;

        $this->excerpts[] = $gated ? (int) $post->ID : 0;

        return $text;
    }

    /**
     * @param mixed $text
     * @param mixed $post
     * @return mixed
     */
    public function endExcerpt($text, $post = null)
    {
        array_pop($this->excerpts);

        return $text;
    }

    /**
     * content_pagination: a post's pages, from its content split at its page
     * breaks. The pages after the one holding the post's first top-level
     * "below" gate go onto that page, below the gate, which hides them (a
     * visitor asking for a later page gets that page). While an automatic
     * excerpt is being made, that page ends where the gate starts instead.
     *
     * @param mixed $pages
     * @param mixed $post
     * @return mixed
     */
    public function pages($pages, $post = null)
    {
        $excerpt = is_object($post) && isset($post->ID) && $this->excerpts !== []
            && in_array((int) $post->ID, $this->excerpts, true);

        if (!is_array($pages)
            || (count($pages) < 2 && !$excerpt)
            || !function_exists('parse_blocks')
            || !function_exists('serialize_blocks')) {
            return $pages;
        }

        $pages = array_values($pages);
        foreach ($pages as $index => $page) {
            if (!is_string($page) || strpos($page, self::DELIMITER) === false) {
                continue;
            }

            $blocks = array_values(parse_blocks($page));
            $at = self::belowGateIndex($blocks);
            if ($at === null) {
                continue;
            }

            $page = $excerpt
                ? serialize_blocks(array_slice($blocks, 0, $at))
                : implode(self::PAGE_BREAK, array_slice($pages, $index));

            return array_merge(array_slice($pages, 0, $index), [$page]);
        }

        return $pages;
    }

    /**
     * @param array<int, mixed> $blocks
     */
    private static function belowGateIndex(array $blocks): ?int
    {
        foreach ($blocks as $index => $block) {
            if (!is_array($block) || ($block['blockName'] ?? null) !== ContentGateBlock::BLOCK_NAME) {
                continue;
            }

            $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : [];
            $inner = isset($block['innerBlocks']) && is_array($block['innerBlocks']) ? $block['innerBlocks'] : [];

            if (self::mode($attrs, $inner) === self::BELOW) {
                return $index;
            }
        }

        return null;
    }

    /**
     * An empty paragraph (the editor's placeholder saves as <p></p>), or the
     * white space between two blocks: nothing a gate could hide.
     *
     * @param array<string, mixed> $block
     */
    private static function isEmpty(array $block): bool
    {
        $name = $block['blockName'] ?? null;
        $html = isset($block['innerHTML']) && is_string($block['innerHTML']) ? $block['innerHTML'] : '';

        if ($name === null) {
            return trim($html) === '';
        }

        return $name === 'core/paragraph'
            && preg_match('#^\s*<p\b[^>]*>(?:\s|&nbsp;|&\#160;|\xC2\xA0|<br\s*/?>)*</p>\s*$#i', $html) === 1;
    }
}
