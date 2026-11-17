{{--
    The PDF round report (Phase 6 step 5, D-101). Print only: A4 portrait, rendered to HTML on the
    server and handed to Chromium (RoundReportView, RoundReportPdf), never served as a page. Charts
    are inline SVG drawn by SvgChart; each has its table (hard rule 11). Tokens come from app.css
    (ReportStyle), the font is the self-hosted IBM Plex Sans. Table header rows repeat on every
    page (thead). An open round carries the DRAFT watermark on every page (position: fixed).
    Nothing personal: no assessor, facility name, source ID, contact or note.
--}}
@php
    /** @var \App\Domain\Export\Report\RoundReport $report */
    /** @var array<string, string> $charts */
    $score = fn (\App\Domain\Scoring\DTOs\ScoreValue $s): string => $s->display;
    $band = fn (\App\Domain\Scoring\DTOs\ScoreValue $s): string => 'band band-'.($s->band->value ?? 'none');
    $c = fn (string $key): string => __("report.columns.{$key}");
    $count = fn (int $n): string => number_format($n);
    $strongLabel = \App\Domain\Scoring\Enums\Band::Strong->label();
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="utf-8">
<title>{{ $report->title() }}</title>
<style>
{!! $fontCss !!}
{!! $rootCss !!}
@page { size: A4 portrait; }
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body {
    font-family: 'IBM Plex Sans', 'Liberation Sans', 'Noto Sans', sans-serif;
    font-size: 9.5pt; line-height: 1.45; color: var(--color-ink); background: var(--color-panel);
    font-variant-numeric: tabular-nums; -webkit-print-color-adjust: exact; print-color-adjust: exact;
}
h1, h2, h3 { font-weight: 600; letter-spacing: -0.01em; margin: 0; color: var(--color-ink); }
h2 { font-size: 14pt; margin: 0 0 3mm; padding-bottom: 1.5mm; border-bottom: 1px solid var(--color-rule); }
h3 { font-size: 10.5pt; margin: 5mm 0 2mm; }
p { margin: 0 0 2mm; }
.muted { color: var(--color-ink-2); }
.small { font-size: 8.5pt; }
section { margin: 0 0 7mm; }
section.break { break-before: page; }
figure { margin: 0 0 3mm; break-inside: avoid; }
figure figcaption { font-size: 8.5pt; color: var(--color-ink-2); margin-top: 1mm; }
.keep { break-inside: avoid; }

/* Cover */
.cover { height: 250mm; display: flex; flex-direction: column; break-after: page; }
.cover .band-top { height: 3mm; background: var(--color-deep); margin-bottom: 30mm; }
.cover .state { font-size: 11pt; font-weight: 600; color: var(--color-deep); text-transform: uppercase; letter-spacing: 0.08em; }
.cover h1 { font-size: 30pt; line-height: 1.15; margin: 4mm 0 2mm; }
.cover .subtitle { font-size: 13pt; color: var(--color-ink-2); }
.cover dl { margin: 18mm 0 0; display: grid; grid-template-columns: 32mm 1fr; row-gap: 2.5mm; font-size: 10.5pt; }
.cover dt { color: var(--color-ink-2); }
.cover dd { margin: 0; font-weight: 600; }
.cover .draft-note { margin-top: 10mm; padding: 3mm 4mm; border-left: 3px solid var(--color-warn-fill); background: var(--color-warn-bg); color: var(--color-warn); font-weight: 600; }
.cover .support { margin-top: auto; padding-top: 4mm; border-top: 1px solid var(--color-rule); color: var(--color-ink-2); }
.cover nav ol { margin: 2mm 0 0; padding-left: 5mm; columns: 2; font-size: 9.5pt; }

/* Headline tiles */
.tiles { display: grid; grid-template-columns: 1.4fr 1fr 1fr 1fr 1fr; gap: 2.5mm; margin-bottom: 4mm; }
.tile { border: 1px solid var(--color-rule); border-radius: 2mm; padding: 2.5mm 3mm; }
.tile .label { font-size: 8pt; color: var(--color-ink-2); }
.tile .value { font-size: 16pt; font-weight: 600; line-height: 1.2; }
.tile.hero { background: var(--color-subtle); }
.tile.hero .value { font-size: 24pt; color: var(--color-deep); }

