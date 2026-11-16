<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

/**
 * Plain-PHP template renderer.
 *
 * A page template is rendered first; its output becomes `$content` inside the
 * layout. Variables passed in $data are available in both. Templates must
 * escape all dynamic output with e().
 */
final class View
{
    public static function render(string $template, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $content = self::capture($template, $data);
        if ($layout === null) {
            return $content;
        }
        return self::capture($layout, array_merge($data, ['content' => $content]));
    }

    private static function capture(string $template, array $data): string
    {
        if (!preg_match('#^[a-z0-9/_-]+$#i', $template)) {
            throw new RuntimeException("Invalid view name [$template].");
        }
        $file = BASE_PATH . '/resources/views/' . $template . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("View [$template] not found.");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            include $file;
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
