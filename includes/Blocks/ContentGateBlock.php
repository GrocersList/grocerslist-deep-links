<?php

namespace GrocersList\Blocks;

use GrocersList\Settings\PluginSettings;
use GrocersList\Support\Config;
use GrocersList\Support\GateCookie;
use GrocersList\Support\WprmRecipes;

/**
 * The GRO Content Gate block: what it hides stays hidden behind one of the
 * creator's GRO forms, and shows for a visitor who subscribes. A gate hides
 * everything below it in the post, or only the blocks inside it (GateRegion);
 * either way, by the time it renders, what it hides is its inner blocks, as
 * GateRegion::cut() moves the rest of the post inside a gate that hides
 * everything below it.
 *
 * A page cache serves the same HTML to everyone, so that HTML never holds
 * what the gate hides. A visitor gets a blurred picture of its shape
 * (GatePreview) under a card holding the gate's form, and the view script
 * (blocks/src/form/view.ts) puts the content in the gate's place — from the
 * answer to the form's submit, or from GateController when the browser comes
 * back holding a GateCookie. The inner blocks are not even rendered for a
 * visitor (skip_inner_blocks), because rendering has side effects that can
 * carry the content into the page anyway: WP Recipe Maker, for one, prints
 * the ingredients of every recipe it renders into window.wprm_recipes in the
 * footer.
 *
 * view() is the one rule for who sees what. An editor sees the content, with
 * a marker where the gate starts and a link that shows them the page as a
 * visitor sees it (?gl_gate_preview=1).
 *
 * Separate from memberships: no WordPress user is created and nothing reads
 * membership state. The grocerslist_content_gate_bypass filter is where
 * memberships, or anything else, can let a visitor through.
 *
 * Registered where the block API supports a dynamic block with wrapper
 * attributes (WordPress 5.6+), like the GRO Form block. There is no shortcode
 * form: a classic-editor gate would need an enclosing [gro_gate]…[/gro_gate].
 */
class ContentGateBlock
{
    public const BLOCK_NAME = 'grocerslist/content-gate';

    public const EDITOR_SCRIPT = 'grocerslist-content-gate-editor';
    public const EDITOR_STYLE = 'grocerslist-content-gate-editor-style';
    public const STYLE = 'grocerslist-content-gate-style';

    /** The query parameter that shows an editor the page as visitors see it. */
    public const PREVIEW_PARAM = 'gl_gate_preview';

    /** block.json's teaser default, which the site's own language replaces. */
    private const DEFAULT_TEASER = 'The rest of this post is one click away.';

    private const VIEW_EDITOR = 'editor';
    private const VIEW_OPEN = 'open';
    private const VIEW_NONE = 'none';
    private const VIEW_LOCKED = 'locked';

    /** The lock in the card's badge and the editors' marker: the content-gate form's icon. */
    private const LOCK_SHAPES = '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>';

    /** How many gates' inner blocks are rendering right now, one inside another. */
    private static int $renderingInner = 0;

    private FormRenderer $forms;
    private WprmRecipes $wprm;
    private GateCookie $cookie;

    public function __construct(?FormRenderer $forms = null, ?WprmRecipes $wprm = null, ?GateCookie $cookie = null)
    {
        $this->forms = $forms ?? new FormRenderer();
        $this->wprm = $wprm ?? new WprmRecipes();
        $this->cookie = $cookie ?? new GateCookie();
    }

