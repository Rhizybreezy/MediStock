<?php
declare(strict_types=1);

namespace App\Services\Support;

use App\Core\Database;
use Throwable;

/**
 * Typed access to system_settings (thresholds, currency, AI limits ...).
 * Loaded once per request.
 */
final class SettingsService
{
    private ?array $cache = null;

    public function __construct(private Database $db)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        return (int) $this->get($key, $default);
    }

    /** @return array<string, mixed> key => typed value */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $this->cache = [];
        try {
            foreach ($this->db->fetchAll('SELECT setting_key, setting_value, value_type FROM system_settings') as $row) {
                $this->cache[$row['setting_key']] = self::cast($row['setting_value'], $row['value_type']);
            }
        } catch (Throwable) {
            // Before migrations run, settings are simply empty.
        }
        return $this->cache;
    }

    /** Full rows grouped for the settings screen. */
    public function grouped(): array
    {
        $groups = [];
        foreach ($this->db->fetchAll('SELECT * FROM system_settings ORDER BY setting_group, sort_order, setting_key') as $row) {
            $groups[$row['setting_group']][] = $row;
        }
        return $groups;
    }

    /**
     * Validate and save settings from the admin form. Only existing keys are accepted.
     * @return array<string, array{0: mixed, 1: mixed}> changed key => [old, new]
     * @throws \App\Exceptions\ValidationException
     */
    public function update(array $input, int $userId): array
    {
        $rows = [];
        foreach ($this->db->fetchAll('SELECT setting_key, setting_value, value_type, label FROM system_settings') as $r) {
            $rows[$r['setting_key']] = $r;
        }
        $errors = [];
        $changes = [];
        foreach ($rows as $key => $row) {
            $raw = $input[$key] ?? null;
            if ($row['value_type'] === 'bool') {
                $value = in_array($raw, ['1', 'on', 1, true], true) ? '1' : '0';
            } else {
                if ($raw === null) {
                    continue; // not submitted
                }
                $value = trim((string) $raw);
                $error = match ($row['value_type']) {
                    'int'     => preg_match('/^\d{1,6}$/', $value) ? null : 'must be a whole number (0 or more).',
                    'decimal' => (is_numeric($value) && (float) $value >= 0 && (float) $value <= 1000000) ? null : 'must be a positive number.',
                    'json'    => json_decode($value) !== null ? null : 'must be valid JSON.',
                    default   => mb_strlen($value) <= 255 ? null : 'may not be longer than 255 characters.',
                };
                if ($key === 'currency_code' && !preg_match('/^[A-Z]{3}$/', strtoupper($value))) {
                    $error = 'must be a 3-letter ISO code (e.g. USD).';
                }
                if ($key === 'currency_code') {
                    $value = strtoupper($value);
                }
                if ($error) {
                    $errors[$key][] = $row['label'] . ' ' . $error;
                    continue;
                }
            }
            if ((string) $row['setting_value'] !== $value) {
                $changes[$key] = [$row['setting_value'], $value];
            }
        }
        if ($errors) {
            throw new \App\Exceptions\ValidationException($errors);
        }
        $this->db->transaction(function () use ($changes, $userId) {
            foreach ($changes as $key => [, $new]) {
                $this->db->update('system_settings', ['setting_value' => $new, 'updated_by' => $userId], 'setting_key = :k', ['k' => $key]);
            }
        });
        $this->flush();
        if ($changes) {
            cache_bump();
        }
        return $changes;
    }

    public function flush(): void
    {
        $this->cache = null;
    }

    public static function cast(?string $value, string $type): mixed
    {
        return match ($type) {
            'int'     => (int) $value,
            'decimal' => (float) $value,
            'bool'    => $value === '1',
            'json'    => json_decode((string) $value, true),
            default   => (string) $value,
        };
    }
}
