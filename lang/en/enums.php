<?php

declare(strict_types=1);

// Labels for every backed enum's label(). Keys are the stored enum values.
return [
    'dimension' => [
        'availability' => 'Availability',
        'consistency' => 'Consistency',
        'validity' => 'Validity',
    ],
    'band' => [
        'strong' => 'Strong',
        'acceptable' => 'Acceptable',
        'review' => 'Review',
        'needs_action' => 'Needs action',
    ],
    'severity' => [
        'hard' => 'Hard',
        'soft' => 'Soft',
    ],
    'export_status' => [
        'queued' => 'Waiting to start',
        'running' => 'Preparing',
        'done' => 'Ready',
        'failed' => 'Failed',
        'expired' => 'Expired',
    ],
    'export_format' => [
        'csv' => 'CSV',
        'xlsx' => 'Excel',
    ],
    'round_status' => [
        'open' => 'Open',
        'closed' => 'Closed',
    ],
    'submission_status' => [
        'received' => 'Received',
        'parsed' => 'Parsed',
        'validated' => 'Validated',
        'accepted' => 'Accepted',
        'flagged' => 'Flagged',
        'quarantined' => 'Quarantined',
        'rejected' => 'Rejected',
        'superseded' => 'Superseded',
        'out_of_scope' => 'Not a facility assessment',
    ],
    'quarantine_status' => [
        'open' => 'Open',
        'resolved' => 'Resolved',
    ],
    'quarantine_resolution' => [
        'reprocessed' => 'Corrected and reprocessed',
        'rejected' => 'Rejected',
        'superseded' => 'Fixed at source',
    ],
    'ownership' => [
        'public' => 'Public',
        'private' => 'Private',
    ],
    'facility_level' => [
        'primary' => 'Primary',
        'secondary' => 'Secondary',
        'tertiary' => 'Tertiary',
    ],
    'pull_trigger' => [
        'scheduled' => 'Scheduled',
        'manual' => 'Pull now',
        'webhook' => 'Webhook',
        'backfill' => 'Backfill',
        'reconcile' => 'Reconcile',
    ],
    'plan_action_status' => [
        'not_started' => 'Not started',
        'in_progress' => 'In progress',
        'done' => 'Done',
    ],
];
