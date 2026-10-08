<?php

namespace GrocersList\Blocks;

use GrocersList\Service\FormConfigCache;
use GrocersList\Settings\PluginSettings;
use GrocersList\Support\Config;
use GrocersList\Support\WprmRecipes;

/**
 * Renders a GRO form from its config (GL_ListGrowthFormConfig), for the GRO
 * Form block, the [gro_form] and [gro_save_to_email] shortcodes and the GRO
 * Content Gate block alike.
 * The markup and its classes, documented at the top of
 * assets/blocks/form/style.css, are the contract the HQ preview and the GRO
 * landing page render too.
 */
class FormRenderer
{
    public const STYLE = 'grocerslist-form-style';
    public const VIEW_SCRIPT = 'grocerslist-form-view';

    /** Config kind => its class modifier and data-gl-kind. */
    public const KIND_SLUGS = [
        'SIGNUP'        => 'signup',
        'SAVE_TO_EMAIL' => 'save-to-email',
        'CONTENT_GATE'  => 'content-gate',
    ];

    private const LAYOUTS = ['stacked', 'inline', 'split', 'compact'];
    private const APPEARANCES = ['boxed', 'plain'];

    /** GRO's cap on design.redirectUrl. */
    private const MAX_REDIRECT_LENGTH = 2048;

    /**
     * The honeypot's own hiding, repeating .gl-form__hp in
     * assets/blocks/form/style.css: an optimizer that dequeues or defers the
     * block stylesheet would otherwise show readers a "Website" box, and a
     * filled one is dropped without a word. Clipped in place with no left or
     * right offset, as the rule is: an offset makes a right-to-left page
     * scroll sideways.
     */
    private const HONEYPOT_STYLE = 'position:absolute;width:1px;height:1px;overflow:hidden;'
        . 'clip:rect(0,0,0,0);clip-path:inset(50%);white-space:nowrap';

    /** The icon's shapes, by kind: an envelope, a heart and a lock. */
    private const ICONS = [
        'SIGNUP'        => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.5 6.5 12 13l8.5-6.5"/>',
        'SAVE_TO_EMAIL' => '<path d="M12 20.5C12 20.5 3 15 3 9.25C3 6.35 5.25 4.5 7.75 4.5C9.6 4.5 11.1 5.55 12 7.1'
            . 'C12.9 5.55 14.4 4.5 16.25 4.5C18.75 4.5 21 6.35 21 9.25C21 15 12 20.5 12 20.5Z"/>',
        'CONTENT_GATE'  => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
    ];

    private const NOTICE_CONNECT = 'connect';
    private const NOTICE_CHOOSE = 'choose';
    private const NOTICE_MISSING = 'missing';

    private FormConfigCache $forms;
    private WprmRecipes $wprm;

    private static int $instances = 0;

    /** Whether the view script has the REST root (printRestRoot()) yet. */
    private bool $restRootPrinted = false;

    public function __construct(?FormConfigCache $forms = null, ?WprmRecipes $wprm = null)
    {
        $this->forms = $forms ?? new FormConfigCache();
        $this->wprm = $wprm ?? new WprmRecipes();
    }

    /**
     * The handles the block's metadata names. Rendering enqueues them with
     * their sources as well, so a shortcode works on WordPress versions
     * without the block API, where nothing registers them.
     */
    public function registerAssets(): void
    {
        wp_register_style(self::STYLE, self::assetUrl('assets/blocks/form/style.css'), [], Config::getPluginVersion());
        wp_register_script(self::VIEW_SCRIPT, self::assetUrl('blocks/dist/form/view.js'), [], Config::getPluginVersion(), true);
    }

    /**
     * The block's render callback.
     *
     * @param mixed $attrs
     * @param mixed $content
     * @param mixed $block WP_Block (WP 5.5+), for its postId and queryId context.
     */
    public function renderBlock($attrs = [], $content = '', $block = null): string
    {
        $formId = is_array($attrs) && isset($attrs['formId']) && is_string($attrs['formId'])
            ? trim($attrs['formId'])
            : '';

        return $this->render(
            function () use ($formId): ?array {
                return $formId === '' ? null : $this->forms->get($formId);
            },
            $block,
            'block',
            0,
            $formId === '' ? self::NOTICE_CHOOSE : self::NOTICE_MISSING
        );
    }

