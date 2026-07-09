<?php

namespace GrocersList\Frontend;

use GrocersList\Service\CreatorSettingsFetcher;
use GrocersList\Support\Config;

class WprmPrintIntegration
{
    private CreatorSettingsFetcher $creatorSettingsFetcher;
    private ClientScripts $clientScripts;

    public function __construct(CreatorSettingsFetcher $creatorSettingsFetcher, ClientScripts $clientScripts)
    {
        $this->creatorSettingsFetcher = $creatorSettingsFetcher;
        $this->clientScripts = $clientScripts;
    }

    public function register(): void
    {
        add_action('wprm_print_head', [$this, 'emitPrintHead']);
        add_action('wprm_print_footer', [$this, 'emitPrintFooter']);
    }

    public function emitPrintHead(): void
    {
        $window_grocersList = $this->clientScripts->buildWindowGrocersList();

        echo '<script>window.grocersList = ' . wp_json_encode($window_grocersList) . ';</script>' . "\n";

        $membershipsFullyEnabled = $this->creatorSettingsFetcher->getMembershipsFullyEnabled();
        $externalJsUrl = Config::getExternalJsUrl();

        if ($membershipsFullyEnabled && !empty($externalJsUrl)) {
            $versionedUrl = add_query_arg('ver', $this->clientScripts->getCacheBustingString(), $externalJsUrl);

            echo '<link rel="preload" href="' . esc_url($versionedUrl) . '" as="script">' . "\n";
            echo '<script src="' . esc_url($versionedUrl) . '" async></script>' . "\n";
        }
    }

    public function emitPrintFooter(): void
    {
        $versionedBundleUrl = add_query_arg('ver', $this->clientScripts->getCacheBustingString(), $this->clientScripts->getBundleUrl());

        echo '<script src="' . esc_url($versionedBundleUrl) . '" defer></script>' . "\n";
    }
}
