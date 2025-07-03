<?php

namespace GrocersList\Public;

use GrocersList\Support\Config;
use GrocersList\Support\Hooks;

class ClientScripts
{
    private Hooks $hooks;

    public function __construct(Hooks $hooks)
    {
        $this->hooks = $hooks;
    }

    public function register(): void
    {
        $this->hooks->addAction('wp_enqueue_scripts', [$this, 'enqueueScripts']);
    }

    public function enqueueScripts(): void
    {
        $assetBase = plugin_dir_url(__FILE__) . '../../client-ui/dist/';
        $version = GROCERS_LIST_VERSION;
        wp_enqueue_script('grocers-list-client', $assetBase . 'bundle.js', [], $version, true);

        wp_localize_script('grocers-list-client', 'grocersListClient', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
        ]);

        $externalJsUrl = Config::getExternalJsUrl();
        if (!empty($externalJsUrl)) {
            wp_enqueue_script('grocers-list-external', $externalJsUrl, [], $version, true);
        }
    }
}
