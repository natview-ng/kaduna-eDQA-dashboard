<?php

declare(strict_types=1);

namespace App\Domain\Facility\Exceptions;

use RuntimeException;

/**
 * A master-list change the domain refuses, rendered as 409 { code, message }.
 * The message comes from lang/en/facilities.php errors.{code}.
 */
final class FacilityActionRefused extends RuntimeException
{
    /** @param  array<string, string|int>  $replace */
    private function __construct(
        public readonly string $errorCode,
        array $replace = [],
    ) {
        $message = __("facilities.errors.{$errorCode}", array_map(strval(...), $replace));

        parent::__construct(is_string($message) ? $message : $errorCode);
    }

    public static function codeLocked(int $assessments): self
    {
        return new self('code_locked', ['count' => $assessments]);
    }

    public static function codeTaken(string $code): self
    {
        return new self('code_taken', ['code' => $code]);
    }

    public static function alreadyInactive(): self
    {
        return new self('already_inactive');
    }

    public static function notInactive(): self
    {
        return new self('not_inactive');
    }

    public static function wardExists(string $name, string $lga): self
    {
        return new self('ward_exists', ['ward' => $name, 'lga' => $lga]);
    }

    public static function reasonTooShort(): self
    {
        return new self('reason_too_short');
    }
}
