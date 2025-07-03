<?php
namespace GrocersList\Support;

class Regex {
    public static function amazonLink(): string {
        return '/<a\s+[^>]*?href=["\'](https?:\/\/(?:www\.|smile\.)?(?:amazon\.([a-z]{2,3}(?:\.[a-z]{2})?)|amzn\.to)[^"\']*)["\'][^>]*?>.*?<\/a>/i';
    }

    public static function amazonLinkWithDataAttribute(): string {
        return '/<a\s+[^>]*?data-grocerslist-rewritten-link=["\'](https?:\/\/(?:x\.link|linksta\.com)[^"\']*)["\'][^>]*?>.*?<\/a>/i';
    }
}
