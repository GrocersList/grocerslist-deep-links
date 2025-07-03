<?php

namespace GrocersList\Support;

class PluginOptions implements IPluginOptions {
    private Options $options;

    public function __construct(Options $options) {
        $this->options = $options;
    }

    public function getApiKey(): ?string {
        return $this->options->get('amazon_deep_linker_api_key');
    }

    public function setApiKey(string $key): void {
        $this->options->set('amazon_deep_linker_api_key', $key);
    }
}