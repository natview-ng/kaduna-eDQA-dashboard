<?php

declare(strict_types=1);

namespace App\Domain\Export\Types;

use App\Domain\Export\Exceptions\UnknownExportType;
use Illuminate\Contracts\Container\Container;

/**
 * The kinds of export there are (Phase 6 step 0). A new one is a class implementing ExportType,
 * listed here, with its strings in lang/en/exports.php.
 */
final class ExportTypes
{
    /** @var array<string, class-string<ExportType>> */
    private const TYPES = [
        'assessments' => AssessmentsExport::class,
    ];

    public function __construct(
        private readonly Container $container,
    ) {}

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(self::TYPES);
    }

    public function get(string $key): ExportType
    {
        $class = self::TYPES[$key] ?? throw new UnknownExportType($key);

        return $this->container->make($class);
    }
}
