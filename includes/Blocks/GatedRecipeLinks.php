<?php

namespace GrocersList\Blocks;

use GrocersList\Support\WprmRecipes;

/**
 * Leaves out WP Recipe Maker's Jump to Recipe, Jump to Video and Print
 * Recipe buttons for a recipe inside a content gate: the snippet WPRM adds
 * above a post, and those buttons wherever the post has them outside the
 * gate. For a visitor the gate is locked, so they would jump to a card the
 * page does not have, and Print Recipe prints the whole recipe. The page is
 * the same for everyone a page cache serves, so they go for everyone; the
 * revealed card brings its own. Inside the gate — in the gate route, or where
 * an editor or a let-through visitor sees the content in the page — they
 * stay.
 *
 * WPRM's own filters do it: wprm_recipe_snippet_shortcode_output for a snippet
 * in its modern template mode, and wprm_recipe_{jump,jump_video,print}_shortcode
 * for each button, which is what a legacy-mode snippet is made of.
 */
class GatedRecipeLinks
{
    /** WPRM's filter for each button's HTML; it passes ($output, $atts, $recipe). */
    private const BUTTON_FILTERS = [
        'wprm_recipe_jump_shortcode',
        'wprm_recipe_jump_video_shortcode',
        'wprm_recipe_print_shortcode',
    ];

    private WprmRecipes $wprm;

    /** @var array<int, array<int, int>> The recipes each post's gates hide, found once per request. */
    private array $gated = [];

    public function __construct(?WprmRecipes $wprm = null)
    {
        $this->wprm = $wprm ?? new WprmRecipes();
    }

    public function register(): void
    {
        add_filter('wprm_recipe_snippet_shortcode_output', [$this, 'snippet'], 10, 3);

        foreach (self::BUTTON_FILTERS as $filter) {
            add_filter($filter, [$this, 'button'], 10, 3);
        }
    }

    /**
     * @param mixed $output
     * @param mixed $atts
     * @param mixed $recipeId
     * @return mixed
     */
    public function snippet($output, $atts = [], $recipeId = 0)
    {
        return is_numeric($recipeId) && $this->gatedHere((int) $recipeId) ? '' : $output;
    }

    /**
     * @param mixed $output
     * @param mixed $atts
     * @param mixed $recipe WPRM_Recipe
     * @return mixed
     */
    public function button($output, $atts = [], $recipe = null)
    {
        $recipeId = is_object($recipe) && method_exists($recipe, 'id') ? (int) $recipe->id() : 0;

        return $this->gatedHere($recipeId) ? '' : $output;
    }

    private function gatedHere(int $recipeId): bool
    {
        if ($recipeId <= 0 || ContentGateBlock::renderingInner() || GateContent::renderingPostId() > 0) {
            return false;
        }

        $postId = (int) get_the_ID();

        return $postId > 0 && in_array($recipeId, $this->gatedRecipeIds($postId), true);
    }

    /**
     * @return array<int, int>
     */
    private function gatedRecipeIds(int $postId): array
    {
        if (!array_key_exists($postId, $this->gated)) {
            $post = get_post($postId);
            $content = is_object($post) && isset($post->post_content) ? (string) $post->post_content : '';

            // A post with neither a gate nor a synced pattern cannot hide a
            // recipe, and most posts are that: no parse for them. Only the
            // block names are looked for, as the parser takes any white
            // space in a delimiter (GateRegion::DELIMITER).
            $mayHide = strpos($content, GateRegion::DELIMITER) !== false
                || strpos($content, 'wp:block') !== false
                || strpos($content, 'wp:core/block') !== false;

            $this->gated[$postId] = $mayHide && function_exists('parse_blocks')
                ? $this->wprm->recipeIdsIn(GateContent::gatedMarkup(parse_blocks($content)))
                : [];
        }

        return $this->gated[$postId];
    }
}
