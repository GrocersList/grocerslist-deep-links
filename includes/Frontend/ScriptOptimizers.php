<?php

namespace GrocersList\Frontend;

use GrocersList\Support\Config;

/**
 * Keeps the JS optimizers (WP Rocket, LiteSpeed Cache, Autoptimize,
 * Perfmatters, SiteGround Optimizer) away from the widget's script, the
 * client bundle and the inline `var grocersList` the widget polls for. WP
 * Rocket's minifier corrupts the widget's bundle (it reads the `//` in a
 * string as a comment), and a delayed or deferred copy mounts late or never.
 *
 * Each filter adds to the list it is given and keeps what is there; an
 * optimizer's matching rules decide each value: a regex over the script's
 * URL (rocket_exclude_js, rocket_exclude_defer_js) or its whole element
 * (rocket_delay_js_exclusions), a substring of its URL or content (the
 * rest), or its handle (SiteGround).
 */
class ScriptOptimizers
{
    public const HANDLES = ['grocers-list-client', 'grocers-list-external'];

    /** What the wp_localize_script() output for the client bundle holds. */
    private const INLINE_MARKER = 'var grocersList';

    /** The client bundle's file, for a matcher that reads its path on disk. */
    private const BUNDLE_FILE = 'client-ui/dist/bundle.js';

    /**
     * WP Rocket's minifier skips a tag by data-no-minify, LiteSpeed by
     * data-no-optimize and data-no-defer, and Autoptimize by data-noptimize.
     */
    private const ATTRIBUTES = ' data-no-optimize="1" data-no-minify="1" data-no-defer="1" data-noptimize="1"';

    private ClientScripts $clientScripts;

    public function __construct(ClientScripts $clientScripts)
    {
        $this->clientScripts = $clientScripts;
    }

    public function register(): void
    {
        add_filter('script_loader_tag', [$this, 'markTag'], 10, 2);

        add_filter('rocket_minify_excluded_external_js', [$this, 'addExternalHost']);
        add_filter('rocket_exclude_js', [$this, 'addBundlePath']);
        add_filter('rocket_delay_js_exclusions', [$this, 'addAll']);
        add_filter('rocket_exclude_defer_js', [$this, 'addUrls']);
        add_filter('rocket_excluded_inline_js_content', [$this, 'addInlineMarker']);

        // LiteSpeed matches these against inline scripts too: with JS Combine
        // on, "Combine External and Inline" (on by default) would move
        // `var grocersList` into the combined file at the end of the body.
        add_filter('litespeed_optimize_js_excludes', [$this, 'addAll']);
        add_filter('litespeed_optm_js_defer_exc', [$this, 'addAll']);
        add_filter('litespeed_optm_gm_js_exc', [$this, 'addAll']);

        add_filter('autoptimize_filter_js_exclude', [$this, 'addAutoptimizeExclusions']);
        // Autoptimize still minifies an excluded local file unless this lists
        // it, matched against the file's path on disk, where the URL's "../"
        // may be resolved.
        add_filter('autoptimize_filter_js_consider_minified', [$this, 'addBundleFile']);

        add_filter('perfmatters_delay_js_exclusions', [$this, 'addAll']);
        add_filter('perfmatters_defer_js_exclusions', [$this, 'addUrls']);
        add_filter('perfmatters_minify_js_exclusions', [$this, 'addUrls']);

        add_filter('sgo_js_minify_exclude', [$this, 'addHandles']);
        add_filter('sgo_javascript_combine_exclude', [$this, 'addHandles']);
        add_filter('sgo_js_async_exclude', [$this, 'addHandles']);
        add_filter('sgo_javascript_combine_excluded_external_paths', [$this, 'addExternalHost']);
        add_filter('sgo_javascript_combine_excluded_inline_content', [$this, 'addInlineMarker']);
    }

