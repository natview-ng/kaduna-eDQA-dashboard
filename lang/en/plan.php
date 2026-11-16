<?php

declare(strict_types=1);

/*
 * The implementation plan's standard responses (ARCHITECTURE.md §9.2.6), by dimension, and the
 * default owners (config edqa.plan.default_owners names the key). ImplementationPlanAggregator
 * reads these; the page shows them as they come.
 */

return [

    'actions' => [
        'availability' => 'Restock HMIS tools and confirm the current register set is in place',
        'consistency' => 'On-site mentoring on reconciling the monthly summary against the register',
        'validity' => 'Retraining on data element definitions and correct tool completion',
    ],

    // What a low score in the dimension means: the "What each action means" panel.
    'meanings' => [
        'availability' => 'The register or tool was not there to check.',
        'consistency' => 'The summary form does not agree with the register.',
        'validity' => 'Entries do not meet the data element definition.',
    ],

    'owners' => [
        'lga_me_officer' => 'LGA M&E officer',
        'state_me_team' => 'State M&E team',
        'programme_manager' => 'Programme manager',
        'state_hmis_team' => 'State HMIS team',
        'lga_phc_coordinator' => 'LGA PHC coordinator',
    ],

    // The admin plan page's server messages (Phase 6 step 3).
    'admin' => [
        'errors' => [
            'past_date' => 'Choose a review date on or after today.',
        ],
    ],

];
