<?php

namespace GrocersList\Admin;

use GrocersList\Service\IApiClient;
use GrocersList\Service\LinkRewriter;
use GrocersList\Support\Hooks;

class SettingsPage {
    private Hooks $hooks;

    public function __construct(Hooks $hooks, IApiClient $apiClient, LinkRewriter $rewriter) {
        $this->hooks = $hooks;
    }

    public function register(): void {
        $this->hooks->addAction('admin_menu', [$this, 'addMenu']);
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

        $assetBase = plugin_dir_url(__FILE__) . '../../admin-ui/dist/';
        $version = GROCERS_LIST_VERSION;
        wp_enqueue_script('grocers-list-ui', $assetBase . 'bundle.js', [], $version, true);

        wp_localize_script('grocers-list-ui', 'grocersList', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonces' => [
                'grocers_list_get_state' => wp_create_nonce('grocers_list_get_state'),
                'grocers_list_update_api_key' => wp_create_nonce('grocers_list_update_api_key'),
                'grocers_list_get_creator_settings' => wp_create_nonce('grocers_list_get_creator_settings'),
                'grocers_list_update_auto_rewrite' => wp_create_nonce('grocers_list_update_auto_rewrite'),
                'grocers_list_update_use_linksta_links' => wp_create_nonce('grocers_list_update_use_linksta_links'),
                'grocers_list_count_matched_links' => wp_create_nonce('grocers_list_count_matched_links'),
                'grocers_list_find_matched_links' => wp_create_nonce('grocers_list_find_matched_links'),
                'grocers_list_mark_setup_complete' => wp_create_nonce('grocers_list_mark_setup_complete'),
                'grocers_list_clear_settings' => wp_create_nonce('grocers_list_clear_settings'),
                'grocers_list_get_migration_status' => wp_create_nonce('grocers_list_get_migration_status'),
                'grocers_list_recount_links' => wp_create_nonce('grocers_list_recount_links'),
                'grocers_list_get_link_count_info' => wp_create_nonce('grocers_list_get_link_count_info'),
                'grocers_list_trigger_migrate' => wp_create_nonce('grocers_list_trigger_migrate'),
                'grocers_list_trigger_recount_links' => wp_create_nonce('grocers_list_trigger_recount_links'),
                'grocers_list_get_post_gating_options' => wp_create_nonce('grocers_list_get_post_gating_options'),
                'grocers_list_update_post_gating_options' => wp_create_nonce('grocers_list_update_post_gating_options'),
                'grocers_list_process_next_count_batch' => wp_create_nonce('grocers_list_process_next_count_batch'),
            ],
        ]);

        echo '<div id="root"></div>';
    }
}
