<?php

namespace GrocersList\Support;

/**
 * The content gate's recognition cookie. A visitor who subscribes through a
 * GRO form on this site gets gl_forms_subscribed = "<ts>.<hmac>": ts is when
 * it was issued, and hmac is HMAC-SHA256 over ts under a key derived from the
 * nonce salt for 'grocerslist/content-gate' alone, so no signature the plugin
 * makes for anything else (FormSignature) verifies as this, or this as one of
 * those.
 *
 * It is httponly, so no script on the page can read or copy it; only the gate
 * route (GateController) checks it. That leaves the view script unable to
 * tell a subscriber from anyone else without asking the route on every page
 * view, so a second cookie, gl_forms_subscribed_hint=1, rides along with it:
 * readable, and trusted for nothing but deciding whether asking is worth a
 * request. What the route reveals depends on the signed cookie alone.
 *
 * Neither says anything about the visitor. The signed cookie proves that
 * someone in this browser subscribed, not who: this is a list-growth gate,
 * not access control, and a copied cookie opens it in another browser too.
 * Rotating the site's salts ends every recognition at once.
 *
 * Needs PHP 7.3+ for setcookie()'s options array, which is how SameSite is
 * set; the plugin's effective floor is 7.4.
 */
class GateCookie
{
    public const NAME = 'gl_forms_subscribed';
    public const HINT = 'gl_forms_subscribed_hint';

    /** 365 days, in seconds. */
    public const LIFETIME = 31536000;

    /**
     * How far ahead of this server's clock a timestamp may be, in seconds.
     * Behind a load balancer another web server, whose clock may run a little
     * ahead, issued the cookie; and a refusal expires the hint (forgetHint()),
     * which would lock a subscriber out of every later page view for good.
     */
    public const MAX_CLOCK_SKEW = 300;

    private const PURPOSE = 'grocerslist/content-gate';

    /** @var callable(): int */
    private $clock;

    /** @var callable(string, string, array<string, mixed>): void */
    private $send;

    /**
     * @param (callable(): int)|null $clock Unix time now; time() by default.
     * @param (callable(string, string, array<string, mixed>): void)|null $send Sets a cookie
     *        on the response; setcookie() by default.
     */
    public function __construct(?callable $clock = null, ?callable $send = null)
    {
        $this->clock = $clock ?? 'time';
        $this->send = $send ?? static function (string $name, string $value, array $options): void {
            if (headers_sent()) {
                Logger::debug('GateCookie: output has started; ' . $name . ' was not set');

                return;
            }

            setcookie($name, $value, $options);
        };
    }

    /**
     * Sets both cookies on the response, and on this request too, so that a
     * gate rendered for the rest of it (the one in the answer to the submit
     * that issued them) recognizes the visitor already.
     */
    public function issue(): void
    {
        $now = $this->now();
        $value = $now . '.' . self::sign($now);
        $options = self::options($now + self::LIFETIME);

        ($this->send)(self::NAME, $value, $options + ['httponly' => true]);
        ($this->send)(self::HINT, '1', $options + ['httponly' => false]);

        $_COOKIE[self::NAME] = $value;
        $_COOKIE[self::HINT] = '1';
    }

    /**
     * Whether $value is a cookie this site issued, within the last 365 days
     * and no more than MAX_CLOCK_SKEW ahead of now. The timestamp is digits
     * without a leading zero and the signature lowercase hex, exactly as
     * issued.
     *
     * @param mixed $value
     */
    public function verify($value): bool
    {
        if (!is_string($value) || preg_match('/^([1-9][0-9]{0,11})\.([0-9a-f]{64})\z/', $value, $matches) !== 1) {
            return false;
        }

        $ts = (int) $matches[1];
        $now = $this->now();
        if ($ts > $now + self::MAX_CLOCK_SKEW || $now - $ts > self::LIFETIME) {
            return false;
        }

        return hash_equals(self::sign($ts), $matches[2]);
    }

    /**
     * Whether this request carries a cookie verify() accepts.
     */
    public function fromRequest(): bool
    {
        $value = $_COOKIE[self::NAME] ?? null;

        return is_string($value) && $this->verify(wp_unslash($value));
    }

    /**
     * Expires the hint when the signed cookie no longer backs it (it expired
     * with it, or the site's salts changed), so the view script stops asking
     * the gate route on every page view.
     */
    public function forgetHint(): void
    {
        if (!isset($_COOKIE[self::HINT])) {
            return;
        }

        ($this->send)(self::HINT, '', self::options(1) + ['httponly' => false]);
        unset($_COOKIE[self::HINT]);
    }

    private static function sign(int $ts): string
    {
        return hash_hmac('sha256', (string) $ts, hash_hmac('sha256', self::PURPOSE, wp_salt('nonce')));
    }

    /**
     * Host-only, like WordPress's own cookies without COOKIE_DOMAIN, under the
     * site's path: the gate route lives there too.
     *
     * @return array{expires: int, path: string, secure: bool, samesite: string}
     */
    private static function options(int $expires): array
    {
        return [
            'expires'  => $expires,
            'path'     => defined('COOKIEPATH') && is_string(COOKIEPATH) && COOKIEPATH !== '' ? COOKIEPATH : '/',
            'secure'   => (bool) is_ssl(),
            'samesite' => 'Lax',
        ];
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }
}