    /**
     * A shortcode's form: the one with $formId, else the creator's default
     * form of $kind (null when the shortcode named a kind that does not exist).
     */
    public function renderShortcode(string $formId, ?string $kind, int $recipeId = 0): string
    {
        return $this->render(
            function () use ($formId, $kind): ?array {
                if ($formId !== '') {
                    return $this->forms->get($formId);
                }

                return $kind === null ? null : $this->forms->getDefault($kind);
            },
            null,
            'shortcode',
            $recipeId,
            self::NOTICE_MISSING
        );
    }

    /**
     * The form inside a content gate: $formId, else the creator's default
     * content-gate form, else their default signup form. It sends the gate's
     * post, carries the gate's id so that the answer to its submit can hold
     * what the gate hides, and never redirects: the gate opens in its place.
     */
    public function renderGateForm(string $formId, int $postId, string $gateId): string
    {
        return $this->render(
            function () use ($formId): ?array {
                return $this->gateConfig($formId);
            },
            null,
            'gate',
            0,
            self::NOTICE_MISSING,
            ['postId' => max(0, $postId), 'gateId' => $gateId]
        );
    }

    /**
     * Whether a content gate's form can show: GRO has $formId, or, with none
     * picked, a default form (see renderGateForm()). When not, visitors see
     * the gate with no way to subscribe, and ContentGateBlock tells editors.
     */
    public function gateFormResolves(string $formId): bool
    {
        return $this->gateForm($formId) !== null;
    }

