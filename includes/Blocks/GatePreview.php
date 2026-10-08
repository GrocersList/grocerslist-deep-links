<?php

namespace GrocersList\Blocks;

/**
 * The picture of what a locked gate hides, blurred under the gate's card: the
 * hidden blocks' shape drawn with the theme's own elements — paragraphs,
 * lists — so that it reads as the post going on in the site's own type, with
 * boxes where pictures and a recipe card would be.
 *
 * A heading's shape is the one thing drawn without the theme's element: a div
 * carrying the level as a class, so the glimpse puts nothing in the page's
 * outline, with the size a heading would have inherited drawn by the
 * stylesheet instead.
 *
 * Only the shape is read: a block's name, a heading's level, how long a list
 * or a paragraph is, a picture's rough proportions. Nothing of the content
 * goes in: no word, attribute value, URL, image, alt text, data attribute or
 * script. A line is an empty span; the words it shows are filler the
 * stylesheet draws (assets/blocks/content-gate/style.css), so they are not
 * even in the page's text for a search engine to index.
 */
final class GatePreview
{
    /** About how many blocks the preview draws; the stylesheet caps its height as well. */
    private const MAX_UNITS = 8;

    /** A recipe card counts for this many. */
    private const RECIPE_UNITS = 3;

    private const MAX_LINES = 5;
    private const MAX_ITEMS = 5;
    private const MAX_TILES = 6;
    private const MAX_COLUMNS = 3;
    private const MAX_DEPTH = 8;

    /** Characters to a line, for how many lines a paragraph gets. */
    private const LINE_LENGTH = 90;

    /** A media box's aspect ratios (width / height), by class suffix. */
    private const RATIOS = [
        '1-1'  => 1.0,
        '4-3'  => 4 / 3,
        '3-2'  => 1.5,
        '16-9' => 16 / 9,
        '21-9' => 21 / 9,
        '3-4'  => 0.75,
        '2-3'  => 2 / 3,
        '9-16' => 9 / 16,
    ];

    private const LINE = '<span class="gl-gate__line"></span>';

    /** Blocks that are containers: their inner blocks are drawn in their place. */
    private const CONTAINERS = ['core/group', 'core/column', 'core/details', ContentGateBlock::BLOCK_NAME];

    /** Blocks drawn as a media box. */
    private const MEDIA = ['core/image', 'core/cover', 'core/video', 'core/embed'];

    /** Blocks drawn as paragraphs as long as their text. */
    private const TEXT = ['core/paragraph', 'core/freeform', 'core/verse', 'core/preformatted'];

    private int $units = 0;

    /**
     * @param array<string, mixed> $gate The gate's parsed block; for a gate
     *                                   the post was cut at, the rest of the
     *                                   post is inside it.
     */
    public static function render(array $gate): string
    {
        $html = (new self())->children($gate, 0);

        // A gate that hides nothing yet still looks like the post goes on.
        return $html !== '' ? $html : self::paragraph(4) . self::paragraph(3) . self::paragraph(4);
    }

