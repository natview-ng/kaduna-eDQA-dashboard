<?php

declare(strict_types=1);

/*
 * The facility master list (Phase 5 step 5). errors.* are the refusals the API returns as
 * 409 { code, message }; import.* is what edqa:facilities:import-master-list prints.
 */

return [

    'errors' => [
        'code_locked' => 'The code cannot change: :count assessment(s) are recorded under it.',
        'code_taken' => 'Another facility already has the code :code.',
        'already_inactive' => 'This facility is already deactivated.',
        'not_inactive' => 'This facility is active.',
        'ward_exists' => ':lga already has a ward called :ward.',
        'reason_too_short' => 'Give a reason of at least 10 characters.',
    ],

    'import' => [
        'no_file' => 'No such file: :file',
        'not_xlsx' => ':file is not an .xlsx workbook that can be read.',
        'no_sheet' => 'The workbook has no :sheet sheet. Use the reconciliation workbook (facility_reconcile.py), signed off.',
        'no_column' => 'The :sheet sheet has no :column column.',
        'refused' => 'Nothing was imported: fix these rows in the workbook and run it again.',
        'line' => ':sheet line :line: :problem',
        'unknown_lga' => 'the LGA ":lga" is not one of the 23',
        'no_decision' => 'no Decision yet (MAP, ADD or REJECT)',
        'bad_decision' => 'the Decision ":decision" is not MAP, ADD or REJECT',
        'no_code' => 'no facility code',
        'not_a_candidate' => 'MAP to :code, which is not one of the row\'s candidates',
        'no_name' => 'no facility name',
        'no_ward' => 'no ward',
        'bad_level' => 'the level ":level" is not primary, secondary or tertiary',
        'bad_ownership' => 'the ownership ":ownership" is not public or private',
        'conflict' => 'the code :code is also on :other with different details',
        'synthetic_needs_force' => 'The synthetic facility list is still loaded and this is :env: run again with --force to replace it.',
        'diff_title' => 'Changes from :file:',
        'add' => '+ :code  :name (:lga / :ward)',
        'change' => '~ :code  :changes',
        'deactivate' => '- :code  :name (synthetic, deactivated: never deleted)',
        'new_ward' => '+ ward :ward in :lga',
        'unchanged' => ':count facility(ies) unchanged.',
        'skipped' => ':count row(s) marked REJECT, skipped.',
        'nothing' => 'Nothing to change: the master list already matches :file.',
        'dry_run' => '--dry-run: nothing was written.',
        'done' => 'Imported: :added added, :changed changed, :deactivated deactivated, :wards ward(s) created. Every change is in the audit log with the reason ":reason".',
        'reason' => 'master list import :file',
    ],

];