    /**
     * Our own <script src> tags only: the tag's first element with a src,
     * not the inline before/after scripts WordPress prints beside it.
     *
     * @param mixed $tag
     * @param mixed $handle
     * @return mixed
     */
    public function markTag($tag, $handle = '')
    {
        if (!is_string($tag) || !in_array($handle, self::HANDLES, true) || strpos($tag, 'data-no-optimize=') !== false) {
            return $tag;
        }

        $marked = preg_replace('/<script\b(?=[^>]*\ssrc=)/i', '<script' . self::ATTRIBUTES, $tag, 1);

        return is_string($marked) ? $marked : $tag;
    }

    /**
     * @param mixed $list
     * @return array<int|string, mixed>
     */
    public function addExternalHost($list): array
    {
        return self::merge($list, self::nonEmpty([$this->externalHost()]));
    }

    /**
     * @param mixed $list
     * @return array<int|string, mixed>
     */
    public function addBundlePath($list): array
    {
        return self::merge($list, self::nonEmpty([$this->bundlePath()]));
    }

    /**
     * @param mixed $list
     * @return array<int|string, mixed>
     */
    public function addBundleFile($list): array
    {
        return self::merge($list, [self::BUNDLE_FILE]);
    }

    /**
     * @param mixed $list
     * @return array<int|string, mixed>
     */
    public function addUrls($list): array
    {
        return self::merge($list, $this->urls());
    }

    /**
     * @param mixed $list
     * @return array<int|string, mixed>
     */
    public function addInlineMarker($list): array
    {
        return self::merge($list, [self::INLINE_MARKER]);
    }

    /**
     * @param mixed $list
     * @return array<int|string, mixed>
     */
    public function addAll($list): array
    {
        return self::merge($list, array_merge($this->urls(), [self::INLINE_MARKER]));
    }

    /**
     * @param mixed $list
     * @return array<int|string, mixed>
     */
    public function addHandles($list): array
    {
        return self::merge($list, self::HANDLES);
    }

    /**
     * Autoptimize takes a comma-separated string, or an array whose keys are
     * the patterns and whose values are flags ('async', 'defer', 'remove').
     *
     * @param mixed $exclude
     * @return string|array<int|string, mixed>
     */
    public function addAutoptimizeExclusions($exclude)
    {
        $ours = array_merge($this->urls(), [self::INLINE_MARKER]);

        if (is_array($exclude)) {
            foreach ($ours as $pattern) {
                if (!array_key_exists($pattern, $exclude)) {
                    $exclude[$pattern] = '';
                }
            }

            return $exclude;
        }

        $current = is_string($exclude) ? trim($exclude) : '';

        return ($current === '' ? '' : rtrim($current, ', ') . ', ') . implode(', ', $ours);
    }

    /** @return string[] */
    private function urls(): array
    {
        $external = '';
        $host = $this->externalHost();
        if ($host !== '') {
            $url = Config::getExternalJsUrl();
            $port = parse_url($url, PHP_URL_PORT);
            $path = parse_url($url, PHP_URL_PATH);
            $external = $host . (is_int($port) ? ':' . $port : '') . (is_string($path) ? $path : '');
        }

        return self::nonEmpty([$external, $this->bundlePath()]);
    }

    private function externalHost(): string
    {
        $host = parse_url(Config::getExternalJsUrl(), PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }

    private function bundlePath(): string
    {
        $path = parse_url($this->clientScripts->getBundleUrl(), PHP_URL_PATH);

        return is_string($path) ? $path : '';
    }

    /**
     * @param string[] $values
     * @return string[]
     */
    private static function nonEmpty(array $values): array
    {
        return array_values(array_filter($values, function ($value) {
            return $value !== '';
        }));
    }

    /**
     * @param mixed $list
     * @param string[] $ours
     * @return array<int|string, mixed>
     */
    private static function merge($list, array $ours): array
    {
        if (is_string($list) && $list !== '') {
            $list = [$list];
        }

        return array_merge(is_array($list) ? $list : [], $ours);
    }
}
