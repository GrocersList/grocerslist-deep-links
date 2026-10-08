<?php

namespace GrocersList\Rest;

use GrocersList\Blocks\GateContent;
use GrocersList\Support\GateCookie;
use GrocersList\Support\RateLimiter;

/**
 * GET /grocerslist/v1/gate/<postId>/<gateId>: what a content gate hides, for
 * a visitor whose browser holds a valid GateCookie, or whom the
 * grocerslist_content_gate_bypass filter lets through. The view script asks
 * for it on page load, because the page's own HTML — served to everyone from
 * the same page cache — never holds it.
 *
 * - 204, no body: not recognized. The same answer whatever the post and the
 *   gate, so it tells a caller nothing about either, and it counts against
 *   no limit. A hint cookie the signed one no longer backs is expired.
 * - 200 { ok: true, html }: the gate's content, rendered as the page would.
 *   The ok is how the view script tells this answer from a 200 written by
 *   something in front of /wp-json — a WAF, a bot challenge, a maintenance
 *   page — since what comes back goes into the page as HTML, and those carry
 *   neither key. It is the submit route's own rule (FormsController::answer)
 *   on the one other answer the script trusts that far.
 * - 404 gate_not_found: the post is not published, is private or has a
 *   password, is of a type visitors cannot view, or has no such gate.
 * - 429 rate_limited, with Retry-After: over 60 reveals in 600 s from one
 *   address.
 *
 * Every answer is Cache-Control: private, no-store — it turns on a cookie
 * that no shared cache keys on.
 */
class GateController extends PublicFormController
{
    private const LIMIT = [
        'prefix'     => 'grocerslist_gate_rl_',
        'filters'    => ['grocerslist_gate_rate_limit'],
        'keyFilters' => ['grocerslist_gate_rate_limit_key'],
        'limit'      => 60,
        'window'     => 600,
    ];

    private GateContent $content;
    private GateCookie $cookie;

    public function __construct(?GateContent $content = null, ?GateCookie $cookie = null, ?RateLimiter $rateLimiter = null)
    {
        parent::__construct($rateLimiter);
        $this->content = $content ?? new GateContent();
        $this->cookie = $cookie ?? new GateCookie();
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        if (!function_exists('register_rest_route')) {
            return;
        }

        register_rest_route(self::REST_NAMESPACE, '/gate/(?P<postId>\d{1,20})/(?P<gateId>[A-Za-z0-9_-]{1,64})', [
            'methods'             => 'GET',
            'callback'            => [$this, 'reveal'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function reveal($request)
    {
        $postId = (int) $request->get_param('postId');
        $gateId = (string) $request->get_param('gateId');

        // Recognition first, as the form routes screen before they count: a
        // visitor who cannot be let in costs an HMAC, not a slot and a write.
        if (!$this->cookie->fromRequest()
            && apply_filters('grocerslist_content_gate_bypass', false, $postId, $gateId) !== true) {
            $this->cookie->forgetHint();

            return self::noStore(new \WP_REST_Response(null, 204));
        }

        $retryAfter = $this->overLimit(self::LIMIT, [$postId, $gateId]);
        if ($retryAfter !== null) {
            return self::noStore($this->withRetryAfter($this->problem(
                429,
                'rate_limited',
                __('Too many requests', 'grocers-list'),
                __('Please wait a minute and try again.', 'grocers-list')
            ), $retryAfter));
        }

        $html = $this->content->render($postId, $gateId);
        if ($html === null) {
            return self::noStore($this->problem(
                404,
                'gate_not_found',
                __('Nothing to show', 'grocers-list'),
                __('This content is not available.', 'grocers-list')
            ));
        }

        return self::noStore(new \WP_REST_Response(['ok' => true, 'html' => $html], 200));
    }

    private static function noStore(\WP_REST_Response $response): \WP_REST_Response
    {
        $response->header('Cache-Control', 'private, no-store');

        return $response;
    }
}
