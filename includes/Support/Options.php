<?php

namespace GrocersList\Support;

class Options {
    public function get(string $key, $default = null) {
        $value = get_option($key);
        return $value !== false ? $value : $default;
    }

    public function set(string $key, $value): bool {
        return update_option($key, $value);
    }
}
