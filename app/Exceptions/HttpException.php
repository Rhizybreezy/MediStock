<?php
declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** An error that maps directly to an HTTP status code (404, 405, 419, 429 ...). */
class HttpException extends RuntimeException
{
    public function __construct(private int $status, string $message = '', private array $headers = [], private ?string $title = null)
    {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** Optional page title override (e.g. "Session expired" for a 403 CSRF failure). */
    public function title(): ?string
    {
        return $this->title;
    }

    public function headers(): array
    {
        return $this->headers;
    }
}
