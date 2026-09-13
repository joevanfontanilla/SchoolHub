<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Plain PHP templates with an optional layout.
 *
 * Templates live in app/Views. Always escape with e() — there is no auto-escaping.
 */
final class View
{
    private static array $shared = [];

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    public static function render(string $template, array $data = [], ?string $layout = null): string
    {
        $content = self::capture($template, $data);

        if ($layout === null) {
            return $content;
        }

        return self::capture($layout, array_merge($data, ['content' => $content]));
    }

    /** Render a partial or component inline from within a template. */
    public static function partial(string $template, array $data = []): string
    {
        return self::capture($template, $data);
    }

    private static function capture(string $template, array $data): string
    {
        $file = APP_PATH . '/Views/' . str_replace('..', '', $template) . '.php';

        if (!is_file($file)) {
            throw new RuntimeException("View not found: {$template}");
        }

        extract(array_merge(self::$shared, $data), EXTR_SKIP);

        ob_start();
        try {
            include $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }
}
