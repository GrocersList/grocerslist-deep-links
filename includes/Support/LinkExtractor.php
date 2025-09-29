<?php

namespace GrocersList\Support;

class LinkExtractor
{
    static function extract(string $content): array
    {
        preg_match_all(Regex::amazonLink(), $content, $hrefMatches);
        $hrefLinks = $hrefMatches[1] ?? [];

        preg_match_all(Regex::amazonLinkWithDataAttribute(), $content, $dataMatches);
        $dataLinks = $dataMatches[1] ?? [];

        return array_unique(array_merge($hrefLinks, $dataLinks));
    }

    static function extractUnrewrittenLinks(string $content): array
    {
        preg_match_all(Regex::amazonLink(), $content, $allMatches, PREG_SET_ORDER);

        $unrewrittenLinks = [];

        foreach ($allMatches as $match) {
            $fullTag = $match[0];
            $url = $match[1];

            if (strpos($fullTag, 'data-grocerslist-rewritten-link') === false) {
                $unrewrittenLinks[] = $url;
            }
        }

        return $unrewrittenLinks;
    }
}
