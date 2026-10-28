<?php

namespace GrocersList\Rest;

use GrocersList\Blocks\ContentGateBlock;
use GrocersList\Blocks\FormRenderer;
use GrocersList\Blocks\FormSignature;
use GrocersList\Blocks\GateContent;
use GrocersList\Service\ApiClient;
use GrocersList\Service\FormConfigCache;
use GrocersList\Settings\PluginSettings;
use GrocersList\Support\GateCookie;
use GrocersList\Support\Logger;
use GrocersList\Support\PostPayload;
use GrocersList\Support\RateLimiter;
use GrocersList\Support\WprmRecipes;

/**
 * The GRO Form routes, under grocerslist/v1:
 *
 * - GET  /forms             the creator's forms, for the block's picker (editors)
 * - GET  /forms/<hash>      one form's config, for the block's preview (editors)
 * - POST /forms/submit      a visitor's submission, proxied to GRO (anyone)
 * - POST /forms/invalidate  GRO's hint that a form changed (anyone; always 202)
 *
 * The public routes carry no WP nonce: a nonce is session-bound, so it goes
 * stale inside page-cached HTML and would break every submission on the hosts
 * creators run. The HMAC over formId|ts|postId|recipeId is what keeps a
 * visitor on the form and the page the site rendered.
 *
 * A subscription GRO accepts (subscribe true: a ticked box, or a page
 * without one) also sets the content gate's GateCookie, and when it came
 * from a form inside a gate, the answer carries what that gate hides.
 */
class FormsController extends PublicFormController
{
    private const MAX_FIRST_NAME_LENGTH = 100;

    /**
     * Submissions per IP per window, by form kind. Each kind's own filter runs
     * first — the signup and Save to Email names are the GL-2436 ones — then
     * grocerslist_form_rate_limit / _key, which also get the kind. A popup is
     * a signup form, counted in a bucket of its own.
     */
    private const SUBMIT_LIMITS = [
        'SIGNUP'        => ['limit' => 20, 'window' => 600, 'filter' => 'grocerslist_email_form_rate_limit'],
        'SAVE_TO_EMAIL' => ['limit' => 10, 'window' => 600, 'filter' => 'grocerslist_save_to_email_rate_limit'],
        'CONTENT_GATE'  => ['limit' => 10, 'window' => 600, 'filter' => ''],
        'POPUP'         => ['limit' => 20, 'window' => 600, 'filter' => 'grocerslist_email_form_rate_limit'],
    ];

    private const INVALIDATE_LIMIT = [
        'prefix'     => 'grocerslist_form_hint_rl_',
        'filters'    => ['grocerslist_forms_invalidate_rate_limit'],
        'keyFilters' => ['grocerslist_forms_invalidate_rate_limit_key'],
        'limit'      => 30,
        'window'     => 60,
    ];

    private FormConfigCache $forms;
    private PostPayload $payload;
    private GateCookie $gateCookie;
    private GateContent $gates;