    /**
     * What a content gate shows of its form besides the form: its name, for
     * the marker editors see where the gate starts, and its brand colours
     * (the custom properties the form's wrapper gets), which the gate's card
     * takes on. Null when the form cannot show (gateFormResolves()). Renders
     * and loads nothing.
     *
     * @return array{name: string, colors: array<string, string>}|null
     */
    public function gateForm(string $formId): ?array
    {
        $config = $this->gateConfig($formId);
        $form = self::normalize($config);
        if ($form === null) {
            return null;
        }

        return [
            'name'   => isset($config['name']) && is_string($config['name']) ? trim($config['name']) : '',
            'colors' => $form['colors'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function gateConfig(string $formId): ?array
    {
        if ($formId !== '') {
            return $this->forms->get($formId);
        }

        return $this->forms->getDefault('CONTENT_GATE') ?? $this->forms->getDefault('SIGNUP');
    }

    /**
     * @param callable(): (array|null) $resolve Finds the config; only called once the page can show a form.
     * @param mixed $block
     * @param array{postId: int, gateId: string}|null $gate The content gate the form sits in, whose post it sends.
     */
    private function render(
        callable $resolve,
        $block,
        string $source,
        int $recipeId,
        string $missingNotice,
        ?array $gate = null
    ): string {
        if ((function_exists('is_feed') && is_feed()) || $this->wprm->isPrintView()) {
            return '';
        }

        if (!PluginSettings::getApiKey()) {
            return self::notice(self::NOTICE_CONNECT);
        }

        $config = $resolve();
        $form = self::normalize($config);
        if ($form === null) {
            return self::notice($missingNotice);
        }

        $postId = $gate !== null ? $gate['postId'] : self::contextPostId($block);

        // Save to Email sends the page itself, so it needs one to send.
        if ($form['kind'] === 'SAVE_TO_EMAIL' && $postId <= 0) {
            return '';
        }

        $recipeId = $this->recipeId($recipeId, $postId);
        $ts = time();
        $sig = FormSignature::signPage($form['hash'], $ts, $postId, $recipeId);

        $this->enqueueAssets();

        // Per request, not per form: one form can sit on a page twice.
        $instance = ++self::$instances;
        $titleId = 'gl-form-title-' . $instance;
        $statusId = 'gl-form-status-' . $instance;

        // A gate opens in place of its form, so the form never leaves for a
        // redirect there, whatever its kind: a gate shows the creator's
        // default signup form unless it names another, and that can have one.
        $redirect = $gate === null ? $form['redirectUrl'] : '';

        $html = '<div ' . self::wrapperAttributes($form, $source) . '>'
            . '<form class="gl-form__form" method="post" action="' . esc_url(rest_url('grocerslist/v1/forms/submit')) . '" novalidate'
            . ' data-gl-form="gl-form" data-gl-kind="' . self::KIND_SLUGS[$form['kind']] . '"'
            // Where the form was rendered, for the detail of the event its
            // submit fires (docs/FORMS.md); the same word the
            // grocerslist_form_html filter gets as source.
            . ' data-gl-placement="' . esc_attr($source) . '"'
            . ' data-gl-event="' . ($form['kind'] === 'SAVE_TO_EMAIL' ? 'grocerslist:saved' : 'grocerslist:subscribed') . '"'
            . ($redirect !== '' ? ' data-gl-redirect="' . esc_url($redirect, ['http', 'https']) . '"' : '')
            . ' data-gl-i18n="' . esc_attr((string) wp_json_encode(self::viewStrings($form['kind'], $form['optInRequired']))) . '"'
            . ($form['heading'] !== '' ? ' aria-labelledby="' . $titleId . '"' : '')
            . '>';

        $html .= self::copy($form, $titleId);

        $html .= '<div class="gl-form__fields"><div class="gl-form__row">';

        if ($form['collectFirstName']) {
            $html .= '<label class="gl-form__field gl-form__field--name">'
                . '<span class="screen-reader-text">' . esc_html($form['namePlaceholder']) . '</span>'
                . '<input type="text" name="firstName" autocomplete="given-name" maxlength="100"'
                . ' placeholder="' . esc_attr($form['namePlaceholder']) . '">'
                . '</label>';
        }

        // The email field is described by the status line, which is where the
        // view script says what went wrong with it.
        $html .= '<label class="gl-form__field gl-form__field--email">'
            . '<span class="screen-reader-text">' . esc_html($form['emailPlaceholder']) . '</span>'
            . '<input type="email" name="email" required autocomplete="email" inputmode="email" enterkeyhint="send" maxlength="254"'
            . ' aria-describedby="' . $statusId . '" placeholder="' . esc_attr($form['emailPlaceholder']) . '">'
            . '</label>'
            . '<button type="submit" class="gl-form__submit wp-block-button__link wp-element-button">'
            . esc_html($form['buttonLabel']) . '</button>'
            . '</div>';

        if ($form['optIn']) {
            // The hidden 0 posts when the box is left unticked; ticked, the
            // box's 1 comes later and wins. A required box stops the view
            // script, and a 0 that gets past it is refused by the submit route.
            $html .= '<input type="hidden" name="subscribe" value="0">'
                . '<label class="gl-form__optin"><input type="checkbox" name="subscribe" value="1"'
                . ($form['optInChecked'] ? ' checked' : '')
                . ($form['optInRequired'] ? ' required aria-required="true"' : '') . '>'
                . '<span>' . esc_html($form['optInLabel']) . '</span></label>';
        }

        $html .= '</div>';

        $html .= '<p class="gl-form__status" id="' . $statusId . '" role="status" aria-live="polite" tabindex="-1"></p>'
            . '<template class="gl-form__success">' . esc_html($form['successMessage']) . '</template>';

        $html .= '<div class="gl-form__hp" style="' . esc_attr(self::HONEYPOT_STYLE) . '" aria-hidden="true">'
            . '<label>' . esc_html__('Website', 'grocers-list')
            . ' <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>';

        $html .= '<input type="hidden" name="formId" value="' . esc_attr($form['hash']) . '">'
            . '<input type="hidden" name="postId" value="' . $postId . '">'
            . '<input type="hidden" name="recipeId" value="' . $recipeId . '">'
            . '<input type="hidden" name="ts" value="' . $ts . '">'
            . '<input type="hidden" name="sig" value="' . esc_attr($sig) . '">';

        if ($gate !== null) {
            $html .= '<input type="hidden" name="gateId" value="' . esc_attr($gate['gateId']) . '">'
                . '<input type="hidden" name="gatePostId" value="' . $gate['postId'] . '">';
        }

        $html .= '</form></div>';

        return (string) apply_filters('grocerslist_form_html', $html, $config, [
            'postId'   => $postId,
            'recipeId' => $recipeId,
            'source'   => $source,
        ]);
    }

    /**
     * Also the content gate's: its view logic lives in the same script, and
     * lets a returning subscriber in even where the gate's form cannot show.
     */
    public function enqueueAssets(): void
    {
        wp_enqueue_style(self::STYLE, self::assetUrl('assets/blocks/form/style.css'), [], Config::getPluginVersion());
        wp_enqueue_script(self::VIEW_SCRIPT, self::assetUrl('blocks/dist/form/view.js'), [], Config::getPluginVersion(), true);
        $this->printRestRoot();
    }

    /**
     * Prints the REST API's root, rest_url(), before the view script, once:
     * the script takes a form's action or a gate's data-gl-reveal for this
     * site's own route only when it is that root and the route. Both are page
     * markup, which a post's author can write too (kses keeps data-*
     * attributes); a script printed here is not. Built while the forms
     * render, as their routes are. WordPress 4.4 has no
     * wp_add_inline_script(), and the view script then reads a route from its
     * tail, as on a page cached without the root (docs/FORMS.md).
     */
    private function printRestRoot(): void
    {
        if ($this->restRootPrinted || !function_exists('wp_add_inline_script')) {
            return;
        }

        $root = wp_json_encode(['restRoot' => rest_url()]);
        if (is_string($root)) {
            $this->restRootPrinted = wp_add_inline_script(self::VIEW_SCRIPT, 'window.grocerslistForms = ' . $root . ';', 'before');
        }
    }

    private static function assetUrl(string $path): string
    {
        return plugins_url($path, dirname(__DIR__, 2) . '/grocerslist.php');
    }

    /**
     * What the renderer reads from a config, with the fallbacks a missing or
     * empty field gets. Null when the config is not a GRO form this can
     * render.
     *
     * @param mixed $config
     * @return array<string, mixed>|null
     */
    private static function normalize($config): ?array
    {
        if (!is_array($config)
            || !isset($config['kind'])
            || !is_string($config['kind'])
            || !isset(self::KIND_SLUGS[$config['kind']])
            || !FormConfigCache::isHash($config['hash'] ?? null)) {
            return null;
        }

        $kind = $config['kind'];
        $defaults = self::defaults($kind);
        $design = isset($config['design']) && is_array($config['design']) ? $config['design'] : [];
        $brand = isset($config['brand']) && is_array($config['brand']) ? $config['brand'] : [];
        $optIn = isset($design['optIn']) && is_array($design['optIn']) ? $design['optIn'] : [];
        $useBrandColors = !array_key_exists('useBrandColors', $design) || !empty($design['useBrandColors']);

        return [
            'hash'             => $config['hash'],
            'kind'             => $kind,
            'heading'          => self::text($design['heading'] ?? null, ''),
            'description'      => self::text($design['description'] ?? null, ''),
            'emailPlaceholder' => self::text($design['emailPlaceholder'] ?? null, $defaults['emailPlaceholder']),
            'namePlaceholder'  => self::text($design['namePlaceholder'] ?? null, $defaults['namePlaceholder']),
            'buttonLabel'      => self::text($design['buttonLabel'] ?? null, $defaults['buttonLabel']),
            'successMessage'   => self::text($design['successMessage'] ?? null, $defaults['successMessage']),
            'collectFirstName' => !empty($design['collectFirstName']),
            'layout'           => in_array($design['layout'] ?? null, self::LAYOUTS, true) ? $design['layout'] : $defaults['layout'],
            'appearance'       => in_array($design['appearance'] ?? null, self::APPEARANCES, true)
                ? $design['appearance']
                : $defaults['appearance'],
            'showIcon'         => !empty($design['showIcon']),
            'optIn'            => self::showsCheckbox($design),
            'optInRequired'    => self::requiresConsent($config),
            'optInLabel'       => self::text($optIn['label'] ?? null, $defaults['optInLabel']),
            // Ticked only when GRO says so: a config without the key (from an
            // older GRO, or a copy of one cached for up to a day) is unticked.
            'optInChecked'     => !empty($optIn['checked']),
            // A content gate shows what it hides in place, so it never leaves.
            'redirectUrl'      => $kind === 'CONTENT_GATE' ? '' : self::redirectUrl($design['redirectUrl'] ?? null),
            'colors'           => $useBrandColors ? self::brandColors($design, $brand) : [],
        ];
    }

    /**
     * Whether a form rendered from $config asks for consent: a signup or
     * content-gate form showing its checkbox (design.optIn), which the
     * visitor must tick. On Save to Email the same checkbox is an optional
     * opt-in.
     *
     * @param mixed $config
     */
    public static function requiresConsent($config): bool
    {
        return is_array($config)
            && in_array($config['kind'] ?? null, ['SIGNUP', 'CONTENT_GATE'], true)
            && self::showsCheckbox($config['design'] ?? null);
    }

    /**
     * @param mixed $design
     */
    private static function showsCheckbox($design): bool
    {
        return is_array($design)
            && isset($design['optIn'])
            && is_array($design['optIn'])
            && !empty($design['optIn']['show']);
    }

    /**
     * design.redirectUrl when it is an absolute http(s) URL with no user name
     * or password, of at most 2048 characters as GRO counts them, else ''.
     * GRO checks it when the creator saves (GL-2450); the config is still
     * only data here, and the grocerslist_form_config filter can change it.
     * Not wp_http_validate_url(), which refuses local and private hosts, such
     * as a creator's staging site.
     *
     * @param mixed $url
     */
    private static function redirectUrl($url): string
    {
        $url = is_string($url) ? trim($url) : '';
        $length = self::utf16Length($url);
        $parts = $url !== '' && $length !== null && $length <= self::MAX_REDIRECT_LENGTH ? wp_parse_url($url) : false;

        return is_array($parts)
            && isset($parts['scheme'], $parts['host'])
            && in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            && $parts['host'] !== ''
            // "https://amyskitchen.com@evil.com" goes to evil.com.
            && ($parts['user'] ?? '') === ''
            && ($parts['pass'] ?? '') === ''
            ? $url
            : '';
    }

    /**
     * $text's length as JavaScript's String.length, and so GRO, counts it:
     * in UTF-16 code units, one for each code point and two for one above
     * U+FFFF. Null when $text is not UTF-8. Counted without mbstring, which
     * some hosts lack.
     */
    private static function utf16Length(string $text): ?int
    {
        $codePoints = preg_match_all('/./us', $text);
        if ($codePoints === false) {
            return null;
        }

        return $codePoints + (int) preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $text);
    }

    /**
     * GRO sends every field, so these only stand in for one left empty.
     *
     * @return array<string, string>
     */
    private static function defaults(string $kind): array
    {
        $defaults = [
            'emailPlaceholder' => __('Your email', 'grocers-list'),
            'namePlaceholder'  => __('First name', 'grocers-list'),
            'buttonLabel'      => __('Subscribe', 'grocers-list'),
            'successMessage'   => __("You're in! Check your inbox.", 'grocers-list'),
            'optInLabel'       => __('I agree to receive emails and can unsubscribe at any time.', 'grocers-list'),
            'layout'           => 'inline',
            'appearance'       => 'plain',
        ];

        if ($kind === 'SAVE_TO_EMAIL') {
            $defaults['buttonLabel'] = __('Send Recipe', 'grocers-list');
            $defaults['successMessage'] = __('Sent! Check your inbox.', 'grocers-list');
            $defaults['optInLabel'] = __('Also send me new recipes and updates', 'grocers-list');
            $defaults['layout'] = 'stacked';
            $defaults['appearance'] = 'boxed';
        } elseif ($kind === 'CONTENT_GATE') {
            $defaults['buttonLabel'] = __('Keep reading', 'grocers-list');
            $defaults['layout'] = 'stacked';
            $defaults['appearance'] = 'boxed';
        }

        return $defaults;
    }

    /**
     * Copy is plain text in GRO, escaped where it is printed.
     *
     * @param mixed $value
     */
    private static function text($value, string $fallback): string
    {
        $text = is_string($value) ? trim($value) : '';

        return $text !== '' ? $text : $fallback;
    }

    /**
     * The brand colours as custom properties for the wrapper's style. GRO
     * resolves brand.accent to the form's fill: the form's own accent colour
     * when set, else the palette's primary (the colour the creator's email
     * buttons use), and sends brand.buttonText only for that primary fill
     * (the label picked for it in GRO's palette). Here the form's own accent,
     * when it is a hex colour, replaces the brand accent, and the button's text is the
     * brand's button text colour when the fill is the brand accent, and
     * otherwise whichever of black and white reads on the fill: a palette
     * from before the button-text swatch sends none.
     *
     * @param array<string, mixed> $design
     * @param array<string, mixed> $brand
     * @return array<string, string>
     */
    private static function brandColors(array $design, array $brand): array
    {
        $ownAccent = self::hexColor($design['accentColor'] ?? null);
        $accent = $ownAccent !== '' ? $ownAccent : self::hexColor($brand['accent'] ?? null);
        $accentText = $ownAccent === '' ? self::hexColor($brand['buttonText'] ?? null) : '';
        if ($accentText === '' && $accent !== '') {
            $accentText = self::textOn($accent);
        }

        return array_filter([
            '--gl-form-accent'      => $accent,
            '--gl-form-accent-text' => $accentText,
            '--gl-form-background'  => self::hexColor($brand['background'] ?? null),
            '--gl-form-text'        => self::hexColor($brand['text'] ?? null),
        ], function (string $color): bool {
            return $color !== '';
        });
    }

    /**
     * @param array<string, mixed> $form
     */
    private static function wrapperAttributes(array $form, string $source): string
    {
        $classes = 'gl-form gl-form--' . self::KIND_SLUGS[$form['kind']]
            . ' gl-form--' . $form['layout']
            . ' gl-form--' . $form['appearance']
            . ($form['showIcon'] ? ' gl-form--icon' : '')
            . ($form['colors'] ? ' gl-form--brand' : '');

        $style = '';
        foreach ($form['colors'] as $property => $color) {
            $style .= $property . ':' . $color . ';';
        }

        // Only while rendering the block itself: from a shortcode it would
        // pick up the supports of whatever block the shortcode sits in.
        if ($source === 'block' && function_exists('get_block_wrapper_attributes')) {
            return get_block_wrapper_attributes(array_filter(['class' => $classes, 'style' => $style]));
        }

        return 'class="' . esc_attr($classes) . '"' . ($style !== '' ? ' style="' . esc_attr($style) . '"' : '');
    }

    /**
     * @param array<string, mixed> $form
     */
    private static function copy(array $form, string $titleId): string
    {
        $icon = $form['showIcon']
            ? '<svg class="gl-form__icon" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"'
                . ' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
                . self::ICONS[$form['kind']] . '</svg>'
            : '';

        $html = '';
        if ($form['heading'] !== '') {
            $html .= '<p class="gl-form__title" id="' . $titleId . '">' . $icon
                . '<span class="gl-form__title-text">' . esc_html($form['heading']) . '</span></p>';
        } elseif ($icon !== '') {
            $html .= '<p class="gl-form__title">' . $icon . '</p>';
        }

        if ($form['layout'] !== 'compact' && $form['description'] !== '') {
            $html .= '<p class="gl-form__description">' . esc_html($form['description']) . '</p>';
        }

        return $html === '' ? '' : '<div class="gl-form__copy">' . $html . '</div>';
    }

    /**
     * Copy the view script shows, emitted with the form so a translated site
     * translates it. Save to Email has no "returning" line: a remembered
     * address is prefilled without one. "consent" goes only with a consent
     * box the visitor must tick. "throttled" is only for a 429 with no detail
     * of its own, such as a host firewall's page: the submit route's 429s say
     * how long to wait. "emailInvalid" is the submit route's invalid_email
     * detail word for word, so editing the address takes that line back
     * whichever of the two said it.
     *
     * @return array<string, string>
     */
    private static function viewStrings(string $kind, bool $consent): array
    {
        if ($kind === 'SAVE_TO_EMAIL') {
            return [
                'busy'         => __("Sending\u{2026}", 'grocers-list'),
                'generic'      => __('Something went wrong. Please try again.', 'grocers-list'),
                'stale'        => __('Please reload the page and try again.', 'grocers-list'),
                'throttled'    => __('Too many requests from here. Please try again later.', 'grocers-list'),
                'emailMissing' => __('Please enter your email address.', 'grocers-list'),
                'emailInvalid' => __('Please enter a valid email address.', 'grocers-list'),
            ];
        }

        $strings = [
            'busy'         => __("Subscribing\u{2026}", 'grocers-list'),
            'returning'    => __("Welcome back \u{2014} you're already on the list.", 'grocers-list'),
            'generic'      => __('Something went wrong. Please try again.', 'grocers-list'),
            'stale'        => __('Please reload the page and try again.', 'grocers-list'),
            'throttled'    => __('Too many signups from here. Please try again later.', 'grocers-list'),
            'emailMissing' => __('Please enter your email address.', 'grocers-list'),
            'emailInvalid' => __('Please enter a valid email address.', 'grocers-list'),
        ];

        if ($consent) {
            $strings['consent'] = __('Tick the box to sign up.', 'grocers-list');
        }

        return $strings;
    }

    /**
     * What an editor sees where a form cannot show; visitors see nothing.
     */
    private static function notice(string $notice): string
    {
        if (!current_user_can('edit_posts')) {
            return '';
        }

        switch ($notice) {
            case self::NOTICE_CONNECT:
                $text = __('Connect this site to GRO to show this form. Only editors see this notice.', 'grocers-list');
                break;
            case self::NOTICE_CHOOSE:
                $text = __("Choose a GRO form in this block\u{2019}s settings. Only editors see this notice.", 'grocers-list');
                break;
            default:
                $text = __("This form no longer exists in GRO \u{2014} pick another one. Only editors see this notice.", 'grocers-list');
        }

        return '<p class="gl-form-notice">' . esc_html($text) . '</p>';
    }

    /**
     * The post the form sends: the one in the loop, on the singular page, in
     * a Query Loop's post template, or whose gated content GateContent is
     * rendering (a form a gate hid, now shown). Anywhere else, like a footer
     * on an archive, the global post is just whichever post the loop left
     * behind, so there is no one post to send.
     *
     * @param mixed $block
     */
    private static function contextPostId($block): int
    {
        $context = is_object($block) && isset($block->context) && is_array($block->context)
            ? $block->context
            : [];
        $inPost = (function_exists('in_the_loop') && in_the_loop())
            || (function_exists('is_singular') && is_singular())
            || GateContent::renderingPostId() > 0;

        if (isset($context['postId']) && ($inPost || isset($context['queryId']))) {
            return (int) $context['postId'];
        }

        return $inPost ? (int) get_the_ID() : 0;
    }

    /**
     * The recipe_id attribute, else the recipe WP Recipe Maker is rendering,
     * else the first recipe in the post, else none (a plain post).
     */
    private function recipeId(int $recipeId, int $postId): int
    {
        if ($recipeId > 0) {
            return $recipeId;
        }

        $current = $this->wprm->currentRecipeId();

        return $current > 0 ? $current : $this->wprm->firstRecipeIdIn($postId);
    }

    /**
     * @param mixed $color
     */
    private static function hexColor($color): string
    {
        $hex = is_string($color) ? ltrim(trim($color), '#') : '';

        return preg_match('/^(?:[0-9a-f]{3}){1,2}$/i', $hex) === 1 ? '#' . strtolower($hex) : '';
    }

    /**
     * Black or white, whichever has more contrast on the given colour.
     */
    private static function textOn(string $hex): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $channels = array_map(function (string $pair): float {
            $value = hexdec($pair) / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, str_split($hex, 2));
        $luminance = 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];

        return 1.05 / ($luminance + 0.05) >= ($luminance + 0.05) / 0.05 ? '#fff' : '#000';
    }
}
