<?php

namespace GrocersList\Admin;

use GrocersList\Support\Config;

class PostGating
{
    private const META_POST_GATED = 'grocers_list_post_gated';
    private const META_RECIPE_CARD_GATED = 'grocers_list_recipe_card_gated';
    private const META_CATEGORY_GATING_TYPE = 'grocers_list_category_gating_type';
    private const META_NO_GATING = 'grocers_list_no_gating';

    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'addMetaBoxes']);
        add_action('save_post', [$this, 'savePostMeta']);
        add_action('init', [$this, 'registerPostMeta']);
        add_action('category_add_form_fields', [$this, 'addCategoryFields']);
        add_action('category_edit_form_fields', [$this, 'editCategoryFields'], 10, 2);
        add_action('created_category', [$this, 'saveCategoryFields']);
        add_action('edited_category', [$this, 'saveCategoryFields']);
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

        register_post_meta('post', self::META_NO_GATING, [
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
            'Grocers List Membership Options',
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
        $no_gating = get_post_meta($post->ID, self::META_NO_GATING, true);
        
        // Determine current gating option
        $current_option = 'by_category';
        if ($no_gating === '1') {
            $current_option = 'no_gating';
        } elseif ($post_gated === '1') {
            $current_option = 'post';
        } elseif ($recipe_card_gated === '1') {
            $current_option = 'recipe';
        }

        // Check if category-level gating is set
        $category_gating_info = '';
        $inherited_gating_text = '';
        $has_category_gating = false;
        $categories = get_the_category($post->ID);
        
        if (!empty($categories)) {
            foreach ($categories as $category) {
                $category_gating = get_term_meta($category->term_id, self::META_CATEGORY_GATING_TYPE, true);
                if (!empty($category_gating) && $category_gating !== 'none') {
                    $has_category_gating = true;
                    $gating_label = $category_gating === 'post' ? 'entire post' : 'recipe cards only';
                    $category_gating_info .= sprintf(
                        '<li>Category "<strong>%s</strong>" gates %s</li>',
                        esc_html($category->name),
                        esc_html($gating_label)
                    );
                    
                    // Set the inherited text (use first category with gating)
                    if (empty($inherited_gating_text)) {
                        $inherited_gating_text = sprintf(
                            ' <span style="color: #2271b1; font-size: 12px;">(will gate %s from "%s")</span>',
                            esc_html($gating_label),
                            esc_html($category->name)
                        );
                    }
                }
            }
        }
        
        // If no category gating, show that too
        if (!$has_category_gating) {
            $inherited_gating_text = ' <span style="color: #666; font-size: 12px;">(no gating from categories)</span>';
        }
        ?>
        <div class="grocers-list-gating-options">
            <p><strong>Post-Level Gating Options:</strong></p>
            <p>
                <label>
                    <input type="radio" name="grocers_list_gating_option" value="by_category" <?php checked($current_option, 'by_category'); ?> />
                    Inherit from category (default)<?php echo $inherited_gating_text; ?>
                </label>
            </p>
            <p>
                <label>
                    <input type="radio" name="grocers_list_gating_option" value="no_gating" <?php checked($current_option, 'no_gating'); ?> />
                    Do not gate
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

            <?php if (!empty($category_gating_info)) : ?>
                <hr style="margin: 15px 0;">
                <p><strong>Category-Level Gating:</strong></p>
                <ul style="margin-left: 20px; font-size: 12px; color: #666;">
                    <?php echo $category_gating_info; ?>
                </ul>
                <p style="font-size: 12px; color: #666;">
                    <em>Note: Post-level settings override category settings.</em>
                </p>
            <?php endif; ?>
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

        $gating_option = isset($_POST['grocers_list_gating_option']) ? sanitize_text_field($_POST['grocers_list_gating_option']) : 'by_category';
        
        // Set meta values based on radio selection
        switch ($gating_option) {
            case 'no_gating':
                $post_gated = '0';
                $recipe_card_gated = '0';
                $no_gating = '1';
                break;
            case 'post':
                $post_gated = '1';
                $recipe_card_gated = '0';
                $no_gating = '0';
                break;
            case 'recipe':
                $post_gated = '0';
                $recipe_card_gated = '1';
                $no_gating = '0';
                break;
            case 'by_category':
            default:
                $post_gated = '0';
                $recipe_card_gated = '0';
                $no_gating = '0';
                break;
        }

        update_post_meta($post_id, self::META_POST_GATED, $post_gated);
        update_post_meta($post_id, self::META_RECIPE_CARD_GATED, $recipe_card_gated);
        update_post_meta($post_id, self::META_NO_GATING, $no_gating);
    }

    public static function isPostGated($post_id): bool
    {
        return get_post_meta($post_id, self::META_POST_GATED, true) === '1';
    }

    public static function isRecipeCardGated($post_id): bool
    {
        return get_post_meta($post_id, self::META_RECIPE_CARD_GATED, true) === '1';
    }

    /**
     * Add gating fields to category creation form
     * 
     * @return void
     */
    public function addCategoryFields(): void
    {
        // If external JS URL is not set, don't show the fields
        $externalJsUrl = Config::getExternalJsUrl();
        if (empty($externalJsUrl)) {
            return;
        }
        ?>
        <div class="form-field">
            <label for="grocers_list_category_gating_type">
                <?php esc_html_e('Grocers List Membership Gating', 'grocers-list'); ?>
            </label>
            <select name="grocers_list_category_gating_type" id="grocers_list_category_gating_type">
                <option value="none"><?php esc_html_e('Do not gate (default)', 'grocers-list'); ?></option>
                <option value="post"><?php esc_html_e('Gate entire post', 'grocers-list'); ?></option>
                <option value="recipe"><?php esc_html_e('Gate recipe cards only', 'grocers-list'); ?></option>
            </select>
            <p class="description">
                <?php esc_html_e('Apply gating to all posts in this category. Individual post settings will override this.', 'grocers-list'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Add gating fields to category edit form
     * 
     * @param \WP_Term $term Current taxonomy term object
     * @return void
     */
    public function editCategoryFields($term): void
    {
        // If external JS URL is not set, don't show the fields
        $externalJsUrl = Config::getExternalJsUrl();
        if (empty($externalJsUrl)) {
            return;
        }

        $gating_type = get_term_meta($term->term_id, self::META_CATEGORY_GATING_TYPE, true);
        if (empty($gating_type)) {
            $gating_type = 'none';
        }
        ?>
        <tr class="form-field">
            <th scope="row">
                <label for="grocers_list_category_gating_type">
                    <?php esc_html_e('Grocers List Membership Gating', 'grocers-list'); ?>
                </label>
            </th>
            <td>
                <select name="grocers_list_category_gating_type" id="grocers_list_category_gating_type">
                    <option value="none" <?php selected($gating_type, 'none'); ?>>
                        <?php esc_html_e('No gating (default)', 'grocers-list'); ?>
                    </option>
                    <option value="post" <?php selected($gating_type, 'post'); ?>>
                        <?php esc_html_e('Gate entire post', 'grocers-list'); ?>
                    </option>
                    <option value="recipe" <?php selected($gating_type, 'recipe'); ?>>
                        <?php esc_html_e('Gate recipe cards only', 'grocers-list'); ?>
                    </option>
                </select>
                <p class="description">
                    <?php esc_html_e('Apply gating to all posts in this category. Individual post settings will override this.', 'grocers-list'); ?>
                </p>
            </td>
        </tr>
        <?php
    }

    /**
     * Save category gating fields
     * 
     * @param int $term_id Term ID
     * @return void
     */
    public function saveCategoryFields(int $term_id): void
    {
        if (!isset($_POST['grocers_list_category_gating_type'])) {
            return;
        }

        $gating_type = sanitize_text_field($_POST['grocers_list_category_gating_type']);
        
        // Validate the gating type
        if (!in_array($gating_type, ['none', 'post', 'recipe'], true)) {
            $gating_type = 'none';
        }

        update_term_meta($term_id, self::META_CATEGORY_GATING_TYPE, $gating_type);
    }

    /**
     * Get the effective gating type for a post
     * Checks post-level settings first, then falls back to category-level settings
     * 
     * @param int $post_id Post ID
     * @return array{post: bool, recipe: bool} Array with 'post' and 'recipe' gating status
     */
    public static function getEffectiveGating(int $post_id): array
    {
        // Check if gating is explicitly disabled at post level
        $no_gating = get_post_meta($post_id, self::META_NO_GATING, true);
        if ($no_gating === '1') {
            return ['post' => false, 'recipe' => false];
        }

        // Check post-level settings
        $post_gated = get_post_meta($post_id, self::META_POST_GATED, true);
        $recipe_card_gated = get_post_meta($post_id, self::META_RECIPE_CARD_GATED, true);

        // If post has explicit gating set, use that
        if ($post_gated === '1') {
            return ['post' => true, 'recipe' => false];
        }
        if ($recipe_card_gated === '1') {
            return ['post' => false, 'recipe' => true];
        }

        // Otherwise, check category-level settings
        $categories = get_the_category($post_id);
        if (empty($categories)) {
            return ['post' => false, 'recipe' => false];
        }
        // Initialize the default post gating to false
        $resolved_post_gating = ['post' => false, 'recipe' => false];
        // Check each category for gating settings
        // If any category has gating enabled, apply it
        foreach ($categories as $category) {
            $category_gating = get_term_meta($category->term_id, self::META_CATEGORY_GATING_TYPE, true);
            // If the category has post gating enabled, return the post gating
            if ($category_gating === 'post') {
                return ['post' => true, 'recipe' => false];
            }

            if ($category_gating === 'recipe') {
                $resolved_post_gating = ['post' => false, 'recipe' => true];
            }
        }
        // Return the resolved post gating, honoring recipe card category settings
        return $resolved_post_gating;
    }
}
