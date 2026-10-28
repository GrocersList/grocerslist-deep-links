<?php

namespace GrocersList\Support;

use GrocersList\Admin\PageGating;
use GrocersList\Admin\PostGating;

/**
 * What GRO is sent about a post: the page a GRO form was submitted from
 * (page()), and a post for the creator's Digests (post()). Both are read
 * from the post itself, never from anything a visitor sent, and every string
 * is cut to GRO's limits as GRO counts them, so an optional field never fails
 * a whole request.
 */
class PostPayload
{
    public const MAX_URL_LENGTH = 2048;

    private const TERM_LENGTH = 100;

    private const FORM_TITLE_LENGTH = 300;
    private const FORM_CATEGORIES = 10;

    private const TITLE_LENGTH = 256;
    private const EXCERPT_LENGTH = 2048;
    private const POST_TYPE_LENGTH = 50;
    private const CATEGORIES = 20;
    private const TAGS = 30;
    private const RECIPE_TERMS = 20;

    /** The descriptions Yoast SEO and Rank Math keep, read in this order when a post has no excerpt. */
    private const DESCRIPTION_META = ['_yoast_wpseo_metadesc', 'rank_math_description'];

    private WprmRecipes $wprm;

    public function __construct(?WprmRecipes $wprm = null)
    {
        $this->wprm = $wprm ?? new WprmRecipes();
    }

    /**
     * The page a GRO form was submitted from, worked out from the signed ids.
     * A form outside any one post (a signup form in an archive's footer, say)
     * sends the home page, unless it needs a post, as Save to Email does.
     *
     * @return array{url: string, title: string, categories: array<int, string>, imageUrl?: string}|null
     *         Null when the post is not published, has a password, or is needed and missing.
     */
    public function page(int $postId, int $recipeId, bool $postRequired): ?array
    {
        if ($postId <= 0) {
            return $postRequired ? null : self::homePage();
        }

        $post = get_post($postId);
        if (!self::isPublic($post)) {
            return null;
        }

        $url = (string) get_permalink($post);
        $title = self::plainText((string) get_the_title($post));
        $imageUrl = (string) get_the_post_thumbnail_url($post, 'full');

        $recipe = $recipeId > 0 ? $this->wprm->recipe($recipeId, $postId) : null;
        if ($recipe !== null) {
            // The recipe's own post is its canonical home; the page the form
            // sat on stands in when there is none, or it is not public.
            $parent = $recipe['parentPostId'] > 0 && $recipe['parentPostId'] !== $postId
                ? get_post($recipe['parentPostId'])
                : null;
            if (self::isPublic($parent)) {
                $url = (string) get_permalink($parent);
            }

            $url = $url !== '' ? $url . '#wprm-recipe-container-' . $recipeId : '';
            $name = self::plainText($recipe['name']);
            $title = $name !== '' ? $name : $title;
            $imageUrl = $recipe['imageUrl'] !== '' ? $recipe['imageUrl'] : $imageUrl;
        }

        $url = self::httpUrl($url);
        if ($url === '') {
            return null;
        }

        $page = [
            'url'        => $url,
            'title'      => self::title($title, $url, self::FORM_TITLE_LENGTH),
            'categories' => self::names(get_the_category($postId), self::FORM_CATEGORIES),
        ];

        $imageUrl = self::httpUrl($imageUrl);
        if ($imageUrl !== '') {
            $page['imageUrl'] = $imageUrl;
        }

        return $page;
    }

