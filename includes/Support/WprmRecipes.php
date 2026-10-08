<?php

namespace GrocersList\Support;

/**
 * The WP Recipe Maker calls the GRO forms and content gates make. WPRM is
 * optional, so each call first checks that its class and method exist, and
 * answers "no recipe" when they do not.
 */
class WprmRecipes
{
    protected const RECIPE_MANAGER = 'WPRM_Recipe_Manager';
    protected const TEMPLATE_SHORTCODES = 'WPRM_Template_Shortcodes';
    protected const CONTEXT = 'WPRM_Context';

    /**
     * WPRM builds its print page inside the wprm_print_output filter and,
     * since WPRM 7, marks the request with a "print" context.
     */
    public function isPrintView(): bool
    {
        if (function_exists('doing_filter') && doing_filter('wprm_print_output')) {
            return true;
        }

        $context = static::CONTEXT;

        return self::available($context, 'get') && $context::get() === 'print';
    }

    /**
     * The recipe whose card is rendering right now — WPRM sets it while a
     * card's template runs its shortcodes — or, outside a card, WPRM's own
     * pick of the first recipe in the current post.
     */
    public function currentRecipeId(): int
    {
        $shortcodes = static::TEMPLATE_SHORTCODES;

        return self::available($shortcodes, 'get_current_recipe_id')
            ? (int) $shortcodes::get_current_recipe_id()
            : 0;
    }

    public function firstRecipeIdIn(int $postId): int
    {
        $manager = static::RECIPE_MANAGER;
        if ($postId <= 0 || !self::available($manager, 'get_recipe_ids_from_post')) {
            return 0;
        }

        $ids = $manager::get_recipe_ids_from_post($postId);

        return is_array($ids) && $ids ? (int) reset($ids) : 0;
    }

    /**
     * The recipes WPRM finds in $content by its own rules: recipe blocks,
     * [wprm-recipe] shortcodes and the other ways a recipe goes in a post.
     *
     * @return array<int, int>
     */
    public function recipeIdsIn(string $content): array
    {
        $manager = static::RECIPE_MANAGER;
        if ($content === '' || !self::available($manager, 'get_recipe_ids_from_content')) {
            return [];
        }

        $ids = $manager::get_recipe_ids_from_content($content);

        return is_array($ids) ? array_values(array_unique(array_filter(array_map('intval', $ids)))) : [];
    }

    /**
     * @return array{name: string, imageUrl: string, parentPostId: int}|null
     */
    public function recipe(int $recipeId, int $publicPostId): ?array
    {
        $manager = static::RECIPE_MANAGER;
        if ($recipeId <= 0 || !self::available($manager, 'get_recipe')) {
            return null;
        }

        // The second argument (WPRM 10.8.4+; older versions ignore it) lets a
        // recipe whose own post is not public resolve through the public post
        // that embeds it — the case for an anonymous REST request.
        $recipe = $manager::get_recipe($recipeId, $publicPostId);
        if (!is_object($recipe)) {
            return null;
        }

        return [
            'name'         => method_exists($recipe, 'name') ? (string) $recipe->name() : '',
            'imageUrl'     => method_exists($recipe, 'image_url') ? (string) $recipe->image_url('full') : '',
            'parentPostId' => method_exists($recipe, 'parent_post_id') ? (int) $recipe->parent_post_id() : 0,
        ];
    }

    private static function available(string $class, string $method): bool
    {
        return class_exists($class) && is_callable([$class, $method]);
    }
}
