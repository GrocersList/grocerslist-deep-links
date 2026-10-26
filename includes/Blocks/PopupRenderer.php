<?php

namespace GrocersList\Blocks;

use GrocersList\Service\FormConfigCache;
use GrocersList\Settings\PluginSettings;
use GrocersList\Support\Config;
use GrocersList\Support\WprmRecipes;

/**
 * The creator's GRO popup: the POPUP form they turned on in GRO, printed in
 * the footer of every page its page rules allow, as a <dialog> the view
 * script opens over the page (blocks/src/form/popup.ts). There is nothing to
 * place in WordPress; the markup and its classes are documented at the top of
 * assets/blocks/popup/style.css, which the HQ preview copies.
 *
 * A page cache serves one HTML per URL to every visitor, so nothing printed
 * here depends on who is asking: no cookie, no signed-in user, no member.
 * The page rules are about the URL, so they are applied here, and a page they
 * exclude carries no popup at all (PopupPageRules). Who sees it, when it opens
 * and whether it was closed lately are the browser's to decide, from the
 * dialog's data attributes.
 *
 * Printed at wp_footer's priority 10, before wp_print_footer_scripts (20)
 * prints the view script, so its form is in the page when the script wires
 * every form it finds there.
 */
class PopupRenderer
{
    public const STYLE = 'grocerslist-popup-style';

    /** Shows the popup on any page, whatever its rules say, and opens it at once (docs/FORMS.md). */
    public const PREVIEW_PARAM = 'gl_popup_preview';

    private const CLOSE_ICON = '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"'
        . ' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>';

    /** popup.image => the picture's brand field and the dialog's modifier. */
    private const IMAGES = [
        'PROFILE_PHOTO' => ['field' => 'avatarUrl', 'slug' => 'avatar'],
        'LOGO'          => ['field' => 'logoUrl', 'slug' => 'logo'],
    ];

    private FormConfigCache $cache;
    private FormRenderer $forms;
    private WprmRecipes $wprm;

    /** @var array<string, mixed>|false|null The popup this page prints, null for none; false until worked out. */
    private $popup = false;

    /** Whether this page has its popup already: a theme that runs wp_footer twice prints it once. */
    private bool $printed = false;

