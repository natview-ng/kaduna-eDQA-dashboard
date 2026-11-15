-- `make e2e-round` only (LOCAL), on edqa_e2e after the demo seed: the starting point of the
-- full-round journey (tests/e2e/admin-full-round.spec.ts). The demo seed leaves Q2 2026 open; here
-- it is closed as it would have been in July, pinned to the published rule version, and every
-- demo score dated then too, so the public footer's "Figures last updated" reads 21 July 2026
-- until the journey closes Q3 2026. Q3 2026 itself does not exist: the journey creates it.
UPDATE rounds
   SET status = 'closed',
       closed_at = '2026-07-21 17:00:00+01',
       rule_version_id = (SELECT id FROM scoring_rule_versions WHERE published_at IS NOT NULL ORDER BY version DESC LIMIT 1),
       updated_at = now()
 WHERE year = 2026 AND quarter = 2;

UPDATE assessment_scores SET computed_at = '2026-07-21 17:00:00+01';

SELECT refresh_round_aggregates();
