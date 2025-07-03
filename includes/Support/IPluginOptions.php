<?php

namespace GrocersList\Support;

interface IPluginOptions {
    public function getApiKey(): ?string;
    public function setApiKey(string $key): void;
}
