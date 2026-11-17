<?php

declare(strict_types=1);

use App\Domain\Export\Report\SvgChart;

// D-101: The round report's server-drawn charts. Scores on a fixed 0–100 axis,
// colour by class (never hex), the displayed value as text, no data as a gap, every label escaped.

function chartDom(string $svg): DOMXPath
{
    $dom = new DOMDocument;
    expect($dom->loadXML($svg))->toBeTrue('the chart is well-formed XML');

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('s', 'http://www.w3.org/2000/svg');

    return $xpath;
}

it('draws one bar per row, proportional to the score, with its displayed value, and the Strong line', function (): void {
    $svg = SvgChart::bars('Score by dimension', [
        ['label' => 'Availability', 'value' => 100.0, 'display' => '100.0', 'class' => 'series-availability'],
        ['label' => 'Validity', 'value' => 50.0, 'display' => '50.0', 'class' => 'series-validity'],
        ['label' => 'Consistency', 'value' => null, 'display' => '—', 'class' => 'series-consistency'],
    ], target: 90.0, width: 640);
    $x = chartDom($svg);

    expect($x->query('/s:svg')->item(0)?->getAttribute('role'))->toBe('img')
        ->and($x->query('/s:svg/s:title')->item(0)?->textContent)->toBe('Score by dimension');
    $bars = $x->query('//s:rect');
    expect($bars->length)->toBe(2);
    $full = (float) $bars->item(0)?->getAttribute('width');
    $half = (float) $bars->item(1)?->getAttribute('width');
    expect(abs($half * 2 - $full))->toBeLessThan(0.05)
        ->and($bars->item(0)?->getAttribute('class'))->toBe('series-availability')
        ->and($svg)->toContain('>100.0<')->toContain('>50.0<')->toContain('>—<')
        ->and($x->query("//s:line[@class='target']")->length)->toBe(1)
        ->and($svg)->not->toMatch('/#[0-9a-fA-F]{3,6}\b/');
});

it('draws grouped bars, one per series in each group, a dash where a series has no score', function (): void {
    $svg = SvgChart::groupedBars('By month', ['April', 'May', 'June'], [
        ['name' => 'Public', 'class' => 'pub', 'values' => [80.0, 90.0, null], 'displays' => ['80.0', '90.0', '—']],
        ['name' => 'Private', 'class' => 'priv', 'values' => [70.0, 0.0, 60.0], 'displays' => ['70.0', '0.0', '60.0']],
    ]);
    $x = chartDom($svg);

    expect($x->query("//s:rect[@class='pub']")->length)->toBe(2)
        ->and($x->query("//s:rect[@class='priv']")->length)->toBe(3)
        ->and($svg)->toContain('>April<')->toContain('>June<')->toContain('>0.0<')->toContain('>—<');
    // A taller bar for a higher score: April's public (80) above its private (70).
    $pub = (float) $x->query("//s:rect[@class='pub']")->item(0)?->getAttribute('height');
    $priv = (float) $x->query("//s:rect[@class='priv']")->item(0)?->getAttribute('height');
    expect($pub / $priv)->toEqualWithDelta(80 / 70, 0.01);
});

it('draws a line per series that breaks at a round with no data, never inventing a value', function (): void {
    $svg = SvgChart::line('By quarter', ['Q1 2026', 'Q2 2026', 'Q3 2026', 'Q4 2026'], [
        ['name' => 'Overall', 'class' => 'series-overall', 'values' => [70.0, 75.0, null, 80.0], 'displays' => ['70.0', '75.0', '—', '80.0']],
    ]);
    $x = chartDom($svg);

    // Q1–Q2 joined; Q4 stands alone (a dot, no line to it).
    expect($x->query('//s:polyline')->length)->toBe(1)
        ->and($x->query('//s:circle')->length)->toBe(3)
        ->and($svg)->not->toContain('>—<');
});

it('escapes every label and title', function (): void {
    $svg = SvgChart::bars('<b>LGA & "ward"</b>', [['label' => "Jema'a <x>", 'value' => 10.0, 'display' => '10.0', 'class' => 'band-review']]);

    chartDom($svg);
    expect($svg)->toContain('&lt;b&gt;LGA &amp; &quot;ward&quot;&lt;/b&gt;')->toContain('Jema&apos;a &lt;x&gt;')->not->toContain('<x>');
});
