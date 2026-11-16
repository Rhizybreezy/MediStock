<?php
declare(strict_types=1);

namespace App\Core;

use App\Exceptions\ValidationException;

/**
 * Server-side input validation. Never rely on browser validation alone.
 *
 *   $data = Validator::validate($request->all(), [
 *       'name'  => 'required|string|max:120',
 *       'email' => 'required|email|unique:users,email,' . $id,
 *       'roles' => 'required|array',
 *       'roles.*' => 'integer|exists:roles,id',
 *   ]);
 *
 * Returns ONLY the validated keys (so unexpected fields are never saved) and
 * throws ValidationException with per-field messages on failure.
 *
 * Supported rules: required, nullable, string, integer, numeric, boolean, email,
 * array, date, min:n, max:n, between:a,b, in:a,b,c, regex:/../, confirmed,
 * password, unique:table,column[,ignoreId], exists:table,column, after_or_equal:field,
 * different:field
 */
final class Validator
{
    private array $errors = [];

    private function __construct(private array $data, private array $rules, private array $labels)
    {
    }

    public static function validate(array $data, array $rules, array $labels = []): array
    {
        $v = new self($data, $rules, $labels);
        return $v->run();
    }

    private function run(): array
    {
        $validated = [];
        foreach ($this->rules as $field => $ruleString) {
            if (str_ends_with($field, '.*')) {
                $parent = substr($field, 0, -2);
                $items = $this->data[$parent] ?? [];
                if (is_array($items)) {
                    foreach ($items as $i => $item) {
                        $this->checkField("$parent.$i", $item, $ruleString, $parent);
                    }
                }
                continue;
            }
            $value = $this->data[$field] ?? null;
            if ($this->checkField($field, $value, $ruleString)) {
                $validated[$field] = $this->normalize($value, $ruleString);
            }
        }
        if ($this->errors) {
            throw new ValidationException($this->errors);
        }
        return $validated;
    }

    private function checkField(string $field, mixed $value, string $ruleString, ?string $labelKey = null): bool
    {
        $rules = $this->parse($ruleString);
        $isEmpty = $value === null || $value === '' || $value === [];

        if ($isEmpty) {
            if (array_key_exists('required', $rules)) {
                $this->fail($field, 'is required.', $labelKey);
                return false;
            }
            return true; // optional & empty → valid (normalized to null)
        }

        foreach ($rules as $rule => $param) {
            $message = $this->check($rule, $param, $field, $value, $rules);
            if ($message !== null) {
                $this->fail($field, $message, $labelKey);
                return false;
            }
        }
        return true;
    }