    public function __construct(
        ?FormConfigCache $forms = null,
        ?WprmRecipes $wprm = null,
        ?RateLimiter $rateLimiter = null,
        ?GateCookie $gateCookie = null,
        ?GateContent $gates = null
    ) {
        parent::__construct($rateLimiter);
        $this->forms = $forms ?? new FormConfigCache();
        $this->payload = new PostPayload($wprm ?? new WprmRecipes());
        $this->gateCookie = $gateCookie ?? new GateCookie();
        $this->gates = $gates ?? new GateContent();
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

        register_rest_route(self::REST_NAMESPACE, '/forms', [
            'methods'             => 'GET',
            'callback'            => [$this, 'listForms'],
            'permission_callback' => [$this, 'canEditPosts'],
        ]);

        // The literal routes go before /forms/<hash>, which their paths match too.
        register_rest_route(self::REST_NAMESPACE, '/forms/submit', [
            'methods'             => 'POST',
            'callback'            => [$this, 'submit'],
            'permission_callback' => '__return_true',
            'args'                => [
                'email'      => ['type' => 'string', 'required' => true],
                'firstName'  => ['type' => 'string'],
                'subscribe'  => ['type' => 'boolean'],
                // The hash format FormConfigCache::isHash() checks; WordPress
                // 5.6+ enforces it before the route runs (its PCRE $ still lets
                // a trailing line break through), and the signature covers the
                // exact string on every version.
                'formId'     => ['type' => 'string', 'required' => true, 'pattern' => '^[A-Za-z0-9_-]{1,64}$'],
                'postId'     => ['type' => 'integer', 'required' => true],
                'recipeId'   => ['type' => 'integer'],
                'ts'         => ['type' => 'integer', 'required' => true],
                'sig'        => ['type' => 'string', 'required' => true],
                'elapsedMs'  => ['type' => 'integer'],
                'website'    => ['type' => 'string'],
                // The content gate a form sits in. Not signed and not
                // pattern-checked: a malformed one only means the answer
                // carries no gate, never a refused submission.
                'gateId'     => ['type' => 'string'],
                'gatePostId' => ['type' => 'integer'],
            ],
        ]);

        // No declared args: WordPress would answer a malformed body with its
        // own 400, and this route answers 202 to anything.
        register_rest_route(self::REST_NAMESPACE, '/forms/invalidate', [
            'methods'             => 'POST',
            'callback'            => [$this, 'invalidate'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::REST_NAMESPACE, '/forms/(?P<hash>[A-Za-z0-9_-]{1,64})', [
            'methods'             => 'GET',
            'callback'            => [$this, 'form'],
            'permission_callback' => [$this, 'canEditPosts'],
        ]);
    }

    public function canEditPosts(): bool
    {
        return current_user_can('edit_posts');
    }

    /**
     * The picker's list, as GRO has it now: a copy over a minute old is
     * refreshed first (FormConfigCache::currentForms()), unlike on the render
     * path. Without one, "reason" tells the editor what to say: not_connected
     * (no key, or GRO refused it), unavailable (GRO Forms is not on the
     * account yet) or unreachable.
     *
     * @return \WP_REST_Response
     */
    public function listForms()
    {
        $forms = $this->forms->currentForms();
        $data = [
            'connected' => $forms !== null,
            'forms'     => $forms ?? [],
        ];

        if ($forms === null) {
            $data['reason'] = $this->forms->listProblem();
        }

        return new \WP_REST_Response($data, 200);
    }

    /**
     * The preview's config, refreshed first as for listForms().
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function form($request)
    {
        if (!PluginSettings::getApiKey()) {
            return $this->notConnected();
        }

        $config = $this->forms->getCurrent((string) $request->get_param('hash'));

        return $config === null ? $this->unknownForm(404) : new \WP_REST_Response($config, 200);
    }

    /**
     * GRO's hint that a form changed: the hash and nothing else, and popup
     * true when the form is a popup. The answer is the same whatever arrives
     * — a real hint, a forged one, a malformed one or one over the limit — so
     * it tells a caller nothing. A popup hint, or one naming the form of the
     * popup this site holds, re-reads the popup too (one slot of the limit
     * either way, so at most two GRO requests); a hash of "popup", the
     * popup's own id (FormConfigCache::POPUP_ID), re-reads the popup alone.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function invalidate($request)
    {
        $hash = $request->get_param('hash');
        $hash = FormConfigCache::isHash($hash) ? $hash : '';
        $popup = filter_var($request->get_param('popup'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
        if ($hash === FormConfigCache::POPUP_ID) {
            $hash = '';
            $popup = true;
        }

        if (($hash !== '' || $popup) && $this->overLimit(self::INVALIDATE_LIMIT) === null) {
            if ($hash !== '') {
                $this->forms->invalidate($hash);
            }

            if ($popup || ($hash !== '' && $hash === $this->forms->popupHash())) {
                $this->forms->invalidatePopup();
            }
        }

        return new \WP_REST_Response(new \stdClass(), 202);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function submit($request)
    {
        $formId = (string) $request->get_param('formId');
        $postId = (int) $request->get_param('postId');
        $recipeId = (int) $request->get_param('recipeId');

        $rejected = $this->screen(
            $request,
            function (string $formId, int $ts, string $sig) use ($postId, $recipeId): bool {
                return FormSignature::verifyPage($formId, $ts, $postId, $recipeId, $sig);
            }
        );
        if ($rejected !== null) {
            return $rejected;
        }

        $apiKey = PluginSettings::getApiKey();
        if (!$apiKey) {
            return $this->notConnected();
        }

        $config = $this->forms->get($formId);
        $kind = is_array($config) && isset($config['kind']) && is_string($config['kind'])
            && isset(self::SUBMIT_LIMITS[$config['kind']])
            ? $config['kind']
            : '';
        if ($kind === '') {
            return $this->unknownForm();
        }

        $retryAfter = $this->overLimit(self::submitLimit($kind), [$kind]);
        if ($retryAfter !== null) {
            return $this->rateLimited($retryAfter, $kind);
        }

        $email = self::submittedEmail($request);
        if ($email === '') {
            return $this->invalidEmail();
        }

        // The form's checkbox. On Save to Email it is the opt-in: left
        // unticked, GRO sends the email and writes no contact. On a signup or
        // content-gate form whose config shows it, it is consent to the
        // emails, and GRO is not asked without it; a page without one is the
        // subscription itself. Otherwise the answer goes to GRO as given: a
        // box the page showed that came back unticked is never an opt-in,
        // even on a form whose config has hidden the box since.
        $checkbox = $request->get_param('subscribe');
        $subscribe = self::wantsToSubscribe($checkbox);
        if (!$subscribe && $kind !== 'SAVE_TO_EMAIL' && FormRenderer::requiresConsent($config)) {
            return $this->problem(
                400,
                'consent_required',
                __('Consent required', 'grocers-list'),
                __('Tick the box to sign up.', 'grocers-list')
            );
        }

        // Worked out from the signed ids, never from a URL or title the
        // visitor posted.
        $page = $this->payload->page($postId, $recipeId, $kind === 'SAVE_TO_EMAIL');
        if ($page === null) {
            return $this->problem(
                400,
                'post_unavailable',
                __('This page cannot take this form', 'grocers-list'),
                __('This form only works on published pages without a password.', 'grocers-list')
            );
        }

        $payload = ['email' => $email];

        $firstName = sanitize_text_field((string) $request->get_param('firstName'));
        if ($firstName !== '') {
            $payload['firstName'] = PostPayload::truncate($firstName, self::MAX_FIRST_NAME_LENGTH);
        }

        $payload['subscribe'] = $subscribe;
        // Whether the visitor's page showed the checkbox, which subscribe
        // cannot say: a page without one sends true, as a ticked box does. A
        // page with the box always posts its hidden 0, so the param is there
        // exactly when the box was. From the request, like the answer itself:
        // a cached page can differ from the form's current config.
        $payload['optInShown'] = $checkbox !== null;
        $payload['page'] = $page;

        $payload = apply_filters('grocerslist_form_payload', $payload, [
            'formId'   => $formId,
            'kind'     => $kind,
            'postId'   => $postId,
            'recipeId' => $recipeId,
            'request'  => $request,
        ]);

        $payload = (array) $payload;
        // Read after grocerslist_form_payload, so the Advanced Tracking
        // hand-off and the gate cookie both answer to the subscribe a site
        // filtering the payload left behind.
        $subscribed = self::subscribed($payload);
        $answer = $this->answer(ApiClient::submitForm($apiKey, $formId, $payload), $formId, $kind, $subscribed);

        if ($answer->get_status() === 200 && $subscribed) {
            $this->gateCookie->issue();

            $gate = $this->gateHtml($request);
            if ($gate !== null) {
                $data = $answer->get_data();
                $data['gate'] = ['html' => $gate];
                $answer->set_data($data);
            }
        }

        return $answer;
    }

    /**
     * Whether the visitor subscribed, which is what the content gate
     * recognizes: subscribe went as true, for a ticked box or a page without
     * one. A box the page showed that came back unticked is no subscription
     * on any kind, even where GRO still adds the contact (a signup or
     * content-gate form whose config has hidden the box since). Read from
     * what was sent, after grocerslist_form_payload; GRO subscribes when told
     * nothing.
     *
     * @param array<string, mixed> $payload
     */
    private static function subscribed(array $payload): bool
    {
        return ($payload['subscribe'] ?? true) === true;
    }

    /**
     * What the content gate the form sits in hides, so the view script can
     * show it in the form's place at once; null when the submission names no
     * gate, or GateContent has nothing for it. The visitor is recognized by
     * now (the cookie was just issued), which is all the gate route asks too.
     *
     * @param \WP_REST_Request $request
     */
    private function gateHtml($request): ?string
    {
        $gateId = $request->get_param('gateId');
        $postId = (int) $request->get_param('gatePostId');

        return ContentGateBlock::isGateId($gateId) && $postId > 0
            ? $this->gates->render($postId, $gateId)
            : null;
    }

    /**
     * GRO's answer as this route's: on a 2xx, its status and, when GRO sends
     * one and the submission subscribed, its Advanced Tracking hand-off —
     * unless that 2xx says itself that GRO did not do it (groDenied), which
     * answers as an outage does. The same answer for a new, an existing and a
     * suppressed address, and never GRO's own wording.
     *
     * @param array|\WP_Error $response
     * @param bool $subscribed As self::subscribed() read the payload.
     * @return \WP_REST_Response
     */
    private function answer($response, string $formId, string $kind, bool $subscribed)
    {
        if (is_wp_error($response)) {
            Logger::debug('FormsController: GRO could not be reached, formId=' . self::logId($formId));

            return $this->upstreamUnavailable();
        }

        $status = (int) wp_remote_retrieve_response_code($response);

        if ($status >= 200 && $status < 300) {
            if (self::groDenied($response)) {
                // Status and form only: the body can echo the address back.
                Logger::debug('FormsController: GRO answered ' . $status . ' saying no, formId=' . self::logId($formId));

                return $this->upstreamUnavailable();
            }

            $data = ['ok' => true, 'status' => self::groStatus($response, $kind)];

            // GRO sends no hand-off for an opt-in the visitor declined; an
            // answer standing in for GRO's (a test hook, a proxy, a stale
            // cache) is held to the same rule here.
            $tracking = $subscribed ? self::groAdvancedTracking($response) : null;
            if ($tracking !== null) {
                $data['advancedTracking'] = $tracking;
            }

            return new \WP_REST_Response($data, 200);
        }

        // Status and form only: the body can echo the address back.
        Logger::debug('FormsController: GRO answered ' . $status . ', formId=' . self::logId($formId));

        if ($status === 401) {
            return $this->notConnected();
        }

        // Not on the creator's plan, such as Save to Email.
        if ($status === 403) {
            return $this->problem(
                503,
                'unavailable',
                __('This form is unavailable', 'grocers-list'),
                __('This form is not available right now.', 'grocers-list')
            );
        }

        // Archived since this page was rendered: re-read the config so the form
        // stops rendering. An archived form purges no page cache (see
        // FormConfigCache::invalidate()), so a cached page keeps it until the
        // cache expires.
        if ($status === 404) {
            $this->forms->invalidate($formId);

            return $this->unknownForm();
        }

        if ($status === 429) {
            return $this->upstreamRateLimited(self::upstreamRetryAfter($response), $kind);
        }

        if ($status >= 400 && $status < 500) {
            return $this->upstreamRejected($response);
        }

        return $this->upstreamUnavailable();
    }

    /**
     * @return array{prefix: string, filters: array<int, string>, keyFilters: array<int, string>, limit: int, window: int}
     */
    private static function submitLimit(string $kind): array
    {
        $limit = self::SUBMIT_LIMITS[$kind];

        return [
            'prefix'     => 'grocerslist_form_rl_' . strtolower($kind) . '_',
            'filters'    => array_values(array_filter([$limit['filter'], 'grocerslist_form_rate_limit'])),
            'keyFilters' => array_values(array_filter([
                $limit['filter'] !== '' ? $limit['filter'] . '_key' : '',
                'grocerslist_form_rate_limit_key',
            ])),
            'limit'      => $limit['limit'],
            'window'     => $limit['window'],
        ];
    }

    /**
     * The answer the form's checkbox gave. No subscribe param means a page
     * rendered without the checkbox, which subscribes: GRO reads it the same
     * way, since a page cached before the creator turned the checkbox on
     * shows none. A form with one posts "0" or "1"; anything unreadable
     * counts as "no". Read from the request rather than the form's current
     * config: a page cached before the creator turned a form's checkbox off
     * still asks, and a "no" there is still a no.
     *
     * @param mixed $value
     */
    private static function wantsToSubscribe($value): bool
    {
        if ($value === null) {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }

    /**
     * Whether GRO's 2xx says itself that it did not do it. GRO's contract has
     * one 2xx, { ok: true, status }, and a problem body with a 4xx or a 5xx
     * for every refusal, so an "ok" that is there and is not true is GRO
     * denying the submission in the one answer this route would otherwise
     * read as a success — and a success here sets the gate's cookies, carries
     * what the gate hides and tells the visitor they are in.
     *
     * An "ok" that is absent is read the other way, and stays as forgiving as
     * it has always been (groStatus() then names the kind's own word): no GRO
     * that keeps the contract leaves the key out, so its absence is an older
     * or an odd build answering, not a denial, and the route is not the place
     * to decide that an answer it does not recognize is a refusal.
     *
     * @param array $response
     */
    private static function groDenied($response): bool
    {
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        return is_array($body) && array_key_exists('ok', $body) && $body['ok'] !== true;
    }

    /**
     * GRO's status word ("subscribed", "sent"), else the one the kind implies.
     *
     * @param array $response
     */
    private static function groStatus($response, string $kind): string
    {
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $status = is_array($body) && isset($body['status']) && is_string($body['status']) ? $body['status'] : '';

        if (preg_match('/^[a-z_]{1,32}\z/', $status) === 1) {
            return $status;
        }

        return $kind === 'SAVE_TO_EMAIL' ? 'sent' : 'subscribed';
    }

    /**
     * GRO's Advanced Tracking hand-off, which it sends when the creator has
     * Mediavine or Raptive on: the subscriber's hashed address, passed on as
     * GRO computed it, for the view script to give the page's ad network.
     * Null, so the answer leaves it out, unless the hash is SHA-256 hex and
     * both flags are booleans, one of them true.
     *
     * @param array $response
     * @return array{subscriberHash: string, mediavine: bool, raptive: bool}|null
     */
    private static function groAdvancedTracking($response): ?array
    {
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $tracking = is_array($body) && isset($body['advancedTracking']) && is_array($body['advancedTracking'])
            ? $body['advancedTracking']
            : [];
        $hash = $tracking['subscriberHash'] ?? null;
        $mediavine = $tracking['mediavine'] ?? null;
        $raptive = $tracking['raptive'] ?? null;

        if (!is_string($hash) || preg_match('/^[a-f0-9]{64}\z/', $hash) !== 1
            || !is_bool($mediavine) || !is_bool($raptive) || (!$mediavine && !$raptive)) {
            return null;
        }

        return ['subscriberHash' => $hash, 'mediavine' => $mediavine, 'raptive' => $raptive];
    }

    /**
     * @param array $response
     * @return \WP_REST_Response
     */
    private function upstreamRejected($response)
    {
        $problem = $this->problem(
            400,
            'upstream_rejected',
            __('We could not submit that', 'grocers-list'),
            __('Please check your email address and try again.', 'grocers-list')
        );

        // GRO's field names only, so the view script can tell whether the
        // address is what it rejected; never its wording.
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $fields = [];
        foreach (is_array($body) && isset($body['errors']) && is_array($body['errors']) ? $body['errors'] : [] as $error) {
            if (is_array($error) && isset($error['field']) && is_string($error['field'])
                && preg_match('/^[A-Za-z0-9_.\[\]-]{1,100}\z/', $error['field']) === 1) {
                $fields[] = ['field' => $error['field']];
            }
        }

        if ($fields) {
            $data = $problem->get_data();
            $data['errors'] = $fields;
            $problem->set_data($data);
        }

        return $problem;
    }

    /**
     * The plugin's own per-IP limit for the form's kind, whose detail says
     * how long is left of the window.
     *
     * @return \WP_REST_Response
     */
    protected function rateLimited(int $retryAfter, string $kind)
    {
        $lead = $kind === 'SAVE_TO_EMAIL'
            ? __('Too many requests from here.', 'grocers-list')
            : __('Too many signups from here.', 'grocers-list');

        return $this->withRetryAfter($this->problem(
            429,
            'rate_limited',
            __('Too many requests', 'grocers-list'),
            $lead . ' ' . $this->retryCopy($retryAfter)
        ), $retryAfter);
    }

    /**
     * GRO's own limit. Its submit counts per API key and per recipient, and
     * its 429 carries no code saying which (GL-2518 adds one), so this can
     * say neither "from here" nor "for this address". GRO's Retry-After goes
     * on only when it sent one.
     *
     * @return \WP_REST_Response
     */
    private function upstreamRateLimited(?int $retryAfter, string $kind)
    {
        $lead = $kind === 'SAVE_TO_EMAIL'
            ? __('Too many requests right now.', 'grocers-list')
            : __('Too many signups right now.', 'grocers-list');

        return $this->withRetryAfter($this->problem(
            429,
            'upstream_rate_limited',
            __('Too many requests', 'grocers-list'),
            $lead . ' ' . $this->retryCopy($retryAfter)
        ), $retryAfter);
    }

    /**
     * @return \WP_REST_Response
     */
    private function notConnected()
    {
        return $this->problem(
            503,
            'not_connected',
            __('This form is unavailable', 'grocers-list'),
            __('This site is not connected to GRO yet.', 'grocers-list')
        );
    }

    /**
     * @return \WP_REST_Response
     */
    private function unknownForm(int $status = 400)
    {
        return $this->problem(
            $status,
            'unknown_form',
            __('This form is no longer available', 'grocers-list'),
            __('Please reload the page and try again.', 'grocers-list')
        );
    }

    /**
     * @return \WP_REST_Response
     */
    private function upstreamUnavailable()
    {
        return $this->problem(
            502,
            'upstream_unavailable',
            __('We could not submit that', 'grocers-list'),
            __('We could not reach GRO. Please try again in a moment.', 'grocers-list')
        );
    }
}
