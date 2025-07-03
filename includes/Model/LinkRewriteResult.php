<?php
namespace GrocersList\Model;

class LinkRewriteResult {
    public string $content;
    public bool $rewritten;

    public function __construct(string $content, bool $rewritten) {
        $this->content = $content;
        $this->rewritten = $rewritten;
    }
}