/* Tables */
table { width: 100%; border-collapse: collapse; font-size: 8.5pt; margin: 0 0 3mm; }
thead { display: table-header-group; }
tr { break-inside: avoid; }
th, td { text-align: left; padding: 1.3mm 2mm; border-bottom: 1px solid var(--color-rule); vertical-align: top; }
th { font-weight: 600; background: var(--color-subtle); color: var(--color-ink); border-bottom: 1px solid var(--color-rule-strong); }
tbody th { background: none; border-bottom: 1px solid var(--color-rule); }
td.num, th.num { text-align: right; white-space: nowrap; }
caption { text-align: left; font-weight: 600; font-size: 9pt; padding: 0 0 1.5mm; caption-side: top; }

/* Band labels: tinted, the label always written (colour never carries meaning alone) */
.band { display: inline-block; padding: 0 1.6mm; border-radius: 1mm; font-size: 7.5pt; font-weight: 600; white-space: nowrap; }
.band-strong { background: var(--color-ok-bg); color: var(--color-ok); }
.band-acceptable { background: var(--color-fine-bg); color: var(--color-fine); }
.band-review { background: var(--color-warn-bg); color: var(--color-warn); }
.band-needs_action { background: var(--color-bad-bg); color: var(--color-bad); }
.band-none { background: var(--color-subtle); color: var(--color-ink-2); }

/* Charts (SvgChart classes) */
svg.chart { display: block; max-width: 100%; height: auto; font-family: inherit; }
svg.chart .grid { stroke: var(--color-rule); stroke-width: 1; }
svg.chart .axis { fill: var(--color-ink-2); font-size: 9px; }
svg.chart .label { fill: var(--color-ink); font-size: 10px; }
svg.chart .value { fill: var(--color-ink); font-size: 10px; font-weight: 600; }
svg.chart .value.small { font-size: 8.5px; }
svg.chart .value.muted { fill: var(--color-ink-2); font-weight: 400; }
svg.chart .target { stroke: var(--color-warn-fill); stroke-width: 1.5; stroke-dasharray: 4 3; }
svg.chart rect.series-availability, svg.chart .dot.series-availability { fill: var(--color-series-availability); }
svg.chart rect.series-consistency, svg.chart .dot.series-consistency { fill: var(--color-series-consistency); }
svg.chart rect.series-validity, svg.chart .dot.series-validity { fill: var(--color-series-validity); }
svg.chart rect.series-overall, svg.chart .dot.series-overall { fill: var(--color-series-overall); }
svg.chart rect.pub, svg.chart .dot.pub { fill: var(--color-pub); }
svg.chart rect.priv, svg.chart .dot.priv { fill: var(--color-priv); }
svg.chart .line { fill: none; stroke-width: 2; }
svg.chart .line.series-overall { stroke: var(--color-series-overall); }
svg.chart .line.pub { stroke: var(--color-pub); }
svg.chart .line.priv { stroke: var(--color-priv); stroke-dasharray: 5 3; }
svg.chart rect.band-strong { fill: var(--color-ok); }
svg.chart rect.band-acceptable { fill: var(--color-fine); }
svg.chart rect.band-review { fill: var(--color-warn-fill); }
svg.chart rect.band-needs_action { fill: var(--color-bad); }
svg.chart rect.band-none { fill: var(--color-ink-3); }
.legend { display: flex; flex-wrap: wrap; gap: 1.5mm 5mm; font-size: 8pt; color: var(--color-ink-2); margin: 0 0 1.5mm; padding: 0; list-style: none; }
.legend .swatch { display: inline-block; width: 3mm; height: 3mm; border-radius: 0.6mm; margin-right: 1.2mm; vertical-align: -0.4mm; }
.swatch.series-availability { background: var(--color-series-availability); }
.swatch.series-consistency { background: var(--color-series-consistency); }
.swatch.series-validity { background: var(--color-series-validity); }
.swatch.series-overall { background: var(--color-series-overall); }
.swatch.pub { background: var(--color-pub); }
.swatch.priv { background: var(--color-priv); }
.swatch.target { background: none; border-top: 1.5px dashed var(--color-warn-fill); height: 0; width: 5mm; vertical-align: 0.6mm; border-radius: 0; }
.two { display: grid; grid-template-columns: 1fr 1fr; gap: 5mm; }

