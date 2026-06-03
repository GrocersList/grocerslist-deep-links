<?php

namespace GrocersList\Admin;

use GrocersList\Support\Logger;
use GrocersList\Support\SalesPagePattern;

class SalesPage
{
    public const OPTION_PAGE_ID = 'grocerslist_sales_page_id';
    public const OPTION_MENU_ITEM_ID = 'grocerslist_sales_page_menu_item_id';

    private SalesPagePattern $pattern;

    public function __construct(SalesPagePattern $pattern)
    {
        $this->pattern = $pattern;
    }

    public function register(): void
    {
        add_action('init', [$this, 'registerPattern']);
    }

    public function registerPattern(): void
    {
        $this->pattern->registerPattern();
    }

    public function getState(): array
    {
        $pageId = (int) get_option(self::OPTION_PAGE_ID, 0);
        $menuItemId = (int) get_option(self::OPTION_MENU_ITEM_ID, 0);

        $page = null;
        if ($pageId > 0) {
            $post = get_post($pageId);
            if ($post && $post->post_type === 'page' && $post->post_status !== 'trash') {
                $page = [
                    'id'         => (int) $post->ID,
                    'slug'       => $post->post_name,
                    'title'      => $post->post_title,
                    'status'     => $post->post_status,
                    'editUrl'    => admin_url('post.php?post=' . $post->ID . '&action=edit'),
                    'previewUrl' => get_preview_post_link($post->ID),
                    'viewUrl'    => get_permalink($post->ID),
                ];
            } else {
                $pageId = 0;
                delete_option(self::OPTION_PAGE_ID);
            }
        }

        $menuItemLabel = '';
        if ($menuItemId > 0) {
            $menuItemPost = get_post($menuItemId);
            if ($menuItemPost) {
                $menuItemLabel = (string) $menuItemPost->post_title;
            } else {
                // stale option: menu item was deleted out-of-band (e.g. via Appearance → Menus)
                $menuItemId = 0;
                delete_option(self::OPTION_MENU_ITEM_ID);
            }
        }

        $menus = [];
        if (function_exists('wp_get_nav_menus')) {
            foreach (wp_get_nav_menus() as $menu) {
                $menus[] = [
                    'id'   => (int) $menu->term_id,
                    'name' => $menu->name,
                ];
            }
        }

        $primaryMenuId = 0;
        if (function_exists('has_nav_menu') && has_nav_menu('primary')) {
            $locations = get_nav_menu_locations();
            if (!empty($locations['primary'])) {
                $primaryMenuId = (int) $locations['primary'];
            }
        }

        return [
            'page'              => $page,
            'menuItemId'        => $menuItemId,
            'menuItemLabel'     => $menuItemLabel,
            'menus'             => $menus,
            'primaryMenuId'     => $primaryMenuId,
            'isBlockTheme'      => function_exists('wp_is_block_theme') && wp_is_block_theme(),
            'menuEditorUrl'     => admin_url('nav-menus.php'),
            'siteEditorUrl'     => admin_url('site-editor.php'),
            'supportsPattern'   => function_exists('register_block_pattern'),
        ];
    }

    /**
     * Create a draft sales page with the rendered pattern content.
     */
    public function createPage(string $slug): array
    {
        $slug = sanitize_title($slug);
        if ($slug === '') {
            $slug = 'membership';
        }

        $content = $this->pattern->buildContent();

        $pageId = wp_insert_post([
            'post_title'   => 'Membership',
            'post_name'    => $slug,
            'post_content' => $content,
            'post_status'  => 'draft',
            'post_type'    => 'page',
        ], true);

        if (is_wp_error($pageId)) {
            Logger::debug('SalesPage::createPage failed: ' . $pageId->get_error_message());
            return ['error' => $pageId->get_error_message()];
        }

        update_option(self::OPTION_PAGE_ID, (int) $pageId);

        return ['pageId' => (int) $pageId];
    }

    /**
     * Move the current sales page to the Trash and create a new draft in its place,
     * so creators can recover earlier edits via WP's Trash UI. Menu items we created
     * are hard-deleted (they're navigation pointers, not user-authored content).
     */
    public function regeneratePage(string $slug): array
    {
        $existingId = (int) get_option(self::OPTION_PAGE_ID, 0);
        if ($existingId > 0) {
            wp_delete_post($existingId, false);
            delete_option(self::OPTION_PAGE_ID);
        }

        $existingMenuItemId = (int) get_option(self::OPTION_MENU_ITEM_ID, 0);
        if ($existingMenuItemId > 0) {
            wp_delete_post($existingMenuItemId, true);
            delete_option(self::OPTION_MENU_ITEM_ID);
        }

        return $this->createPage($slug);
    }

