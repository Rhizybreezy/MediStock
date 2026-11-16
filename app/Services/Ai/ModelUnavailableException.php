<?php
declare(strict_types=1);

namespace App\Services\Ai;

/** A model is overloaded, timed out or not offered: the client may try a fallback model. */
final class ModelUnavailableException extends \RuntimeException
{
}
