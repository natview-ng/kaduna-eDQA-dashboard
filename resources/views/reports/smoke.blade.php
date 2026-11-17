{{-- edqa:pdf-smoke's one page (Phase 6 step 5): the round report's tokens and font, nothing else. --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="utf-8">
<title>{{ $heading }}</title>
<style>
{!! $fontCss !!}
{!! $rootCss !!}
@page { size: A4 portrait; }
body { font-family: 'IBM Plex Sans', 'Liberation Sans', sans-serif; color: var(--color-ink); }
h1 { color: var(--color-deep); font-weight: 600; }
</style>
</head>
<body>
<h1>{{ $heading }}</h1>
<p>{{ __('report.smoke.body') }}</p>
</body>
</html>
