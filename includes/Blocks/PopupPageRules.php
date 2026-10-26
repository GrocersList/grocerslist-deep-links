<?php

namespace GrocersList\Blocks;

/**
 * Whether the popup's page rules (its config's popup.pages) let it show on a
 * request path. Pure: nothing here reads the request or WordPress.
 *
 * A rule and the path are both compared as a path: scheme, host, query and
 * fragment dropped, percent-escapes decoded, lowercased, runs of slashes read
 * as one, and without a trailing slash, bar the root's own "/". So
 * "https://site.test/About/?x=1" is "/about". A rule ending in "*" matches
 * every path that starts with what comes before the "*", taken as written,
 * its trailing slash too: "/recipes/*" matches "/recipes/" itself and every
 * path under it, and not "/recipes-archive/"; "/recipe*" matches both. Any
 * other rule matches its own path only, so "/" is the home page alone and
 * "/*" every page; a "*" anywhere but at the end is an ordinary character
 * (GRO refuses such a rule).
 *
 * A rule's "//host" names a site, as in a link. The request's path never
 * does: REQUEST_URI carries a host only after a scheme (an absolute-form
 * request), and a path that starts "//" is the path with its first slash
 * doubled, which WordPress serves as the page without it.
 *
 * ALL shows the popup everywhere, whatever the rules; ONLY where a rule
 * matches, so nowhere without one; EXCEPT everywhere but where one does.
 * Anything else counts as ALL.
 */
class PopupPageRules
{
    /**
     * @param array<string, mixed> $pages popup.pages: { mode, urls }.
     * @param string $path The request's path, as REQUEST_URI has it (a query is ignored).
     */
    public static function allows(array $pages, string $path): bool
    {
        $mode = $pages['mode'] ?? null;
        if ($mode !== 'ONLY' && $mode !== 'EXCEPT') {
            return true;
        }

        $path = self::path($path, false, false);
        $matched = false;
        foreach (isset($pages['urls']) && is_array($pages['urls']) ? $pages['urls'] : [] as $rule) {
            if (is_string($rule) && self::matches(trim($rule), $path)) {
                $matched = true;
                break;
            }
        }

        return $mode === 'ONLY' ? $matched : !$matched;
    }

    /**
     * @param string $path Already compared as a path (path()).
     */
    private static function matches(string $rule, string $path): bool
    {
        if ($rule === '') {
            return false;
        }

        if (substr($rule, -1) !== '*') {
            return self::path($rule, false, true) === $path;
        }

        // The path with its trailing slash back, so "/recipes/*" takes in
        // "/recipes" as well as what is under it.
        $prefix = self::path(substr($rule, 0, -1), true, true);

        return strpos(rtrim($path, '/') . '/', $prefix) === 0;
    }

    /**
     * $url as the rules compare it. A relative path starts at the root.
     *
     * @param bool $rule Whether $url is a rule, whose "//host" without a scheme names a site.
     */
    private static function path(string $url, bool $keepTrailingSlash, bool $rule): string
    {
        $url = (string) preg_replace('/[?#].*$/s', '', $url);
        $url = (string) preg_replace(
            $rule ? '#^(?:[a-z][a-z0-9+.-]*:)?//[^/]*#i' : '#^[a-z][a-z0-9+.-]*://[^/]*#i',
            '',
            $url
        );
        $path = (string) preg_replace('#/{2,}#', '/', strtolower(rawurldecode($url)));

        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }

        return $keepTrailingSlash || $path === '/' ? $path : (rtrim($path, '/') ?: '/');
    }
}
