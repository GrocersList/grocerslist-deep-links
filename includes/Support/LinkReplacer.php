<?php

namespace GrocersList\Support;

use GrocersList\Model\LinkRewriteResult;


class LinkReplacer
{
    static function replace(string $content, array $urlMap): LinkRewriteResult
    {
        $rewritten = false;

        // Normalize map keys by decoding HTML entities
        $normalizedMap = [];
        foreach ($urlMap as $raw => $replacement) {
            $normalizedMap[html_entity_decode($raw)] = $replacement;
        }

        $newContent = preg_replace_callback(Regex::amazonLink(), function ($match) use ($normalizedMap, &$rewritten) {
            $url = html_entity_decode($match[1]);
            if (isset($normalizedMap[$url])) {
                $rewritten = true;
                $originalUrl = esc_attr($url);
                $newUrl = esc_attr($normalizedMap[$url]);

                // Handle both single and double quotes
                $quoteChar = strpos($match[0], 'href="') !== false ? '"' : "'";
                return str_replace(
                    '<a href=' . $quoteChar . $match[1] . $quoteChar,
                    '<a href=' . $quoteChar . $match[1] . $quoteChar . ' data-grocerslist-rewritten-link="' . $newUrl . '" rel="noopener noreferrer"',
                    $match[0]
                );
            }
            return $match[0];
        }, $content);

        return new LinkRewriteResult($newContent, $rewritten);
    }
}
