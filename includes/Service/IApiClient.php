<?php

namespace GrocersList\Service;

use GrocersList\Model\LinkResponse;
use GrocersList\Support\Config;

interface IApiClient {
    public function postAppLinks(array $urls): LinkResponse;
    public function validateApiKey(string $apiKey): bool;
}
