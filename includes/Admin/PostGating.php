<?php

namespace GrocersList\Admin;

use GrocersList\Support\Config;
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
        // If external JS URL is not set, don't show the meta box
        $externalJsUrl = Config::getExternalJsUrl();
        if (empty($externalJsUrl)) {
            return;
        }

        add_meta_box(
            'grocers_list_gating_options',
            'Membership Options',
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
        
        // Determine current gating option
        $current_option = 'none';
        if ($post_gated === '1') {
            $current_option = 'post';
        } elseif ($recipe_card_gated === '1') {
            $current_option = 'recipe';
        }
        ?>
        <div class="grocers-list-gating-options">
            <p><strong>Gating Options:</strong></p>
            <p>
                <label>
                    <input type="radio" name="grocers_list_gating_option" value="none" <?php checked($current_option, 'none'); ?> />
                    No gating
                </label>
            </p>
            <p>
                <label>
                    <input type="radio" name="grocers_list_gating_option" value="post" <?php checked($current_option, 'post'); ?> />
                    Gate entire post
                </label>
            </p>
            <p>
                <label>
                    <input type="radio" name="grocers_list_gating_option" value="recipe" <?php checked($current_option, 'recipe'); ?> />
                    Gate recipe cards only
                </label>
            </p>
           
        </div>
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

        $gating_option = isset($_POST['grocers_list_gating_option']) ? sanitize_text_field($_POST['grocers_list_gating_option']) : 'none';
        
        // Set meta values based on radio selection
        switch ($gating_option) {
            case 'post':
                $post_gated = '1';
                $recipe_card_gated = '0';
                break;
            case 'recipe':
                $post_gated = '0';
                $recipe_card_gated = '1';
                break;
            case 'none':
            default:
                $post_gated = '0';
                $recipe_card_gated = '0';
                break;
        }

        update_post_meta($post_id, self::META_POST_GATED, $post_gated);
        update_post_meta($post_id, self::META_RECIPE_CARD_GATED, $recipe_card_gated);
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
