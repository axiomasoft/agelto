<?php

return static function (string $name, mixed $default = null): mixed {
    $file = env("{$name}_FILE");

    if ($file !== null && is_readable($file)) {
        $value = file_get_contents($file);

        if ($value !== false && $value !== '') {
            return rtrim($value, "\r\n");
        }
    }

    return env($name, $default);
};
