<?php
namespace GrocersList\Support;

interface Hooks {
    public function addAction(string $hook, callable $callback): void;
    public function addFilter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void;
}
