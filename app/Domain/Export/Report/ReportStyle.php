<?php

declare(strict_types=1);

namespace App\Domain\Export\Report;

/**
 * The PDF's design tokens and fonts (Phase 6 step 5, D-101; CONVENTION.md §6). The colours are
 * read from resources/css/app.css, where the tokens are defined once, and given to the print view
 * as the same custom properties (--color-ink, --color-series-availability…). IBM Plex Sans is the
 * app's self-hosted copy, embedded in the HTML as data: URIs, so Chromium fetches nothing: the
 * built assets in public/build (the worker image), else the @fontsource package (development).
 */
final class ReportStyle
{
    private const WEIGHTS = [400, 600];

    /** @var array<string, string>|null */
    private static ?array $tokens = null;

    /** @return array<string, string> token name ("ink", "series-availability") => colour */
    public static function tokens(): array
    {
        if (self::$tokens === null) {
            $css = (string) @file_get_contents(resource_path('css/app.css'));
            preg_match_all('/--color-([a-z0-9-]+):\s*(#[0-9a-fA-F]{3,8})\s*;/', $css, $matches, PREG_SET_ORDER);
            $tokens = [];
            foreach ($matches as $match) {
                $tokens[$match[1]] ??= strtolower($match[2]);
            }
            self::$tokens = $tokens;
        }

        return self::$tokens;
    }

    /** ":root { --color-ink: #17302b; … }" */
    public static function rootCss(): string
    {
        $lines = [];
        foreach (self::tokens() as $name => $value) {
            $lines[] = "--color-{$name}: {$value};";
        }

        return ':root { '.implode(' ', $lines).' }';
    }

    /** @font-face rules for IBM Plex Sans 400 and 600 (Latin), or "" when neither copy is there. */
    public static function fontCss(): string
    {
        $css = '';
        foreach (self::WEIGHTS as $weight) {
            $file = self::fontFile($weight);
            if ($file === null) {
                continue;
            }
            $data = base64_encode((string) file_get_contents($file));
            $css .= "@font-face { font-family: 'IBM Plex Sans'; font-style: normal; font-weight: {$weight}; src: url(data:font/woff2;base64,{$data}) format('woff2'); }\n";
        }

        return $css;
    }

    /** Whether the self-hosted font is there (edqa:pdf-smoke reports it). */
    public static function hasFonts(): bool
    {
        foreach (self::WEIGHTS as $weight) {
            if (self::fontFile($weight) === null) {
                return false;
            }
        }

        return true;
    }

    private static function fontFile(int $weight): ?string
    {
        $built = glob(public_path("build/assets/ibm-plex-sans-latin-{$weight}-normal-*.woff2")) ?: [];
        $candidates = [...$built, base_path("node_modules/@fontsource/ibm-plex-sans/files/ibm-plex-sans-latin-{$weight}-normal.woff2")];
        foreach ($candidates as $file) {
            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }
}
