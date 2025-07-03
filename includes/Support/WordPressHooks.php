<?php

namespace GrocersList\Support;

class WordPressHooks implements Hooks {
    public function addAction(string $hook, callable $callback): void {
        add_action($hook, $callback);
    }

    public function addFilter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void {
        add_filter($hook, $callback, $priority, $acceptedArgs);
    }
}