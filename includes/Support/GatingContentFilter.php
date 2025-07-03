<?php

namespace GrocersList\Support;

use GrocersList\Admin\PostGating;

class GatingContentFilter
{
    private Hooks $hooks;

    public function __construct(Hooks $hooks)
    {
        $this->hooks = $hooks;
    }

    public function register(): void
    {
        $this->hooks->addFilter('the_content', [$this, 'filterContent'], 20); // TODO figure out priority for filter
    }

    public function filterContent(string $content): string
    {
        if (!is_singular('post')) {
            return $content;
        }

        $post_id = get_the_ID();
        $is_post_gated = PostGating::isPostGated($post_id);
        $is_recipe_card_gated = PostGating::isRecipeCardGated($post_id);

        // Only add data attributes without actually gating the content
        // Client-side code will handle the gating based on these attributes
        $content = '<div class="grocers-list-content" data-post-gated="' . ($is_post_gated ? 'true' : 'false') . '" data-recipe-card-gated="' . ($is_recipe_card_gated ? 'true' : 'false') . '">' . $content . '</div>';

        return $content;
    }

    private function gateEntireContent(string $content): string
    {
        // TODO this is incomplete
        return '<div class="grocers-list-gated-content">' .
            '<div class="grocers-list-paywall">' .
            '<h3>This content is gated</h3>' .
            '<p>Please subscribe to access this content.</p>' .
            '<button class="grocers-list-subscribe-button">Subscribe Now</button>' .
            '</div>' .
            '<div class="grocers-list-hidden-content" style="display: none;">' . $content . '</div>' .
            '</div>';
    }

    private function gateRecipeCardContent(string $content): string
    {
        $pattern = '/<div class="wp-block-recipe[^>]*>(.*?)<\/div>/s';
        $replacement = '<div class="wp-block-recipe grocers-list-gated-recipe">' .
            '<div class="grocers-list-paywall">' .
            '<h3>This recipe is gated</h3>' .
            '<p>Please subscribe to access this recipe.</p>' .
            '<button class="grocers-list-subscribe-button">Subscribe Now</button>' .
            '</div>' .
            '<div class="grocers-list-hidden-content" style="display: none;">$1</div>' .
            '</div>';

        $content = preg_replace($pattern, $replacement, $content);

        $shortcodes = ['recipe', 'tasty-recipe', 'wp-recipe-maker', 'wpurp-recipe'];

        foreach ($shortcodes as $shortcode) {
            $pattern = '/\[' . $shortcode . '(.*?)\](.*?)\[\/' . $shortcode . '\]/s';
            $replacement = '<div class="grocers-list-gated-recipe">' .
                '<div class="grocers-list-paywall">' .
                '<h3>This recipe is gated</h3>' .
                '<p>Please subscribe to access this recipe.</p>' .
                '<button class="grocers-list-subscribe-button">Subscribe Now</button>' .
                '</div>' .
                '<div class="grocers-list-hidden-content" style="display: none;">[$1]$2[/' . $shortcode . ']</div>' .
                '</div>';

            $content = preg_replace($pattern, $replacement, $content);
        }

        return $content;
    }
}
