<?php

namespace GrocersList\Blocks;

use GrocersList\Support\Config;

/**
 * The GRO Form block. The creator picks one of their GRO forms; its copy,
 * layout, colours, tags and follow-up are configured in GRO, and
 * FormRenderer renders it server-side from that config. Registered only
 * where the block API supports a dynamic block with wrapper attributes
 * (WordPress 5.6+); the shortcodes cover older versions.
 */
class FormBlock
{
    public const BLOCK_NAME = 'grocerslist/form';
    public const CATEGORY_SLUG = 'grocerslist';

    public const EDITOR_SCRIPT = 'grocerslist-form-editor';
    public const EDITOR_STYLE = 'grocerslist-form-editor-style';

    private FormRenderer $renderer;

    public function __construct(?FormRenderer $renderer = null)
    {
        $this->renderer = $renderer ?? new FormRenderer();
    }

    public function register(): void
    {
        add_action('init', [$this, 'registerBlock']);
        add_filter('block_categories_all', [$this, 'addCategory']);
    }

    /**
     * @param mixed $categories
     * @return mixed
     */
    public function addCategory($categories)
    {
        if (!is_array($categories)) {
            return $categories;
        }

        foreach ($categories as $category) {
            if (isset($category['slug']) && $category['slug'] === self::CATEGORY_SLUG) {
                return $categories;
            }
        }

        $categories[] = [
            'slug'  => self::CATEGORY_SLUG,
            'title' => __('GRO', 'grocers-list'),
        ];

        return $categories;
    }

    public function registerBlock(): void
    {
        if (!function_exists('register_block_type_from_metadata') || !function_exists('get_block_wrapper_attributes')) {
            return;
        }

        $version = Config::getPluginVersion();
        $pluginDir = dirname(__DIR__, 2);
        $pluginFile = $pluginDir . '/grocerslist.php';

        $this->renderer->registerAssets();
        wp_register_script(
            self::EDITOR_SCRIPT,
            plugins_url('blocks/dist/form/editor.js', $pluginFile),
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-api-fetch'],
            $version
        );
        wp_register_style(
            self::EDITOR_STYLE,
            plugins_url('assets/blocks/form/editor.css', $pluginFile),
            [],
            $version
        );

        register_block_type_from_metadata(
            $pluginDir . '/assets/blocks/form',
            ['render_callback' => [$this->renderer, 'renderBlock']]
        );
    }
}
