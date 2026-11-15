<?php

declare(strict_types=1);

namespace App\Domain\Export\Support;

use App\Domain\Ingestion\SourceRegistry;

/** A source's name as administrators know it (ODK Central, KoboToolbox), by its row id. */
final class SourceNames
{
    /** @var array<int, string> */
    private array $names = [];

    public function __construct(
        private readonly SourceRegistry $sources,
    ) {}

    public function of(?int $sourceId): ?string
    {
        if ($sourceId === null) {
            return null;
        }
        if (! isset($this->names[$sourceId])) {
            $key = $this->sources->keyOf($sourceId);
            $this->names[$sourceId] = $key === null ? (string) __('exports.unknown_source') : $this->sources->name($key);
        }

        return $this->names[$sourceId];
    }

    /** @param  list<int>  $sourceIds */
    public function list(array $sourceIds): ?string
    {
        return $sourceIds === [] ? null : implode('; ', array_map(fn (int $id): string => (string) $this->of($id), $sourceIds));
    }

    /** Every configured source, for "About this file". */
    public function configured(): string
    {
        $names = array_map(fn (string $key): string => $this->sources->name($key), $this->sources->configured());

        return $names === [] ? (string) __('exports.about.no_sources') : implode(', ', $names);
    }
}
