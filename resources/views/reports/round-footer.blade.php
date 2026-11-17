{{-- Chromium's running footer for the round report: the page number (Chromium fills pageNumber and totalPages) and the date it was made. Inline styles only. --}}
<div style="width: 100%; padding: 0 14mm; font-family: 'Liberation Sans', 'Noto Sans', sans-serif; font-size: 7.5pt; color: #5a6f68; display: flex; justify-content: space-between;">
    <span>{{ __('report.cover.support') }} · {{ $report->generatedOn }}</span>
    <span>{!! __('report.page', ['page' => '<span class="pageNumber"></span>', 'pages' => '<span class="totalPages"></span>']) !!}</span>
</div>