    public function addToMenu(int $menuId, string $label): array
    {
        $pageId = (int) get_option(self::OPTION_PAGE_ID, 0);
        if ($pageId <= 0) {
            return ['error' => 'No sales page exists yet'];
        }

        if ($menuId <= 0 || !wp_get_nav_menu_object($menuId)) {
            return ['error' => 'Invalid menu'];
        }

        $existingMenuItemId = (int) get_option(self::OPTION_MENU_ITEM_ID, 0);
        if ($existingMenuItemId > 0 && get_post($existingMenuItemId)) {
            return ['menuItemId' => $existingMenuItemId];
        }

        $url = get_permalink($pageId);
        $label = sanitize_text_field($label);
        if ($label === '') {
            $label = 'Membership';
        }

        $menuItemId = wp_update_nav_menu_item($menuId, 0, [
            'menu-item-title'   => $label,
            'menu-item-url'     => $url,
            'menu-item-status'  => 'publish',
            'menu-item-type'    => 'custom',
        ]);

        if (is_wp_error($menuItemId) || !$menuItemId) {
            $msg = is_wp_error($menuItemId) ? $menuItemId->get_error_message() : 'Failed to add menu item';
            Logger::debug('SalesPage::addToMenu failed: ' . $msg);
            return ['error' => $msg];
        }

        update_option(self::OPTION_MENU_ITEM_ID, (int) $menuItemId);

        return ['menuItemId' => (int) $menuItemId];
    }

    /**
     * Update the title of the existing nav menu item without touching its
     * position, parent, or any other field. wp_update_nav_menu_item resets
     * unspecified fields to defaults, so we re-pass every persisted field
     * from the current menu item alongside the new title.
     */
    public function updateMenuItemLabel(string $label): array
    {
        $menuItemId = (int) get_option(self::OPTION_MENU_ITEM_ID, 0);
        if ($menuItemId <= 0) {
            return ['error' => 'No menu item to update'];
        }

        $post = get_post($menuItemId);
        if (!$post) {
            // stale option pointing at a deleted item; reconcile and bail
            delete_option(self::OPTION_MENU_ITEM_ID);
            return ['error' => 'No menu item to update'];
        }

        $label = sanitize_text_field($label);
        if ($label === '') {
            return ['error' => 'Label cannot be empty'];
        }

        $item = wp_setup_nav_menu_item($post);

        $menuTerms = wp_get_post_terms($menuItemId, 'nav_menu', ['fields' => 'ids']);
        if (is_wp_error($menuTerms) || empty($menuTerms)) {
            return ['error' => 'Menu item is not attached to a menu'];
        }
        $menuId = (int) $menuTerms[0];

        $args = [
            'menu-item-title'       => $label,
            'menu-item-url'         => isset($item->url) ? (string) $item->url : '',
            'menu-item-status'      => isset($item->post_status) ? (string) $item->post_status : 'publish',
            'menu-item-type'        => isset($item->type) ? (string) $item->type : 'custom',
            'menu-item-object'      => isset($item->object) ? (string) $item->object : '',
            'menu-item-object-id'   => isset($item->object_id) ? (int) $item->object_id : 0,
            'menu-item-parent-id'   => isset($item->menu_item_parent) ? (int) $item->menu_item_parent : 0,
            'menu-item-position'    => isset($item->menu_order) ? (int) $item->menu_order : 0,
            'menu-item-target'      => isset($item->target) ? (string) $item->target : '',
            'menu-item-classes'     => isset($item->classes) ? (is_array($item->classes) ? implode(' ', $item->classes) : (string) $item->classes) : '',
            'menu-item-xfn'         => isset($item->xfn) ? (string) $item->xfn : '',
            'menu-item-attr-title'  => isset($item->attr_title) ? (string) $item->attr_title : '',
            'menu-item-description' => isset($item->description) ? (string) $item->description : '',
        ];

        $result = wp_update_nav_menu_item($menuId, $menuItemId, $args);

        if (is_wp_error($result) || !$result) {
            $msg = is_wp_error($result) ? $result->get_error_message() : 'Failed to update menu item';
            Logger::debug('SalesPage::updateMenuItemLabel failed: ' . $msg);
            return ['error' => $msg];
        }

        return ['menuItemId' => (int) $result];
    }

    public function removeFromMenu(): array
    {
        $this->deleteMenuItemIfPresent();
        return ['ok' => true];
    }

    /**
     * Move the sales page to Trash and remove the menu item we added, so
     * creators can recover edits via Pages → Trash if they change their mind.
     */
    public function removePage(): array
    {
        $pageId = (int) get_option(self::OPTION_PAGE_ID, 0);
        if ($pageId > 0) {
            wp_delete_post($pageId, false);
            delete_option(self::OPTION_PAGE_ID);
        }

        $this->deleteMenuItemIfPresent();

        return ['ok' => true];
    }

    private function deleteMenuItemIfPresent(): void
    {
        $menuItemId = (int) get_option(self::OPTION_MENU_ITEM_ID, 0);
        if ($menuItemId > 0) {
            wp_delete_post($menuItemId, true);
            delete_option(self::OPTION_MENU_ITEM_ID);
        }
    }
}
