<?php

declare(strict_types=1);

namespace App\Domain\Export\Exceptions;

use InvalidArgumentException;

final class UnknownExportType extends InvalidArgumentException
{
    public function __construct(string $key)
    {
        parent::__construct((string) __('exports.errors.unknown_type', ['type' => $key]));
    }
}