dl.method { display: grid; grid-template-columns: 32mm 1fr; gap: 2mm 4mm; margin: 0; }
dl.method dt { font-weight: 600; }
dl.method dd { margin: 0; }

/* The open-round watermark on every page: Chromium repeats a fixed element on each printed page. */
.watermark {
    position: fixed; top: 45%; left: 50%; transform: translate(-50%, -50%) rotate(-32deg);
    font-size: 40pt; font-weight: 600; letter-spacing: 0.04em; white-space: nowrap;
    color: var(--color-bad); opacity: 0.13; z-index: 10; pointer-events: none;
}
</style>
</head>
<body>
@if ($report->draft)
    <div class="watermark" aria-hidden="true">{{ __('report.draft') }}</div>
@endif

<header class="cover">
    <div class="band-top"></div>
    <p class="state">{{ __('report.cover.state') }}</p>
    <h1>{{ $report->round }}</h1>
    <p class="subtitle">{{ __('report.cover.subtitle') }}</p>
    <dl>
        <dt>{{ __('report.cover.round') }}</dt><dd>{{ $report->round }}</dd>
        <dt>{{ __('report.cover.generated') }}</dt><dd>{{ $report->generatedOn }}</dd>
        <dt>{{ __('report.cover.status') }}</dt><dd>{{ $report->draft ? __('report.draft') : __('report.cover.published') }}</dd>
    </dl>
    @if ($report->draft)
        <p class="draft-note">{{ __('report.cover.draft') }}</p>
    @endif
    <nav aria-label="{{ __('report.contents') }}" style="margin-top: 14mm">
        <h3>{{ __('report.contents') }}</h3>
        <ol>
            @foreach (__('report.sections') as $title)
                <li>{{ $title }}</li>
            @endforeach
        </ol>
    </nav>
    <p class="support">{{ __('report.cover.support') }}</p>
</header>

<main>
{{-- 1. State headline and the three dimensions --}}
<section aria-labelledby="s-headline">
    <h2 id="s-headline">{{ __('report.sections.headline') }}</h2>
    <div class="tiles">
        <div class="tile hero">
            <div class="label">{{ __('report.headline.overall') }}</div>
            <div class="value" data-figure="overall">{{ $report->summary->overall->headline }}</div>
            <span class="{{ $band($report->summary->overall) }}">{{ $report->summary->overall->bandLabel }}</span>
        </div>
        <div class="tile"><div class="label">{{ __('report.headline.visited') }}</div><div class="value" data-figure="visited">{{ $count($report->summary->facilitiesVisited) }}</div></div>
        <div class="tile"><div class="label">{{ __('report.headline.lgas') }}</div><div class="value" data-figure="lgas">{{ $count($report->summary->lgasCovered) }}</div></div>
        <div class="tile"><div class="label">{{ __('report.headline.strong') }}</div><div class="value" data-figure="strong">{{ $count($report->summary->facilitiesStrong) }}</div></div>
        <div class="tile"><div class="label">{{ __('report.headline.weak') }}</div><div class="value" data-figure="weak">{{ $count($report->summary->facilitiesWeak) }}</div></div>
    </div>
    <p class="muted small">{{ __('report.headline.overall_note') }}</p>

    <h3>{{ __('report.headline.dimensions') }}</h3>
    <div class="two keep">
        <figure>
            {!! $charts['dimensions'] !!}
            @if ($report->summary->strongAt !== null)
                <ul class="legend"><li><span class="swatch target"></span>{{ $strongLabel }} ({{ rtrim(rtrim(number_format($report->summary->strongAt, 1), '0'), '.') }})</li></ul>
            @endif
        </figure>
        <table data-table="dimensions">
            <thead><tr><th scope="col">{{ $c('measure') }}</th><th scope="col" class="num">{{ $c('score') }}</th><th scope="col">{{ $c('band') }}</th></tr></thead>
            <tbody>
            @foreach (['availability', 'consistency', 'validity', 'overall'] as $key)
                <tr><th scope="row">{{ $c($key) }}</th><td class="num" data-figure="dimension-{{ $key }}">{{ $score($report->dimensions->{$key}) }}</td><td><span class="{{ $band($report->dimensions->{$key}) }}">{{ $report->dimensions->{$key}->bandLabel }}</span></td></tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @if ($report->dimensions->weakest !== null)
        <p>{{ __('report.headline.weakest_dimension', ['dimension' => $report->dimensions->weakest->label()]) }}</p>
    @endif
