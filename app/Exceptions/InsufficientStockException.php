<?php
declare(strict_types=1);

namespace App\Exceptions;

/** Not enough usable (non-expired, non-recalled, unreserved) stock for the operation. */
class InsufficientStockException extends BusinessRuleException
{
}
