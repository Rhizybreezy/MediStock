<?php
/**
 * Global helper functions used by controllers and views.
 * Kept small and dependency-free; each delegates to a Core class.
 */

use App\Core\App;
use App\Core\Config;
use App\Core\Gate;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\Support\AuthService;
use App\Services\Support\SettingsService;

if (!function_exists('env')) {
    /** Read an environment variable with type conversion of common literals. */
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return $default;
        }
        return match (strtolower((string) $value)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            'empty', '(empty)' => '',
            default            => $value,
        };
    }
}

function config(string $key, mixed $default = null): mixed
{
    return Config::get($key, $default);
}

/** Resolve a service from the container. */
function app(?string $id = null): mixed
{
    $container = App::instance()->container;
    return $id === null ? $container : $container->get($id);
}

/** Escape for HTML output. ALWAYS use this when printing data in views. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Absolute-path URL inside the application (handles sub-folder installs). */
function url(string $path = '/', array $query = []): string
{
    $url = Request::basePath() . '/' . ltrim($path, '/');
    if ($url === '') {
        $url = '/';
    }
    return $query ? $url . '?' . http_build_query($query) : $url;
}

/** URL of a named route: route('users.edit', ['id' => 5]) */
function route(string $name, array $params = [], array $query = []): string
{
    return url(App::instance()->router->path($name, $params), $query);
}

function asset(string $path): string
{
    $file = BASE_PATH . '/public/assets/' . ltrim($path, '/');
    $version = is_file($file) ? substr((string) filemtime($file), -6) : '1';
    return url('assets/' . ltrim($path, '/')) . '?v=' . $version;
}

function view(string $template, array $data = [], ?string $layout = 'layouts/app', int $status = 200): Response
{
    return new Response(View::render($template, $data, $layout), $status);
}

function partial(string $template, array $data = []): string
{
    return View::render($template, $data, null);
}

function redirect(string $url, int $status = 302): Response
{
    return Response::redirect($url, $status);
}

function json(mixed $data, int $status = 200): Response
{
    return Response::json($data, $status);
}

