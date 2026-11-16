<?php
declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Validates arguments produced by the model against a tool's parameter spec.
 * Unknown arguments are rejected, types are checked strictly, ranges/lengths/enums
 * enforced and defaults applied. The model's output is never trusted.
 *
 * Spec per parameter: ['type' => string|integer|number|boolean|array, 'description',
 *   'required'?, 'enum'?, 'min'?, 'max'?, 'maxLength'?, 'default'?, 'maxItems'?, 'items'? (object spec)]
 */
final class ArgValidator
{
    public static function validate(array $spec, array $args, string $path = ''): array
    {
        foreach (array_keys($args) as $key) {
            if (!isset($spec[$key])) {
                throw new ToolException("Unknown argument '$path$key'.");
            }
        }
        $out = [];
        foreach ($spec as $name => $rule) {
            $label = $path . $name;
            if (!array_key_exists($name, $args) || $args[$name] === null || $args[$name] === '') {
                if (!empty($rule['required'])) {
                    throw new ToolException("Missing required argument '$label'.");
                }
                $out[$name] = $rule['default'] ?? null;
                continue;
            }
            $v = $args[$name];
            switch ($rule['type']) {
                case 'integer':
                    if (is_float($v) && floor($v) == $v) {
                        $v = (int) $v;
                    } elseif (is_string($v) && preg_match('/^-?\d+$/', $v)) {
                        $v = (int) $v;
                    }
                    if (!is_int($v)) {
                        throw new ToolException("'$label' must be a whole number.");
                    }
                    break;
                case 'number':
                    if (!is_int($v) && !is_float($v) && !(is_string($v) && is_numeric($v))) {
                        throw new ToolException("'$label' must be a number.");
                    }
                    $v = (float) $v;
                    break;
                case 'boolean':
                    if (!is_bool($v)) {
                        throw new ToolException("'$label' must be true or false.");
                    }
                    break;
                case 'string':
                    if (!is_string($v) && !is_int($v)) {
                        throw new ToolException("'$label' must be text.");
                    }
                    $v = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) $v) ?? '');
                    if (isset($rule['maxLength']) && mb_strlen($v) > $rule['maxLength']) {
                        throw new ToolException("'$label' is too long (max {$rule['maxLength']} characters).");
                    }
                    break;
                case 'array':
                    if (!is_array($v) || !array_is_list($v)) {
                        throw new ToolException("'$label' must be a list.");
                    }
                    if (isset($rule['maxItems']) && count($v) > $rule['maxItems']) {
                        throw new ToolException("'$label' may contain at most {$rule['maxItems']} items.");
                    }
                    if (!$v && !empty($rule['required'])) {
                        throw new ToolException("'$label' must not be empty.");
                    }
                    $v = array_map(function ($item) use ($rule, $label) {
                        if (!is_array($item)) {
                            throw new ToolException("Each item in '$label' must be an object.");
                        }
                        return self::validate($rule['items'], $item, $label . '[].');
                    }, $v);
                    break;
                default:
                    throw new ToolException("Unsupported argument type for '$label'.");
            }
            if (isset($rule['enum']) && !in_array($v, $rule['enum'], true)) {
                throw new ToolException("'$label' must be one of: " . implode(', ', $rule['enum']) . '.');
            }
            if (in_array($rule['type'], ['integer', 'number'], true)) {
                if (isset($rule['min']) && $v < $rule['min']) {
                    throw new ToolException("'$label' must be at least {$rule['min']}.");
                }
                if (isset($rule['max']) && $v > $rule['max']) {
                    throw new ToolException("'$label' must be at most {$rule['max']}.");
                }
            }
            $out[$name] = $v;
        }
        return $out;
    }

    /** Convert a spec into a Gemini function-declaration "parameters" schema. */
    public static function schema(array $spec): array
    {
        $props = [];
        $required = [];
        foreach ($spec as $name => $rule) {
            $p = ['type' => $rule['type'], 'description' => $rule['description'] ?? $name];
            foreach (['enum'] as $k) {
                if (isset($rule[$k])) {
                    $p[$k] = $rule[$k];
                }
            }
            if ($rule['type'] === 'array') {
                $p['items'] = ['type' => 'object'] + self::schema($rule['items']);
            }
            $props[$name] = $p;
            if (!empty($rule['required'])) {
                $required[] = $name;
            }
        }
        $schema = ['type' => 'object', 'properties' => $props ?: new \stdClass()];
        if ($required) {
            $schema['required'] = $required;
        }
        return $schema;
    }
}