    private function check(string $rule, ?string $param, string $field, mixed $value, array $rules): ?string
    {
        $isNumericRule = array_key_exists('integer', $rules) || array_key_exists('numeric', $rules);

        switch ($rule) {
            case 'required':
            case 'nullable':
                return null;
            case 'string':
                return is_string($value) ? null : 'must be text.';
            case 'integer':
                return (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value))) ? null : 'must be a whole number.';
            case 'numeric':
                return is_numeric($value) ? null : 'must be a number.';
            case 'boolean':
                return in_array($value, [true, false, 0, 1, '0', '1', 'on', 'off', 'true', 'false'], true) ? null : 'must be yes or no.';
            case 'email':
                return filter_var($value, FILTER_VALIDATE_EMAIL) ? null : 'must be a valid email address.';
            case 'array':
                return is_array($value) ? null : 'must be a list.';
            case 'date':
                $d = is_string($value) ? \DateTime::createFromFormat('Y-m-d', $value) : false;
                return ($d && $d->format('Y-m-d') === $value) ? null : 'must be a valid date (YYYY-MM-DD).';
            case 'min':
                if ($isNumericRule) {
                    return (float) $value >= (float) $param ? null : "must be at least $param.";
                }
                if (is_array($value)) {
                    return count($value) >= (int) $param ? null : "must have at least $param item(s).";
                }
                return mb_strlen((string) $value) >= (int) $param ? null : "must be at least $param characters.";
            case 'max':
                if ($isNumericRule) {
                    return (float) $value <= (float) $param ? null : "may not be greater than $param.";
                }
                if (is_array($value)) {
                    return count($value) <= (int) $param ? null : "may not have more than $param items.";
                }
                return mb_strlen((string) $value) <= (int) $param ? null : "may not be longer than $param characters.";
            case 'between':
                [$a, $b] = explode(',', (string) $param);
                return ((float) $value >= (float) $a && (float) $value <= (float) $b) ? null : "must be between $a and $b.";
            case 'in':
                return in_array((string) $value, explode(',', (string) $param), true) ? null : 'has an invalid value.';
            case 'regex':
                return preg_match((string) $param, (string) $value) ? null : 'has an invalid format.';
            case 'confirmed':
                return ($this->data[$field . '_confirmation'] ?? null) === $value ? null : 'confirmation does not match.';
            case 'different':
                return ($this->data[$param] ?? null) !== $value ? null : 'must be different from ' . $this->label((string) $param) . '.';
            case 'after_or_equal':
                $other = $this->data[$param] ?? null;
                return (!$other || (string) $value >= (string) $other) ? null : 'must be on or after ' . $this->label((string) $param) . '.';
            case 'password':
                $min = (int) Config::get('security.password.min_length', 8);
                if (mb_strlen((string) $value) < $min) {
                    return "must be at least $min characters.";
                }
                return (preg_match('/[A-Za-z]/', (string) $value) && preg_match('/\d/', (string) $value))
                    ? null : 'must contain both letters and numbers.';
            case 'unique':
                [$table, $column, $ignore] = array_pad(explode(',', (string) $param), 3, null);
                $this->assertIdentifiers($table, $column);
                $sql = "SELECT COUNT(*) FROM `$table` WHERE `$column` = :v";
                $params = ['v' => $value];
                if ($ignore !== null && $ignore !== '') {
                    $sql .= ' AND id <> :ignore';
                    $params['ignore'] = (int) $ignore;
                }
                return (int) app(Database::class)->fetchValue($sql, $params) === 0 ? null : 'is already in use.';
            case 'exists':
                [$table, $column] = array_pad(explode(',', (string) $param), 2, 'id');
                $this->assertIdentifiers($table, $column);
                return (int) app(Database::class)->fetchValue("SELECT COUNT(*) FROM `$table` WHERE `$column` = :v", ['v' => $value]) > 0
                    ? null : 'does not exist.';
        }
        throw new \InvalidArgumentException("Unknown validation rule [$rule].");
    }

    private function normalize(mixed $value, string $ruleString): mixed
    {
        $rules = $this->parse($ruleString);
        if ($value === '' || $value === null) {
            return array_key_exists('boolean', $rules) ? 0 : null;
        }
        if (array_key_exists('integer', $rules)) {
            return (int) $value;
        }
        if (array_key_exists('boolean', $rules)) {
            return in_array($value, [true, 1, '1', 'on', 'true'], true) ? 1 : 0;
        }
        return $value;
    }

    private function parse(string $ruleString): array
    {
        $rules = [];
        // A regex pattern may contain '|', so it must be the LAST rule and is taken verbatim.
        $regexPos = strpos($ruleString, 'regex:');
        if ($regexPos !== false) {
            $rules['regex'] = substr($ruleString, $regexPos + 6);
            $ruleString = rtrim(substr($ruleString, 0, $regexPos), '|');
        }
        foreach (explode('|', $ruleString) as $part) {
            if ($part === '') {
                continue;
            }
            [$name, $param] = array_pad(explode(':', $part, 2), 2, null);
            $rules[$name] = $param;
        }
        return $rules;
    }

    private function fail(string $field, string $message, ?string $labelKey): void
    {
        $this->errors[$field][] = $this->label($labelKey ?? $field) . ' ' . $message;
    }

    private function label(string $field): string
    {
        return $this->labels[$field] ?? ucfirst(str_replace('_', ' ', preg_replace('/_id$/', '', $field)));
    }

    private function assertIdentifiers(?string ...$names): void
    {
        foreach ($names as $n) {
            if ($n === null || !preg_match('/^[a-z_][a-z0-9_]*$/', $n)) {
                throw new \InvalidArgumentException('Invalid identifier in validation rule.');
            }
        }
    }
}