function csrf_token(): string
{
    return Session::csrfToken();
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function flash(string $type, string $message): void
{
    Session::flash('messages', array_merge(Session::getNextFlash('messages', []), [[$type, $message]]));
}

/** Previously submitted form value (after a validation error). */
function old(string $key, mixed $default = ''): mixed
{
    $old = Session::getFlash('_old', []);
    return array_key_exists($key, $old) ? $old[$key] : $default;
}

/** First validation error for a field, or null. */
function error(string $field): ?string
{
    $errors = Session::getFlash('_errors', []);
    return $errors[$field][0] ?? null;
}

/** Bootstrap "is-invalid" class helper. */
function invalid(string $field): string
{
    return error($field) ? ' is-invalid' : '';
}

function auth(): AuthService
{
    return app(AuthService::class);
}

function user(): ?array
{
    return auth()->user();
}

function can(string $permission, ?int $departmentId = null): bool
{
    return app(Gate::class)->can($permission, $departmentId);
}

function setting(string $key, mixed $default = null): mixed
{
    return app(SettingsService::class)->get($key, $default);
}

function money(mixed $amount, bool $withSymbol = true): string
{
    $formatted = number_format((float) $amount, 2);
    return $withSymbol ? setting('currency_code', 'USD') . ' ' . $formatted : $formatted;
}

/** Format a quantity: drop trailing zeros (12.500 -> 12.5, 40.000 -> 40). */
function qty(mixed $value): string
{
    $n = round((float) $value, 3);
    if (floor($n) == $n) {
        return number_format($n, 0);
    }
    // Fractional: show up to 3 decimals without trailing zeros (12.500 -> 12.5)
    return rtrim(rtrim(number_format($n, 3), '0'), '.');
}

function format_date(?string $value, bool $withTime = false): string
{
    if (!$value) {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date($withTime ? 'd M Y, H:i' : 'd M Y', $ts) : '—';
}

/** Human "3 minutes ago" text. */
function time_ago(?string $value): string
{
    if (!$value) {
        return '—';
    }
    $diff = time() - strtotime($value);
    return match (true) {
        $diff < 60     => 'just now',
        $diff < 3600   => floor($diff / 60) . ' min ago',
        $diff < 86400  => floor($diff / 3600) . ' h ago',
        $diff < 604800 => floor($diff / 86400) . ' d ago',
        default        => format_date($value),
    };
}

/** Convert snake_case / codes to "Title Case" labels. */
function label(?string $value): string
{
    return ucwords(str_replace(['_', '.'], ' ', (string) $value));
}

/** Current request (inside HTTP lifecycle). */
function request(): Request
{
    return App::instance()->request;
}

/**
 * Sortable column header link for server-side tables.
 * Keeps the current query string and toggles direction.
 */
function sort_link(string $column, string $text): string
{
    $req = request();
    $current = $req->query('sort');
    $dir = $req->query('dir') === 'asc' ? 'asc' : 'desc';
    $newDir = ($current === $column && $dir === 'asc') ? 'desc' : 'asc';
    $query = array_merge($req->queryAll(), ['sort' => $column, 'dir' => $newDir, 'page' => 1]);
    $icon = $current === $column ? ($dir === 'asc' ? 'bi-sort-up' : 'bi-sort-down') : 'bi-arrow-down-up opacity-25';
    return '<a class="sort-link" href="' . e(url($req->path(), $query)) . '">' . e($text)
        . ' <i class="bi ' . $icon . '" aria-hidden="true"></i></a>';
}

/** Badge markup for a status value. */
function status_badge(?string $status): string
{
    $map = [
        'active' => 'success', 'inactive' => 'secondary', 'locked' => 'danger',
        'pending' => 'warning', 'submitted' => 'info', 'approved' => 'primary',
        'rejected' => 'danger', 'cancelled' => 'secondary', 'draft' => 'secondary',
        'posted' => 'success', 'received' => 'success', 'issued' => 'success',
        'expired' => 'danger', 'recalled' => 'danger', 'quarantined' => 'warning',
        'critical' => 'danger', 'warning' => 'warning', 'info' => 'info',
    ];
    $color = $map[$status] ?? 'secondary';
    return '<span class="badge rounded-pill text-bg-' . $color . ' badge-status">' . e(label($status)) . '</span>';
}

/** Coloured pill for a stock movement type. */
function movement_badge(string $type): string
{
    $class = match ($type) {
        'RECEIVED', 'ADJUSTED_IN' => 'mv-in',
        'CONSUMED', 'DAMAGED', 'EXPIRED', 'WRITTEN_OFF' => 'mv-out',
        'ADJUSTED_OUT', 'QUARANTINED' => 'mv-adj',
        default => 'mv-move',
    };
    return '<span class="mv ' . $class . '">' . e(str_replace('_', ' ', $type)) . '</span>';
}

/**
 * Expiry badge: expired / critical / warning / ok, using the configured windows.
 * Expired stock is always shown in red and must never be issued.
 */
function expiry_badge(?string $expiryDate): string
{
    if (!$expiryDate) {
        return '<span class="text-muted small">No expiry</span>';
    }
    $days = (int) floor((strtotime($expiryDate) - strtotime(date('Y-m-d'))) / 86400);
    $critical = (int) setting('expiry_critical_days', 30);
    $warning = (int) setting('expiry_warning_days', 90);
    [$tone, $text] = match (true) {
        $days < 0          => ['danger', 'Expired ' . abs($days) . 'd ago'],
        $days === 0        => ['danger', 'Expires today'],
        $days <= $critical => ['danger', $days . 'd left'],
        $days <= $warning  => ['warning', $days . 'd left'],
        default            => ['success', $days . 'd left'],
    };
    return '<span class="text-nowrap">' . e(format_date($expiryDate)) . '</span> <span class="badge text-bg-' . $tone . ' badge-status">' . e($text) . '</span>';
}

/** Stock level badge against a reorder level. */
function stock_level_badge(float $usable, float $reorder): string
{
    if ($usable <= 0) {
        return '<span class="badge text-bg-danger badge-status">Out of stock</span>';
    }
    if ($reorder > 0 && $usable <= $reorder) {
        return '<span class="badge text-bg-warning badge-status">Low</span>';
    }
    return '<span class="badge text-bg-success badge-status">In stock</span>';
}

/** Invalidate cached analytics (forecasts, expiry risk, charts) after a data change. */
function cache_bump(): void
{
    app(\App\Core\Database::class)->afterCommit(static function (): void {
        try {
            app(\App\Services\Support\CacheService::class)->bump();
        } catch (\Throwable) {
            // Caching must never break a business operation.
        }
    });
}
