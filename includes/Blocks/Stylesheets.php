<?php

namespace GrocersList\Blocks;

/**
 * The stylesheets of the GRO forms, the popup and the content gate, printed
 * in the page as <style> elements, each once a request: in the head when the
 * page is known by then to need it (printHead()), else just before the
 * first markup that needs it, rather than enqueued as <link>s. A "Remove
 * Unused CSS" optimizer (WP Rocket, Perfmatters, FlyingPress) takes out the
 * <link>s of a page and keeps only the rules it found used, and a form it
 * found none for renders unstyled; an inline <style> it is told to leave
 * alone stays with the form. Each element carries the attributes Autoptimize,
 * LiteSpeed and other minifiers skip an element by, and register() puts their
 * ids, the marker their CSS starts with and the files' path on the
 * optimizers' own lists (docs/FORMS.md, "The stylesheets").
 *
 * They print in one order, whichever asks first: the popup's and the gate's
 * override the form's at equal specificity (.gl-gate__card .gl-form), so the
 * form's comes first, and asking for either of them prints it too when it
 * has not printed yet.
 *
 * Minified as they print, onto one line: wpautop keeps a line break only
 * inside <pre>, and a theme that runs it again over the rendered post turns
 * every one in a <style> into <br />.
 */
class Stylesheets
{
    /** The sheets by name, in the order they print. */
    private const SHEETS = [
        'form'         => ['file' => 'assets/blocks/form/style.css', 'id' => 'gl-form-css', 'marker' => '/* gl-form */'],
        'popup'        => ['file' => 'assets/blocks/popup/style.css', 'id' => 'gl-popup-css', 'marker' => '/* gl-popup */'],
        'content-gate' => ['file' => 'assets/blocks/content-gate/style.css', 'id' => 'gl-gate-css', 'marker' => '/* gl-gate */'],
    ];

    /** The stylesheets' files in a URL, for the optimizers that read <link>s. */
    private const PATH = 'grocerslist/assets/blocks/';

    private const SELECTORS = ['.gl-form', '.gl-popup', '.gl-gate'];

    /**
     * What in a post's saved content renders a form: the GRO Form block and
     * shortcodes, and a WP Recipe Maker recipe, whose card template can hold
     * [gro_save_to_email] (docs/FORMS.md, "Inside a WP Recipe Maker card").
     * Nothing says whether it does, so every recipe counts.
     */
    private const FORM_BLOCKS = [FormBlock::BLOCK_NAME, 'wp-recipe-maker/recipe'];
    private const FORM_SHORTCODES = [FormShortcodes::FORM, FormShortcodes::SAVE_TO_EMAIL, 'wprm-recipe'];

    /** A comment, or a string in either quote; what lies between them is code. */
    private const TOKENS = '~(/\*.*?\*/|"[^"\\\\]*(?:\\\\.[^"\\\\]*)*"|\'[^\'\\\\]*(?:\\\\.[^\'\\\\]*)*\')~s';

    /** @var array<string, true> The sheets printed in this request, or for this post (printOnce()), by name. */
    private static array $printed = [];

    /** @var int|false|null The post they printed for, in a request that serves fragments; null before any. */
    private static $forPost = null;

    /** @var array<string, string> Each sheet's CSS as it prints, by name. */
    private static array $css = [];

    private ?PopupRenderer $popup;

    public function __construct(?PopupRenderer $popup = null)
    {
        $this->popup = $popup;
    }

    public function register(): void
    {
        // After wp_enqueue_scripts (1), where the popup decides whether this
        // page prints it; before wp_print_styles (8), so the theme's
        // stylesheets follow the forms', as they followed the forms' <link>s.
        add_action('wp_head', [$this, 'printHead'], 7);
        add_filter('rocket_rucss_inline_atts_exclusions', [$this, 'addIds']);
        add_filter('rocket_rucss_inline_content_exclusions', [$this, 'addMarkers']);
        add_filter('rocket_rucss_external_exclusions', [$this, 'addPath']);
        add_filter('perfmatters_rucss_excluded_stylesheets', [$this, 'addPath']);
        add_filter('perfmatters_rucss_excluded_selectors', [$this, 'addSelectors']);
    }

    /**
     * WP Rocket leaves an inline <style> whose attributes hold one of these.
     *
     * @param mixed $ids
     * @return array<int|string, mixed>
     */
    public function addIds($ids): array
    {
        return self::merge($ids, array_column(self::SHEETS, 'id'));
    }

    /**
     * And one whose CSS holds one of these.
     *
     * @param mixed $markers
     * @return array<int|string, mixed>
     */
    public function addMarkers($markers): array
    {
        return self::merge($markers, array_column(self::SHEETS, 'marker'));
    }

