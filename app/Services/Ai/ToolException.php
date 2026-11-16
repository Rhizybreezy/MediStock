<?php
declare(strict_types=1);

namespace App\Services\Ai;

/** A tool call that cannot be completed; status is 'invalid' or 'denied'. Message is shown to the model. */
final class ToolException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $status = 'invalid', public readonly array $extra = [])
    {
        parent::__construct($message);
    }
}