    /**
     * A container's inner blocks and the text between them (classic content
     * the cut moved into a gate is text), in order.
     *
     * @param array<string, mixed> $block
     */
    private function children(array $block, int $depth): string
    {
        $inner = isset($block['innerBlocks']) && is_array($block['innerBlocks']) ? array_values($block['innerBlocks']) : [];
        $chunks = isset($block['innerContent']) && is_array($block['innerContent']) ? $block['innerContent'] : [];
        if ($chunks === []) {
            $chunks = array_fill(0, count($inner), null);
        }

        $html = '';
        $index = 0;
        foreach ($chunks as $chunk) {
            if ($this->units >= self::MAX_UNITS) {
                break;
            }

            if (is_string($chunk)) {
                $length = self::textLength($chunk);
                if ($length > 0 && $this->take(1)) {
                    $html .= self::paragraph(self::lines($length));
                }
                continue;
            }

            $child = $inner[$index] ?? null;
            $index++;
            if (is_array($child)) {
                $html .= $this->block($child, $depth);
            }
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function block(array $block, int $depth): string
    {
        $name = isset($block['blockName']) && is_string($block['blockName']) ? $block['blockName'] : null;
        $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : [];
        $html = isset($block['innerHTML']) && is_string($block['innerHTML']) ? $block['innerHTML'] : '';

        if ($name === null || in_array($name, self::TEXT, true)) {
            $length = self::textLength($html);

            return $length > 0 && $this->take(1) ? self::paragraph(self::lines($length)) : '';
        }

        if (in_array($name, self::CONTAINERS, true)) {
            return $depth < self::MAX_DEPTH ? $this->children($block, $depth + 1) : '';
        }

        if ($name === 'core/columns') {
            return $depth < self::MAX_DEPTH ? $this->columns($block, $depth + 1) : '';
        }

        if ($name === 'core/media-text') {
            return $depth < self::MAX_DEPTH ? $this->mediaText($block, $depth + 1) : '';
        }

        if ($name === 'core/spacer') {
            return '';
        }

        if (!$this->take($name === 'wp-recipe-maker/recipe' ? self::RECIPE_UNITS : 1)) {
            return '';
        }

        if ($name === 'core/heading') {
            $level = isset($attrs['level']) && is_numeric($attrs['level']) ? (int) $attrs['level'] : 2;
            $level = max(1, min(6, $level));

            return '<div class="gl-gate__heading gl-gate__heading--h' . $level . '">' . self::LINE . '</div>';
        }

        if ($name === 'core/list') {
            return self::items(!empty($attrs['ordered']) ? 'ol' : 'ul', self::count($block, 'core/list-item', '<li\b', self::MAX_ITEMS));
        }

        if ($name === 'core/quote' || $name === 'core/pullquote') {
            return '<blockquote class="wp-block-quote">' . self::paragraph(2) . '</blockquote>';
        }

        if ($name === 'core/separator') {
            return '<hr class="wp-block-separator">';
        }

        if ($name === 'core/gallery') {
            return self::gallery($block, $attrs);
        }

        if ($name === 'wp-recipe-maker/recipe') {
            return self::recipe();
        }

        if (in_array($name, self::MEDIA, true) || strpos($name, 'core-embed/') === 0) {
            return self::media(self::ratio($name, $attrs, $html));
        }

        return self::paragraph(2);
    }

    /**
     * @param array<string, mixed> $block
     */
    private function columns(array $block, int $depth): string
    {
        $html = '';
        $columns = 0;
        foreach (isset($block['innerBlocks']) && is_array($block['innerBlocks']) ? $block['innerBlocks'] : [] as $column) {
            if ($columns >= self::MAX_COLUMNS || $this->units >= self::MAX_UNITS) {
                break;
            }

            $inner = is_array($column) ? $this->children($column, $depth) : '';
            if ($inner !== '') {
                $html .= '<div class="gl-gate__column">' . $inner . '</div>';
                $columns++;
            }
        }

        return $html === '' ? '' : '<div class="gl-gate__columns">' . $html . '</div>';
    }

    /**
     * @param array<string, mixed> $block
     */
    private function mediaText(array $block, int $depth): string
    {
        if (!$this->take(1)) {
            return '';
        }

        $text = $this->children($block, $depth);

        return '<div class="gl-gate__columns">'
            . '<div class="gl-gate__column">' . self::media('4-3') . '</div>'
            . ($text !== '' ? '<div class="gl-gate__column">' . $text . '</div>' : '')
            . '</div>';
    }

    private function take(int $units): bool
    {
        if ($this->units >= self::MAX_UNITS) {
            return false;
        }

        $this->units += $units;

        return true;
    }

    private static function paragraph(int $lines): string
    {
        return '<p>' . str_repeat(self::LINE, $lines) . '</p>';
    }

    private static function items(string $tag, int $count): string
    {
        return '<' . $tag . '>' . str_repeat('<li>' . self::LINE . '</li>', $count) . '</' . $tag . '>';
    }

    private static function media(string $ratio): string
    {
        return '<div class="gl-gate__media gl-gate__media--' . $ratio . '"></div>';
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $attrs
     */
    private static function gallery(array $block, array $attrs): string
    {
        $tiles = self::count($block, 'core/image', '<img\b', self::MAX_TILES);
        $columns = isset($attrs['columns']) && is_numeric($attrs['columns']) ? (int) $attrs['columns'] : $tiles;
        $columns = max(1, min(self::MAX_COLUMNS, $columns, $tiles));

        return '<div class="gl-gate__gallery gl-gate__gallery--' . $columns . '">'
            . str_repeat(self::media('1-1'), $tiles)
            . '</div>';
    }

    private static function recipe(): string
    {
        return '<div class="gl-gate__recipe">'
            . '<div class="gl-gate__recipe-top">'
            . self::media('1-1')
            . '<div class="gl-gate__recipe-intro">'
            . '<p class="gl-gate__recipe-name">' . self::LINE . '</p>'
            . self::paragraph(3)
            . '</div>'
            . '</div>'
            . '<p class="gl-gate__recipe-label">' . self::LINE . '</p>'
            . self::items('ul', 5)
            . '<p class="gl-gate__recipe-label">' . self::LINE . '</p>'
            . self::items('ol', 4)
            . '</div>';
    }

    /**
     * How many of a block's items there are, from 1 to $max: its inner
     * blocks named $item, else the tags matching $tag in its HTML (the markup
     * older versions of the block saved).
     *
     * @param array<string, mixed> $block
     */
    private static function count(array $block, string $item, string $tag, int $max): int
    {
        $count = 0;
        foreach (isset($block['innerBlocks']) && is_array($block['innerBlocks']) ? $block['innerBlocks'] : [] as $inner) {
            if (is_array($inner) && ($inner['blockName'] ?? null) === $item) {
                $count++;
            }
        }

        if ($count === 0 && isset($block['innerHTML']) && is_string($block['innerHTML'])) {
            $count = (int) preg_match_all('#' . $tag . '#i', $block['innerHTML']);
        }

        return max(1, min($max, $count));
    }

    /**
     * The media box's shape, the known one nearest the block's proportions:
     * its aspect ratio, else its width and height, else those of its image,
     * else a photo's or a video's.
     *
     * @param array<string, mixed> $attrs
     */
    private static function ratio(string $name, array $attrs, string $html): string
    {
        $ratio = 0.0;

        if (isset($attrs['aspectRatio']) && is_string($attrs['aspectRatio'])
            && preg_match('#^\s*(\d+(?:\.\d+)?)\s*/\s*(\d+(?:\.\d+)?)\s*$#', $attrs['aspectRatio'], $matches) === 1
            && (float) $matches[2] > 0) {
            $ratio = (float) $matches[1] / (float) $matches[2];
        } elseif (self::dimension($attrs['width'] ?? null) > 0 && self::dimension($attrs['height'] ?? null) > 0) {
            $ratio = self::dimension($attrs['width']) / self::dimension($attrs['height']);
        } elseif (preg_match('#<img\b[^>]*\swidth=["\']?(\d+)#i', $html, $width) === 1
            && preg_match('#<img\b[^>]*\sheight=["\']?(\d+)#i', $html, $height) === 1
            && (int) $height[1] > 0) {
            $ratio = (int) $width[1] / (int) $height[1];
        } elseif (isset($attrs['className']) && is_string($attrs['className'])
            && preg_match('#\bwp-embed-aspect-(\d+)-(\d+)\b#', $attrs['className'], $matches) === 1
            && (int) $matches[2] > 0) {
            $ratio = (int) $matches[1] / (int) $matches[2];
        }

        if ($ratio <= 0) {
            return $name === 'core/image' ? '3-2' : '16-9';
        }

        $nearest = '3-2';
        $distance = INF;
        foreach (self::RATIOS as $suffix => $known) {
            $off = abs(log($ratio / $known));
            if ($off < $distance) {
                $nearest = $suffix;
                $distance = $off;
            }
        }

        return $nearest;
    }

    /**
     * A width or height as a block saves it: a number, or a string like
     * "640px"; 0 when it is neither.
     *
     * @param mixed $value
     */
    private static function dimension($value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        return is_string($value) && preg_match('#^\s*(\d+(?:\.\d+)?)(?:px)?\s*$#', $value, $matches) === 1
            ? (float) $matches[1]
            : 0.0;
    }

    private static function lines(int $length): int
    {
        return max(1, min(self::MAX_LINES, (int) ceil($length / self::LINE_LENGTH)));
    }

    /**
     * How much text there is in some HTML: its characters, give or take,
     * tags, comments and runs of white space aside.
     */
    private static function textLength(string $html): int
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');

        return strlen(trim((string) preg_replace('/\s+/', ' ', $text)));
    }
}
