<?php

declare(strict_types=1);

namespace App\Domain\Export\Exceptions;

use RuntimeException;

/**
 * An export request or download that cannot go ahead. Rendered as 409 { code, message }; the
 * message comes from lang/en/exports.php under errors.{code}.
 */
final class ExportRefused extends RuntimeException
{
    private function __construct(
        public readonly string $errorCode,
    ) {
        $message = __("exports.errors.{$errorCode}");

        parent::__construct(is_string($message) ? $message : $errorCode);
    }

    /** "Include assessor and source details" asked for a type that has none. */
    public static function privateNotOffered(): self
    {
        return new self('private_not_offered');
    }

    public static function notReady(): self
    {
        return new self('not_ready');
    }

    public static function expired(): self
    {
        return new self('expired');
    }
}