    /**
     * WP Rocket and Perfmatters leave a <link> whose URL holds this.
     *
     * @param mixed $paths
     * @return array<int|string, mixed>
     */
    public function addPath($paths): array
    {
        return self::merge($paths, [self::PATH]);
    }

    /**
     * Perfmatters keeps a rule whose selector holds one of these.
     *
     * @param mixed $selectors
     * @return array<int|string, mixed>
     */
    public function addSelectors($selectors): array
    {
        return self::merge($selectors, self::SELECTORS);
    }

    /**
     * On wp_head, the sheets the page is known by then to need, marked
     * printed so that their markup prints none: on a singular page, the
     * form's for a GRO Form block, a form shortcode or a WP Recipe Maker
     * recipe in its post (FORM_BLOCKS, FORM_SHORTCODES) and the gate's too
     * for a GRO Content Gate block there; on any page, the form's and the
     * popup's when the popup prints (PopupRenderer decided on
     * wp_enqueue_scripts). A render of the content thrown away before the
     * page's own (a theme's reading time) then uses up nothing. Not for an
     * archive's posts, which mostly show excerpts whose forms never render.
     * A form this misses (in a widget, a theme's do_shortcode(), a post an
     * archive shows in full) prints its sheet with its markup.
     *
     * Only while nothing has printed: a block theme renders its template
     * before wp_head and prints it after, so the form's sheet, printed with
     * its markup then, would follow a popup's or a gate's printed here.
     */
    public function printHead(): void
    {
        if (self::$printed !== [] || self::servesFragments()) {
            return;
        }

        $sheets = self::singularSheets();
        if ($this->popup !== null && $this->popup->prints()) {
            $sheets[] = 'popup';
        }

        if ($sheets !== []) {
            echo self::styles($sheets);
        }
    }

    /**
     * The <style> elements of $sheets ('form', 'popup', 'content-gate') and
     * of the form's, those not printed yet, in print order, each marked
     * printed: the caller prints them before the markup they style. '' once
     * all have printed: on a page, once a request; in a request that serves
     * fragments, once a post, what printed being forgotten whenever
     * get_the_ID() changes, so that each post's HTML carries its own.
     *
     * '' where what renders goes into no page (silent()), and the sheets
     * left unmarked for a render before the template starts
     * (beforeTemplate()).
     */
    public static function printOnce(string ...$sheets): string
    {
        if (self::silent()) {
            return '';
        }

        if (self::servesFragments()) {
            $post = function_exists('get_the_ID') ? get_the_ID() : false;
            if ($post !== self::$forPost) {
                self::$printed = [];
                self::$forPost = $post;
            }
        }

        return self::styles($sheets, !self::beforeTemplate());
    }

    /**
     * For the tests: a request of their own, with nothing printed and the
     * files read afresh.
     */
    public static function reset(): void
    {
        self::$printed = [];
        self::$forPost = null;
        self::$css = [];
    }

    /**
     * Whether this request serves pieces of HTML that each go their own way
     * rather than a page: a REST request (each post's content.rendered in a
     * list), an AJAX request (a theme's "load more") or WP-CLI (an export).
     * None of them runs template_redirect or wp_head.
     */
    private static function servesFragments(): bool
    {
        return (defined('REST_REQUEST') && REST_REQUEST)
            || (defined('DOING_AJAX') && DOING_AJAX)
            || (defined('WP_CLI') && WP_CLI);
    }

    /**
     * Whether what renders goes into no page: in wp-admin and in a WP-Cron
     * run, and during wp_head (an SEO plugin's description, WP Recipe Maker's
     * JSON-LD; printHead() is the head's own) and while an automatic excerpt
     * is made (get_the_excerpt), whose render ends up as the text of a meta
     * tag or a teaser: a sheet there gains nothing, and one stripped with
     * PHP's strip_tags() would leave its CSS in that text. An AJAX request
     * prints, though is_admin() is true in admin-ajax.php: what it renders
     * goes into a page.
     */
    private static function silent(): bool
    {
        // DOING_AJAX and DOING_CRON, not wp_doing_ajax() and wp_doing_cron(),
        // which are WordPress 4.7+ and 4.8+.
        return (function_exists('is_admin') && is_admin() && !(defined('DOING_AJAX') && DOING_AJAX))
            || (defined('DOING_CRON') && DOING_CRON)
            || (function_exists('doing_action') && doing_action('wp_head'))
            || (function_exists('doing_filter') && doing_filter('get_the_excerpt'));
    }

