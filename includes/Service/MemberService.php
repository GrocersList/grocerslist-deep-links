<?php

namespace GrocersList\Service;

use GrocersList\Settings\PluginSettings;
use GrocersList\Support\Logger;

class MemberService {
    protected $EMAIL_METADATA_KEY = 'glEmail';
    protected $SUBSCRIPTION_STATUS_METADATA_KEY = 'glSubscriptionStatus';
    protected $IS_PAID_MEMBER_METADATA_KEY = 'glIsPaidMember';
    protected $IS_PAST_DUE_METADATA_KEY = 'glIsPastDue';
    protected $SUBSCRIPTION_MANAGEMENT_LINK_METADATA_KEY = 'glSubscriptionManagementLink';
    protected $LAST_UPDATED = 'glLastUpdated';
    protected $MEMBERSHIP_ENDS_AT_METADATA_KEY = 'glMembershipEndsAt';
    protected $IS_GIFT_MEMBERSHIP_METADATA_KEY = 'glIsGiftMembership';

    // What Date#toISOString() writes, with any UTC offset; no bare dates.
    private const ISO_8601_DATE_TIME = '/\A(\d{4})-(\d{2})-(\d{2})T([01]\d|2[0-3]):[0-5]\d:[0-5]\d(\.\d+)?(Z|[+-]\d{2}:\d{2})\z/';

    /**
     * GRO's membershipEndsAt as the plugin stores it and hands it to the
     * widget: an ISO 8601 date-time on a real calendar day, verbatim, or ''
     * for anything else (null included).
     *
     * @param mixed $value
     */
    public static function sanitizeMembershipEndsAt($value): string {
        if (
            !is_string($value)
            || !preg_match(self::ISO_8601_DATE_TIME, $value, $parts)
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
        ) {
            return '';
        }

        return $value;
    }

    protected function _namespaceMetaDataKey(string $namespace, string $key): string {
        return $key . '-' . $namespace;
    }

    protected function _getWordpressUserMetaData(string $creator_id = null) {
        // TODO: NMML - need to put a limit on how long we trust WP_User... e.g., hit GL servers once every 24 hours per user to ensure active subscriptions via Stripe API check
        // TODO: NMML - can we kill JWT if we start using WP_User?
        // Build member info from current WP user meta
        $email = null;
        $subscription_status = null;
        $is_paid_member = false;
        $is_past_due = false;
        $subscription_management_link = null;
        $last_updated = null;
        $membership_ends_at = null;
        $is_gift_membership = false;

        $current_user = wp_get_current_user();

        $creator_id = $creator_id ?? '';
        if ($current_user && $current_user->ID) {
            $email = get_user_meta($current_user->ID, $this->_namespaceMetaDataKey($creator_id, $this->EMAIL_METADATA_KEY), true);
            $subscription_status = get_user_meta($current_user->ID, $this->_namespaceMetaDataKey($creator_id, $this->SUBSCRIPTION_STATUS_METADATA_KEY), true);
            $is_paid_member = get_user_meta($current_user->ID, $this->_namespaceMetaDataKey($creator_id, $this->IS_PAID_MEMBER_METADATA_KEY), true);
            $is_past_due = get_user_meta($current_user->ID, $this->_namespaceMetaDataKey($creator_id, $this->IS_PAST_DUE_METADATA_KEY), true);
            $subscription_management_link = get_user_meta($current_user->ID, $this->_namespaceMetaDataKey($creator_id, $this->SUBSCRIPTION_MANAGEMENT_LINK_METADATA_KEY), true);
            $last_updated = get_user_meta($current_user->ID, $this->_namespaceMetaDataKey($creator_id, $this->LAST_UPDATED), true);
            $membership_ends_at = get_user_meta($current_user->ID, $this->_namespaceMetaDataKey($creator_id, $this->MEMBERSHIP_ENDS_AT_METADATA_KEY), true);
            $is_gift_membership = get_user_meta($current_user->ID, $this->_namespaceMetaDataKey($creator_id, $this->IS_GIFT_MEMBERSHIP_METADATA_KEY), true);
        }

        return [$email, $subscription_status, $is_paid_member, $is_past_due, $subscription_management_link, $last_updated, $membership_ends_at, $is_gift_membership];
    }

    public function getMemberData(string $creator_id = null) {
        return $this->_getWordpressUserMetaData($creator_id);
    }

    public function logout() {
        if (is_user_logged_in()) {
            wp_logout();
            exit();
        }
    }

    /**
     * Capabilities that mark a WP user as privileged. The plugin does not
     * authenticate any account that holds even one of these through the
     * membership flow; a plain reader/subscriber/customer holds none of these
     * and is allowed through.
     */
    public const PRIVILEGED_CAPABILITIES = [
        'manage_options',
        'edit_posts',
        'edit_pages',
        'edit_others_posts',
        'publish_posts',
        'upload_files',
        'edit_published_posts',
        'delete_posts',
        'manage_categories',
        'moderate_comments',
        'list_users',
    ];

    /**
     * A WP user is treated as safe to authenticate through the membership flow
     * only when it holds none of the privileged capabilities above (i.e. it
     * cannot edit content or manage the site).
     */
    protected function _isSafeToAuthenticate(\WP_User $user): bool {
        foreach (self::PRIVILEGED_CAPABILITIES as $cap) {
            if (user_can($user, $cap)) {
                return false;
            }
        }

        return true;
    }

