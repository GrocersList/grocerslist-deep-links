<?php

namespace GrocersList\Frontend;

use GrocersList\Service\ApiClient;
use GrocersList\Settings\PluginSettings;
use GrocersList\Support\Logger;

class EmailVerificationPage
{
    private const NONCE_ACTION = 'gl_verify_email';
    private const NONCE_FIELD = 'gl_verify_nonce';
    private const QUERY_PARAM = 'gl-verify-token';

    public function register(): void
    {
        add_action('template_redirect', [$this, 'maybeHandle']);
    }

    public function maybeHandle(): void
    {
        if (is_admin()) {
            return;
        }
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return;
        }
        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            return;
        }

        if (empty($_GET[self::QUERY_PARAM])) {
            return;
        }

        $token = sanitize_text_field(wp_unslash($_GET[self::QUERY_PARAM]));

        if (!preg_match('/^[a-f0-9]{24}$/', $token)) {
            $this->renderInvalid();
            exit;
        }

        $method = isset($_SERVER['REQUEST_METHOD'])
            ? strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])))
            : 'GET';

        if ($method === 'POST') {
            $this->handleConfirm($token);
            exit;
        }

        $this->renderConfirm($token);
        exit;
    }

    private function handleConfirm(string $token): void
    {
        $nonce = isset($_POST[self::NONCE_FIELD])
            ? sanitize_text_field(wp_unslash($_POST[self::NONCE_FIELD]))
            : '';

        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            $this->renderInvalid();
            return;
        }

        $success = false;

        try {
            $api_key = PluginSettings::getApiKey();
            $response = ApiClient::verifyEmail($api_key, $token);

            if (is_wp_error($response)) {
                Logger::debug('EmailVerificationPage: verifyEmail returned WP_Error: ' . $response->get_error_message());
            } else {
                $status = wp_remote_retrieve_response_code($response);
                if ($status >= 200 && $status < 300) {
                    $success = true;
                } else {
                    Logger::debug('EmailVerificationPage: verifyEmail non-2xx status: ' . $status);
                }
            }
        } catch (\Throwable $e) {
            Logger::debug('EmailVerificationPage: verifyEmail threw: ' . $e->getMessage());
        }

        if ($success) {
            $this->renderResult(
                'Email verified',
                'Email verified',
                'Your email address has been confirmed. You can now log in.'
            );
        } else {
            $this->renderResult(
                'Verification failed',
                'This link is no longer valid',
                'We could not verify your email. The link may have expired or already been used. Please request a new verification email and try again.'
            );
        }
    }

    private function renderConfirm(string $token): void
    {
        $action = add_query_arg(self::QUERY_PARAM, $token, home_url('/'));
        $nonce = wp_create_nonce(self::NONCE_ACTION);

        $inner = '<h1>Confirm your email</h1>'
            . '<p>Click the button below to confirm your email address.</p>'
            . '<form method="post" action="' . esc_url($action) . '">'
            . '<input type="hidden" name="' . esc_attr(self::QUERY_PARAM) . '" value="' . esc_attr($token) . '" />'
            . '<input type="hidden" name="' . esc_attr(self::NONCE_FIELD) . '" value="' . esc_attr($nonce) . '" />'
            . '<button type="submit" class="gl-verify-button">Confirm</button>'
            . '</form>';

        $this->renderShell('Confirm your email', $inner);
    }

    private function renderResult(string $title, string $heading, string $message): void
    {
        $inner = '<h1>' . esc_html($heading) . '</h1>'
            . '<p>' . esc_html($message) . '</p>';

        $this->renderShell($title, $inner);
    }

    private function renderInvalid(): void
    {
        $this->renderResult(
            'Link no longer valid',
            'This link is no longer valid',
            'This verification link is invalid or has expired. Please request a new verification email and try again.'
        );
    }

    private function renderShell(string $title, string $inner): void
    {
        if (function_exists('nocache_headers')) {
            nocache_headers();
        }

        echo '<!DOCTYPE html>' . "\n";
        echo '<html><head>';
        echo '<meta charset="utf-8" />';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1" />';
        echo '<meta name="robots" content="noindex,nofollow" />';
        echo '<title>' . esc_html($title) . '</title>';
        echo '<style>'
            . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
            . 'background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#1f2933;}'
            . '.gl-verify-card{background:#fff;max-width:420px;width:calc(100% - 32px);margin:16px;padding:32px;'
            . 'border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,0.08);text-align:center;}'
            . '.gl-verify-card h1{font-size:22px;margin:0 0 12px;}'
            . '.gl-verify-card p{font-size:15px;line-height:1.5;margin:0 0 24px;color:#52606d;}'
            . '.gl-verify-button{display:inline-block;border:0;cursor:pointer;background:#16a34a;color:#fff;'
            . 'font-size:16px;font-weight:600;padding:12px 28px;border-radius:8px;}'
            . '.gl-verify-button:hover{background:#15803d;}'
            . '</style>';
        echo '</head><body>';
        echo '<div class="gl-verify-card">' . $inner . '</div>';
        echo '</body></html>';
    }
}