</section>

{{-- 2. Public vs private --}}
<section aria-labelledby="s-owners" class="keep">
    <h2 id="s-owners">{{ __('report.sections.owners') }}</h2>
    <figure>
        <ul class="legend"><li><span class="swatch pub"></span>{{ $c('public') }}</li><li><span class="swatch priv"></span>{{ $c('private') }}</li></ul>
        {!! $charts['owners'] !!}
    </figure>
    <table data-table="owners">
        <thead><tr><th scope="col">{{ $c('group') }}</th><th scope="col" class="num">{{ $c('facilities') }}</th>
            @foreach (['availability', 'consistency', 'validity', 'overall'] as $key)<th scope="col" class="num">{{ $c($key) }}</th>@endforeach
        </tr></thead>
        <tbody>
        @foreach (['public' => $report->owners->public, 'private' => $report->owners->private] as $key => $group)
            <tr>
                <th scope="row">{{ $c($key) }}</th>
                <td class="num" data-figure="{{ $key }}-facilities">{{ $count($group->facilities) }}</td>
                @foreach (['availability', 'consistency', 'validity', 'overall'] as $dim)
                    <td class="num" data-figure="{{ $key }}-{{ $dim }}">{{ $score($group->{$dim}) }} <span class="{{ $band($group->{$dim}) }}">{{ $group->{$dim}->bandLabel }}</span></td>
                @endforeach
            </tr>
        @endforeach
        </tbody>
    </table>
</section>

{{-- 3. Quality by quarter --}}
<section aria-labelledby="s-trend" class="keep">
    <h2 id="s-trend">{{ __('report.sections.trend') }}</h2>
    @if ($report->trendTakeaway !== null)<p>{{ $report->trendTakeaway }}</p>@endif
    <figure>
        <ul class="legend"><li><span class="swatch series-overall"></span>{{ $c('overall') }}</li><li><span class="swatch pub"></span>{{ $c('public') }}</li><li><span class="swatch priv"></span>{{ $c('private') }}</li></ul>
        {!! $charts['trend'] !!}
        <figcaption>{{ __('report.trend.note') }}</figcaption>
    </figure>
    <table data-table="trend">
        <thead><tr><th scope="col">{{ $c('round') }}</th><th scope="col" class="num">{{ $c('overall') }}</th><th scope="col" class="num">{{ $c('public') }}</th><th scope="col" class="num">{{ $c('private') }}</th><th scope="col">{{ $c('band') }}</th></tr></thead>
        <tbody>
        @foreach ($report->trend as $point)
            <tr>
                <th scope="row">{{ $point->label }}</th>
                <td class="num" data-figure="trend-{{ $point->roundId }}-overall">{{ $score($point->overall) }}</td>
                <td class="num">{{ $score($point->public) }}</td>
                <td class="num">{{ $score($point->private) }}</td>
                <td><span class="{{ $band($point->overall) }}">{{ $point->overall->bandLabel }}</span></td>
            </tr>
        @endforeach
        </tbody>
    </table>
</section>

{{-- 4. Month-slot charts --}}
<section aria-labelledby="s-months" class="keep">
    <h2 id="s-months">{{ __('report.sections.months') }}</h2>
    <figure>
        <ul class="legend">
            @foreach (['availability', 'consistency', 'validity', 'overall'] as $key)<li><span class="swatch series-{{ $key }}"></span>{{ $c($key) }}</li>@endforeach
        </ul>
        {!! $charts['months'] !!}
        <figcaption>{{ __('report.months.note') }}</figcaption>
    </figure>
    <table data-table="months">
        <thead><tr><th scope="col">{{ $c('month') }}</th>@foreach (['availability', 'consistency', 'validity', 'overall'] as $key)<th scope="col" class="num">{{ $c($key) }}</th>@endforeach</tr></thead>
        <tbody>
        @foreach ($report->months as $point)
            <tr>
                <th scope="row">{{ $point->label }}</th>
                @foreach (['availability', 'consistency', 'validity', 'overall'] as $key)
                    <td class="num" data-figure="month-{{ $point->slot }}-{{ $key }}">{{ $score($point->{$key}) }}</td>
                @endforeach
            </tr>
        @endforeach
        </tbody>
    </table>
