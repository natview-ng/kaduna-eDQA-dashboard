<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Export\Report\PdfRenderer;
use App\Domain\Export\Report\PdfText;
use App\Domain\Export\Report\ReportStyle;
use Illuminate\Console\Command;
use Throwable;

/**
 * The PDF smoke test (DEPLOY.md §12, run after each deploy in worker-exports; Phase 6 step 5):
 * renders a one-page PDF to /tmp with the round report's own renderer (Chromium through
 * Browsershot, the same tokens and font), reads its text back with pdftotext and checks it.
 * Touches no data. Exit 0 when the PDF renders and reads back; 1 otherwise, with the reason.
 */
final class PdfSmoke extends Command
{
    protected $signature = 'edqa:pdf-smoke';

    protected $description = 'Render a one-page PDF with Chromium and read it back';

    public function handle(PdfRenderer $renderer, PdfText $reader): int
    {
        $path = rtrim(sys_get_temp_dir(), '/').'/edqa-pdf-smoke-'.getmypid().'.pdf';
        $heading = (string) __('report.smoke.heading');

        try {
            $renderer->save(view('reports.smoke', [
                'heading' => $heading,
                'rootCss' => ReportStyle::rootCss(),
                'fontCss' => ReportStyle::fontCss(),
            ])->render(), $path, $heading);

            $bytes = (int) filesize($path);
            if (! str_starts_with((string) file_get_contents($path, length: 5), '%PDF-')) {
                throw new \RuntimeException('the file is not a PDF');
            }
            if (! str_contains($reader->text($path), $heading)) {
                throw new \RuntimeException('the heading is not in the PDF text');
            }
            $pages = $reader->pages($path);
        } catch (Throwable $e) {
            $this->error((string) __('report.smoke.failed', ['reason' => $e->getMessage()]));

            return self::FAILURE;
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        if (! ReportStyle::hasFonts()) {
            $this->warn((string) __('report.smoke.no_fonts'));
        }
        $this->info((string) __('report.smoke.ok', ['pages' => $pages, 'bytes' => $bytes]));

        return self::SUCCESS;
    }
}
