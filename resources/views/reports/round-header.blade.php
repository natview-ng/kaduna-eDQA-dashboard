{{-- Chromium's running header for the round report (Phase 6 step 5): the round and, while it is open, DRAFT. Inline styles only: a page template sees none of the page's CSS. --}}
<div style="width: 100%; padding: 0 14mm; font-family: 'Liberation Sans', 'Noto Sans', sans-serif; font-size: 7.5pt; color: #5a6f68; display: flex; justify-content: space-between;">
    <span>{{ __('report.header', ['round' => $report->round]) }}</span>
    @if ($report->draft)<span style="color: #9e3b2f; font-weight: bold;">{{ __('report.draft') }}</span>@endif
</div>