    /**
     * Whether a page renders before its template starts: until
     * template_redirect has run, and while it runs. An SEO or schema plugin
     * rendering a post on wp throws that render away; a coming-soon or
     * landing-page plugin, or legacy AMP, serves its whole page from
     * template_redirect. Such a render gets its sheets and uses none of them
     * up: served, it keeps its copy; thrown away, the copy goes with it, and
     * the page's own markup prints its own.
     */
    private static function beforeTemplate(): bool
    {
        return !self::servesFragments()
            && function_exists('did_action')
            && (!did_action('template_redirect') || doing_action('template_redirect'));
    }

    /**
     * On a singular page, the sheets the forms and gates in its post need,
     * read off the main query's saved content. has_block() is WordPress
     * 5.0+, and the blocks register on 5.6+: before that the shortcodes are
     * all there is.
     *
     * @return array<int, string>
     */
    private static function singularSheets(): array
    {
        $query = $GLOBALS['wp_query'] ?? null;
        if (!$query instanceof \WP_Query
            || !is_array($query->posts)
            || !function_exists('is_singular')
            || !is_singular()) {
            return [];
        }

        $blocks = function_exists('has_block');
        $sheets = [];
        foreach ($query->posts as $post) {
            $content = is_object($post) && isset($post->post_content) && is_string($post->post_content)
                ? $post->post_content
                : '';
            if ($content === '') {
                continue;
            }

            if ($blocks && has_block(ContentGateBlock::BLOCK_NAME, $content)) {
                $sheets['content-gate'] = true;
            }
            if (self::rendersForm($content, $blocks)) {
                $sheets['form'] = true;
            }
        }

        return array_keys($sheets);
    }

    private static function rendersForm(string $content, bool $blocks): bool
    {
        foreach (self::FORM_SHORTCODES as $tag) {
            if (has_shortcode($content, $tag)) {
                return true;
            }
        }

        foreach ($blocks ? self::FORM_BLOCKS : [] as $name) {
            if (has_block($name, $content)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $sheets
     * @param bool $mark Whether they count as printed, so that none prints again.
     */
    private static function styles(array $sheets, bool $mark = true): string
    {
        $sheets[] = 'form';
        $html = '';
        foreach (self::SHEETS as $name => $sheet) {
            if (isset(self::$printed[$name]) || !in_array($name, $sheets, true)) {
                continue;
            }

            if ($mark) {
                self::$printed[$name] = true;
            }
            $css = self::css($name);
            if ($css !== '') {
                $html .= '<style id="' . $sheet['id'] . '" data-noptimize="1" data-no-optimize="1" data-no-minify="1">'
                    . $sheet['marker'] . $css . '</style>';
            }
        }

        return $html;
    }

    private static function css(string $name): string
    {
        if (!isset(self::$css[$name])) {
            $path = dirname(__DIR__, 2) . '/' . self::SHEETS[$name]['file'];
            $source = is_readable($path) ? file_get_contents($path) : false;
            self::$css[$name] = is_string($source) ? self::minify($source) : '';
        }

        return self::$css[$name];
    }

    /**
     * $css with its comments dropped and its code squeezed (squeeze()), its
     * strings left as written: the gate's glimpse draws its words with
     * content: "…". A "</" becomes "<\/", the same to CSS, so that nothing
     * in a string can end the <style> element.
     */
    private static function minify(string $css): string
    {
        $parts = preg_split(self::TOKENS, $css, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            return '';
        }

        $minified = '';
        $code = '';
        foreach ($parts as $i => $part) {
            if ($i % 2 === 0) {
                $code .= $part;
            } elseif ($part[0] !== '/') {
                $minified .= self::squeeze($code) . $part;
                $code = '';
            }
        }

        return str_replace('</', '<\\/', trim($minified . self::squeeze($code)));
    }

    /**
     * Code between strings: each run of white space one space, and none
     * around { } ; , or after a colon. Kept before a colon, where it is a
     * descendant combinator: ".gl-gate__preview :nth-child(2n)" is not
     * ".gl-gate__preview:nth-child(2n)".
     */
    private static function squeeze(string $code): string
    {
        return (string) preg_replace(['/\s+/', '/ ?([{};,]) ?/', '/: /'], [' ', '$1', ':'], $code);
    }

    /**
     * @param mixed $list What the optimizer's filter holds so far: a list, unless another filter broke it.
     * @param array<int, string> $ours
     * @return array<int|string, mixed>
     */
    private static function merge($list, array $ours): array
    {
        return array_merge(is_array($list) ? $list : [], $ours);
    }
}