    public function login(string $email) {
        $user = get_user_by('email', $email);

        if (!$user || !is_a($user, 'WP_User')) {
            return;
        }

        if (!$this->_isSafeToAuthenticate($user)) {
            Logger::debug("Refusing to log in user for email '" . $email . "': account holds a privileged capability");
            return;
        }

        if (!is_user_logged_in()) {
            // TODO: NMML - what if there's already a logged in user and emails don't match?
            wp_set_current_user($user->ID, $user->user_login );
            wp_set_auth_cookie($user->ID);
            $credentials = array(
                'user_login'    => $user->user_login,
                'user_password' => $user->user_pass,
                'remember'      => true,
            );
            wp_signon($credentials);
        }
    }

    public function createOrUpdateMember(string $email, string $subscription_status, bool $is_paid_member, bool $is_past_due, string $subscription_management_link, string $creator_id = null, string $jwt = null, string $membership_ends_at = '', bool $is_gift_membership = false) {
        $creator_id = $creator_id ?? '';
        if (!empty($email)) {
            Logger::debug("attempt to find user");

            // TODO: NMML - what if there's already a logged in user and emails don't match?
            // Find existing user by email or create one
            $user = get_user_by('email', $email);

            $password = wp_generate_password(12, true);

            if (!$user) {
                Logger::debug("No user found for email: " . $email);

                $username_base = sanitize_user(current(explode('@', $email)));
                if (empty($username_base)) {
                    $username_base = 'gl_user';
                }
                $username = $username_base;
                $i = 1;
                while (username_exists($username)) {
                    $username = $username_base . '_' . $i;
                    $i++;
                }

                $user_id = wp_create_user($username, $password, $email);

                if (!is_wp_error($user_id)) {
                    $user = get_user_by('id', $user_id);
                    Logger::debug("Found created user: " . print_r($user, true));

                    if ($user && is_a($user, 'WP_User')) {
                        $user->set_role('subscriber');
                    }
                }
            } else {
                Logger::debug("Found user: " . print_r($user, true));

                // Never attach member metadata to, or log in, an existing WP
                // user that can edit content or manage the site (admin/editor/
                // author/contributor). Non-privileged accounts are allowed.
                if (!$this->_isSafeToAuthenticate($user)) {
                    Logger::debug("Refusing to manage existing user for email '" . $email . "': account holds a privileged capability");
                    return;
                }
            }

            if ($user && is_a($user, 'WP_User')) {
                update_user_meta($user->ID, $this->_namespaceMetaDataKey($creator_id, $this->SUBSCRIPTION_STATUS_METADATA_KEY), $subscription_status);

                // TODO: NMML - what if there's already a logged in user and emails don't match?
                update_user_meta($user->ID, $this->_namespaceMetaDataKey($creator_id, $this->EMAIL_METADATA_KEY), $email);
                update_user_meta($user->ID, $this->_namespaceMetaDataKey($creator_id, $this->IS_PAID_MEMBER_METADATA_KEY), $is_paid_member);
                update_user_meta($user->ID, $this->_namespaceMetaDataKey($creator_id, $this->IS_PAST_DUE_METADATA_KEY), $is_past_due);
                update_user_meta($user->ID, $this->_namespaceMetaDataKey($creator_id, $this->SUBSCRIPTION_MANAGEMENT_LINK_METADATA_KEY), $subscription_management_link);
                update_user_meta($user->ID, $this->_namespaceMetaDataKey($creator_id, $this->MEMBERSHIP_ENDS_AT_METADATA_KEY), $membership_ends_at);
                update_user_meta($user->ID, $this->_namespaceMetaDataKey($creator_id, $this->IS_GIFT_MEMBERSHIP_METADATA_KEY), $is_gift_membership);
                update_user_meta($user->ID, $this->_namespaceMetaDataKey($creator_id, $this->LAST_UPDATED), time());

                $this->login($email);

                // Best-effort side-channel: tell the GL server which WP user id represents
                // this follower so the server can call back to delete the WP user on churn.
                if (!empty($jwt)) {
                    $apiKey = PluginSettings::getApiKey();
                    if (!empty($apiKey)) {
                        ApiClient::patchFollowerWpUserId($apiKey, $jwt, (int) $user->ID);
                    }
                }
            }
        }
    }

    public function shouldUpdateMemberData(string $creator_id = null, bool $forceUpdate = false) {
        if ($forceUpdate) {
            return true;
        }

        if (is_user_logged_in()) {
            list(, , , , , $last_updated, $membership_ends_at) = $this->getMemberData($creator_id);
            $last_updated = intval($last_updated);
            $now = time();
            // One day in seconds
            $one_day = 86400;

            return $now - $last_updated > $one_day
                || $this->_membershipEndedSince($membership_ends_at, $last_updated, $now);
        }

        return false;
    }

    /**
     * Whether the stored membershipEndsAt has passed since $last_updated: the
     * member is still stored as paid by a gift that is over. GRO stops sending
     * the end once it has passed, so this costs one refresh. Comparing with
     * $last_updated as well as $now keeps it at one whatever the refresh
     * stores (an end past on this site's clock but not yet on GRO's would
     * otherwise refresh every request), and only an end the sanitizer keeps
     * counts: strtotime() alone reads "10 minutes ago" as one that just passed.
     *
     * @param mixed $membership_ends_at
     */
    protected function _membershipEndedSince($membership_ends_at, int $last_updated, int $now): bool {
        $ends_at = self::sanitizeMembershipEndsAt($membership_ends_at);
        $ends_at = $ends_at === '' ? false : strtotime($ends_at);

        return $ends_at !== false && $last_updated <= $ends_at && $ends_at < $now;
    }
}
