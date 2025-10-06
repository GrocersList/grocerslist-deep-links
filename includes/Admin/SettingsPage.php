<?php

namespace GrocersList\Admin;

use GrocersList\Service\CreatorSettingsFetcher;
use GrocersList\Support\Config;

class SettingsPage {
    private CreatorSettingsFetcher $creatorSettingsFetcher;

    public function __construct(CreatorSettingsFetcher $creatorSettingsFetcher) {
        $this->creatorSettingsFetcher = $creatorSettingsFetcher;
    }

    public function register(): void {
        add_action('admin_menu', [$this, 'addMenu']);
    }

    public function addMenu(): void {
        $svgPath = plugin_dir_path(__FILE__) . '../../assets/gl.svg';
        $svgData = file_get_contents($svgPath);
        $iconUrl = 'data:image/svg+xml;base64,' . base64_encode($svgData);

        add_menu_page(
            'Grocers List',
            'Grocers List',
            'manage_options',
            'grocers-list',
            [$this, 'renderPage'],
            $iconUrl
        );
    }

    public function renderPage(): void {
        if (!current_user_can('manage_options')) return;

        // Set cache control headers for 1 hour
        if (!headers_sent()) {
            header('Cache-Control: public, max-age=3600');
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT');
        }

        $assetBase = plugin_dir_url(__FILE__) . '../../admin-ui/dist/';
        
        // Use WordPress's built-in versioning system
        $version = Config::getPluginVersion();
        
        wp_enqueue_script('grocers-list-admin-ui', $assetBase . 'bundle.js', [], $version, true);

        // $skipCacheRead on settings page. This occurs only at Creator scale and we always want the latest data.
        $creatorSettings = $this->creatorSettingsFetcher->getCreatorSettings(true);

        $window_grocersList = [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonces' => [
                'grocers_list_get_state' => wp_create_nonce('grocers_list_get_state'),
                'grocers_list_update_api_key' => wp_create_nonce('grocers_list_update_api_key'),
                'grocers_list_update_use_linksta_links' => wp_create_nonce('grocers_list_update_use_linksta_links'),
                'grocers_list_mark_setup_complete' => wp_create_nonce('grocers_list_mark_setup_complete'),
                'grocers_list_clear_cache' => wp_create_nonce('grocers_list_clear_cache'),
                'grocers_list_clear_settings' => wp_create_nonce('grocers_list_clear_settings'),
                'grocers_list_get_migration_status' => wp_create_nonce('grocers_list_get_migration_status'),
                'grocers_list_get_link_count_info' => wp_create_nonce('grocers_list_get_link_count_info'),
                'grocers_list_trigger_migrate' => wp_create_nonce('grocers_list_trigger_migrate'),
                'grocers_list_process_next_count_batch' => wp_create_nonce('grocers_list_process_next_count_batch'),
                'grocers_list_update_memberships_enabled' => wp_create_nonce('grocers_list_update_memberships_enabled'),
            ],
            'settings' => $creatorSettings->settings ?? null,
            'provisioning' => $creatorSettings->provisioning ?? null
        ];

        wp_localize_script('grocers-list-admin-ui', 'grocersList', $window_grocersList);

        echo '<div id="root"></div>';
    }
}