    /**
     * The post as GRO's Digests take it (POST /api/v1/creator-api/content/posts).
     * Optional fields GRO cannot use are left out rather than sent empty: its
     * schema refuses unknown keys and nulls.
     *
     * "gated" is true for a post only members may read: its own switch, its
     * category's, or a page's. A post with only its recipe cards gated is
     * not: its page is public, and a Digest that sends readers to it sends
     * them to the card the creator's membership sells.
     *
     * @param mixed $post
     * @return array<string, mixed>|null Null for a post that is not public,
     *                                   or has no URL or publish date to send.
     */
    public function post($post): ?array
    {
        if (!self::isPublic($post) || !isset($post->ID, $post->post_type)) {
            return null;
        }

        $postId = (int) $post->ID;
        $url = self::httpUrl((string) get_permalink($post));
        $publishedAt = self::isoUtc((string) ($post->post_date_gmt ?? ''));
        if ($publishedAt === '') {
            // Imported rows can lack the GMT column that WordPress fills on save.
            $publishedAt = self::isoUtc((string) get_gmt_from_date((string) ($post->post_date ?? '')));
        }
        if ($url === '' || $publishedAt === '') {
            return null;
        }

        $recipeId = $this->wprm->firstRecipeIdIn($postId);
        $recipe = $recipeId > 0 ? $this->wprm->recipe($recipeId, $postId, true) : null;

        $entry = [
            'sourceKey' => self::sourceKey($postId),
            'url'       => $url,
            'title'     => self::title(self::plainText((string) get_the_title($post)), $url, self::TITLE_LENGTH),
        ];

        $membersOnly = self::membersOnly($post);
        $excerpt = self::description($post, $membersOnly);
        if ($excerpt !== '') {
            $entry['excerpt'] = $excerpt;
        }

        $imageUrl = self::httpUrl((string) get_the_post_thumbnail_url($post, 'full'));
        if ($imageUrl === '' && $recipe !== null) {
            $imageUrl = self::httpUrl($recipe['imageUrl']);
        }
        if ($imageUrl !== '') {
            $entry['imageUrl'] = $imageUrl;
        }

        $entry['categories'] = self::names(get_the_category($postId), self::CATEGORIES);
        $entry['tags'] = self::names(get_the_tags($postId), self::TAGS);
        $entry['postType'] = self::truncate((string) $post->post_type, self::POST_TYPE_LENGTH);
        $entry['publishedAt'] = $publishedAt;

        $modifiedAt = self::isoUtc((string) ($post->post_modified_gmt ?? ''));
        if ($modifiedAt !== '') {
            $entry['modifiedAt'] = $modifiedAt;
        }

        $entry['gated'] = $membersOnly;

        if ($recipe !== null) {
            $entry['recipe'] = self::recipe($recipe);
        }

        return $entry;
    }

    public static function sourceKey(int $postId): string
    {
        return 'wp:' . $postId;
    }

    /**
     * Published and without a password: what any visitor can read.
     *
     * @param mixed $post
     */
    public static function isPublic($post): bool
    {
        return is_object($post)
            && isset($post->post_status)
            && $post->post_status === 'publish'
            && (string) ($post->post_password ?? '') === '';
    }

    public static function plainText(string $text): string
    {
        $text = html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES, 'UTF-8');

        // U+FEFF shows as nothing, and GRO trims it as JavaScript does where
        // \s does not: a name of nothing else would reach GRO empty.
        $text = str_replace("\u{FEFF}", '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * GRO counts length the way JavaScript does, in UTF-16 code units, so a
     * character outside the Basic Multilingual Plane (most emoji) counts twice.
     */
    public static function truncate(string $text, int $maxUnits): string
    {
        $out = '';
        $units = 0;

        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $units += strlen($char) === 4 ? 2 : 1;
            if ($units > $maxUnits) {
                break;
            }
            $out .= $char;
        }

        return trim($out);
    }

    /**
     * An absolute http(s) URL within GRO's length limit, or '' — anything
     * else would fail the whole request upstream over an optional field.
     */
    public static function httpUrl(string $url): string
    {
        if (strpos($url, '//') === 0) {
            $url = set_url_scheme($url);
        }

        return strlen($url) <= self::MAX_URL_LENGTH && preg_match('#^https?://\S+$#i', $url) === 1
            ? $url
            : '';
    }

    /**
     * @return array{url: string, title: string, categories: array<int, string>}|null
     */
    private static function homePage(): ?array
    {
        $url = self::httpUrl((string) home_url('/'));
        if ($url === '') {
            return null;
        }

        return [
            'url'        => $url,
            'title'      => self::title('', $url, self::FORM_TITLE_LENGTH),
            'categories' => [],
        ];
    }

    /**
     * The title, else the site's name, else the URL: GRO needs one.
     */
    private static function title(string $title, string $url, int $maxUnits): string
    {
        if ($title === '') {
            $title = self::plainText((string) get_bloginfo('name'));
        }

        return self::truncate($title !== '' ? $title : $url, $maxUnits);
    }

