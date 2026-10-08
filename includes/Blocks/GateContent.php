<?php

namespace GrocersList\Blocks;

/**
 * What one content gate hides, from the post's saved content, rendered as the
 * post's page would render it: through the_content, with the post as the
 * global post, so shortcodes, embeds, responsive images and every content
 * filter (the plugin's own link rewriting included) apply. GateController
 * answers a recognized subscriber with it, and FormsController the submit
 * that just made the visitor one.
 *
 * What a gate hides is its inner blocks, and for the first gate at the top
 * level of the post that hides everything below it, every block after it
 * too: the post is read through GateRegion::cut(), as the_content cuts it for
 * the page. Only that goes through the_content, never the whole post, so a
 * gate beside or around this one gives nothing away. It renders outside the
 * main query (is_singular() is false), so filters that append to a singular
 * post's content, like sharing buttons or related posts, leave it alone.
 */
class GateContent
{
    /** Synced patterns looked inside while searching for a gate, at most. */
    private const MAX_PATTERNS = 10;

    private static int $renderingPostId = 0;

    /**
     * The post a gate's content is being rendered for right now, else 0.
     * Meanwhile ContentGateBlock opens the gates nested in that content for a
     * recognized subscriber, and FormRenderer puts the forms in it on that
     * post, as on its page.
     */
    public static function renderingPostId(): int
    {
        return self::$renderingPostId;
    }

    /**
     * @return string|null The gate's content; null when the post is not
     *                     published, is private or has a password, is of a
     *                     type visitors cannot view, or holds no such gate.
     */
    public function render(int $postId, string $gateId): ?string
    {
        if ($postId <= 0
            || !ContentGateBlock::isGateId($gateId)
            || !function_exists('parse_blocks')
            || !function_exists('serialize_block')) {
            return null;
        }

        $post = get_post($postId);
        if (!self::isPublic($post)) {
            return null;
        }

        $patterns = [];
        $gate = self::find(self::cut(parse_blocks((string) $post->post_content)), $gateId, $patterns);
        if ($gate === null) {
            return null;
        }

        $markup = self::innerMarkup($gate);

        $previousPost = $GLOBALS['post'] ?? null;
        $previousRendering = self::$renderingPostId;

        $GLOBALS['post'] = $post;
        setup_postdata($post);
        self::$renderingPostId = $postId;

        try {
            return (string) apply_filters('the_content', $markup);
        } finally {
            self::$renderingPostId = $previousRendering;
            $GLOBALS['post'] = $previousPost;
            if (is_object($previousPost)) {
                setup_postdata($previousPost);
            }
        }
    }

    /**
     * The saved markup of everything the gates in a post's content hide — the
     * blocks inside every gate, in gates nested in others and in the synced
     * patterns used inside or outside a gate, and every block after the first
     * top-level gate that hides everything below it — each block once, with
     * its own delimiters and HTML, for GatedRecipeLinks to find the recipes a
     * gate hides by WP Recipe Maker's own rules.
     *
     * @param array<int, mixed> $blocks The post content's parsed blocks.
     */
    public static function gatedMarkup(array $blocks): string
    {
        if (!function_exists('serialize_block')) {
            return '';
        }

        $patterns = [];

        return self::collect(self::cut($blocks), false, $patterns);
    }

    /**
     * A post's top-level blocks as the_content cuts them for the page.
     *
     * @param array<int, mixed> $blocks
     * @return array<int, mixed>
     */
    private static function cut(array $blocks): array
    {
        return GateRegion::cut($blocks) ?? $blocks;
    }

