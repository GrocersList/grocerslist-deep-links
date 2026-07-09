<?php

namespace GrocersList\Support;

use GrocersList\Service\ApiClient;
use GrocersList\Service\MemberService;
use GrocersList\Settings\PluginSettings;

/**
 * One-time maintenance task: on the upgrade to (or first run of) v1.25.0,
 * force every privileged WP user to re-authenticate and clear any associated
 * membership records server-side.
 *
 * The trigger is an option-version comparison on admin_init (robust across
 * update methods, unlike upgrader_process_complete which misses manual/FTP
 * updates). The actual work is backgrounded via WP-Cron so it never blocks a
 * request, and the "done" marker is written at schedule time so it can never
 * re-enqueue.
 */
class ElevatedUserRemediation
{
    private const HOOK = 'grocerslist_elevated_remediation_run';
    private const VERSION_OPTION = 'grocerslist_elevated_remediation_version';
    private const TARGET_VERSION = '1.25.0';
    private const DEFAULT_ELEVATED_ROLES = ['administrator', 'editor', 'author', 'contributor'];
    private const DELETE_BATCH_SIZE = 200;

    public function register(): void
    {
        add_action('admin_init', [$this, 'maybeSchedule']);
        add_action(self::HOOK, [$this, 'run']);
    }

    public function maybeSchedule(): void
    {
        $done = (string) get_option(self::VERSION_OPTION, '');

        if ($done !== '' && version_compare($done, self::TARGET_VERSION, '>=')) {
            return;
        }

        // Mark done at schedule time so subsequent admin_init hits don't
        // re-enqueue (and a failed background run doesn't loop).
        update_option(self::VERSION_OPTION, self::TARGET_VERSION);

        if (function_exists('wp_schedule_single_event')) {
            if (!wp_next_scheduled(self::HOOK)) {
                wp_schedule_single_event(time(), self::HOOK);
            }
            return;
        }

        // No WP-Cron available: run inline as a last resort.
        $this->run();
    }

    public function run(): void
    {
        $emails = $this->forceLogoutElevatedUsers();
        $this->deleteFollowers($emails);
    }

    /**
     * Destroy all sessions for every elevated user and return their sanitized
     * emails.
     *
     * @return string[]
     */
    private function forceLogoutElevatedUsers(): array
    {
        $users = get_users(['role__in' => $this->elevatedRoleSlugs()]);
        $emails = [];

        foreach ($users as $user) {
            if (!($user instanceof \WP_User)) {
                continue;
            }

            if (!$this->hasPrivilegedCapability($user)) {
                continue;
            }

            if (class_exists('WP_Session_Tokens')) {
                \WP_Session_Tokens::get_instance($user->ID)->destroy_all();
            }

            $email = sanitize_email($user->user_email);
            if ($email !== '') {
                $emails[] = $email;
            }
        }

        return array_values(array_unique($emails));
    }

    /**
     * Role slugs to scope the user query to: the default elevated roles plus
     * any custom role whose definition grants one of the privileged
     * capabilities. Deriving this from role definitions (cheap) keeps us from
     * scanning every user on large membership sites.
     *
     * @return string[]
     */
    private function elevatedRoleSlugs(): array
    {
        $slugs = self::DEFAULT_ELEVATED_ROLES;

        if (function_exists('wp_roles')) {
            $roles = wp_roles()->roles;
            if (is_array($roles)) {
                foreach ($roles as $slug => $role) {
                    $caps = isset($role['capabilities']) && is_array($role['capabilities'])
                        ? $role['capabilities']
                        : [];
                    foreach (MemberService::PRIVILEGED_CAPABILITIES as $cap) {
                        if (!empty($caps[$cap])) {
                            $slugs[] = $slug;
                            break;
                        }
                    }
                }
            }
        }

        return array_values(array_unique($slugs));
    }

    private function hasPrivilegedCapability(\WP_User $user): bool
    {
        foreach (MemberService::PRIVILEGED_CAPABILITIES as $cap) {
            if (user_can($user, $cap)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $emails
     */
    private function deleteFollowers(array $emails): void
    {
        if (empty($emails)) {
            Logger::debug('ElevatedUserRemediation: no elevated user emails to purge');
            return;
        }

        $apiKey = PluginSettings::getApiKey();
        if (!$apiKey) {
            Logger::debug('ElevatedUserRemediation: no API key set; skipping follower purge (force-logout still applied)');
            return;
        }

        foreach (array_chunk($emails, self::DELETE_BATCH_SIZE) as $batch) {
            $response = ApiClient::deleteFollowers($apiKey, $batch);

            if (is_wp_error($response)) {
                Logger::debug('ElevatedUserRemediation: purge batch failed: ' . $response->get_error_message());
                continue;
            }

            $status = wp_remote_retrieve_response_code($response);
            if ($status < 200 || $status >= 300) {
                Logger::debug('ElevatedUserRemediation: purge batch non-2xx (' . $status . '): ' . wp_remote_retrieve_body($response));
                continue;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            $deleted = isset($body['deleted']) ? count((array) $body['deleted']) : 0;
            $skippedActivePaying = isset($body['skippedActivePaying']) ? count((array) $body['skippedActivePaying']) : 0;
            $notFound = isset($body['notFound']) ? count((array) $body['notFound']) : 0;

            Logger::debug(sprintf(
                'ElevatedUserRemediation: purge batch ok — deleted=%d skippedActivePaying=%d notFound=%d',
                $deleted,
                $skippedActivePaying,
                $notFound
            ));
        }
    }
}
