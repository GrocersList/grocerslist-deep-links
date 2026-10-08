<?php

namespace GrocersList\Blocks;

use GrocersList\Support\Logger;

/**
 * The GRO Form shortcodes, for WP Recipe Maker card templates, classic-editor
 * posts and theme templates — anywhere a block cannot go, on any WordPress
 * version:
 *
 * - [gro_form id="<hash>"]          that form
 * - [gro_form]                      the creator's default signup form
 * - [gro_form kind="save-to-email"] the default form of a kind: signup,
 *                                   save-to-email or content-gate
 * - [gro_save_to_email]             the default Save to Email form, so a
 *                                   recipe card template needs no id
 *
 * Both take recipe_id="<id>" to name the WP Recipe Maker recipe the form
 * sends; without it the form uses the recipe WPRM is rendering, else the
 * first one in the post. The form's copy and look come from GRO, so the
 * GL-2436 attributes (layout, heading, tags, …) are ignored.
 */
class FormShortcodes
{
    public const FORM = 'gro_form';
    public const SAVE_TO_EMAIL = 'gro_save_to_email';

    /** kind="…" => the config kind. */
    private const KINDS = [
        'signup'        => 'SIGNUP',
        'save-to-email' => 'SAVE_TO_EMAIL',
        'content-gate'  => 'CONTENT_GATE',
    ];

    private const ATTRIBUTES = ['id', 'kind', 'recipe_id'];

    private FormRenderer $renderer;

    public function __construct(?FormRenderer $renderer = null)
    {
        $this->renderer = $renderer ?? new FormRenderer();
    }

    public function register(): void
    {
        add_action('init', [$this, 'registerShortcodes']);
    }

    public function registerShortcodes(): void
    {
        add_shortcode(self::FORM, [$this, 'form']);
        add_shortcode(self::SAVE_TO_EMAIL, [$this, 'saveToEmail']);
    }

    /**
     * @param mixed $atts
     */
    public function form($atts = []): string
    {
        return $this->render(is_array($atts) ? $atts : [], self::FORM, 'SIGNUP');
    }

    /**
     * @param mixed $atts
     */
    public function saveToEmail($atts = []): string
    {
        return $this->render(is_array($atts) ? $atts : [], self::SAVE_TO_EMAIL, 'SAVE_TO_EMAIL');
    }

    /**
     * @param array<int|string, mixed> $atts
     */
    private function render(array $atts, string $tag, string $defaultKind): string
    {
        $known = shortcode_atts(array_fill_keys(self::ATTRIBUTES, ''), $atts, $tag);

        $ignored = array_diff(array_filter(array_keys($atts), 'is_string'), self::ATTRIBUTES);
        if ($tag === self::SAVE_TO_EMAIL && $known['kind'] !== '') {
            $ignored[] = 'kind';
        }
        if ($ignored) {
            Logger::debug(
                'FormShortcodes: [' . $tag . '] ignores ' . implode(', ', $ignored)
                . '; the form is designed in GRO'
            );
        }

        $kind = $defaultKind;
        if ($tag === self::FORM && trim((string) $known['kind']) !== '') {
            $requested = strtolower(str_replace(['_', ' '], '-', trim((string) $known['kind'])));
            $kind = self::KINDS[$requested] ?? null;

            if ($kind === null) {
                Logger::debug('FormShortcodes: [' . $tag . '] has an unknown kind, ' . sanitize_text_field($requested));
            }
        }

        return $this->renderer->renderShortcode(
            trim((string) $known['id']),
            $kind,
            max(0, (int) $known['recipe_id'])
        );
    }
}
