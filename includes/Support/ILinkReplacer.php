<?php

namespace GrocersList\Support;

use GrocersList\Model\LinkRewriteResult;


interface ILinkReplacer {
    public function replace(string $content, array $urlMap): LinkRewriteResult;
}
