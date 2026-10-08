<?php

namespace GrocersList\Rest;

use GrocersList\Support\Logger;
use GrocersList\Support\RateLimiter;

/**
 * What the anonymous form routes share: the checks a submission passes
 * before anything leaves the site, the per-IP limits, and the problem+json
 * they answer with.
 */
abstract class PublicFormController
{
    public const REST_NAMESPACE = 'grocerslist/v1';

    private const MIN_ELAPSED_MS = 1500;
    private const MAX_CLOCK_SKEW = 300;
    private const MAX_EMAIL_LENGTH = 254;

    private RateLimiter $rateLimiter;

    public function __construct(?RateLimiter $rateLimiter = null)
    {
        $this->rateLimiter = $rateLimiter ?? new RateLimiter();
    }

    /**
     * Honeypot, signature, then timing: the checks that need nothing but the
     * request, run before any lookup or per-IP count, so a caught bot or a
     * forged form never uses up a slot in the caller's bucket.
     *
     * @param \WP_REST_Request $request
     * @param callable $verify fn(string $formId, int $ts, string $sig): bool
     * @return \WP_REST_Response|null Null when the submission may go on.
     */
    protected function screen($request, callable $verify)
    {
        $formId = (string) $request->get_param('formId');

        if (trim((string) $request->get_param('website')) !== '') {
            Logger::debug($this->logLabel() . ': ignored submission, formId=' . self::logId($formId));

            return new \WP_REST_Response(['ok' => true, 'status' => 'ignored'], 200);
        }

        $ts = (int) $request->get_param('ts');

        if (!$verify($formId, $ts, (string) $request->get_param('sig'))) {
            return $this->problem(
                400,
                'invalid_signature',
                __('This form is out of date', 'grocers-list'),
                __('Please reload the page and try again.', 'grocers-list')
            );
        }

        // An old ts is fine — page caches serve the same form for hours. A ts in
        // the future, or a submission faster than a human can type, is not.
        $elapsedMs = $request->get_param('elapsedMs');
        if ($ts > time() + self::MAX_CLOCK_SKEW
            || ($elapsedMs !== null && (int) $elapsedMs < self::MIN_ELAPSED_MS)) {
            return $this->problem(
                400,
                'too_fast',
                __('That was too quick', 'grocers-list'),
                __('Please take a moment and submit the form again.', 'grocers-list')
            );
        }

        return null;
    }

    /**
     * Counts this request in the caller's bucket for $policy. Every filter in
     * the policy may change the limit (or turn it off with 0) and the caller
     * id the bucket is keyed on; each gets $filterArgs after the value.
     *
     * @param array{prefix: string, filters: array<int, string>, keyFilters: array<int, string>, limit: int, window: int} $policy
     * @param array<int, mixed> $filterArgs
     * @return int|null Null when the caller is under the limit, else the
     *                  seconds until the window resets, for Retry-After.
     */
    protected function overLimit(array $policy, array $filterArgs = []): ?int
    {
        $config = ['limit' => $policy['limit'], 'window' => $policy['window']];
        foreach ($policy['filters'] as $filter) {
            $config = apply_filters($filter, $config, ...$filterArgs);
        }

        $limit = isset($config['limit']) ? (int) $config['limit'] : $policy['limit'];
        $window = isset($config['window']) ? (int) $config['window'] : $policy['window'];

        if ($limit <= 0 || $window <= 0) {
            return null;
        }

        $caller = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';

        // Behind a proxy REMOTE_ADDR is the proxy, so every visitor shares one
        // bucket. Hosts that trust a real-IP header swap it in here.
        foreach ($policy['keyFilters'] as $filter) {
            $caller = apply_filters($filter, $caller, ...$filterArgs);
        }

        return $this->rateLimiter->hit($policy['prefix'] . md5((string) $caller), $limit, $window);
    }

    /**
     * @param \WP_REST_Request $request
     * @return string The trimmed address, or '' when it is not a valid one.
     */
    protected static function submittedEmail($request): string
    {
        $email = trim((string) $request->get_param('email'));

        return strlen($email) <= self::MAX_EMAIL_LENGTH && is_email($email) ? $email : '';
    }

    /**
     * @return \WP_REST_Response
     */
    protected function invalidEmail()
    {
        return $this->problem(
            400,
            'invalid_email',
            __('Invalid email', 'grocers-list'),
            __('Please enter a valid email address.', 'grocers-list')
        );
    }

    /**
     * GRO's Retry-After in seconds, or null when it sent none this can read:
     * an HTTP date, or a repeated header, which wp_remote_retrieve_header()
     * gives as an array that (int) would read as 1.
     *
     * @param array|\WP_Error $response
     */
    protected static function upstreamRetryAfter($response): ?int
    {
        $header = wp_remote_retrieve_header($response, 'retry-after');
        $retryAfter = is_numeric($header) ? (int) $header : 0;

        return $retryAfter > 0 ? $retryAfter : null;
    }

    /**
     * How long a 429's detail tells the visitor to wait. "A minute" stands
     * for anything up to a minute and a half; past that the wait is rounded
     * up, so it never names a time before the limit has passed. Without a
     * Retry-After to go on, it names no time at all.
     */
    protected function retryCopy(?int $seconds): string
    {
        if ($seconds === null) {
            return __('Please try again later.', 'grocers-list');
        }

        if ($seconds <= 90) {
            return __('Please wait a minute and try again.', 'grocers-list');
        }

        if ($seconds < 3600) {
            $minutes = (int) ceil($seconds / 60);

            return sprintf(
                /* translators: %d: the minutes to wait, from 2 to 60. */
                _n('Please wait about %d minute and try again.', 'Please wait about %d minutes and try again.', $minutes, 'grocers-list'),
                $minutes
            );
        }

        $hours = (int) ceil($seconds / 3600);
        if ($hours === 1) {
            return __('Please wait about an hour and try again.', 'grocers-list');
        }

        return sprintf(
            /* translators: %d: the hours to wait, 2 or more. */
            _n('Please wait about %d hour and try again.', 'Please wait about %d hours and try again.', $hours, 'grocers-list'),
            $hours
        );
    }

    /**
     * The form id is whatever the visitor posted, and Logger::debug() writes
     * straight to debug.log, so strip the line breaks that would let them
     * forge log entries.
     */
    protected static function logId(string $formId): string
    {
        return substr(sanitize_text_field($formId), 0, 64);
    }

    /**
     * @return \WP_REST_Response
     */
    protected function problem(int $status, string $code, string $title, string $detail)
    {
        return new \WP_REST_Response([
            'type'   => 'about:blank',
            'title'  => $title,
            'status' => $status,
            'detail' => $detail,
            'code'   => $code,
        ], $status);
    }

    /**
     * No header without a wait to pass on: the plugin never makes one up.
     *
     * @return \WP_REST_Response
     */
    protected function withRetryAfter(\WP_REST_Response $response, ?int $retryAfter)
    {
        if ($retryAfter !== null) {
            $response->header('Retry-After', (string) $retryAfter);
        }

        return $response;
    }

    protected function logLabel(): string
    {
        $class = static::class;
        $slash = strrpos($class, '\\');

        return $slash === false ? $class : substr($class, $slash + 1);
    }
}
