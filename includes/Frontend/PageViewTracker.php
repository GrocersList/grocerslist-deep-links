<?php

namespace GrocersList\Frontend;

use GrocersList\Service\ApiClient;
use GrocersList\Settings\PluginSettings;
use GrocersList\Support\Hooks;
use GrocersList\Support\Logger;

class PageViewTracker
{
    private $hooks;
    private $settings;
    private $api;

    public function __construct(Hooks $hooks, PluginSettings $settings, ApiClient $api)
    {
        $this->hooks = $hooks;
        $this->settings = $settings;
        $this->api = $api;
    }

    public function register()
    {
        $this->hooks->addAction('wp_head', [$this, 'trackPageView']);
    }

    public function trackPageView()
    {
        // Don't track page views for admin users
        if (current_user_can('manage_options')) {
            return;
        }

        $api_key = $this->settings->getApiKey();
        if (empty($api_key)) {
            return;
        }

        // Record the page view
        try {
            $this->api->recordMembershipEvent($api_key, 'PAGE_VIEW', current_time('c'), get_the_permalink());
        } catch (\Exception $e) {
            Logger::debug("PageViewTracker::trackPageView() - Error recording page view: " . $e->getMessage());
        }
    }
}