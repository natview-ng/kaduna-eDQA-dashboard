<?php

declare(strict_types=1);

namespace App\Domain\Export\Report;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Reads a PDF back with poppler (pdftotext, pdfinfo; worker image only): edqa:pdf-smoke checks
 * the text of what it rendered, and the export records the page count (Phase 6 step 5).
 */
final class PdfText
{
    /**
     * The PDF's text, pages split by form feeds: in reading order, or with $raw in content-stream order,
     * which keeps rotated text (the DRAFT watermark) in one piece.
     */
    public function text(string $path, bool $raw = false): string
    {
        $result = Process::timeout(60)->run([(string) config('edqa.pdf.pdftotext'), '-enc', 'UTF-8', ...($raw ? ['-raw'] : []), $path, '-']);
        if (! $result->successful()) {
            throw new RuntimeException('pdftotext failed: '.trim($result->errorOutput()));
        }

        return $result->output();
    }

    /** The page count: pdfinfo's, else the page objects counted in the file. */
    public function pages(string $path): int
    {
        $pdfinfo = dirname((string) config('edqa.pdf.pdftotext')).'/pdfinfo';
        if (is_executable($pdfinfo)) {
            $result = Process::timeout(30)->run([$pdfinfo, $path]);
            if ($result->successful() && preg_match('/^Pages:\s+(\d+)/m', $result->output(), $match) === 1) {
                return (int) $match[1];
            }
        }

        if (! is_file($path)) {
            return 0;
        }

        return max(1, (int) preg_match_all('#/Type\s*/Page[^s]#', (string) file_get_contents($path)));
    }
}
