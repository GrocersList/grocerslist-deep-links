<?php

namespace GrocersList\Service;

use GrocersList\Model\LinkResponse;
use GrocersList\Support\Config;

interface IApiClient {
    public function postAppLinks(array $urls): LinkResponse;
    /**
     * @param string $apiKey
     * @return mixed Returns response data or false/WP_Error on failure
     */
    public function validateApiKey(string $apiKey);
}
