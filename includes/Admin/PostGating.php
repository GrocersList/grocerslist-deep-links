<?php

namespace GrocersList\Admin;

use GrocersList\Support\Hooks;

class PostGating
{
    private Hooks $hooks;

    private const META_POST_GATED = 'grocers_list_post_gated';
    private const META_RECIPE_CARD_GATED = 'grocers_list_recipe_card_gated';

    public function __construct(Hooks $hooks)
    {
        $this->hooks = $hooks;
    }

    public function register(): void
    {
        $this->hooks->addAction('add_meta_boxes', [$this, 'addMetaBoxes']);
        $this->hooks->addAction('save_post', [$this, 'savePostMeta']);
        $this->hooks->addAction('init', [$this, 'registerPostMeta']);
    }

    public function registerPostMeta(): void
    {
        register_post_meta('post', self::META_POST_GATED, [
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'auth_callback' => function () {
                return current_user_can('edit_posts');
            }
        ]);

        register_post_meta('post', self::META_RECIPE_CARD_GATED, [
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'auth_callback' => function () {
                return current_user_can('edit_posts');
            }
        ]);
    }

    public function addMetaBoxes(): void
    {
        add_meta_box(
            'grocers_list_gating_options',
            'Grocers List Gating Options',
            [$this, 'renderMetaBox'],
            'post',
            'side',
            'default'
        );
    }

    public function renderMetaBox($post): void
    {
        wp_nonce_field('grocers_list_gating_options', 'grocers_list_gating_nonce');

        $post_gated = get_post_meta($post->ID, self::META_POST_GATED, true);
        $recipe_card_gated = get_post_meta($post->ID, self::META_RECIPE_CARD_GATED, true);
        ?>
        <div class="grocers-list-gating-options">
            <p>
                <label>
                    <input type="checkbox" id="grocers_list_post_gated" name="grocers_list_post_gated" value="1" <?php checked($post_gated, '1'); ?> />
                    Post Gated
                </label>
            </p>
            <p style="margin-left: 20px;">
                <label>
                    <input type="checkbox" id="grocers_list_recipe_card_gated" name="grocers_list_recipe_card_gated" value="1" <?php checked($recipe_card_gated, '1'); ?> />
                    Recipe Card Gated
                </label>
            </p>
        </div>
        <script type="text/javascript">
            (function() {
                var postGatedCheckbox = document.getElementById('grocers_list_post_gated');
                var recipeCardGatedCheckbox = document.getElementById('grocers_list_recipe_card_gated');

                // When Post Gated is checked, Recipe Card Gated should also be checked
                postGatedCheckbox.addEventListener('change', function() {
                    if (this.checked) {
                        recipeCardGatedCheckbox.checked = true;
                    }
                });

                // Initial check - if Post Gated is checked, Recipe Card Gated should also be checked
                if (postGatedCheckbox.checked) {
                    recipeCardGatedCheckbox.checked = true;
                }
            })();
        </script>
        <?php
    }

    public function savePostMeta($post_id): void
    {
        if (!isset($_POST['grocers_list_gating_nonce'])) {
            return;
        }

        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['grocers_list_gating_nonce'])), 'grocers_list_gating_options')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && constant('DOING_AUTOSAVE')) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $post_gated = isset($_POST['grocers_list_post_gated']) ? '1' : '0';
        update_post_meta($post_id, self::META_POST_GATED, sanitize_text_field($post_gated));

        // If post is gated, recipe card should also be gated
        if ($post_gated === '1') {
            $recipe_card_gated = '1';
        } else {
            $recipe_card_gated = isset($_POST['grocers_list_recipe_card_gated']) ? '1' : '0';
        }

        update_post_meta($post_id, self::META_RECIPE_CARD_GATED, sanitize_text_field($recipe_card_gated));
    }

    public static function isPostGated($post_id): bool
    {
        return get_post_meta($post_id, self::META_POST_GATED, true) === '1';
    }

    public static function isRecipeCardGated($post_id): bool
    {
        return get_post_meta($post_id, self::META_RECIPE_CARD_GATED, true) === '1';
    }
}
