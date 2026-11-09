<?php

namespace GrocersList\Blocks;

/**
 * Whether the active theme's palette has both a base and a contrast colour:
 * the pair a GRO form, the popup and a content gate take for what brand
 * colours leave unset, through a --theme-pair modifier on each root
 * (assets/blocks/form/style.css). Both or neither: a theme that names its
 * background base and its text something else, as a dark style variation
 * can (base: #111), would get dark text on GRO's own dark button.
 *
 * Read once a request from theme.json's settings (WordPress 5.9+): the
 * theme's palette and the colours a user added in the Site Editor, not
 * WordPress's own defaults. Older WordPress has no such settings, so no
 * pair.
 */
class ThemePalette
{
    private const SLUGS = ['base', 'contrast'];

    /** This request's answer; null until asked. */
    private static ?bool $hasPair = null;

    public static function hasBaseAndContrast(): bool
    {
        if (self::$hasPair === null) {
            self::$hasPair = self::read();
        }

        return self::$hasPair;
    }

    /**
     * For the tests: a request of their own.
     */
    public static function reset(): void
    {
        self::$hasPair = null;
    }

    private static function read(): bool
    {
        if (!function_exists('wp_get_global_settings')) {
            return false;
        }

        $palette = wp_get_global_settings(['color', 'palette']);
        $slugs = [];
        foreach (['theme', 'custom'] as $origin) {
            $colors = is_array($palette) && isset($palette[$origin]) && is_array($palette[$origin]) ? $palette[$origin] : [];
            foreach ($colors as $color) {
                if (is_array($color)
                    && isset($color['slug'], $color['color'])
                    && is_string($color['slug'])
                    && is_string($color['color'])
                    && trim($color['color']) !== '') {
                    $slugs[] = $color['slug'];
                }
            }
        }

        return array_diff(self::SLUGS, $slugs) === [];
    }
}
