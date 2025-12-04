<?php

namespace GrocersList\Admin;

use GrocersList\Support\Config;

class PageGating
{
    private const META_PAGE_GATED = 'grocers_list_page_gated';
    private const META_NO_GATING = 'grocers_list_page_no_gating';
    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'addMetaBoxes']);
        add_action('save_post', [$this, 'savePageMeta']);
        add_action('init', [$this, 'registerPageMeta']);
    }

    public function registerPageMeta(): void
    {
        register_post_meta('page', self::META_PAGE_GATED, [
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'auth_callback' => function () {
                return current_user_can('edit_pages');
            }
        ]);

        register_post_meta('page', self::META_NO_GATING, [
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'auth_callback' => function () {
                return current_user_can('edit_pages');
            }
        ]);
    }

    public function addMetaBoxes(): void
    {
        $externalJsUrl = Config::getExternalJsUrl();
        if (empty($externalJsUrl)) {
            return;
        }

        add_meta_box(
            'grocers_list_page_gating_options',
            'Grocers List Membership Options',
            [$this, 'renderMetaBox'],
            'page',
            'side',
            'default'
        );
    }

    public function renderMetaBox($post): void
    {
        wp_nonce_field('grocers_list_page_gating_options', 'grocers_list_page_gating_nonce');

        $page_gated = get_post_meta($post->ID, self::META_PAGE_GATED, true);
        $no_gating = get_post_meta($post->ID, self::META_NO_GATING, true);

        $current_option = 'no_gating';
        if ($no_gating === '1') {
            $current_option = 'no_gating';
        }

        if ($page_gated === '1') {
            $current_option = 'page';
        }

        ?>
        <div class="grocers-list-gating-options">
            <p><strong>Page-Level Gating Options:</strong></p>
            <p>
                <label>
                    <input type="radio" name="grocers_list_page_gating_option" value="no_gating" <?php checked($current_option, 'no_gating'); ?> />
                    Do not gate
                </label>
            </p>
            <p>
                <label>
                    <input type="radio" name="grocers_list_page_gating_option" value="page" <?php checked($current_option, 'page'); ?> />
                    Gate entire page
                </label>
            </p>
        </div>
        <?php
    }

    public function savePageMeta($post_id): void
    {
        if (get_post_type($post_id) !== 'page') {
            return;
        }

        if (!isset($_POST['grocers_list_page_gating_nonce'])) {
            return;
        }

        if (!wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST['grocers_list_page_gating_nonce'])),
            'grocers_list_page_gating_options'
        )) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && constant('DOING_AUTOSAVE')) {
            return;
        }

        if (!current_user_can('edit_page', $post_id)) {
            return;
        }

        $gating_option = isset($_POST['grocers_list_page_gating_option'])
            ? sanitize_text_field($_POST['grocers_list_page_gating_option'])
            : 'default';

        switch ($gating_option) {
            case 'page':
                $page_gated = '1';
                $no_gating = '0';
                break;
            case 'no_gating':
                $page_gated = '0';
                $no_gating = '1';
                break;
            default:
                $page_gated = '0';
                $no_gating = '1';
                break;
        }

        update_post_meta($post_id, self::META_PAGE_GATED, $page_gated);
        update_post_meta($post_id, self::META_NO_GATING, $no_gating);
    }

    public static function isPageGated($page_id): bool
    {
        return get_post_meta($page_id, self::META_PAGE_GATED, true) === '1';
    }

    /**
     * Determine gating for a page. Pages do not inherit category settings,
     * so we only consider page-level configuration.
     *
     * @param int $page_id Page ID
     * @return array{page: bool}
     */
    public static function getEffectiveGating(int $page_id): array
    {
        $defaults = ['page' => false];

        if (self::isPageGated($page_id)) {
            return ['page' => true];
        }
        
        return $defaults;
    }
}


