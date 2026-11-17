<?php

declare(strict_types=1);

/*
 * The PDF round report (Phase 6 step 5, D-101): resources/views/reports/round.blade.php, its
 * charts, running header and footer, and edqa:pdf-smoke.
 */

return [

    'title' => 'Kaduna State eDQA round report, :round',
    'file' => 'round-report-:year-Q:quarter',

    'cover' => [
        'state' => 'Kaduna State eDQA',
        'subtitle' => 'Data Quality Assessment round report',
        'round' => 'Round',
        'generated' => 'Generated',
        'status' => 'Status',
        'published' => 'Published (round closed)',
        'draft' => 'Draft: round not closed. These figures are provisional and not published.',
        'support' => 'Support: HSDF',
    ],

    'draft' => 'DRAFT — round not closed',
    'header' => 'Kaduna State eDQA · :round round report',
    'page' => 'Page :page of :pages',

    'contents' => 'Contents',
    'sections' => [
        'headline' => 'State headline',
        'owners' => 'Public and private facilities',
        'trend' => 'Quality by quarter',
        'months' => 'Month by month',
        'lgas' => 'Local government areas',
        'weakest' => 'Weakest local governments',
        'plan' => 'Implementation plan',
        'quality' => 'Data quality',
        'method' => 'Method',
    ],

    'headline' => [
        'overall' => 'Average quality score',
        'overall_note' => 'Unweighted mean of facility overall scores, out of 100.',
        'visited' => 'Facilities visited',
        'lgas' => 'LGAs covered',
        'strong' => 'Facilities Strong',
        'weak' => 'Facilities under Review threshold',
        'dimensions' => 'The three dimensions',
        'weakest_dimension' => 'Weakest dimension this round: :dimension.',
        'chart' => 'Score by dimension, :round',
    ],

    'columns' => [
        'measure' => 'Measure',
        'score' => 'Score',
        'band' => 'Band',
        'group' => 'Group',
        'facilities' => 'Facilities',
        'round' => 'Round',
        'month' => 'Month',
        'lga' => 'LGA',
        'overall' => 'Overall',
        'availability' => 'Availability',
        'consistency' => 'Consistency',
        'validity' => 'Validity',
        'public' => 'Public',
        'private' => 'Private',
        'm1' => 'Month 1',
        'm2' => 'Month 2',
        'm3' => 'Month 3',
        'weakest' => 'Weakest dimension',
        'action' => 'Recommended action',
        'owner' => 'Owner role',
        'status' => 'Status',
        'review' => 'Review',
        'type' => 'Type',
        'actions' => 'Actions',
        'rule' => 'Rule',
        'count' => 'Submissions',
        'source' => 'Source',
        'accepted' => 'In the figures',
        'quarantined' => 'Quarantined',
    ],

    'owners' => [
        'chart' => 'Public and private facilities by dimension, :round',
        'none' => 'No facilities of this kind were assessed.',
    ],

    'trend' => [
        'chart' => 'Overall quality score by quarter, :year',
        'note' => 'Each round is read under its own scoring rule version.',
    ],

    'months' => [
        'chart' => 'Dimensions by month, :round',
        'note' => 'Month 1 is the first month of the quarter. Overall is the mean of the three dimensions that month.',
    ],

    'lgas' => [
        'note' => 'All 23 LGAs. An LGA with no visits shows —.',
    ],

    'weakest' => [
        'chart' => 'Lowest overall scores by LGA, :round',
        'none' => 'No LGA has a score for this round.',
    ],

    'plan' => [
        'intro' => 'An action for each LGA whose overall score is under :threshold (:band), for its weakest dimension.',
        'intro_none' => 'No threshold is set for this round.',
        'summary' => 'Actions by type and status',
        'actions' => 'Actions by LGA',
        'none' => 'No LGA needs an action this round.',
        'clear' => 'No action needed: :lgas.',
    ],

    'quality' => [
        'intro' => 'Every submission is checked before it can count. A submission that fails a check is quarantined and kept out of every figure until it is corrected or rejected. Counts only: no names or IDs.',
        'accepted' => 'Assessments in the figures',
        'quarantined' => 'Submissions quarantined (open or rejected)',
        'by_rule' => 'Quarantined by rule',
        'by_rule_note' => 'A submission that fails two rules is counted under both.',
        'by_source' => 'By source',
        'none' => 'No submission of this round is quarantined.',
    ],

    'method' => [
        'formula' => 'Formula',
        'formula_text' => 'Month score = items passed ÷ items applicable × 100 (N/A excluded from both). Dimension score = mean of its month scores. Overall = mean of Availability, Consistency and Validity.',
        'bands' => 'Bands',
        'bands_text' => 'Strong: :strong and above · Acceptable: :acceptable to under :strong · Review: :review to under :acceptable · Needs action: under :review. Banded on the displayed score.',
        'rule_version' => 'Rule version',
        'rounding' => 'Rounding mode',
        'rounding_modes' => [
            'half_up' => 'Half up to one decimal place, for display only; scores are stored and combined at full precision',
            'truncate' => 'Truncated to one decimal place, for display only; scores are stored and combined at full precision',
        ],
        'aggregation' => 'Aggregation',
        'sources' => 'Data sources',
        'published' => 'Publication',
        'published_text' => 'Closed rounds only are published.',
    ],

    'no_data' => '—',

    'smoke' => [
        'heading' => 'eDQA PDF smoke test',
        'body' => 'Chromium rendered this page from HTML on the worker.',
        'ok' => 'PDF smoke test passed: :pages page(s), :bytes bytes, text read back.',
        'no_fonts' => 'IBM Plex Sans was not found: the PDF used a fallback font.',
        'failed' => 'PDF smoke test failed: :reason',
    ],

];
