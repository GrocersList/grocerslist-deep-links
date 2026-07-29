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
        // Priority 1 so the ad-disable classes exist before any other
        // wprm_print_body_open hook (e.g. WPRM's own ad integrations) runs.
        add_action('wprm_print_body_open', [$this, 'emitPrintBodyOpen'], 1);
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

    public function emitPrintBodyOpen(): void
    {
        if (!$this->clientScripts->isPaidMember()) {
            return;
        }

        // WPRM's print template hardcodes its <body> tag — body_class never runs —
        // so the ad-removal classes go on via inline JS the instant <body> opens.
        // Single-arg classList.add per iteration: multi-arg add is ignored by the
        // old WebKit builds still inside our WP 4.4 support floor.
        echo '<script>(function(c){for(var i=0;i<c.length;i++){document.body.classList.add(c[i]);}})('
            . wp_json_encode(array_values(ClientScripts::AD_REMOVAL_CLASSES))
            . ');</script>' . "\n";

        // Body-open rather than footer: print docs are tiny, and Mediavine's async
        // wrapper can boot before a footer-emitted settings div is parsed.
        echo $this->clientScripts->getMediavineSettingsMarkup() . "\n";
    }

    public function emitPrintFooter(): void
    {
        $versionedBundleUrl = add_query_arg('ver', $this->clientScripts->getCacheBustingString(), $this->clientScripts->getBundleUrl());

        echo '<script src="' . esc_url($versionedBundleUrl) . '" defer></script>' . "\n";
    }
}
