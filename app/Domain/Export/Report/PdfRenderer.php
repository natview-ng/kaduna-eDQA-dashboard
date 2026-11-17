<?php

declare(strict_types=1);

namespace App\Domain\Export\Report;

use Illuminate\Support\Facades\File;
use Spatie\Browsershot\Browsershot;
use Spatie\LaravelPdf\Enums\Format;
use Spatie\LaravelPdf\Facades\Pdf;

/**
 * HTML to PDF with Chromium, through spatie/laravel-pdf's Browsershot driver (Phase 6 step 5,
 * D-101; DEPLOY.md §12). Only on worker-exports, whose image has Chromium. Takes rendered HTML,
 * never a URL: Chromium runs with --no-sandbox, acceptable only because it renders the app's own
 * views of validated data. JavaScript is off (the report has none) and the Chromium and Node paths
 * come from config (the image's env). The PDF is tagged (structure for screen readers, /Lang from
 * <html lang>) and carries its title in its metadata.
 */
final class PdfRenderer
{
    public function save(string $html, string $path, string $title, ?string $header = null, ?string $footer = null): void
    {
        // A home of its own in /tmp: the app user's home is not writable in the image, and
        // Chromium's crash handler will not start without somewhere to write. Removed after.
        $profile = rtrim(sys_get_temp_dir(), '/').'/edqa-chromium-'.bin2hex(random_bytes(6));
        try {
            $this->render($html, $path, $title, $header, $footer, $profile);
        } finally {
            File::deleteDirectory($profile);
        }
    }

    private function render(string $html, string $path, string $title, ?string $header, ?string $footer, string $profile): void
    {
        $pdf = Pdf::html($html)
            ->format(Format::A4)
            ->portrait()
            ->margins(16, 14, 14, 14)
            ->tagged()
            ->meta(title: $title, author: (string) __('report.cover.state'), subject: $title, creator: (string) config('app.name'))
            ->withBrowsershot(function (Browsershot $browsershot) use ($profile): void {
                $browsershot->noSandbox()
                    ->setUserDataDir("{$profile}/profile")
                    // Chromium's own environment: HOME and XDG in the temp directory.
                    ->setEnvironmentOptions([
                        'HOME' => $profile,
                        'XDG_CONFIG_HOME' => "{$profile}/config",
                        'XDG_CACHE_HOME' => "{$profile}/cache",
                        'PATH' => (string) getenv('PATH'),
                    ])
                    ->setChromePath((string) config('edqa.pdf.chrome_path'))
                    ->setNodeBinary((string) config('edqa.pdf.node_binary'))
                    ->setNodeModulePath((string) config('edqa.pdf.node_modules'))
                    ->disableJavascript()
                    ->timeout((int) config('edqa.pdf.timeout'));
            });
        if ($header !== null) {
            $pdf->headerHtml($header);
        }
        if ($footer !== null) {
            $pdf->footerHtml($footer);
        }
        $pdf->save($path);
    }
}
