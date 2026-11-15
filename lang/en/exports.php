<?php

declare(strict_types=1);

/*
 * Queued exports (Phase 6 step 0, D-96): the file's own words (headings, the "About this export"
 * sheet) and the refusals. The ExportButton's words are in admin.php under exports.
 */

return [

    'types' => [
        'assessments' => [
            'title' => 'Assessments',
            'columns' => [
                'round' => 'Round',
                'lga_code' => 'LGA code',
                'lga' => 'LGA',
                'ward' => 'Ward',
                'facility_code' => 'Facility code',
                'facility' => 'Facility',
                'level' => 'Level',
                'ownership' => 'Ownership',
                'source' => 'Source',
                'visited_on' => 'Visit date',
                'status' => 'Status',
                'availability' => 'Availability',
                'consistency' => 'Consistency',
                'validity' => 'Validity',
                'overall' => 'Overall',
                'band' => 'Band',
                'assessor' => 'Assessor',
                'device' => 'Device ID',
                'latitude' => 'Visit latitude',
                'longitude' => 'Visit longitude',
                'instance_id' => 'Instance ID',
                'source_link' => 'Link at source',
                'attachments' => 'Attachment links',
            ],
            'statuses' => [
                'accepted' => 'Accepted',
                'flagged' => 'Flagged',
            ],
            'unknown_source' => 'Unknown source',
        ],
    ],

    'sheets' => [
        'data' => 'Data',
        'about' => 'About this export',
    ],

    'about' => [
        'type' => 'Export',
        'filter' => 'Filters',
        'requested_by' => 'Requested by',
        'requested_at' => 'Requested at',
        'rows' => 'Rows',
        'private' => 'Assessor and source details',
        'private_yes' => 'Included: names, device IDs, visit GPS, source links and attachment links. Handle as personal data.',
        'private_no' => 'Not included',
        'scores' => 'Scores',
        'scores_note' => 'Each round is scored under its own scoring rule version, and rounded as on the dashboard. The open round can still change until it is closed.',
    ],

    'filter' => [
        'year' => 'Year :year',
        'lga' => 'LGA :lga',
        'latest' => 'Latest round',
    ],

    'errors' => [
        'unknown_type' => 'There is no export called :type.',
        'private_not_offered' => 'This export has no assessor or source details to include.',
        'not_ready' => 'This export is not ready to download yet.',
        'expired' => 'This export has expired: its file is deleted 7 days after it was made. Export it again.',
        'failed' => 'The export could not be prepared. Try again; if it fails again, tell the technical team.',
    ],
];
