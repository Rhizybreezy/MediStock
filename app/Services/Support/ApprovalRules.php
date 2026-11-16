<?php
declare(strict_types=1);

namespace App\Services\Support;

use App\Exceptions\BusinessRuleException;

/**
 * Segregation of duties: the person who raised a request may not approve it.
 */
final class ApprovalRules
{
    public static function assertDifferentUser(int $requesterId, int $approverId, string $what = 'request'): void
    {
        if ($requesterId === $approverId) {
            throw new BusinessRuleException("You cannot approve your own $what. Another authorised person must review it.");
        }
    }
}
