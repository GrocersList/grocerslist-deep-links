<?php

namespace GrocersList\Blocks;

use GrocersList\Support\WprmRecipes;

/**
 * A locked gate hides a WP Recipe Maker recipe in the page and leaves its
 * ingredients and steps in the page's structured data, where anyone who views
 * source can read them: WPRM builds that JSON-LD from the post's recipes, not
 * from the rendered page, so the gate never sees it.
 *
 * A gate can ask (hideRecipeMetadata, off by default) for the ingredients and
 * the steps to be left out while it is locked. Everything else stays byte for
 * byte — name, picture, summary, times, yield, rating, reviews and the
 * nutrition facts included — so the page is still a Recipe to anything reading
 * the data, with two of Google's recommended properties missing.
 *
 * Everything on a hook path here is static, and that is not a style choice.
 * From WPRM 10.7.0 the metadata is cached across requests in the post meta
 * wprm_metadata_cache, under a hash that includes the registered callbacks'
 * ids; WordPress makes an object callback's id spl_object_hash($obj) . $method,
 * which is a per-request handle. An instance callback would therefore miss the
 * hash on most requests and run update_post_meta() — a database write on a
 * public GET, for every recipe on the site, gated or not.
 *
 * cacheEnabled() takes the recipes a post's gates hide out of that cache
 * altogether, whether the gate asks or not: the decision below depends on the
 * request (who is asking, the preview flag, the bypass filter) and so must
 * never be frozen into post meta.
 */
final class GatedRecipeMetadata
{
    private static ?WprmRecipes $wprm = null;

    /**
     * The gates that hide each recipe, by post then recipe.
     *
     * @var array<int, array<int, array<int, array{id: string, asks: bool}>>>
     */
    private static array $hiding = [];

    /** @var array<string, bool> "postId|gateId" => whether that gate locks the page */
    private static array $locks = [];

    public function __construct(?WprmRecipes $wprm = null)
    {
        self::$wprm = $wprm ?? new WprmRecipes();
    }

    public function register(): void
    {
        // A fresh registration is a fresh request.
        self::$hiding = [];
        self::$locks = [];

        add_filter('wprm_recipe_metadata', [self::class, 'metadata'], 10, 2);
        add_filter('wprm_recipe_metadata_cache_enabled', [self::class, 'cacheEnabled'], 10, 2);
    }

    /**
     * @param mixed $metadata WPRM's metadata array.
     * @param mixed $recipe WPRM_Recipe.
     * @return mixed
     */
    public static function metadata($metadata, $recipe = null)
    {
        if (!is_array($metadata)) {
            return $metadata;
        }

        $postId = (int) get_the_ID();
        $recipeId = self::recipeId($recipe);
        if ($postId <= 0 || $recipeId <= 0 || !self::hide($postId, $recipeId)) {
            return $metadata;
        }

        unset(
            $metadata['recipeIngredient'],
            $metadata['recipeInstructions'],
            $metadata['supply'],
            $metadata['step'],
            $metadata['tool']
        );

        if (isset($metadata['video']) && is_array($metadata['video'])) {
            unset($metadata['video']['hasPart']);
            if ($metadata['video'] === []) {
                unset($metadata['video']);
            }
        }

        return $metadata;
    }

    /**
     * WPRM's cross-request metadata cache, off for every recipe any gate on
     * this post hides — whether that gate asks or not, and whoever is asking.
     *
     * Scoped to any hiding gate rather than to the asking ones on purpose: the
     * public filter can turn suppression on with every switch off, so any
     * recipe the answer could differ for has to stay out of the cache. What is
     * left is request-independent, which is what makes the rest safe.
     *
     * @param mixed $enabled
     * @param mixed $recipe WPRM_Recipe.
     * @return mixed
     */
    public static function cacheEnabled($enabled, $recipe = null)
    {
        $postId = (int) get_the_ID();
        $recipeId = self::recipeId($recipe);
        if ($postId <= 0 || $recipeId <= 0) {
            return $enabled;
        }

        // Another plugin's false is left alone; only a hiding gate forces one.
        return self::hidingGates($postId, $recipeId) === [] ? $enabled : false;
    }

