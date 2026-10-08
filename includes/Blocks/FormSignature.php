<?php

namespace GrocersList\Blocks;

/**
 * HMAC over what a rendered GRO form carries — its form hash, when it was
 * rendered, and the post and WP Recipe Maker recipe it sits on — so a
 * visitor cannot point a submission at another form or at a page of their
 * choosing. Nothing session-bound is signed, so page-cached HTML keeps
 * working for as long as the cache holds it.
 */
class FormSignature
{
    private const PAGE = 'grocerslist/form';

    public static function signPage(string $formId, int $ts, int $postId, int $recipeId): string
    {
        return hash_hmac(
            'sha256',
            self::PAGE . '|' . $formId . '|' . $ts . '|' . $postId . '|' . $recipeId,
            self::key(self::PAGE)
        );
    }

    public static function verifyPage(string $formId, int $ts, int $postId, int $recipeId, string $sig): bool
    {
        return hash_equals(self::signPage($formId, $ts, $postId, $recipeId), $sig);
    }

    /**
     * Each purpose signs under its own key, derived from the nonce salt, as
     * well as its own prefix: the GL-2436 forms signed with the salt itself
     * and with a key derived for 'grocerslist/save-to-email', and a route
     * added later (the content gate) gets a key of its own, so no signature
     * ever verifies anywhere but where it was made for.
     */
    private static function key(string $purpose): string
    {
        return hash_hmac('sha256', $purpose, wp_salt('nonce'));
    }
}
