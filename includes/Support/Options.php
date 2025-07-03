<?php

namespace GrocersList\Support;

class Options {
    public function get(string $key, mixed $default = null): mixed {
        $value = get_option($key);
        return $value !== false ? $value : $default;
    }

    public function set(string $key, mixed $value): bool {
        return update_option($key, $value);
    }
}