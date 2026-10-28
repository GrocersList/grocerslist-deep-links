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
     * The recipe's name, full-size image and parent post. With $details, also
     * what a WPRM that has them says of it: its course, cuisine and keyword
     * names, total time in minutes, average rating and how many rated it, and
     * servings, each left out when this WPRM cannot say. Only a Digest post
     * asks for those: they cost three term lookups, and WPRM's rating() can
     * write the rating meta of a recipe nobody has rated yet — nothing a
     * visitor's form submission should do.
     *
     * @return array{name: string, imageUrl: string, parentPostId: int, courses?: array<int, string>, cuisines?: array<int, string>, keywords?: array<int, string>, totalTimeMinutes?: int, rating?: float, ratingCount?: int, servings?: int}|null
     */
    public function recipe(int $recipeId, int $publicPostId, bool $details = false): ?array
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

        $data = [
            'name'         => method_exists($recipe, 'name') ? (string) $recipe->name() : '',
            'imageUrl'     => method_exists($recipe, 'image_url') ? (string) $recipe->image_url('full') : '',
            'parentPostId' => method_exists($recipe, 'parent_post_id') ? (int) $recipe->parent_post_id() : 0,
        ];

        if (!$details) {
            return $data;
        }

        if (self::has($recipe, 'tags')) {
            foreach (['courses' => 'course', 'cuisines' => 'cuisine', 'keywords' => 'keyword'] as $key => $tag) {
                $data[$key] = self::termNames($recipe->tags($tag));
            }
        }

        $minutes = self::has($recipe, 'total_time') ? $recipe->total_time() : null;
        if (is_numeric($minutes) && (int) $minutes > 0) {
            $data['totalTimeMinutes'] = (int) $minutes;
        }

        // WPRM's wprm_rating meta: ['count' => …, 'total' => …, 'average' => …].
        $rating = self::has($recipe, 'rating') ? $recipe->rating() : null;
        $count = is_array($rating) && isset($rating['count']) && is_numeric($rating['count']) ? (int) $rating['count'] : 0;
        $average = is_array($rating) && isset($rating['average']) && is_numeric($rating['average']) ? (float) $rating['average'] : 0.0;
        if ($count > 0 && $average > 0 && $average <= 5) {
            $data['rating'] = round($average, 2);
            $data['ratingCount'] = $count;
        }

        $servings = self::has($recipe, 'servings') ? $recipe->servings() : null;
        if (is_numeric($servings) && (int) $servings > 0) {
            $data['servings'] = (int) $servings;
        }

        return $data;
    }

    /**
     * The post a recipe is in, by WPRM's own record (its parent post), or 0.
     */
    public function parentPostId(int $recipeId): int
    {
        $manager = static::RECIPE_MANAGER;
        if ($recipeId <= 0 || !self::available($manager, 'get_recipe')) {
            return 0;
        }

        $recipe = $manager::get_recipe($recipeId);

        return is_object($recipe) && self::has($recipe, 'parent_post_id') ? (int) $recipe->parent_post_id() : 0;
    }

    private static function available(string $class, string $method): bool
    {
        return class_exists($class) && is_callable([$class, $method]);
    }

    /**
     * @param object $recipe
     */
    private static function has($recipe, string $method): bool
    {
        return method_exists($recipe, $method) && is_callable([$recipe, $method]);
    }

    /**
     * @param mixed $terms WPRM's terms for one of its tag taxonomies.
     * @return array<int, string>
     */
    private static function termNames($terms): array
    {
        $names = [];

        foreach (is_array($terms) ? $terms : [] as $term) {
            if (is_object($term) && isset($term->name)) {
                $names[] = (string) $term->name;
            } elseif (is_string($term)) {
                $names[] = $term;
            }
        }

        return $names;
    }
}
