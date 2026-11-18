<?php

declare(strict_types=1);

namespace App\Domain\Export\Report;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Browsershot\Browsershot;
use Spatie\LaravelPdf\Enums\Format;
use Spatie\LaravelPdf\Facades\Pdf;
use Throwable;

/**
 * HTML to PDF with Chromium, through spatie/laravel-pdf's Browsershot driver (D-101; DEPLOY.md
 * §12). Only on worker-exports, whose image has Chromium. Takes rendered HTML,
 * never a URL: Chromium runs with --no-sandbox, acceptable only because it renders the app's own
 * views of validated data. JavaScript is off (the report has none) and the Chromium and Node paths
 * come from config (the image's env). The PDF is tagged (structure for screen readers, /Lang from
 * <html lang>) and carries its title in its metadata.
 */
final class PdfRenderer
{
    /** Chromium's first start after an idle spell can stall (a cold read of its binary); the next is quick. */
    private const ATTEMPTS = 2;

    public function save(string $html, string $path, string $title, ?string $header = null, ?string $footer = null): void
    {
        for ($attempt = 1; ; $attempt++) {
            // A home of its own in /tmp: the app user's home is not writable in the image, and
            // Chromium's crash handler will not start without somewhere to write. Removed after.
            $profile = rtrim(sys_get_temp_dir(), '/').'/edqa-chromium-'.bin2hex(random_bytes(6));
            try {
                $this->render($html, $path, $title, $header, $footer, $profile);

                return;
            } catch (Throwable $e) {
                if ($attempt >= self::ATTEMPTS) {
                    throw $e;
                }
                Log::warning('PDF render failed; trying once more.', ['attempt' => $attempt, 'error' => Str::limit($e->getMessage(), 300)]);
            } finally {
                File::deleteDirectory($profile);
            }
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
                    // New headless (Chromium has dropped the old mode Browsershot asks for by default),
                    // talking over a pipe rather than waiting for a WebSocket address on stdout, and no
                    // zygote process (the sandbox is off anyway): each start was otherwise liable to
                    // stall past Puppeteer's 30-second launch limit on a busy host.
                    ->newHeadless()
                    ->usePipe()
                    ->addChromiumArguments(['no-zygote', 'disable-dev-shm-usage'])
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
                    ->timeout((int) config('edqa.pdf.timeout'))
                    // Each DevTools call may take the whole budget, not Puppeteer's default 30 s: on a
                    // loaded host Chromium's first answer can come later than that.
                    ->setOption('protocolTimeout', (int) config('edqa.pdf.timeout') * 1000);
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
