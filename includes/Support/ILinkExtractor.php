<?php

namespace GrocersList\Support;

interface ILinkExtractor {
    public function extract(string $content): array;

    public function extractUnrewrittenLinks(string $content): array;
}