    /**
     * @param array<int, mixed> $blocks
     * @param array<int, true> $patterns The synced patterns looked inside so far.
     */
    private static function collect(array $blocks, bool $inGate, array &$patterns): string
    {
        $markup = '';
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $name = $block['blockName'] ?? null;
            $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : [];

            if ($name === 'core/block') {
                $markup .= self::collect(self::pattern($attrs['ref'] ?? null, $patterns), $inGate, $patterns);
                continue;
            }

            $inner = isset($block['innerBlocks']) && is_array($block['innerBlocks']) ? $block['innerBlocks'] : [];

            // The block alone, its inner blocks left to the walk: a synced
            // pattern among them is expanded too.
            if ($inGate) {
                $own = $block;
                $own['innerBlocks'] = [];
                $own['innerContent'] = array_values(array_filter(
                    isset($block['innerContent']) && is_array($block['innerContent']) ? $block['innerContent'] : [],
                    'is_string'
                ));
                $markup .= serialize_block($own);
            }

            $markup .= self::collect($inner, $inGate || $name === ContentGateBlock::BLOCK_NAME, $patterns);
        }

        return $markup;
    }

    /**
     * @param mixed $post
     */
    private static function isPublic($post): bool
    {
        if (!is_object($post)
            || !isset($post->post_status, $post->post_type)
            || $post->post_status !== 'publish'
            || (string) ($post->post_password ?? '') !== '') {
            return false;
        }

        // An object, not the name: before WordPress 5.9 it took nothing else.
        $type = get_post_type_object($post->post_type);

        return is_object($type) && is_post_type_viewable($type);
    }

    /**
     * The first gate with $gateId, looking inside every block: in the gates
     * nested in others, and in the synced patterns the content uses (a gate
     * in one renders on the post that uses it).
     *
     * @param array<int, mixed> $blocks Parsed blocks.
     * @param array<int, true> $patterns The synced patterns looked inside so far.
     * @return array<string, mixed>|null
     */
    private static function find(array $blocks, string $gateId, array &$patterns): ?array
    {
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $name = $block['blockName'] ?? null;
            $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : [];

            if ($name === ContentGateBlock::BLOCK_NAME && ($attrs['gateId'] ?? null) === $gateId) {
                return $block;
            }

            $inner = $name === 'core/block'
                ? self::pattern($attrs['ref'] ?? null, $patterns)
                : (isset($block['innerBlocks']) && is_array($block['innerBlocks']) ? $block['innerBlocks'] : []);

            $found = $inner ? self::find($inner, $gateId, $patterns) : null;
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * A synced pattern's blocks, when it is published and has not been looked
     * inside already (a pattern can hold itself, or another that holds it).
     *
     * @param mixed $ref
     * @param array<int, true> $patterns
     * @return array<int, mixed>
     */
    private static function pattern($ref, array &$patterns): array
    {
        $ref = is_numeric($ref) ? (int) $ref : 0;
        if ($ref <= 0 || isset($patterns[$ref]) || count($patterns) >= self::MAX_PATTERNS) {
            return [];
        }

        $patterns[$ref] = true;
        $pattern = get_post($ref);

        return is_object($pattern)
            && ($pattern->post_type ?? '') === 'wp_block'
            && ($pattern->post_status ?? '') === 'publish'
            && (string) ($pattern->post_password ?? '') === ''
            ? parse_blocks((string) $pattern->post_content)
            : [];
    }

    /**
     * The gate's inner blocks (for a gate the post was cut at, the rest of
     * the post) as saved markup, block delimiters included, so the_content
     * renders them the way it renders a post: do_blocks() sees blocks and
     * keeps wpautop() off them.
     *
     * @param array<string, mixed> $gate
     */
    private static function innerMarkup(array $gate): string
    {
        $inner = isset($gate['innerBlocks']) && is_array($gate['innerBlocks']) ? $gate['innerBlocks'] : [];
        $chunks = isset($gate['innerContent']) && is_array($gate['innerContent']) ? $gate['innerContent'] : [];

        $markup = '';
        $index = 0;
        foreach ($chunks as $chunk) {
            if (is_string($chunk)) {
                $markup .= $chunk;
                continue;
            }

            if (isset($inner[$index]) && is_array($inner[$index])) {
                $markup .= serialize_block($inner[$index]);
            }
            $index++;
        }

        return $markup;
    }
}
