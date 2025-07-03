<?php

namespace GrocersList\Model;
use GrocersList\Model\LinkStats;

class LinkResponseItem {
    public string $_id;
    public string $url;
    public string $creatorAccount;
    public LinkStats $stats;
    public string $hash;
    public int $__v;
    public string $createdAt;
    public string $updatedAt;

    public function __construct(array $data) {
        $this->_id = $data['_id'];
        $this->url = $data['url'];
        $this->creatorAccount = $data['creatorAccount'];
        $this->stats = new LinkStats($data['stats']);
        $this->hash = $data['hash'];
        $this->__v = $data['__v'];
        $this->createdAt = $data['createdAt'];
        $this->updatedAt = $data['updatedAt'];
    }
}

