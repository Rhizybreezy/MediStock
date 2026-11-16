<?php
declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** Thrown by the Validator (or services) with per-field error messages. */
class ValidationException extends RuntimeException
{
    /** @param array<string, string[]> $errors */
    public function __construct(private array $errors, string $message = 'Please correct the highlighted fields.')
    {
        parent::__construct($message, 422);
    }

    /** Convenience for a single business-rule error. */
    public static function withMessage(string $field, string $message): self
    {
        return new self([$field => [$message]], $message);
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
