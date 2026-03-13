<?php

namespace GrocersList\Service;

use GrocersList\Support\Logger;

class MemberService {
    protected $EMAIL_METADATA_KEY = 'glEmail';
    protected $SUBSCRIPTION_STATUS_METADATA_KEY = 'glSubscriptionStatus';
    protected $IS_PAID_MEMBER_METADATA_KEY = 'glIsPaidMember';
    protected $IS_PAST_DUE_METADATA_KEY = 'glIsPastDue';
    protected $SUBSCRIPTION_MANAGEMENT_LINK_METADATA_KEY = 'glSubscriptionManagementLink';
    protected $LAST_UPDATED = 'glLastUpdated';

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

        $current_user = wp_get_current_user();

        $creator_id = $creator_id ?? '';
        if ($current_user && $current_user->ID) {
            $email = get_user_meta($current_user->ID, $this->_namespaceMetaDataKey($creator_id, $this->EMAIL_METADATA_KEY), true);
            $subscription_status = get_user_meta($current_user->ID, $this->_namespaceMetaDataKey($creator_id, $this->SUBSCRIPTION_STATUS_METADATA_KEY), true);
            $is_paid_member = get_user_meta($current_user->ID, $this->_namespaceMetaDataKey($creator_id, $this->IS_PAID_MEMBER_METADATA_KEY), true);
            $is_past_due = get_user_meta($current_user->ID, $this->_namespaceMetaDataKey($creator_id, $this->IS_PAST_DUE_METADATA_KEY), true);
            $subscription_management_link = get_user_meta($current_user->ID, $this->_namespaceMetaDataKey($creator_id, $this->SUBSCRIPTION_MANAGEMENT_LINK_METADATA_KEY), true);
            $last_updated = get_user_meta($current_user->ID, $this->_namespaceMetaDataKey($creator_id, $this->LAST_UPDATED), true);
        }

        return [$email, $subscription_status, $is_paid_member, $is_past_due, $subscription_management_link, $last_updated];
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

    public function login(string $email) {
        $user = get_user_by('email', $email);

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

    public function createOrUpdateMember(string $email, string $subscription_status, bool $is_paid_member, bool $is_past_due, string $subscription_management_link, string $creator_id = null) {
        $creator_id = $creator_id ?? '';
        if (!empty($email)) {
            Logger::debug("attempt to find user");

            // TODO: NMML - what if there's already a logged in user and emails don't match?
            // Find existing user by email or create one
            $user = get_user_by('email', $email);

            // TODO: NMML - password? do we sync with our FollowerAccount passwords? - YES
            $password = wp_generate_password(12, true);
            Logger::debug("Generated password: " . $password);

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
            }

            if ($user && is_a($user, 'WP_User')) {
                update_user_meta($user->ID, $this->_namespaceMetaDataKey($creator_id, $this->SUBSCRIPTION_STATUS_METADATA_KEY), $subscription_status);

                // TODO: NMML - what if there's already a logged in user and emails don't match?
                update_user_meta($user->ID, $this->_namespaceMetaDataKey($creator_id, $this->EMAIL_METADATA_KEY), $email);
                update_user_meta($user->ID, $this->_namespaceMetaDataKey($creator_id, $this->IS_PAID_MEMBER_METADATA_KEY), $is_paid_member);
                update_user_meta($user->ID, $this->_namespaceMetaDataKey($creator_id, $this->IS_PAST_DUE_METADATA_KEY), $is_past_due);
                update_user_meta($user->ID, $this->_namespaceMetaDataKey($creator_id, $this->SUBSCRIPTION_MANAGEMENT_LINK_METADATA_KEY), $subscription_management_link);
                update_user_meta($user->ID, $this->_namespaceMetaDataKey($creator_id, $this->LAST_UPDATED), time());

                $this->login($email);
            }
        }
    }

    public function shouldUpdateMemberData(string $creator_id = null) {
        if (is_user_logged_in()) {
            list(, , , , , $last_updated) = $this->getMemberData($creator_id);
            // One day in seconds
            $one_day = 86400;

            return time() - intval($last_updated) > $one_day;
        }

        return false;
    }
}
