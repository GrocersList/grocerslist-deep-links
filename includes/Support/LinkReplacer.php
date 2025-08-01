<?php

namespace GrocersList\Support;

use GrocersList\Model\LinkRewriteResult;


class LinkReplacer implements ILinkReplacer
{
    public function replace(string $content, array $urlMap): LinkRewriteResult
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

                return str_replace(
                    '<a href="' . $match[1] . '"',
                    '<a href="' . $match[1] . '" data-grocerslist-rewritten-link="' . $newUrl . '"',
                    $match[0]
                );
            }
            return $match[0];
        }, $content);

        return new LinkRewriteResult($newContent, $rewritten);
    }
}