    /**
     * Term names, which GRO tags with: decoded, because WordPress stores
     * "Mac & Cheese" as "Mac &amp; Cheese", each once, and untranslated — a
     * tag is a record in GRO, the same whichever locale rendered the page.
     * The site's own category and tag lists go through here too
     * (ContentPublisher::pushTerms()): a Digest filters posts by these names,
     * so both must spell a term the same.
     *
     * @param mixed $terms Term objects or names; anything else counts as none.
     * @return array<int, string>
     */
    public static function names($terms, int $max): array
    {
        $names = [];

        foreach (is_array($terms) ? $terms : [] as $term) {
            $name = is_object($term) && isset($term->name) ? (string) $term->name : (is_string($term) ? $term : '');
            $name = self::truncate(self::plainText($name), self::TERM_LENGTH);

            if ($name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }

            if (count($names) === $max) {
                break;
            }
        }

        return $names;
    }

    /**
     * The post's excerpt as the site shows it, else the description an SEO
     * plugin keeps for it, as plain text. A post only members may read gets
     * an excerpt only when one was written for it: WordPress would make one
     * from its opening words, which are members-only too.
     *
     * @param object $post
     */
    private static function description($post, bool $membersOnly): string
    {
        $text = $membersOnly && trim((string) ($post->post_excerpt ?? '')) === ''
            ? ''
            : self::plainText(strip_shortcodes(self::excerpt($post)));

        foreach (self::DESCRIPTION_META as $key) {
            if ($text !== '') {
                break;
            }

            $meta = get_post_meta((int) $post->ID, $key, true);
            $meta = is_string($meta) ? self::plainText(strip_shortcodes($meta)) : '';
            // A template (Yoast's %%title%%, Rank Math's %title%) is no
            // description until its own plugin fills it in.
            $text = preg_match('/%[a-z0-9_]+%/i', $meta) === 1 ? '' : $meta;
        }

        return self::truncate($text, self::EXCERPT_LENGTH);
    }

    /**
     * get_the_excerpt() with the post set up as the current post, as the REST
     * API does: the site's excerpt filters run as they do on its pages —
     * GateRegion's among them, so an automatic excerpt stops at a content
     * gate — and an automatic excerpt is made from this post's content, which
     * before WordPress 5.2 is read from the current post. The "Read more"
     * link a theme appends to one is left off.
     *
     * @param object $post
     */
    private static function excerpt($post): string
    {
        $previous = $GLOBALS['post'] ?? null;
        $GLOBALS['post'] = $post;
        setup_postdata($post);
        add_filter('excerpt_more', '__return_empty_string', PHP_INT_MAX);

        try {
            return (string) get_the_excerpt();
        } finally {
            remove_filter('excerpt_more', '__return_empty_string', PHP_INT_MAX);
            $GLOBALS['post'] = $previous;
            if (is_object($previous)) {
                setup_postdata($previous);
            }
        }
    }

    /**
     * Whether GRO's membership gating keeps the whole post from visitors: a
     * page's own switch, or a post's or its category's. Recipe cards gated
     * on their own leave the post public.
     *
     * @param object $post
     */
    private static function membersOnly($post): bool
    {
        if ($post->post_type === 'page') {
            return !empty(PageGating::getEffectiveGating((int) $post->ID)['page']);
        }

        return !empty(PostGating::getEffectiveGating((int) $post->ID)['post']);
    }

    /**
     * @param array<string, mixed> $recipe As WprmRecipes::recipe() reads it.
     * @return array<string, mixed>
     */
    private static function recipe(array $recipe): array
    {
        $out = [
            'courses'  => self::names($recipe['courses'] ?? [], self::RECIPE_TERMS),
            'cuisines' => self::names($recipe['cuisines'] ?? [], self::RECIPE_TERMS),
            'keywords' => self::names($recipe['keywords'] ?? [], self::RECIPE_TERMS),
        ];

        foreach (['totalTimeMinutes', 'rating', 'ratingCount', 'servings'] as $key) {
            if (isset($recipe[$key])) {
                $out[$key] = $recipe[$key];
            }
        }

        return $out;
    }

    /**
     * A GMT datetime column as ISO 8601 in UTC, or '' for an empty or zero one.
     */
    private static function isoUtc(string $gmt): string
    {
        $date = $gmt !== '' && strpos($gmt, '0000-00-00') !== 0
            ? \DateTime::createFromFormat('!Y-m-d H:i:s', $gmt, new \DateTimeZone('UTC'))
            : false;

        return $date ? $date->format('Y-m-d\TH:i:s\Z') : '';
    }
}
