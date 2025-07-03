<?php

namespace GrocersList\Model;

use GrocersList\Model\LinkResponseItem;

class LinkResponse {
    /** @var LinkResponseItem[] */
    public array $successes = [];

    public function __construct(array $data) {
        foreach ($data['successes'] ?? [] as $item) {
            $this->successes[] = new LinkResponseItem($item);
        }
    }
}