</section>

{{-- 5. LGA table with band labels --}}
<section aria-labelledby="s-lgas" class="break">
    <h2 id="s-lgas">{{ __('report.sections.lgas') }}</h2>
    <p class="muted small">{{ __('report.lgas.note') }}</p>
    <table data-table="lgas">
        <thead><tr>
            <th scope="col">{{ $c('lga') }}</th><th scope="col" class="num">{{ $c('facilities') }}</th>
            <th scope="col" class="num">{{ $c('availability') }}</th><th scope="col" class="num">{{ $c('consistency') }}</th><th scope="col" class="num">{{ $c('validity') }}</th>
            <th scope="col" class="num">{{ $c('m1') }}</th><th scope="col" class="num">{{ $c('m2') }}</th><th scope="col" class="num">{{ $c('m3') }}</th>
            <th scope="col" class="num">{{ $c('overall') }}</th><th scope="col">{{ $c('band') }}</th>
        </tr></thead>
        <tbody>
        @foreach ($report->lgas as $lga)
            <tr data-lga="{{ $lga->code }}">
                <th scope="row">{{ $lga->name }}</th>
                <td class="num">{{ $count($lga->facilities) }}</td>
                @foreach (['availability', 'consistency', 'validity', 'm1', 'm2', 'm3'] as $key)
                    <td class="num" data-figure="lga-{{ $lga->code }}-{{ $key }}">{{ $score($lga->{$key}) }}</td>
                @endforeach
                <td class="num" data-figure="lga-{{ $lga->code }}-overall">{{ $score($lga->overall) }}</td>
                <td><span class="{{ $band($lga->overall) }}">{{ $lga->overall->bandLabel }}</span></td>
            </tr>
        @endforeach
        </tbody>
    </table>
</section>

{{-- 6. Weakest LGAs --}}
<section aria-labelledby="s-weakest" class="keep">
    <h2 id="s-weakest">{{ __('report.sections.weakest') }}</h2>
    @if ($report->weakest === [])
        <p class="muted">{{ __('report.weakest.none') }}</p>
    @else
        <div class="two">
            <figure>{!! $charts['weakest'] !!}</figure>
            <table data-table="weakest">
                <thead><tr><th scope="col">{{ $c('lga') }}</th><th scope="col" class="num">{{ $c('overall') }}</th><th scope="col">{{ $c('band') }}</th></tr></thead>
                <tbody>
                @foreach ($report->weakest as $lga)
                    <tr><th scope="row">{{ $lga->name }}</th><td class="num" data-figure="weakest-{{ $lga->code }}">{{ $score($lga->overall) }}</td><td><span class="{{ $band($lga->overall) }}">{{ $lga->overall->bandLabel }}</span></td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

