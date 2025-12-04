<?php

namespace GrocersList\Admin;

use GrocersList\Support\Config;

class CategoryGating
{
    public const META_CATEGORY_GATING_TYPE = 'grocers_list_category_gating_type';

    public function register(): void
    {
        add_action('category_add_form_fields', [$this, 'addCategoryFields']);
        add_action('category_edit_form_fields', [$this, 'editCategoryFields'], 10, 2);
        add_action('created_category', [$this, 'saveCategoryFields']);
        add_action('edited_category', [$this, 'saveCategoryFields']);
    }

    /**
     * Add gating fields to category creation form
     */
    public function addCategoryFields(): void
    {
        $externalJsUrl = Config::getExternalJsUrl();
        if (empty($externalJsUrl)) {
            return;
        }
        ?>
        <div class="form-field">
            <?php echo $this->renderCategoryGatingFieldset(); ?>
        </div>
        <?php
    }

    /**
     * Add gating fields to category edit form
     *
     * @param \WP_Term $term Current taxonomy term object
     */
    public function editCategoryFields($term): void
    {
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
                <?php echo $this->renderCategoryGatingFieldset($gating_type); ?>
            </td>
        </tr>
        <?php
    }

    /**
     * Save category gating fields
     *
     * @param int $term_id Term ID
     */
    public function saveCategoryFields(int $term_id): void
    {
        if (!isset($_POST['grocers_list_category_gating_type'])) {
            return;
        }

        $gating_type = sanitize_text_field($_POST['grocers_list_category_gating_type']);

        if (!in_array($gating_type, ['none', 'post', 'recipe', 'page'], true)) {
            $gating_type = 'none';
        }

        update_term_meta($term_id, self::META_CATEGORY_GATING_TYPE, $gating_type);
    }

    private static function renderCategoryGatingFieldset(string $selected = 'none'): string
    {
        ob_start();
        ?>
        <label for="grocers_list_category_gating_type">
            <?php esc_html_e('Grocers List Membership Gating', 'grocers-list'); ?>
        </label>
        <select name="grocers_list_category_gating_type" id="grocers_list_category_gating_type">
            <option value="none" <?php selected($selected, 'none'); ?>>
                <?php esc_html_e('Do not gate (default)', 'grocers-list'); ?>
            </option>
            <option value="post" <?php selected($selected, 'post'); ?>>
                <?php esc_html_e('Gate entire post', 'grocers-list'); ?>
            </option>
            <option value="recipe" <?php selected($selected, 'recipe'); ?>>
                <?php esc_html_e('Gate recipe cards only', 'grocers-list'); ?>
            </option>
            <option value="page" <?php selected($selected, 'page'); ?>>
                <?php esc_html_e('Gate category page, and all posts in this category', 'grocers-list'); ?>
            </option>
        </select>
        <p class="description">
            <?php esc_html_e('Apply gating to all posts and pages in this category. Individual post and page settings will override this.', 'grocers-list'); ?>
        </p>
        <?php
        return (string) ob_get_clean();
    }
    /**
     * Get the effective gating type for a category
     * 
     *
     * @param int $term_id Category term ID
     * @return array{category: bool} Array with 'category' gating status
     */
    public static function getEffectiveGating(int $term_id): array
    {
        $defaults = ['category' => false];
        $gating_type = get_term_meta($term_id, self::META_CATEGORY_GATING_TYPE, true);

        if (empty($gating_type)) {
            return $defaults;
        }
        
        if ($gating_type === 'none') {
            return $defaults;
        }
        /**
         * Only page gating is supported for now on category pages.
         */
        if ($gating_type === 'page') {
            return ['category' => true];
        }
    
        return ['category' => true];
    }
}