    public function __construct(?FormConfigCache $cache = null, ?FormRenderer $forms = null, ?WprmRecipes $wprm = null)
    {
        $this->cache = $cache ?? new FormConfigCache();
        $this->wprm = $wprm ?? new WprmRecipes();
        $this->forms = $forms ?? new FormRenderer($this->cache, $this->wprm);
    }

    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('wp_footer', [$this, 'render'], 10);
    }

    /**
     * In the head, on a page that will print the popup: its stylesheet, and
     * the form's style and view script as a rendered form has them.
     */
    public function enqueueAssets(): void
    {
        if ($this->popup() !== null) {
            $this->enqueue();
        }
    }

    public function render(): void
    {
        if ($this->printed) {
            return;
        }

        $config = $this->popup();
        $frame = $config !== null ? $this->forms->popupForm($config) : null;
        if ($config === null || $frame === null) {
            return;
        }

        $form = $this->forms->renderPopupForm($config, self::postId());
        if ($form === '') {
            return;
        }

        // Again, for a theme that never runs wp_head: WordPress prints a
        // stylesheet enqueued this late with the footer scripts.
        $this->enqueue();

        $this->printed = true;
        echo self::dialog($config, $frame, $form);
    }

    private function enqueue(): void
    {
        $this->forms->enqueueAssets();
        wp_enqueue_style(
            self::STYLE,
            plugins_url('assets/blocks/popup/style.css', dirname(__DIR__, 2) . '/grocerslist.php'),
            [FormRenderer::STYLE],
            Config::getPluginVersion()
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function popup(): ?array
    {
        if ($this->popup === false) {
            $this->popup = $this->resolve();
        }

        return $this->popup;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolve(): ?array
    {
        if ($this->skipped()) {
            return null;
        }

        $config = $this->cache->getPopup();
        if ($config === null) {
            return null;
        }

        // A distinct URL, so a page cache keeps the preview apart.
        return self::previewRequested() || PopupPageRules::allows($config['popup']['pages'], self::requestPath())
            ? $config
            : null;
    }

    /**
     * Requests no visitor reads a popup on, or none could subscribe from.
     */
    private function skipped(): bool
    {
        return (function_exists('is_admin') && is_admin())
            || (function_exists('is_feed') && is_feed())
            || (function_exists('is_embed') && is_embed())
            || (function_exists('is_preview') && is_preview())
            || (function_exists('is_404') && is_404())
            || (defined('REST_REQUEST') && REST_REQUEST)
            // wp_doing_ajax() is WordPress 4.7+, wp_doing_cron() 4.8+.
            || (function_exists('wp_doing_ajax') ? wp_doing_ajax() : (defined('DOING_AJAX') && DOING_AJAX))
            || (function_exists('wp_doing_cron') ? wp_doing_cron() : (defined('DOING_CRON') && DOING_CRON))
            || $this->wprm->isPrintView()
            || PluginSettings::getApiKey() === '';
    }

    private static function previewRequested(): bool
    {
        return isset($_GET[self::PREVIEW_PARAM])
            && is_string($_GET[self::PREVIEW_PARAM])
            && sanitize_text_field(wp_unslash($_GET[self::PREVIEW_PARAM])) === '1';
    }

    private static function requestPath(): string
    {
        return isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])
            ? (string) wp_unslash($_SERVER['REQUEST_URI'])
            : '/';
    }

    /**
     * The post the popup's form sends: the singular page's, else none, which
     * the submit route sends GRO as the home page. None too for a post the
     * submit route would refuse as not public (an attachment's "inherit", a
     * password): the popup shows there as anywhere else, and signs up from it.
     */
    private static function postId(): int
    {
        if (!function_exists('is_singular') || !is_singular() || !function_exists('get_queried_object')) {
            return 0;
        }

        $post = get_queried_object();

        return is_object($post)
            && isset($post->ID, $post->post_status)
            && $post->post_status === 'publish'
            && (string) ($post->post_password ?? '') === ''
            ? (int) $post->ID
            : 0;
    }

    /**
     * @param array<string, mixed> $config
     * @param array{name: string, colors: array<string, string>, titleId: string} $frame FormRenderer::popupForm()
     */
    private static function dialog(array $config, array $frame, string $form): string
    {
        $popup = $config['popup'];
        $hash = $config['hash'];
        $version = isset($config['version']) && is_int($config['version']) ? $config['version'] : 0;
        [$slug, $imageUrl] = self::image($popup['image'], $config['brand'] ?? null);

        if ($frame['titleId'] !== '') {
            $label = ' aria-labelledby="' . esc_attr($frame['titleId']) . '"';
        } else {
            $label = $frame['name'] !== '' ? ' aria-label="' . esc_attr($frame['name']) . '"' : '';
        }

        // The brand colours, as the form's wrapper has them, on the dialog:
        // the card and the close button beside it both wear them.
        $style = '';
        foreach ($frame['colors'] as $property => $color) {
            $style .= $property . ':' . $color . ';';
        }

        return '<dialog class="gl-popup gl-popup--image-' . $slug . '" id="gl-popup-' . esc_attr($hash) . '" hidden'
            . ' data-gl-popup="' . esc_attr($hash . '.' . $version) . '"'
            . ' data-gl-audience="' . strtolower($popup['audience']) . '"'
            . ' data-gl-trigger="' . strtolower($popup['trigger']['type']) . '"'
            . ' data-gl-delay="' . $popup['trigger']['timeDelaySeconds'] . '"'
            . ' data-gl-scroll="' . $popup['trigger']['scrollPercentage'] . '"'
            . ' data-gl-dismiss-days="' . $popup['dismissDays'] . '"'
            . $label
            . ($style !== '' ? ' style="' . esc_attr($style) . '"' : '')
            . '>'
            . '<button type="button" class="gl-popup__close" aria-label="' . esc_attr(__('Close', 'grocers-list')) . '">'
            . self::CLOSE_ICON . '</button>'
            . '<div class="gl-popup__card">'
            . ($imageUrl !== '' ? '<img class="gl-popup__image" src="' . esc_url($imageUrl) . '" alt="" loading="lazy">' : '')
            . $form
            . '</div></dialog>';
    }

    /**
     * The picture above the form: the creator's profile photo or logo, as
     * popup.image picks, when GRO has an http(s) URL for it; else none.
     *
     * @param mixed $brand
     * @return array{0: string, 1: string} The dialog's modifier, and the URL ('' for none).
     */
    private static function image(string $image, $brand): array
    {
        $picked = self::IMAGES[$image] ?? null;
        $url = $picked !== null && is_array($brand) && isset($brand[$picked['field']]) && is_string($brand[$picked['field']])
            ? trim($brand[$picked['field']])
            : '';
        $parts = $url !== '' ? wp_parse_url($url) : false;

        return is_array($parts)
            && isset($parts['scheme'], $parts['host'])
            && in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            && $parts['host'] !== ''
            ? [$picked['slug'], $url]
            : ['none', ''];
    }
}
