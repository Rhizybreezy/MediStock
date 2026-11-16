<?php
declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** The user is logged in but not allowed to perform the action. */
class AuthorizationException extends RuntimeException
{
    public function __construct(string $message = 'You do not have permission to perform this action.', private ?string $permission = null)
    {
        parent::__construct($message, 403);
    }

    public function permission(): ?string
    {
        return $this->permission;
    }
}