{{-- 7. Implementation plan summary: owner roles and statuses, never contacts or notes --}}
<section aria-labelledby="s-plan" class="break">
    <h2 id="s-plan">{{ __('report.sections.plan') }}</h2>
    <p>{{ $report->plan->threshold === null ? __('report.plan.intro_none') : __('report.plan.intro', ['threshold' => $report->plan->threshold['value'], 'band' => $report->plan->threshold['label']]) }}</p>
    @if ($report->plan->actions === [])
        <p class="muted">{{ __('report.plan.none') }}</p>
    @else
        <table data-table="plan-summary">
            <caption>{{ __('report.plan.summary') }}</caption>
            <thead><tr><th scope="col">{{ $c('type') }}</th><th scope="col">{{ $c('owner') }}</th><th scope="col" class="num">{{ $c('actions') }}</th>
                @foreach (\App\Domain\Plan\Enums\PlanActionStatus::cases() as $status)<th scope="col" class="num">{{ $status->label() }}</th>@endforeach
            </tr></thead>
            <tbody>
            @foreach ($report->plan->summary as $type)
                <tr>
                    <th scope="row">{{ $type->dimension->label() }}</th>
                    <td>{{ $type->owner }}</td>
                    <td class="num" data-figure="plan-{{ $type->dimension->value }}">{{ $count($type->count) }}</td>
                    @foreach (\App\Domain\Plan\Enums\PlanActionStatus::cases() as $status)
                        <td class="num">{{ $count($type->statuses[$status->value] ?? 0) }}</td>
                    @endforeach
                </tr>
            @endforeach
            </tbody>
        </table>
        <table data-table="plan-actions">
            <caption>{{ __('report.plan.actions') }}</caption>
            <thead><tr><th scope="col">{{ $c('lga') }}</th><th scope="col" class="num">{{ $c('overall') }}</th><th scope="col">{{ $c('weakest') }}</th><th scope="col">{{ $c('action') }}</th><th scope="col">{{ $c('owner') }}</th><th scope="col">{{ $c('status') }}</th><th scope="col">{{ $c('review') }}</th></tr></thead>
            <tbody>
            @foreach ($report->plan->actions as $action)
                <tr>
                    <th scope="row">{{ $action->name }}</th>
                    <td class="num">{{ $score($action->overall) }}</td>
                    <td>{{ $action->dimension->label() }} ({{ $score($action->score) }})</td>
                    <td>{{ $action->action }}</td>
                    <td>{{ $action->owner }}</td>
                    <td>{{ $action->status->label() }}</td>
                    <td>{{ $action->reviewRound ?? $action->reviewDate ?? __('report.no_data') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
    @if ($report->plan->clear !== [])
        <p class="muted small">{{ __('report.plan.clear', ['lgas' => implode(', ', array_map(fn ($l) => $l->name, $report->plan->clear))]) }}</p>
    @endif
</section>

{{-- 8. Data quality: counts only --}}
<section aria-labelledby="s-quality" class="keep">
    <h2 id="s-quality">{{ __('report.sections.quality') }}</h2>
    <p>{{ __('report.quality.intro') }}</p>
    <div class="tiles" style="grid-template-columns: 1fr 1fr">
        <div class="tile"><div class="label">{{ __('report.quality.accepted') }}</div><div class="value" data-figure="accepted">{{ $count($report->quality->accepted) }}</div></div>
        <div class="tile"><div class="label">{{ __('report.quality.quarantined') }}</div><div class="value" data-figure="quarantined">{{ $count($report->quality->quarantined) }}</div></div>
    </div>
    <div class="two">
        <div>
            @if ($report->quality->rules === [])
                <p class="muted">{{ __('report.quality.none') }}</p>
            @else
                <table data-table="quality-rules">
                    <caption>{{ __('report.quality.by_rule') }}</caption>
                    <thead><tr><th scope="col">{{ $c('rule') }}</th><th scope="col" class="num">{{ $c('count') }}</th></tr></thead>
                    <tbody>
                    @foreach ($report->quality->rules as $rule)
                        <tr><th scope="row">{{ $rule['name'] }}</th><td class="num" data-figure="rule-{{ $rule['code'] }}">{{ $count($rule['count']) }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
                <p class="muted small">{{ __('report.quality.by_rule_note') }}</p>
            @endif
        </div>
        <table data-table="quality-sources">
            <caption>{{ __('report.quality.by_source') }}</caption>
            <thead><tr><th scope="col">{{ $c('source') }}</th><th scope="col" class="num">{{ $c('accepted') }}</th><th scope="col" class="num">{{ $c('quarantined') }}</th></tr></thead>
            <tbody>
            @foreach ($report->quality->sources as $source)
                <tr><th scope="row">{{ $source['name'] }}</th><td class="num">{{ $count($source['accepted']) }}</td><td class="num">{{ $count($source['quarantined']) }}</td></tr>
            @endforeach
            </tbody>
        </table>
    </div>
</section>

{{-- 9. Method --}}
<section aria-labelledby="s-method" class="keep">
    <h2 id="s-method">{{ __('report.sections.method') }}</h2>
    <dl class="method">
        @foreach ($report->method as $line)
            <dt>{{ $line['label'] }}</dt><dd>{{ $line['value'] }}</dd>
        @endforeach
    </dl>
</section>
</main>
</body>
</html>