    private static function hide(int $postId, int $recipeId): bool
    {
        // A reveal is being served to a recognized subscriber, or the gate's
        // own inner blocks are in the page: either way the content is there.
        if (GateContent::renderingPostId() > 0 || ContentGateBlock::renderingInner()) {
            return false;
        }

        $gates = self::hidingGates($postId, $recipeId);
        if ($gates === []) {
            return false;
        }

        $hide = true;
        foreach ($gates as $gate) {
            if (!$gate['asks'] || !self::locks($postId, $gate['id'])) {
                $hide = false;
                break;
            }
        }

        return apply_filters(
            'grocerslist_suppress_gated_recipe_metadata',
            $hide,
            $postId,
            $recipeId,
            array_column($gates, 'id')
        ) === true;
    }

    private static function locks(int $postId, string $gateId): bool
    {
        $key = $postId . '|' . $gateId;
        if (!array_key_exists($key, self::$locks)) {
            self::$locks[$key] = ContentGateBlock::locksInPage($postId, $gateId);
        }

        return self::$locks[$key];
    }

    /**
     * Every gate on the post that hides this recipe, each with whether it asks
     * for the metadata to go. Every one of them, not only the asking ones: a
     * recipe two gates hide is published unless both ask and both lock, and
     * the public filter is told about both either way.
     *
     * @return array<int, array{id: string, asks: bool}>
     */
    private static function hidingGates(int $postId, int $recipeId): array
    {
        if (!array_key_exists($postId, self::$hiding)) {
            self::$hiding[$postId] = self::readGates($postId);
        }

        return self::$hiding[$postId][$recipeId] ?? [];
    }

    /**
     * @return array<int, array<int, array{id: string, asks: bool}>>
     */
    private static function readGates(int $postId): array
    {
        $post = get_post($postId);
        $content = is_object($post) && isset($post->post_content) ? (string) $post->post_content : '';

        // A post with neither a gate nor a synced pattern cannot hide a
        // recipe, and most posts are that: no parse for them. The parse_blocks
        // conjunct is the WordPress 4.4 floor — the strpos test alone can pass
        // on a classic post whose content holds the literal "wp:block".
        $mayHide = strpos($content, GateRegion::DELIMITER) !== false
            || strpos($content, 'wp:block') !== false
            || strpos($content, 'wp:core/block') !== false;
        if (!$mayHide || !function_exists('parse_blocks')) {
            return [];
        }

        $gates = [];
        foreach (GateContent::gatesIn(parse_blocks($content)) as $gate) {
            $attrs = $gate['attrs'];

            // Coerced, never dropped, as ContentGateBlock::render() coerces
            // it: a gate whose id GateContent::render() refuses can never be
            // revealed, so it locks for good and its recipe must be suppressed
            // — dropping it would publish the ingredient list instead.
            $id = ContentGateBlock::isGateId($attrs['gateId'] ?? null) ? (string) $attrs['gateId'] : '';
            $asks = ($attrs['hideRecipeMetadata'] ?? null) === true;

            foreach (self::recipes()->recipeIdsIn($gate['markup']) as $recipeId) {
                $gates[$recipeId][] = ['id' => $id, 'asks' => $asks];
            }
        }

        return $gates;
    }

    /**
     * @param mixed $recipe
     */
    private static function recipeId($recipe): int
    {
        return is_object($recipe) && method_exists($recipe, 'id') ? (int) $recipe->id() : 0;
    }

    private static function recipes(): WprmRecipes
    {
        if (self::$wprm === null) {
            self::$wprm = new WprmRecipes();
        }

        return self::$wprm;
    }
}