    /**
     * A gate id as it appears in the block, the gate route and a submission:
     * the block editor's clientId, or any letters, digits, "_" and "-" up to
     * 64 of them. \z, not $, which would let a trailing line break through.
     *
     * @param mixed $value
     */
    public static function isGateId($value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $value) === 1;
    }

    /**
     * Whether a gate's inner blocks are rendering right now for someone who
     * may see them in the page: an editor, or a visitor the bypass filter
     * lets through. (For a subscriber they render in the gate route, which
     * GateContent::renderingPostId() tells.)
     */
    public static function renderingInner(): bool
    {
        return self::$renderingInner > 0;
    }

    public function register(): void
    {
        add_action('init', [$this, 'registerBlock']);
    }

    public function registerBlock(): void
    {
        if (!function_exists('register_block_type_from_metadata') || !function_exists('get_block_wrapper_attributes')) {
            return;
        }

        $version = Config::getPluginVersion();
        $pluginDir = dirname(__DIR__, 2);
        $pluginFile = $pluginDir . '/grocerslist.php';

        $this->forms->registerAssets();
        wp_register_style(self::STYLE, plugins_url('assets/blocks/content-gate/style.css', $pluginFile), [], $version);

        wp_register_script(
            self::EDITOR_SCRIPT,
            plugins_url('blocks/dist/content-gate/editor.js', $pluginFile),
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-data', 'wp-i18n', 'wp-api-fetch'],
            $version
        );
        // The canvas shows the card visitors see, with the form's preview in it.
        wp_register_style(
            self::EDITOR_STYLE,
            plugins_url('assets/blocks/content-gate/editor.css', $pluginFile),
            [self::STYLE, FormRenderer::STYLE, FormBlock::EDITOR_STYLE],
            $version
        );

        register_block_type_from_metadata($pluginDir . '/assets/blocks/content-gate', [
            'render_callback'   => [$this, 'render'],
            // WordPress renders the inner blocks only when render() does, for
            // someone allowed to see them (the class docblock says why).
            'skip_inner_blocks' => true,
        ]);
    }

    /**
     * The block's render callback.
     *
     * @param mixed $attrs
     * @param mixed $content The rendered inner blocks, which skip_inner_blocks leaves empty.
     * @param mixed $block WP_Block, for its postId context and inner blocks.
     */
    public function render($attrs = [], $content = '', $block = null): string
    {
        $attrs = is_array($attrs) ? $attrs : [];
        $gateId = self::isGateId($attrs['gateId'] ?? null) ? $attrs['gateId'] : '';
        $formId = isset($attrs['formId']) && is_string($attrs['formId']) ? trim($attrs['formId']) : '';
        $postId = self::postId($block);

        switch ($this->view($postId, $gateId)) {
            case self::VIEW_EDITOR:
                return $this->editorView($attrs, $formId, $gateId, $block, $content);
            case self::VIEW_OPEN:
                return self::innerHtml($block, $content);
            case self::VIEW_NONE:
                return '';
            default:
                return $this->locked($attrs, $formId, $gateId, $postId, $block);
        }
    }

    /**
     * Who sees what a gate hides — the one rule for it, which the cut of a
     * post at a gate that hides everything below it follows too, since that
     * cut only moves the rest of the post inside the gate:
     *
     * - an editor (signed in, with edit_posts) sees it, unless previewing the
     *   page as a visitor (then: the locked gate);
     * - so does a recognized subscriber, in content GateContent is revealing,
     *   and anyone grocerslist_content_gate_bypass lets through;
     * - a feed and WP Recipe Maker's print view get neither it nor the gate:
     *   neither can subscribe;
     * - anyone else gets the locked gate.
     */
    private function view(int $postId, string $gateId): string
    {
        $editor = is_user_logged_in() && current_user_can('edit_posts');
        if ($editor && !self::previewRequested()) {
            return self::VIEW_EDITOR;
        }

        // An editor previewing the page sees what any visitor does.
        if (!$editor
            && ($this->revealing() || apply_filters('grocerslist_content_gate_bypass', false, $postId, $gateId) === true)) {
            return self::VIEW_OPEN;
        }

        if ((function_exists('is_feed') && is_feed()) || $this->wprm->isPrintView()) {
            return self::VIEW_NONE;
        }

        return self::VIEW_LOCKED;
    }

    /**
     * Whether this request asks to show an editor the page as visitors see
     * it. Only an editor's view reads it; for anyone else it changes nothing.
     */
    private static function previewRequested(): bool
    {
        return isset($_GET[self::PREVIEW_PARAM])
            && is_string($_GET[self::PREVIEW_PARAM])
            && sanitize_text_field(wp_unslash($_GET[self::PREVIEW_PARAM])) === '1';
    }

    /**
     * Whether this is a gate nested in content GateContent is rendering for
     * a recognized subscriber. The cookie is read only then, never while a
     * page renders: a page cache would keep that page for everyone.
     */
    private function revealing(): bool
    {
        return GateContent::renderingPostId() > 0 && $this->cookie->fromRequest();
    }

    /**
     * What a visitor sees: the blurred picture of what the gate hides, and
     * over it the card with the teaser and the gate's form.
     *
     * @param array<string, mixed> $attrs
     * @param mixed $block
     */
    private function locked(array $attrs, string $formId, string $gateId, int $postId, $block): string
    {
        // The view script lets a returning subscriber in even where the form
        // itself cannot show (GRO unreachable, the form archived).
        $this->forms->enqueueAssets();
        self::enqueueStyles($block);

        $preview = is_user_logged_in() && current_user_can('edit_posts');
        $teaser = self::teaser($attrs['teaser'] ?? self::DEFAULT_TEASER);
        $form = $this->forms->gateForm($formId);

        $html = '<div ' . self::wrapperAttributes($attrs, $gateId, $postId, $preview) . '>'
            . '<div class="gl-gate__preview" aria-hidden="true" inert>' . GatePreview::render(self::parsedBlock($block)) . '</div>'
            . '<div class="gl-gate__overlay"><div class="gl-gate__card"' . self::cardStyle($form) . '>'
            . '<span class="gl-gate__badge">' . self::lock('') . '</span>';

        if ($teaser !== '') {
            $html .= '<p class="gl-gate__teaser">' . esc_html($teaser) . '</p>';
        }

        $html .= $this->forms->renderGateForm($formId, $postId, $gateId) . '</div></div></div>';

        return $preview ? self::previewMarker($attrs, $gateId) . $html : $html;
    }

    /**
     * What an editor sees: the content in place, with a marker where the
     * gate starts (and, for a gate that hides only its inner blocks, where it
     * ends), and above the content a notice when visitors could never get
     * past the gate.
     *
     * @param array<string, mixed> $attrs
     * @param mixed $block
     * @param mixed $content
     */
    private function editorView(array $attrs, string $formId, string $gateId, $block, $content): string
    {
        self::enqueueStyle();

        $connected = PluginSettings::getApiKey() !== '';
        $form = $connected ? $this->forms->gateForm($formId) : null;
        $below = self::hidesBelow($attrs);

        $html = self::marker($attrs, $gateId, $form !== null ? $form['name'] : '', $below)
            . self::editorNotice($connected, $gateId, $formId, $form)
            . self::innerHtml($block, $content);

        return $below
            ? $html
            : $html . '<p class="gl-gate-marker gl-gate-marker--end">' . esc_html(__('End of the gated section.', 'grocers-list')) . '</p>';
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private static function hidesBelow(array $attrs): bool
    {
        return ($attrs[GateRegion::CUT] ?? null) === true;
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private static function wrapperAttributes(array $attrs, string $gateId, int $postId, bool $preview): string
    {
        $attributes = [
            'class'        => 'gl-gate gl-gate--' . (self::hidesBelow($attrs) ? GateRegion::BELOW : GateRegion::INSIDE),
            'data-gl-gate' => $gateId,
            'data-gl-post' => (string) $postId,
        ];

        if ($gateId !== '' && $postId > 0) {
            $attributes['data-gl-reveal'] = rest_url('grocerslist/v1/gate/' . $postId . '/' . $gateId);
        }

        // The view script leaves a previewed gate locked for a subscriber too.
        if ($preview) {
            $attributes['data-gl-preview'] = '1';
        }

        return get_block_wrapper_attributes($attributes);
    }

    /**
     * The card's style: the gate form's brand colours, as the form has them,
     * so the card and its badge wear them too.
     *
     * @param array{name: string, colors: array<string, string>}|null $form
     */
    private static function cardStyle(?array $form): string
    {
        $style = '';
        foreach ($form !== null ? $form['colors'] : [] as $property => $color) {
            $style .= $property . ':' . $color . ';';
        }

        return $style !== '' ? ' style="' . esc_attr($style) . '"' : '';
    }

    /**
     * The lock, as an inline SVG.
     */
    private static function lock(string $class): string
    {
        return '<svg' . ($class !== '' ? ' class="' . $class . '"' : '') . ' viewBox="0 0 24 24" width="24" height="24"'
            . ' aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2"'
            . ' stroke-linecap="round" stroke-linejoin="round">' . self::LOCK_SHAPES . '</svg>';
    }

    /**
     * The block's HTML anchor (block supports, stored with the block from
     * WordPress 7.0), else ''.
     *
     * @param array<string, mixed> $attrs
     */
    private static function anchor(array $attrs): string
    {
        return isset($attrs['anchor']) && is_string($attrs['anchor']) ? trim($attrs['anchor']) : '';
    }

    /**
     * The id of the editors' marker where the gate starts: the block's
     * anchor, which the locked gate's wrapper carries for a visitor, else one
     * from the gate's id, for "Stop previewing" to land on.
     *
     * @param array<string, mixed> $attrs
     */
    private static function markerId(array $attrs, string $gateId): string
    {
        $anchor = self::anchor($attrs);
        if ($anchor !== '') {
            return $anchor;
        }

        return $gateId !== '' ? 'gl-gate-' . $gateId : '';
    }

    /**
     * The id of the line above a gate an editor is previewing, for "Preview
     * as a visitor" to land on.
     */
    private static function previewMarkerId(string $gateId): string
    {
        return $gateId !== '' ? 'gl-gate-preview-' . $gateId : '';
    }

    private static function fragment(string $id): string
    {
        return $id !== '' ? '#' . $id : '';
    }

    /**
     * The editors' marker where a gate starts: what visitors see from there,
     * and a link to this page as they see it.
     *
     * @param array<string, mixed> $attrs
     */
    private static function marker(array $attrs, string $gateId, string $formName, bool $below): string
    {
        if ($formName !== '') {
            $text = $below
                /* translators: %s: the name of the GRO form the gate shows. */
                ? sprintf(__("Visitors see the \u{201C}%s\u{201D} gate from here: everything below is hidden until they subscribe.", 'grocers-list'), $formName)
                /* translators: %s: the name of the GRO form the gate shows. */
                : sprintf(__("Visitors see the \u{201C}%s\u{201D} gate here: the section below is hidden until they subscribe.", 'grocers-list'), $formName);
        } else {
            $text = $below
                ? __('Visitors see a gate from here: everything below is hidden until they subscribe.', 'grocers-list')
                : __('Visitors see a gate here: the section below is hidden until they subscribe.', 'grocers-list');
        }

        $id = self::markerId($attrs, $gateId);
        $url = add_query_arg(self::PREVIEW_PARAM, '1', false) . self::fragment(self::previewMarkerId($gateId));

        return '<p class="gl-gate-marker"' . ($id !== '' ? ' id="' . esc_attr($id) . '"' : '') . '>'
            . self::lock('gl-gate-marker__icon')
            . '<span class="gl-gate-marker__text">' . esc_html($text) . '</span> '
            . '<a class="gl-gate-marker__link" href="' . esc_url($url) . '">'
            . esc_html(__('Preview as a visitor', 'grocers-list')) . '</a></p>';
    }

    /**
     * Above a gate an editor is previewing: how to get back to the editor's
     * view. Visitors never see it.
     *
     * @param array<string, mixed> $attrs
     */
    private static function previewMarker(array $attrs, string $gateId): string
    {
        $id = self::previewMarkerId($gateId);
        $url = remove_query_arg(self::PREVIEW_PARAM, false) . self::fragment(self::markerId($attrs, $gateId));

        return '<p class="gl-gate-marker gl-gate-marker--preview"' . ($id !== '' ? ' id="' . esc_attr($id) . '"' : '') . '>'
            . self::lock('gl-gate-marker__icon')
            . '<span class="gl-gate-marker__text">'
            . esc_html(__('This is the gate as visitors see it. Only editors see this line.', 'grocers-list'))
            . '</span> <a class="gl-gate-marker__link" href="' . esc_url($url) . '">'
            . esc_html(__('Stop previewing', 'grocers-list')) . '</a></p>';
    }

    /**
     * The gate's stylesheet alone: an editor's marker needs it.
     */
    private static function enqueueStyle(): void
    {
        wp_enqueue_style(
            self::STYLE,
            plugins_url('assets/blocks/content-gate/style.css', dirname(__DIR__, 2) . '/grocerslist.php'),
            [],
            Config::getPluginVersion()
        );
    }

    /**
     * The gate's stylesheet, and those of the blocks it hides: a block theme
     * loads a block's styles only when the block renders, and these render
     * later, in the gate route. Only the handles are read; nothing renders.
     *
     * @param mixed $block
     */
    private static function enqueueStyles($block): void
    {
        self::enqueueStyle();

        $inner = self::parsedBlock($block)['innerBlocks'] ?? [];

        if (is_array($inner) && $inner && class_exists('WP_Block_Type_Registry')) {
            self::enqueueBlockStyles(\WP_Block_Type_Registry::get_instance(), $inner);
        }
    }

    /**
     * @param \WP_Block_Type_Registry $registry
     * @param array<int, mixed> $blocks Parsed blocks.
     */
    private static function enqueueBlockStyles($registry, array $blocks): void
    {
        foreach ($blocks as $parsed) {
            if (!is_array($parsed)) {
                continue;
            }

            $type = isset($parsed['blockName']) && is_string($parsed['blockName'])
                ? $registry->get_registered($parsed['blockName'])
                : null;

            if (is_object($type)) {
                // style_handles from WordPress 6.1; one handle in style before.
                $handles = isset($type->style_handles) && is_array($type->style_handles)
                    ? $type->style_handles
                    : (isset($type->style) ? (array) $type->style : []);
                if (isset($type->view_style_handles) && is_array($type->view_style_handles)) {
                    $handles = array_merge($handles, $type->view_style_handles);
                }

                foreach ($handles as $handle) {
                    if (is_string($handle) && $handle !== '') {
                        wp_enqueue_style($handle);
                    }
                }
            }

            if (isset($parsed['innerBlocks']) && is_array($parsed['innerBlocks'])) {
                self::enqueueBlockStyles($registry, $parsed['innerBlocks']);
            }
        }
    }

    /**
     * @param mixed $block
     * @return array<string, mixed>
     */
    private static function parsedBlock($block): array
    {
        return is_object($block) && isset($block->parsed_block) && is_array($block->parsed_block)
            ? $block->parsed_block
            : [];
    }

    /**
     * The inner blocks rendered as WordPress would have rendered them before
     * calling render(), had skip_inner_blocks not been set.
     *
     * @param mixed $block
     * @param mixed $content
     */
    private static function innerHtml($block, $content): string
    {
        // A WordPress that rendered them anyway has done the work.
        if (is_string($content) && $content !== '') {
            return $content;
        }

        if (!is_object($block) || !isset($block->inner_content) || !is_array($block->inner_content)) {
            return '';
        }

        $html = '';
        $index = 0;
        self::$renderingInner++;

        try {
            foreach ($block->inner_content as $chunk) {
                if (is_string($chunk)) {
                    $html .= $chunk;
                    continue;
                }

                $inner = $block->inner_blocks[$index] ?? null;
                $index++;
                if (is_object($inner) && method_exists($inner, 'render')) {
                    $html .= self::renderInner($inner, $block);
                }
            }
        } finally {
            self::$renderingInner--;
        }

        return $html;
    }

    /**
     * One inner block, as WP_Block::render() renders a block's inner blocks:
     * through pre_render_block, render_block_data and render_block_context
     * with the gate as the parent — where block visibility plugins, custom
     * link colours, style variations and custom CSS hook in — and, from
     * WordPress 6.8, with what it derives from them recomputed when a filter
     * changed them. It keeps the context the gate passes down (a synced
     * pattern's overrides, say), which render_block() would rebuild from the
     * global post. (Before 5.9, core ran these filters for top-level blocks
     * only.)
     *
     * @param object $inner WP_Block
     * @param object $parent WP_Block, the gate
     */
    private static function renderInner($inner, $parent): string
    {
        $pre = apply_filters('pre_render_block', null, $inner->parsed_block, $parent);
        if ($pre !== null) {
            return (string) $pre;
        }

        $source = $inner->parsed_block;
        $context = $inner->context;

        $inner->parsed_block = apply_filters('render_block_data', $inner->parsed_block, $source, $parent);
        $inner->context = apply_filters('render_block_context', $inner->context, $inner->parsed_block, $parent);

        // refresh_context_dependents() refreshes the parsed block's too.
        if ($inner->context !== $context && method_exists($inner, 'refresh_context_dependents')) {
            $inner->refresh_context_dependents();
        } elseif ($inner->parsed_block !== $source && method_exists($inner, 'refresh_parsed_block_dependents')) {
            $inner->refresh_parsed_block_dependents();
        }

        return (string) $inner->render();
    }

    /**
     * The post whose content holds the gate: the one WordPress is rendering
     * (render_block() puts it in the context), which is where the gate route
     * looks for the gate.
     *
     * @param mixed $block
     */
    private static function postId($block): int
    {
        $context = is_object($block) && isset($block->context) && is_array($block->context) ? $block->context : [];
        if (isset($context['postId']) && (int) $context['postId'] > 0) {
            return (int) $context['postId'];
        }

        return (int) get_the_ID();
    }

    /**
     * @param mixed $teaser
     */
    private static function teaser($teaser): string
    {
        $text = is_string($teaser) ? trim($teaser) : '';

        return $text === self::DEFAULT_TEASER ? __('The rest of this post is one click away.', 'grocers-list') : $text;
    }

    /**
     * What an editor, who sees the content, sees above it when visitors
     * could never get past the gate: the site is not connected to GRO, the
     * gate has no id, or there is no form to subscribe with (GRO no longer
     * has the one picked, or, with none picked, there is no default
     * content-gate or signup form). Only an editor's view asks GRO for that
     * last one.
     *
     * @param array{name: string, colors: array<string, string>}|null $form
     */
    private static function editorNotice(bool $connected, string $gateId, string $formId, ?array $form): string
    {
        if (!$connected) {
            $text = __('Connect this site to GRO so this gate can show its form; until then visitors see the gate without one. Only editors see this notice.', 'grocers-list');
        } elseif ($gateId === '') {
            $text = __("This gate has no id yet, so visitors could never unlock it: open this post in the block editor and update it. Only editors see this notice.", 'grocers-list');
        } elseif ($form === null) {
            $text = $formId !== ''
                ? __("This gate's form no longer exists in GRO \u{2014} pick another one in the gate's settings. Until then visitors see the gate without a form. Only editors see this notice.", 'grocers-list')
                : __("Pick a form in this gate's settings: GRO has no default content gate or signup form to show instead, so visitors see the gate without one. Only editors see this notice.", 'grocers-list');
        } else {
            return '';
        }

        return '<p class="gl-form-notice">' . esc_html($text) . '</p>';
    }
}
