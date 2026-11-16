<?php
declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A request that is well-formed but violates a business rule
 * (e.g. "cannot deactivate your own account", "insufficient stock").
 * Shown to the user as a friendly error message.
 */
class BusinessRuleException extends RuntimeException
{
}
