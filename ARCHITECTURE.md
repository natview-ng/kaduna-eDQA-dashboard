# ARCHITECTURE.md — Kaduna eDQA Portal

How the system is put together: layers, data flow, domain, schema, ingestion, validation,
scoring, API and page structure. Read the section for the area you are changing before you
change it. Coding style lives in `CONVENTION.md`; security in `SECURITY.md`; accessibility in
`ACCESSIBILITY.md`; servers and pipelines in `DEPLOY.md` (separate DevOps project).

## Contents
1. System overview · 2. Application layers · 3. Domain model · 4. Database · 5. Ingestion ·
6. Validation · 7. Scoring · 8. API · 9. Presentation layer · 10. Non-functional targets ·
11. Decisions, prototype deltas, open questions

---

## 1. System overview

```mermaid
flowchart LR
  subgraph Field
    C[ODK Collect<br/>offline] --> OC[ODK Central]
    K[KoboCollect / Enketo<br/>offline] --> KB[KoboToolbox]
  end
  subgraph Portal[Laravel + PostgreSQL]
    P[Pull job per source<br/>10 min / Pull now / webhook / reconcile] --> S[(submissions<br/>verbatim jsonb + source)]
    S --> NZ[Source normaliser] --> PR[Parser + field map]
    PR --> V{Validation pipeline}
    V -->|hard fail| Q[(quarantined_records)]
    V -->|pass / soft flag| A[(assessments<br/>item_responses)]
    A --> SC[ScoreCalculator] --> AS[(assessment_scores)]
    AS --> MV[(round_aggregates<br/>materialised view)]
    MV --> AG[Aggregators + PublicScope] --> UI[Public dashboard<br/>closed rounds, no login]
    AG --> ADMUI[Admin area /admin<br/>password login]
    Q --> ADM[Data issues admin] --> PR
  end
  OC --> P
  KB --> P
```

**Governing principle:** a score that cannot be true never reaches a chart. Aggregators read only
`assessments`, `assessment_scores` and `round_aggregates` — never `submissions` or
`quarantined_records`. This separation is structural (separate tables + an architecture test),
not a query filter.

**Goals this architecture serves (from the PRD):**

| # | Goal | Where it is enforced |
|---|---|---|
| G1 | No invalid score ever charts | Validation pipeline (§6), CHECK constraints (§4), table separation |
| G2 | Submission on dashboard ≤ 15 min, no human step | Scheduled pull (§5), debounced aggregate refresh |
| G3 | Scores reproducible | Pure `ScoreCalculator`, verbatim payloads, rule versions (§7) |
| G4 | Exactly 23 LGAs | Seeded `lgas`, `LGA_UNKNOWN` rule, no insert path |
| G5 | Every page renders | Pages read local materialised data; no live external query at view time |
| G6 | Follow-up actionable | `plan_actions` (§9.2.6) |
| G7 | Corrections traceable | Activity log on every write (SECURITY.md §4) |
| G8 | Works on 3G | Server-rendered numbers, deferred charts, JS budget (§10) |

**Deployment:** containerised (Docker images, Compose on one host) — see §2 and `DEPLOY.md`.

**Data sources:** ODK Central and KoboToolbox, both pulled through one shared pipeline (§5).

**Out of scope for v1:** any source besides ODK Central and KoboToolbox, roles/permissions, writing back to ODK, Kobo or
DHIS2, sampling logic, mobile app, multi-state tenancy, DHIS2 comparison.

**Audiences:** a **public, read-only dashboard** (no login, closed rounds only) and an **admin
area** under `/admin`, reached by typing `/login` (D-76–D-83, SECURITY.md §3).

---

## 2. Application layers

```
app/
  Domain/                      ← all business logic
    Assessment/  Models, Actions, DTOs, Events
    Round/       Models, OpenRound, CloseRound, ReopenRound, MonthSlot
    Facility/    Models (Lga, Ward, Facility), Actions, CascadeExporter
    Ingestion/   Contracts/{SubmissionSource,PayloadNormalizer}, SourceRegistry,
                 SubmissionEnvelope, FieldMap, SubmissionParser, ProcessSubmissionsPage,
                 PullSubmissionsJob, BackfillSubmissionsJob, ReconcileSourceJob,
                 FetchAttachmentsJob, PullLock, ReprocessSubmission
      Odk/       OdkCentralSource, OdkCentralClient, SessionTokenCache, OdkPayloadNormalizer
      Kobo/      KoboSource, KoboClient, KoboPayloadNormalizer
    Validation/  Contracts/ValidationRule, Rules/*, ValidationPipeline, ValidationContext,
                 QuarantineAction, ResolveQuarantineAction
    Scoring/     ScoreCalculator, ScoreCard, RuleConfig, Bands, RuleVersion, RescoreRoundJob
    Reporting/   Aggregators/*, ReportFilter, Exporters/*
    Plan/        PlanAction model, Actions
  Http/
    Controllers/ thin — one per page; validate → Action/Aggregator → Resource
    Requests/    FormRequests (validation only)
    Resources/   Inertia/JSON shapes (no arithmetic)
    Middleware/  EnsureTwoFactorConfirmed, SecurityHeaders, VerifySourceWebhook (per-source strategy)
  Console/Commands/  edqa:source:check, edqa:pull, edqa:reconcile, edqa:backfill, edqa:backfill-report, edqa:rescore,
                     edqa:refresh-aggregates, edqa:admin:create, edqa:admin:disable
config/edqa.php, config/edqa_field_maps.php
database/sql/round_aggregates.sql
resources/js/Pages/{Dashboard,Admin}/*, Components/*, lib/*, types/*
lang/en/*.php
Dockerfile                    # targets: base, vendor, assets, app, worker, web, ci, dev
compose.dev.yml               # local development stack
docker/                       # entrypoint.sh, php/*.ini, fpm-pool.conf, caddy/Caddyfile*, postgres/init-dev.sql
database/sql/post-migrate-grants.sql
Makefile                      # make up / down / sh / test / artisan
```

**Dependency direction:** `Http → Domain`. Inside Domain: `Reporting → Scoring, Assessment,
Round, Facility` (read-only); `Ingestion → Validation → Scoring → Assessment`. `Reporting` must
not depend on `Ingestion` or `Validation` models (architecture test).

**Events and listeners:**

| Event | Listeners |
|---|---|
| `SubmissionIngested` | log counters on `pull_runs` |
| `SubmissionQuarantined` | bump quarantine badge cache |
| `AssessmentScored` | dispatch debounced `RefreshRoundAggregatesJob`, flush round cache tag |
| `QuarantineResolved` | dispatch `RescoreRoundJob` for the round |
| `RuleVersionPublished` | dispatch `RescoreRoundJob` per affected round |
| `RoundClosed` | refresh materialised view, snapshot |
| `PullFailed` | admin alert, digest entry (names the source) |
| `SourceDeletionDetected` | flag for administrator review; never auto-delete |

**Queues:** `default` (pull, process), `rescore`, `exports`, `low` (attachments).

**Runtime processes (containers).** The application ships as container images built from one
multi-stage `Dockerfile` (`DEPLOY.md` §6). One image runs every PHP role, chosen by the
entrypoint argument:

| Container | Role | Image | Notes |
|---|---|---|---|
| `web` | Caddy: TLS, static assets, FastCGI to `app` | `edqa-web` | Only container with published ports |
| `app` | PHP-FPM serving Inertia pages and `/api/*` | `edqa-app` | Stateless; can scale to 2+ |
| `worker-default` | `queue:work --queue=default` | `edqa-app` | Pulls (all sources), reconcile, submission processing |
| `worker-rescore` | `queue:work --queue=rescore` | `edqa-app` | Long jobs; generous stop grace period |
| `worker-exports` | `queue:work --queue=exports,low` | `edqa-worker` | Has Chromium for PDFs |
| `scheduler` | `schedule:work` | `edqa-app` | Exactly one instance |
| `migrate` | one-off: migrate + grants | `edqa-app` | Runs as `edqa_migrator` before each rollout |
| `postgres`, `redis` | data | official images | Internal network only |

Consequences for application code are listed in `CONVENTION.md` §12 (stateless containers, logs
to stderr, persistent files only under `storage/app`, config cached at start not build).

---

## 3. Domain model

### Entities and relationships

```mermaid
flowchart LR
  LGA --> Ward --> Facility
  Round --> Assessment
  Facility --> Assessment
  Submission --> Assessment
  Submission --> QuarantinedRecord
  Assessment --> ItemResponse --> AssessmentScore
  ScoringRuleVersion --> AssessmentScore
  Round --> PlanAction
  LGA --> PlanAction
```

| Entity | Key facts |
|---|---|
| **LGA** | Exactly 23, seeded. No runtime insert. |
| **Ward** | Belongs to one LGA. |
| **Facility** | Belongs to one ward (and denormalised `lga_id`). `level` ∈ Primary/Secondary/Tertiary. `ownership` ∈ Public/Private. `is_active` — deactivate, never delete. |
| **Round** | `(year, quarter)` unique. Assessment window `window_start..window_end`. Status `open` or `closed`. |
| **Submission source** | A configured field-collection server + form: `odk` (ODK Central) or `kobo` (KoboToolbox). |
| **Submission** | One raw record from a source, verbatim, with `source_id`, `external_id` (the source's stable logical ID) and `instance_id` (this version; unique across sources). May be `superseded` by an edit at source. |
| **Assessment** | Created only from a submission that passed all hard rules. `(round_id, facility_id)` unique. |
| **ItemResponse** | One scored question: `dimension`, `month_slot`, `item_code`, `value`, `is_applicable`. |
| **AssessmentScore** | One row per `(assessment, dimension, month_slot)`. `score numeric(5,2)` or null when the slot is all-N/A. Records `rule_version_id`. |
| **QuarantinedRecord** | Failed submission, all failed rule codes + details, resolution state. |
| **ScoringRuleVersion** | Versioned JSON config (bands, item weights, N/A policy). `draft` until `published_at`. |
| **PlanAction** | Implementation-plan row: LGA, round, dimension, action text, owner, due date, status. |

### Current LGAs (seed list)

Birnin Gwari, Chikun, Giwa, Igabi, Ikara, Jaba, Jema'a, Kachia, Kaduna North, Kaduna South,
Kagarko, Kajuru, Kaura, Kauru, Kubau, Kudan, Lere, Makarfi, Sabon Gari, Sanga, Soba,
Zangon Kataf, Zaria.  **= 23.** Confirm codes against the national facility registry.

### Month-slot mapping

| Quarter | Slot 1 | Slot 2 | Slot 3 |
|---|---|---|---|
| Q1 | January | February | March |
| Q2 | April | May | June |
| Q3 | July | August | September |
| Q4 | October | November | December |

UI rule: show real month names when exactly one quarter is in view; otherwise "Month 1/2/3".
Implement once in `App\Domain\Round\MonthSlot::label(int $quarter, int $slot)` and mirror in
`resources/js/lib/months.ts`.

### Round lifecycle

```
          create                close (snapshot aggregates)
 (none) ────────▶ open ──────────────────────────▶ closed
                   ▲                                  │
                   └──────── reopen (reason) ─────────┘
```

- **open** — submissions whose visit date falls in the window bind to it.
- **closed** — no new submissions bind; aggregates are snapshotted into `round_aggregates`.
  A submission for a closed round quarantines under `ROUND_WINDOW` with detail "round closed".
- **reopen** — requires typed reason, audited, triggers rescore on next close.
- Windows must not overlap between rounds (validated on create/edit).

> The PRD also mentions a "publish state" (PRD §5) and "published rounds" (PRD §8). v1 treats **closed =
> published**. See §11 Decisions D-07.

### Submission lifecycle

```
received ─▶ parsed ─▶ validated ─┬─▶ accepted  (assessment + scores created)
                                 ├─▶ flagged   (accepted with soft flags)
                                 └─▶ quarantined ─┬─▶ reprocessed → accepted/flagged
                                                  └─▶ rejected (reason)
any ─▶ superseded   (an edit at source arrived: deprecatedID = this instance_id,
                     or same external_id with a new instance_id)
```

When a submission is superseded after it produced an assessment, the replacement is processed
and the assessment is updated in place (audited) — the `(round, facility)` uniqueness holds.

### Assessor identity

Assessors have no portal account. `assessor_name` and `device_id` come from the submission's
metadata on whichever source it came from
(`meta/username` or a form field, `deviceid`). Used for the `ASSESSOR_VOLUME` soft rule and for
display only.

---

## 4. Database — PostgreSQL 16

All timestamps `timestamptz`. All raw payloads `jsonb`. Every migration reversible.

### Tables

| Table | Columns | Constraints / notes |
|---|---|---|
| `users` | id, name, email UQ, password, two_factor_secret, two_factor_recovery_codes, two_factor_confirmed_at, is_active, remember_token, timestamps | Administrators only. No role column |
| `lgas` | id, name UQ, code UQ | Seeded with exactly 23 |
| `wards` | id, lga_id FK, name, code | UQ (lga_id, name) |
| `facilities` | id, code UQ, name, ward_id FK, lga_id FK, level, ownership, is_active, lat numeric(9,6)?, lng numeric(9,6)?, timestamps | `level` / `ownership` CHECK against enum values |
| `rounds` | id, year smallint, quarter smallint, window_start date, window_end date, status, closed_at?, reopened_reason?, timestamps | UQ (year, quarter); CHECK quarter 1–4; CHECK window_end ≥ window_start; status CHECK in ('open','closed') |
| `submission_sources` | id, key UQ, type, name, is_enabled, timestamps | `type` CHECK in ('odk_central','kobo'); seeded from config; credentials never stored here |
| `submissions` | id, source_id FK, external_id, instance_id UQ, deprecated_id?, form_ref, form_version, payload jsonb, submitted_at, source_updated_at?, received_at, processed_at?, status, superseded_by_id?, deleted_at_source? | Payload never mutated; index (source_id, external_id) |
| `assessments` | id, round_id FK, facility_id FK, submission_id FK, assessor_name, device_id?, started_at, ended_at, gps jsonb?, status, flags jsonb default '[]', timestamps | UQ (round_id, facility_id); CHECK ended_at ≥ started_at |
| `item_responses` | id, assessment_id FK cascade, dimension, month_slot smallint, item_code, value, is_applicable bool | CHECK month_slot 1–3; dimension CHECK |
| `assessment_scores` | id, assessment_id FK cascade, dimension, month_slot smallint, score numeric(5,2) NULL, rule_version_id FK, computed_at | CHECK score 0–100; UQ (assessment_id, dimension, month_slot, rule_version_id) |
| `quarantined_records` | id, submission_id FK, source_id FK, instance_id, round_id?, facility_ref?, lga_ref?, payload jsonb, failures jsonb, status, resolution, resolved_by?, resolved_at?, resolution_note?, timestamps | `failures` = `[{code, severity, detail}]` |
| `scoring_rule_versions` | id, version int UQ, config jsonb, created_by, published_at?, published_reason?, timestamps | Exactly one "current" = max published version |
| `plan_actions` | id, round_id, lga_id, dimension, action_text, owner_role, owner_contact?, due_date, status, notes?, timestamps | UQ (round_id, lga_id, dimension). `owner_role` CHECK against the role keys (`edqa.plan.roles`, labels in `lang/en/plan.php`); `owner_contact` is admin-only, never public (D-79) |
| `source_syncs` | id, source_id UQ FK, cursor_at?, cursor_instance_id?, backfill_checkpoint int, last_success_at?, last_error?, last_reconciled_at?, submission_count, timestamps | The pull cursor, one per source |
| `pull_runs` | id, source_id FK, triggered_by?, trigger_type, started_at, finished_at?, fetched, accepted, flagged, quarantined, duplicates, cross_source_duplicates, error?, outcome | Powers pull history; `trigger_type` includes `reconcile` |
| `submission_attachments` | id, submission_id, filename, mime, size, source_url?, path?, fetched_at?, status | Deferred fetch during backfill |
| `activity_log` | Spatie standard | Append-only (see SECURITY.md) |
| `round_aggregates` | **materialised view** | See below |

> Note on `assessment_scores` uniqueness: the PRD lists UQ `(assessment_id, dimension, month_slot)`.
> Because published rounds keep old-version scores readable, include `rule_version_id` in the
> unique key. See §11 Decisions D-05.

### Constraints (write the failing test first)

```sql
ALTER TABLE assessment_scores ADD CONSTRAINT score_in_range   CHECK (score IS NULL OR (score >= 0 AND score <= 100));
ALTER TABLE assessments       ADD CONSTRAINT visit_dates_ordered CHECK (ended_at >= started_at);
ALTER TABLE item_responses    ADD CONSTRAINT month_slot_valid CHECK (month_slot BETWEEN 1 AND 3);
ALTER TABLE assessment_scores ADD CONSTRAINT score_slot_valid CHECK (month_slot BETWEEN 1 AND 3);
ALTER TABLE rounds            ADD CONSTRAINT quarter_valid    CHECK (quarter BETWEEN 1 AND 4);
ALTER TABLE rounds            ADD CONSTRAINT window_ordered   CHECK (window_end >= window_start);
```

A test inserts `347.66` directly via `DB::table('assessment_scores')->insert(...)` and asserts a
`QueryException` with SQLSTATE `23514` (check_violation).

### Indexes

| Index | Purpose |
|---|---|
| `assessments (round_id, facility_id)` UNIQUE | One assessment per facility per round |
| `submissions (instance_id)` UNIQUE | Idempotency |
| `submissions (deprecated_id)` | Edit chain lookup |
| `item_responses (assessment_id, dimension, month_slot)` | Scoring access path |
| `assessment_scores (assessment_id, dimension, month_slot, rule_version_id)` UNIQUE | |
| `submissions USING gin (payload jsonb_path_ops)` | Payload queries during reprocessing |
| `quarantined_records USING gin (failures)` | Group Data issues by rule code |
| `quarantined_records (status, round_id)` | Open-issues badge |
| `facilities (lga_id) WHERE is_active` | Cascade export |
| `round_aggregates (round_id, scope_type, scope_id, owner_type)` UNIQUE | Required for `REFRESH ... CONCURRENTLY` |

### `round_aggregates` materialised view

Columns: `round_id, scope_type ('state'|'lga'|'ward'|'facility'), scope_id, owner_type
('all'|'public'|'private'), level ('all'|...), availability, consistency, validity,
m1, m2, m3, overall, facility_count`.

Built from **accepted assessments only** (`assessments` joined to current-version
`assessment_scores`). Facility score first, then unweighted means of facility scores upward.
Use `COALESCE(scope_id, 0)` / sentinel values so the unique index has no NULLs.

```php
public function up(): void {
    DB::statement(file_get_contents(database_path('sql/round_aggregates.sql')));
    DB::statement('CREATE UNIQUE INDEX round_aggregates_key ON round_aggregates (round_id, scope_type, scope_id, owner_type, level)');
}
public function down(): void {
    DB::statement('DROP MATERIALIZED VIEW IF EXISTS round_aggregates');
}
```

The app runs as `edqa_app`, which does not own the view, so the migration also creates a
`SECURITY DEFINER` function owned by the migrator role:

```sql
CREATE FUNCTION refresh_round_aggregates() RETURNS void
LANGUAGE sql SECURITY DEFINER SET search_path = public AS
$$ REFRESH MATERIALIZED VIEW CONCURRENTLY round_aggregates $$;
```

The app calls `SELECT refresh_round_aggregates()` — never the raw statement. Migrations run in
the one-off `migrate` container, which receives `DB_USERNAME=edqa_migrator`; the app containers
connect as `edqa_app`. After migrating, the same container executes
`database/sql/post-migrate-grants.sql`. All of this is part of the contract with the DevOps
project (`DEPLOY.md` §1.3, §8).

Refresh with `SELECT refresh_round_aggregates();` on `AssessmentScored`
(debounced via a unique queued job), `RoundClosed`, and after rescore.

Filters not covered by the view (e.g. LGA + Level + Owner combined) are answered by Aggregator
SQL over `assessment_scores` with the indexes above; keep the p95 under 1.5 s and cache by round tag.

### Laravel specifics

- `$table->jsonb('payload')`; cast `'payload' => 'array'`.
- `$table->timestampsTz()`.
- Enums: string columns + CHECK, cast to PHP backed enums.
- `Model::preventLazyLoading(! app()->isProduction());`
- `Model::shouldBeStrict()` in local/testing.

### Data volume

5 years × 4 rounds × 2,200 facilities × 9 scores ≈ 400,000 score rows, ~40,000 submissions.
Comfortable for Postgres with these indexes.

---

## 5. Ingestion pipeline — ODK Central and KoboToolbox

Assessments arrive from **two field-collection sources**: **ODK Central** and **KoboToolbox**.
Both run XLSForm-based forms filled offline in ODK Collect or KoboCollect/Enketo. They are the
system of record for submissions; the portal pulls from them and never writes back.

Both sources go through **one** pipeline. Each source has an adapter that knows how to talk to
its API and how to turn its JSON into a common shape. Everything after that — storage, parsing,
validation, scoring — is shared, so there is still exactly one place where data can enter and be
checked.

```mermaid
flowchart TD
  OC[ODK Collect] --> ODK[ODK Central]
  KC[KoboCollect / Enketo] --> KOBO[KoboToolbox]
  ODK --> A1[OdkCentralSource adapter]
  KOBO --> A2[KoboSource adapter]
  A1 & A2 --> PJ[Pull job per source<br/>10 min / Pull now / webhook / reconcile]
  PJ --> D[submissions: raw payload verbatim + source]
  D --> N[Source normaliser → common envelope]
  N --> E[SubmissionParser + FieldMap for source + form version]
  E --> F{ValidationPipeline}
  F -->|pass| G[Assessment + item responses + scores]
  F -->|soft fail| G2[Assessment, flagged]
  F -->|hard fail| H[quarantined_records]
  H --> I[Administrator resolves]
  I --> N
```

### 5.0 Sources

| Key | Type | Talks to | Identity of a submission | Version field |
|---|---|---|---|---|
| `odk` | `odk_central` | ODK Central REST + OData API (session token) | `__id` (logical ID) + `meta/instanceID` (this version) | `__system/formVersion` |
| `kobo` | `kobo` | KoboToolbox KPI API v2 (`Authorization: Token …`) | `_id` (logical ID) + `meta/instanceID` (this version) | `__version__` (Kobo deployment version) |

- Each configured source is **one form** (an ODK project + form, or a Kobo asset UID). The schema
  allows more later; v1 configures at most one of each.
- Sources are declared in `config/edqa.php` → `sources`, with credentials from env only. A
  `submission_sources` table (key, type, name, is_enabled) gives each one a stable ID for foreign
  keys. A source with missing credentials is "not configured" and simply never pulls.
- **Adding a third source** (e.g. ODK Aggregate, SurveyCTO) means writing a new adapter that
  implements the same contracts, plus a decision record. It never means a shortcut path such as
  a file upload.

### 5.1 Form contract (both sources)

The form must satisfy these on every source. The client should deploy **the same XLSForm** to
both where possible (open question Q-16). If a form doesn't comply, adapting it is a scoped task.

| Requirement | Why |
|---|---|
| Facility chosen by cascading select LGA → ward → facility, from a CSV media file generated by the portal and attached to the form on each source | Kills free-text names like `2019.00` |
| Scored question names match `^(avail|consist|valid)_m[1-3]_[a-z0-9_]+$` | Dimension and month slot parsed from the name; new questions need no code change |
| Scored questions are `select_one` with a fixed list (`yes` / `no` / `na`) | Scores computed from pass counts, never typed. Kills `347.66` at source |
| `round_year`, `round_quarter` are read-only calculated fields | Not typed by assessors |
| `meta/instanceID` required | Idempotency key |
| `start`, `end` metadata fields | Visit duration, `END_BEFORE_START`, `VISIT_TOO_SHORT` |
| GPS and register photo optional | Stored when present |

Choice mapping (configurable per field map): `yes` → pass, `no` → fail, `na` → not applicable.

### 5.2 Components (`app/Domain/Ingestion/`)

**Shared**

| Class | Responsibility |
|---|---|
| `Contracts\SubmissionSource` | `ping()`, `formVersions()`, `submissionsPage(since, top, skip)`, `changedSince(since)` (edits), `attachmentList()`, `downloadAttachment()` |
| `Contracts\PayloadNormalizer` | Raw source JSON → `SubmissionEnvelope` (common shape, below). Pure, deterministic |
| `SourceRegistry` | Resolves a source key to its adapter, normaliser, config and `submission_sources` row; knows which are configured and enabled |
| `SubmissionEnvelope` (DTO) | `meta` {source, externalId, instanceId, deprecatedId, submittedAt, updatedAt, formVersion, submitter, deviceId, attachments[{name, mime, url?}]} + `data` (answers nested by group path) |
| `FieldMap` | Resolves logical names → question paths for a (source, form version) |
| `SubmissionParser` | `SubmissionEnvelope` + `FieldMap` → `ParsedSubmission` |
| `ProcessSubmissionsPage` | One page from any source: insert raw rows, normalise, parse, handle edits, validate, score — one transaction |
| `PullSubmissionsJob(source, trigger)` | Incremental pull for one source |
| `BackfillSubmissionsJob(source)` | Cursor-from-zero pull, checkpointed per page, throttled |
| `ReconcileSourceJob(source, window)` | Sweep that catches edits and deletions an incremental pull can miss (§5.3) |
| `FetchAttachmentsJob` | Low-priority queue; downloads deferred attachments |
| `PullLock` | `Cache::lock("pull:{source}", 900)` — one pull per source at a time; different sources may pull in parallel |

**Per source**

| Source | Classes |
|---|---|
| ODK Central | `Odk\OdkCentralSource`, `Odk\OdkCentralClient`, `Odk\SessionTokenCache`, `Odk\OdkPayloadNormalizer` |
| KoboToolbox | `Kobo\KoboSource`, `Kobo\KoboClient`, `Kobo\KoboPayloadNormalizer` |

The normaliser is where the two APIs' differences are absorbed: ODK OData returns nested
objects per group and a `__system` block; Kobo returns flat keys like `facility/lga`, underscore
system fields (`_id`, `_submission_time`, `__version__`, `_attachments`, `_submitted_by`) and
timestamps without a timezone suffix (treat as UTC). After normalisation the parser cannot tell
the sources apart.

### 5.3 Pull mechanics

**Common to both sources**

- **Cursor:** one `source_syncs` row per source. `cursor_at` advances **only after the whole
  page commits**. A crash mid-page replays that page; uniqueness makes replay harmless. Use
  `>=` with the last timestamp plus instance-ID de-duplication, because timestamps tie.
- **Idempotency:** `submissions.instance_id` is unique **across all sources**. A repeat from
  the same source counts as a `duplicate`. The same instance ID arriving from the *other*
  source (e.g. data copied between servers) counts as `cross_source_duplicate`, is not
  inserted, and is shown on the Data sources page.
- **Edits:** both tools keep a stable logical ID and give each edited version a new
  `meta/instanceID` with `meta/deprecatedID` set. A new version — detected by `deprecatedID`
  **or** by a known `external_id` with a new instance ID — marks the old submission
  `superseded`, keeps it, and updates the existing assessment in place (audited). An open
  quarantined record for the old version auto-resolves as superseded.
- **Edits don't change the submission date** on either platform, so an incremental pull filtered
  only on submission time misses them. Each adapter's `changedSince()` uses the platform's
  last-modified field where its API supports filtering on it (ODK Central:
  `__system/updatedAt`), and `ReconcileSourceJob` sweeps the open round's window nightly and on
  demand, comparing (external ID, instance ID) pairs. Verify Kobo's filterable fields against
  the current KPI API docs; if no last-edited filter exists, the sweep is the edit path for Kobo.
- **Deletions at source** are reported by the sweep only (a submission we hold that the source
  no longer returns). They are **not** applied automatically: the record is flagged for an
  administrator, because deleting a published assessment is a decision, not a sync.
- **Attachments:** stored in `storage/app/attachments/{source}/{instance_id}/`, on first sight,
  never re-fetched; deferred during backfill. Download URLs are only followed when their host
  equals the configured source host (SSRF guard, SECURITY.md §6).
- **Retries:** 3 attempts, backoff `[30, 120, 600]`, honour `Retry-After` on 429; then `failed()`
  → error on the run + `source_syncs.last_error` + `PullFailed` + digest.
- **Progress:** each run writes a `pull_runs` row (with `source_id`) and updates counters as pages
  commit; the admin page polls `GET /api/pulls/{runId}`.
- **Schedule:** one scheduled pull per **configured and enabled** source, every 10 minutes,
  `withoutOverlapping()` per source; a nightly `ReconcileSourceJob` per source for the open round.
- **Latency target:** a submission on either source visible on the dashboard ≤ 15 minutes.

**ODK Central**

- `GET /v1/projects/{p}/forms/{f}.svc/Submissions?$filter=__system/submissionDate ge {cursor} or __system/updatedAt ge {cursor}&$top=500&$skip={n}&$count=true&$expand=*`
- Session token from `POST /v1/sessions`, cached until 30 min before expiry; refresh once on 401.

**KoboToolbox**

- `GET {base}/api/v2/assets/{uid}/data/?format=json&limit=…&start=…` with a `query` on
  `_submission_time` (`$gte` the cursor) and a sort on the same field.
- `Authorization: Token {KOBO_API_TOKEN}`; no session step.
- Base URL is configurable: the global server, the EU server, or a self-hosted KoboToolbox
  (open question Q-17).
- Check parameter names, page-size limits and rate limits against the current KPI API docs when
  building the adapter; record what was verified in the adapter's docblock.

### 5.4 Webhooks (optional, per source)

`POST /webhooks/{source}` — outside the auth group, verified per source, and it **only**
dispatches `PullSubmissionsJob(source, Webhook)`; the body is never treated as data. The
scheduled pull always runs regardless.

- **ODK:** HMAC-SHA256 over the raw body with `ODK_WEBHOOK_SECRET` (SECURITY.md §6).
- **Kobo:** Kobo's REST Services can't sign requests, so a long random token is sent in a custom
  header (`X-EDQA-Webhook-Token`) and compared with `hash_equals`; HTTPS only.

### 5.5 Form versions and field maps

Every submission records its source and form version. Field maps are **named maps** that a
(source, version) pair points to, so the same XLSForm deployed on both platforms shares one map
even though each platform labels the version differently:

```php
return [
    'maps' => [
        'xls-2026.1' => [ /* full map — current form */
            'facility_code' => 'facility/facility_code',
            'lga_code'      => 'facility/lga',
            'ward_code'     => 'facility/ward',
            'round_year'    => 'round_year',
            'round_quarter' => 'round_quarter',
            'started_at'    => 'start',
            'ended_at'      => 'end',
            'assessor'      => 'assessor_name',
            'scored_prefix_pattern' => '/^(avail|consist|valid)_m([1-3])_([a-z0-9_]+)$/',
            'choices'       => ['yes' => 'pass', 'no' => 'fail', 'na' => 'na'],
        ],
        'xls-2021.3' => [ /* additive: only names that differ */
            'extends'       => 'xls-2026.1',
            'facility_code' => 'grp_fac/fac_id',
            'renames'       => ['avail_m1_nhmis_summary' => 'avail_m1_nhmis_form'],
        ],
    ],
    'sources' => [
        'odk'  => ['2026.1' => 'xls-2026.1', '2021.3' => 'xls-2021.3'],   // __system/formVersion
        'kobo' => ['vQ8xK2…' => 'xls-2026.1'],                            // __version__ uid
    ],
];
```

**Rule:** a (source, version) pair with no entry quarantines under `UNKNOWN_FORM_VERSION`, with
the source named in the detail. Never fall back to another version's map. Silent misparsing is
the worst outcome.

**Phase 0 task, per source:** list every deployed form version (ODK:
`GET /v1/projects/{p}/forms/{f}/versions`; Kobo: the asset's deployed versions from the KPI API),
download each XLSForm, and diff question names. That diff *is* the field map.

### 5.6 Initial backfill

Same adapters, normalisers, parser, validation and scoring — cursor set to zero, **run one
source at a time**.

- ~40,000 submissions in total across sources; at 500 per page, roughly 80 pages. Hours, not minutes.
- Checkpoint after every page into `source_syncs.backfill_checkpoint`. Restart resumes there.
- Attachments recorded, fetched later by `FetchAttachmentsJob` on the `low` queue.
- Historical rounds **created before the backfill runs**. Submissions outside every window →
  `ROUND_WINDOW`, never guessed into the nearest round.
- Throttle between pages per source (default 5 s); run out of hours on the first pass.
- Commands: `edqa:backfill {source} {--survey} {--from-page=} {--throttle=} {--dry-run}`.
- The **backfill report** breaks results down by source, round, year, rule and form version
  (`edqa:backfill-report --format=md|csv|xlsx`). As Excel it has a sheet per question (Summary,
  By rule × year, By rule × form version, Examples, Unmatched facilities, About this file), and
  the Data sources page downloads it per source (D-98).
- If both sources hold history for the same quarters, expect `DUPLICATE_ASSESSMENT` and
  `cross_source_duplicate` counts; the report lists them so the client can decide which copy is
  authoritative (Q-15).

### 5.7 Reprocessing

`ReprocessSubmission` reads `submissions.payload` and runs it through **its own source's**
normaliser, never re-fetching. Used after a quarantine correction, a field-map fix, or a
scoring-rule publish. Idempotent.

### 5.8 When both sources report the same visit

One assessment per facility per round still holds, whichever tool it came from. If the same
facility is submitted for the same round on both sources, the first accepted one stands and the
other quarantines under `DUPLICATE_ASSESSMENT`, with the detail naming both sources and instance
IDs. The administrator decides in Data issues. If the client assigns sources by LGA or by period
(Q-15), that is configuration for a future soft rule, not a v1 behaviour.


---

## 6. Validation rules and quarantine

Every rule is a class implementing `App\Domain\Validation\Contracts\ValidationRule`, registered
in `config/edqa.php` under `validation.rules`. Adding a rule = adding a class + config line.

```php
interface ValidationRule
{
    public function code(): string;            // e.g. 'SCORE_RANGE'
    public function severity(): Severity;       // Hard | Soft
    /** @return list<RuleFailure> empty when passing */
    public function check(ParsedSubmission $s, ValidationContext $ctx): array;
}
```

`ValidationContext` provides the facility master list, rounds, prior assessment scores and
assessor-day counts — loaded once per page of submissions, not per rule (no N+1).

The pipeline runs **every** rule and collects **all** failures (not fail-fast), so the Data
issues page shows every reason at once.

### Rule catalogue (14 rules: 10 hard, 4 soft)

| # | Code | Sev | Passes when | Live-report defect it prevents |
|---|---|---|---|---|
| 1 | `UNKNOWN_FORM_VERSION` | Hard | Form version has a field map. **Runs first; if it fails, skip parse-dependent rules** | Old form parsed with the wrong map |
| 2 | `COLUMN_DRIFT` | Hard | Date fields parse as dates; ward is not a reserved word (`Quarterly`, month names, `null`, numeric) | `Ward = Quarterly`, `Start = FEBRUARY` |
| 3 | `LGA_UNKNOWN` | Hard | LGA resolves to one of the 23 | `null` LGA → 24 LGAs |
| 4 | `FACILITY_UNKNOWN` | Hard | Facility code matches an **active** master-list entry | Facility named `2019.00` |
| 5 | `FACILITY_LGA_MISMATCH` | Hard | Master-list LGA == submitted LGA | Wrong cascading-select pick |
| 6 | `ITEMS_INCOMPLETE` | Hard | All required items present for all 3 dimensions × 3 months | Missing dimension can't be averaged |
| 7 | `SCORE_RANGE` | Hard | Every derived score within 0–100 (defence in depth; DB CHECK is the backstop) | `347.66`, `392.42` |
| 8 | `END_BEFORE_START` | Hard | `ended_at ≥ started_at` | Basic integrity |
| 9 | `ROUND_WINDOW` | Hard | Visit date inside an **open** round's window | Q1 visit landing in Q2 |
| 10 | `DUPLICATE_ASSESSMENT` | Hard | No existing accepted assessment for (round, facility), excluding the one this submission supersedes | Double counting |
| 11 | `SCORE_JUMP` | Soft | Overall within 30 points of facility's previous round | Usually entry error |
| 12 | `ALL_PERFECT` | Soft | Not all nine scores exactly 100 | Form filled without visiting |
| 13 | `VISIT_TOO_SHORT` | Soft | `ended_at − started_at ≥ 20 min` | Rushed/fake visit |
| 14 | `ASSESSOR_VOLUME` | Soft | Assessor ≤ 6 facilities that day | Supervisor attention |

Soft thresholds (30 points, 20 min, 6 facilities) live in `config/edqa.php`.

> The PRD build order says "the thirteen rules from §7" but the PRD §7 table lists 14. Build all 14.
> See §11 Decisions D-06.

### Outcomes

- Any hard failure → `quarantined_records` row (payload copy, `failures` jsonb with every code +
  human-readable detail). No assessment, no item responses, no scores.
- Only soft failures → assessment created, `assessments.flags` lists the codes; shown with a
  "Flagged" tag in the facility drawer and Data issues (soft tab). Contributes to aggregates.
- All pass → assessment created, scored, `AssessmentScored` event.

Detail strings must be specific: `"availability_m1 derived score 347.66 exceeds 100"`,
`"lga was blank"`, `"ward 'Quarterly', start 'FEBRUARY'"`.

### Quarantine workflow (Data issues page)

1. Grouped by rule code, filterable by LGA and round; badge count on the admin tab.
2. Each row: source, instance ID, rule(s), facility reference, LGA, detail, and a **deep link to the submission on its source** (ODK Central or KoboToolbox).
3. Actions:
   - **Correct and reprocess** — edit the offending mapped field (stored as a correction overlay,
     never mutating `submissions.payload`), re-run the pipeline. Audited with old/new/reason.
   - **Reject** — typed reason required; excluded permanently; audited.
   - **Override soft flag** — typed justification; audited.
   - **Fix at source** — assessor edits on the source (ODK Central or KoboToolbox); the next pull or reconcile brings a new instance with
     `deprecatedID`; the quarantined record auto-resolves as `superseded`.
4. **Bulk resolve** rows failing the same rule the same way (one reason applies to all; one audit
   entry per record).
5. Every action writes an audit entry. Nothing is silently fixed.
6. Resolving triggers a rescore of the affected round.

### The guarantee

No aggregate — chart, scorecard, export — reads from `submissions` or `quarantined_records`.
Aggregators read only `assessments` / `assessment_scores` / `round_aggregates`. An architecture
test (Pest `arch()`) asserts `App\Domain\Reporting` does not depend on
`QuarantinedRecord` or `Submission` models.

---

## 7. Scoring engine

### Principles

1. Scores are always derived. A submitted "overall" or score field is ignored.
2. Every score records the `scoring_rule_version` that produced it.
3. Changing a rule never mutates history: it creates a new version and triggers a rescore; old
   scores stay readable for closed rounds.
4. Aggregates are SQL over `assessment_scores` (and the `round_aggregates` view), cached, and
   invalidated by round.
5. Store full precision (`numeric(5,2)`); round **only** at presentation.

### Computation (`ScoreCalculator`)

```
dimension_month_score = items_passed / items_applicable × 100
dimension_score       = mean of non-null month scores
overall_score         = mean(availability, consistency, validity)
```

- Items marked N/A are excluded from numerator **and** denominator.
- If every item in a dimension–month is N/A → that slot score is `NULL`, dimension score is the
  mean of the remaining slots, and the exclusion is recorded (`assessments.flags` gets
  `SLOT_ALL_NA:{dim}:{m}` as an informational note).
- If a whole dimension is null → `ITEMS_INCOMPLETE` (hard) should have caught it; the calculator
  throws `IncompleteAssessmentException` as a safeguard.
- Item weights (from the rule version config) default to 1. With weights:
  `Σ(weight × pass) / Σ(weight × applicable) × 100`.

Pure function: `ScoreCalculator::calculate(ItemResponseSet, RuleConfig): ScoreCard`. No DB
access inside — makes it trivially testable and deterministic (goal G3: two runs on identical
inputs produce identical outputs).

### Aggregation (Aggregators in `app/Domain/Reporting/Aggregators/`)

| Level | Method |
|---|---|
| Facility | Mean of its assessments in the round (normally one) |
| Ward | Unweighted mean of facility scores |
| LGA | Unweighted mean of facility scores |
| Owner type | Unweighted mean within Public / Private |
| State | Unweighted mean of all assessed facilities |

Config: `edqa.aggregation.weighting` = `'unweighted'` (default) | `'facility_count'`.
Every aggregator has a test and is the **only** place a number shown on a page is computed.

Aggregators (suggested):
`StateSummaryAggregator`, `DimensionAggregator`, `OwnerSplitAggregator`, `LgaTableAggregator`,
`FacilityScoresQuery`, `QuarterTrendAggregator`, `MonthSlotAggregator`, `CoverageAggregator`,
`AssessmentWindowAggregator`, `ImplementationPlanAggregator`.

All take a `ReportFilter` DTO (year, quarter, lga, level, owner) built from the URL query string.

### Reference figures (must pass as tests before building UI)

| Group | Avail | Consist | Valid | Mean | Shown |
|---|---|---|---|---|---|
| Public | 92 | 79 | 92 | 87.67 | 88 |
| Private | 75 | 62 | 84 | 73.67 | 74 (**PRD says 73**) |
| State (2,000 pub + 219 priv, unweighted over facilities) | | | | ≈ 86.3 | 86 |

> Note: 73.67 rounds to 74, not 73. The live tool shows 73 — likely because its underlying
> dimension values are unrounded (e.g. 74.7/61.9/83.8) or it truncates. Test against the
> **unrounded** figures from a known client round, and confirm the display rounding rule
> (half-up vs truncate) with the client. See §11 Decisions Q-09.
> Also: (2000×87.67 + 219×73.67)/2219 ≈ 86.29, not 86.5 — still displays 86.

### Bands

Config in the rule version, defaults:

| Band | Range | Tag |
|---|---|---|
| Strong | ≥ 90 | green |
| Acceptable | 80–89.99 | green-muted |
| Review | 70–79.99 | amber |
| Needs action | < 70 | red |

Bands drive table colour, status column, and which LGAs enter the implementation plan.
Changing a band never changes a stored score. Band logic lives server-side
(`App\Domain\Scoring\Bands`); the frontend receives the band key with each number and never
recomputes it.

### Rule versions

- `scoring_rule_versions.config` jsonb: `{ bands, item_weights, na_policy, choice_map }`.
- Edit → new **draft** version. **Publish** requires a typed reason and shows a **preview**:
  number of facilities and LGAs that would change band, per round. Then dispatches rescore.
- Current version = highest published. Closed rounds keep their existing scores readable; the
  dashboard reads the current version by default.

### Rescore

Triggered by: rule version publish, quarantine resolution, assessment correction, manual button.

- `RescoreRoundJob` queued, chunked by 200 assessments, reports progress (`job_batches` /
  Bus::batch).
- Reads item responses → `ScoreCalculator` → upserts `assessment_scores` for the target version.
- Ends with `REFRESH MATERIALIZED VIEW CONCURRENTLY round_aggregates` and cache flush for the round.
- Target: full round (~2,200 assessments) in < 5 minutes.

---

## 8. Internal API surface

Dashboard pages are Inertia. JSON endpoints come in two groups (SECURITY.md §3):

- **Public** (`/api/public/*`): GET only, no login, rate limited, closed rounds only, public
  resources only. Used by the public dashboard for partial reloads and the facility drawer.
- **Admin** (`/admin/api/*`): authenticated administrator session (cookie + CSRF).

No API tokens in v1.

**Public**

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/public/aggregates` | Scores by scope, all five filters, closed rounds |
| GET | `/api/public/facilities` | Paginated (50), sortable, filterable facility scores |
| GET | `/api/public/facilities/{id}` | Public drawer: facility details, scores, item results, history (SECURITY.md §3.1) |
| GET | `/api/public/status` | "Figures last updated …" only (date source: D-81) |

The public pages are `/`, `/facilities`, `/lgas`, `/monitor`, `/visuals` and `/plan` (§9.0); the
drawer opens with `?facility={id}`. The signed-in dashboard's old URLs (`/dashboard`,
`/dashboard/{home,facility,lga,monitor,visuals,plan}`) answer 301 to the public ones, keeping the
query string. Public pages and JSON send `Cache-Control: private, max-age=60`
(`edqa.public.max_age`) to visitors and `private, no-store` to a signed-in administrator, with
`Vary: X-Inertia, Cookie` (SECURITY.md §8).

**Admin** (all paths below are under `/admin`; `/admin` itself redirects to `/admin/sources`, the
admin facility view is the page `/admin/facilities/{id}`)

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/facilities/{id}` | Full admin detail: item responses, history, flags, attachments, audit, source + deep link |
| POST | `/api/assessments/{id}/corrections` | Correct an assessment (reason required) |
| GET | `/api/rounds` | Rounds and status |
| POST | `/api/rounds/{id}/close` | Close round (reason required): publishes it, snapshots aggregates, flushes its cache |
| POST | `/api/rounds/{id}/reopen` | Reopen (reason required): takes it off the public dashboard, flushes its cache |
| GET | `/api/sources` | Every source: configured?, enabled?, reachable?, last success, cursor, last error, open-round counts |
| POST | `/api/sources/{key}/pull` | Manual pull for one source; returns `{ run_id }` (existing run if one is running) |
| POST | `/api/sources/pull-all` | Manual pull for every configured, enabled source; returns run ids |
| POST | `/api/sources/{key}/reconcile` | Run the edit/deletion sweep for the open round |
| GET | `/api/pulls/{runId}` | Progress: fetched, parsed, accepted, flagged, quarantined, duplicates, status |
| GET | `/api/quarantine` | Quarantined records, grouped or flat |
| POST | `/api/quarantine/{id}/resolve` | `{ action: correct|reject|override, fields?, reason }` |
| POST | `/api/quarantine/bulk-resolve` | Same rule, same resolution, many ids |
| GET | `/api/facility-list` | Master list for admin table |
| POST | `/api/facility-list/export-cascade` | Regenerate the cascading-select media file (same file for ODK Central and KoboToolbox) |
| POST | `/api/facilities` | Add a facility to the master list (D-91) |
| PATCH | `/api/facilities/{id}` | Edit a facility inline: name, ward (its LGA follows), level, ownership, coordinates; the code only while no assessment holds it |
| POST | `/api/facilities/{id}/deactivate`, `/reactivate` | With a reason; never a delete |
| POST, PATCH | `/api/wards`, `/api/wards/{id}` | Add a ward to an LGA, rename one; no LGA route (hard rule 9) |
| PATCH, DELETE | `/api/scoring-rules/{id}` | Save or discard the draft (409 for a published version) (D-92) |
| POST | `/api/scoring-rules/{id}/preview` | Band-change preview before publish (reads only; facility names, counts) |
| POST | `/api/scoring-rules/{id}/publish` | Publish (reason required), triggers rescore |
| POST | `/api/exports` | Queue an export: one of ten types (state summary, LGA table, facility scores, item responses, data issues, pull history, audit log: CSV or Excel; backfill report and workplan: Excel only, 409 `excel_only` for CSV; round report: PDF only, 409 `wrong_format` for any other format, and PDF for no other type), the privacy option where the type has one (item responses; the workplan's private plan fields; 409 `private_not_offered` elsewhere), and the page's filter by its URL names (the workplan and round report also take `round`); 202 `{ export_id }`; 10/min per administrator; audited (D-96, D-97, D-98, D-100, D-101) |
| GET | `/api/exports/{id}` | Poll status and progress; a relative signed download URL (30 min) when ready |
| GET | `/exports/{id}/download` (not under /api) | Signed (`signed:relative`) **and** signed in: streams the file from the private disk with nosniff and no-store; audited. A guest is sent to sign in, the JSON endpoints answer 401 |
| POST | `/webhooks/{source}` (not under /admin) | Optional, verified per source (§5.4), outside auth group; only enqueues a pull |

**There is no `POST /api/imports`.** Removing that route is the enforcement of "field-collection sources only".

### Conventions

- Controllers thin: validate via FormRequest → call Action/Aggregator → return JsonResource.
- Errors: Laravel default validation 422 shape; domain errors 409 with `{ code, message }`.
- Filters are query params: `year, quarter, lga, level, owner`, identical names to the dashboard
  URL so a view link can be turned into an export.
- Rate limits: see SECURITY.md §8.

---

## 9. Presentation layer

Visual design tokens are in `CONVENTION.md` §6; accessibility rules in `ACCESSIBILITY.md`. The prototype (`kaduna-edqa-prototype-v4.html`) is the visual reference only.

### 9.0 Two surfaces

| | Public dashboard | Admin area |
|---|---|---|
| URL | `/` (Home), `/facilities`, `/lgas`, `/monitor`, `/visuals`, `/plan` | `/admin/*`; `/login` to enter |
| Who | Anyone, no login | Administrators, password login |
| Data | Closed rounds only (D-77) | All rounds; admin preview of the dashboard includes the open round |
| Layout | Public shell: header with KD seal and title, filter bar, page tabs. **No** Administration link, **no** login link | Admin shell: rail with the admin pages, admin status bar, link to "View public dashboard", logout |
| Status bar | "Figures last updated {date}" (D-81) | Full per-source sync status |
| Facility drawer | Public drawer (scores, item results, history only) | Admin facility view with flags, attachments, audit, source links, corrections |
| Exports | None (D-80) | All exports |
| Implementation plan | Read-only, owners shown as roles (D-79) | Editable |

### 9.1 Layout shell

- **Public shell:** header with KD seal and "eDQA e-tool / Kaduna State"; footer with
  "Support: LAFIYA" and the figures-last-updated line. No Administration or login link.
- **Admin shell:** left **rail** (210px, `--color-rail`) with the admin pages and "View public
  dashboard". Collapses to a horizontal bar under 760px.
- **Admin status bar** above the top bar: sync state across sources (worst state wins; each source named) — green "Last pull 4 min ago · 2,184
  assessments · 12 in quarantine" / amber when > 30 min / red when last pull failed.
  (Replaces the prototype's "Sample data / Import data" bar.)
- Sticky **top bar**: page title + subtitle; **global filter bar** (Year, Quarter, LGA, Level,
  Owner); tabs underneath. The Year/Quarter options on the public site list closed rounds only.
  Export buttons appear in the admin preview only.
- Filters live in the **URL query string** (Inertia `router.get` with `preserveState`,
  `preserveScroll`). Any view is bookmarkable and shareable.
- Content max-width 1280px, padding 22/26px.

### 9.2 Dashboard pages

#### 9.2.1 Home
- **Hero** (270px left / flexible right): Average quality score (64px display), sentence
  "Averaged across N facilities…", 2×2 minis: Facilities visited, Local governments, Facilities
  90+, Facilities under 70.
- Right: "The three dimensions" — horizontal bars for Availability, Consistency, Validity,
  Overall with a Strong-threshold marker; note naming the weakest dimension.
- **Public vs Private** split panel: big overall + 3 dimension minis each.
- **Quality score by quarter** line chart with dashed Strong line.
- **Weakest local governments** — six lowest, value tags.

#### 9.2.2 Facility view
- Segmented metric switcher: Availability / Consistency / Validity / Overall.
- Columns: Facility, LGA, Ward, Owner (tag), Assessed (round + date), Month 1/2/3 (heat, real
  month names when one quarter in view), Overall (band tag).
- **Sorted weakest first** by default. Server-side pagination 50/page, sortable on any column
  (TanStack Table in manual mode).
- Facility name opens the **detail drawer**.

#### 9.2.3 LGA view
- 23-tile grid: name, overall, facility count, left border in band colour, `aria-pressed`
  when selected; click toggles the LGA filter.
- Table: LGA, Facilities, Availability, Consistency, Validity, M1, M2, M3 (heat), Overall,
  Status label.
- Tab badge shows LGA count — must always be ≤ 23.

#### 9.2.4 Monitor
- Public / Private split (same component as Home).
- Facilities visited per LGA — ranked bar list (hand-built SVG/CSS), state-mean marker.
- Coverage by level (Primary / Secondary / Tertiary).
- Assessment window table: rounds in view, first visit, last visit, assessments recorded.

#### 9.2.5 Visuals
- Toggle Overall / Public / Private.
- Three grouped bar charts (Recharts), one per month slot, 4 series across quarters in view,
  x-labels = real month name + quarter.
- Four ranked horizontal bar charts by LGA (hand-built SVG): Overall, Availability,
  Consistency, Validity. Bar colour by band.

#### 9.2.6 Implementation plan
- Per LGA: overall (tag), weakest dimension, its score (heat), standard action, **owner role**
  (a unit such as "LGA M&E officer" — never a personal name publicly), **review date**,
  **status** (Not started / In progress / Done). Editable in the admin area only; read-only on
  the public site.
- Standard actions:
  - Availability — Restock HMIS tools; confirm current register set is in place
  - Consistency — On-site mentoring on reconciling the monthly summary against the register
  - Validity — Retraining on data element definitions and correct tool completion
- "Actions by type" summary + "What each action means" reference panel.
- Export to Excel workplan (admin only). **As built (Phase 6 step 4, D-100):** from the admin plan
  page and the admin preview of the public plan; sheets Plan, Summary (type × status), Reference
  and About this file; a Status dropdown, A4 landscape with the header row repeated; contacts and
  notes only with "Include private plan fields", audited. The plan also reaches paper in the PDF
  round report (D-101): owner roles and statuses, never contacts or notes.
- Edits persist to `plan_actions` and are audited (prototype was read-only). **As built (Phase 6
  step 3, D-99):** the admin plan page `/admin/plan?round=` lists every LGA below the threshold for
  any round, open or closed, with "Generate plan for this round" (idempotent) and each row's owner
  (a role, or "Other role…" written out), contact and notes (admin-only), review date and status
  saved inline. The public page adds each row's status and the counts by status; never the contact
  or the notes.

#### 9.2.7 Facility detail drawer
**Public drawer:** facility name, LGA, ward, level, ownership, score card for the selected
closed round, item-level results grouped by dimension × month (pass / fail / N/A),
round-over-round sparkline. Nothing from SECURITY.md §3.1.

**Admin facility view** (`/admin/facilities/{id}`): everything above for all rounds, plus soft
flags, attachments (admin URLs), correction audit trail, the source (ODK Central or
KoboToolbox), instance ID and deep link, and "Correct this assessment".

### 9.3 Administration pages (six, plus the Audit log)

| Page | Replaces prototype tab | Content |
|---|---|---|
| **Data sources** | "Imports" + "Data sources" | One panel per source (ODK Central, KoboToolbox): configured/enabled, reachable, last success, fetched in last pull, round cumulative, last error (HTTP status + time), **Pull now** and **Reconcile** with live progress. **Pull all**. Combined pull history with a source column: time, source, trigger, retrieved, accepted, flagged, quarantined, duplicates, cross-source duplicates, duration, outcome. Deletions-at-source list for review; the facility media file |
| **Data issues** | "Data issues" | Rule list with counts (reuse prototype's issue rows + "why it exists" text), three tabs: **hard** (quarantine), **soft** (flags) and **deleted at source**; filters LGA + round, rows with instance ID, rule, facility, LGA, `code`-styled detail; correct/reject/override inline; bulk reject or correct; source filter and deep link to the submission on its source. A DUPLICATE_ASSESSMENT clashing with an accepted visit shows both sides and offers **Keep the existing one** or **Use this one instead** (replace from submission). The deleted-at-source tab offers **Withdraw** / **Restore** and **Keep** (mark reviewed). All reasoned (D-94) |
| **Facility (admin view)** | — (new) | `/admin/facilities/{id}`: every round, items, flags, attachments, the audit trail, the source and its link; **Correct this assessment** while the round is open (D-58) |
| **Rounds** | — (new) | List (window, status in words, accepted per source, open issues, rule version, closed at/by, reopen reason), create (year, quarter, window), close (open issues, Reconcile all sources, reason; **closing publishes**), reopen (leaves the public dashboard), edit window and rescore, each with a reason (D-90); the PDF round report of any round, the latest closed one first, an open one as a watermarked draft (D-101) |
| **Facility list** | "Facility list" | Search (name, code, ward, LGA; pg_trgm) and filters (LGA, ward, level, ownership, active), server pages, visits and latest score; inline edit with the public-impact note; add; deactivate/reactivate with a reason (never delete); wards added and renamed within an LGA (the 23 LGAs fixed); the facility media file marked out of date until regenerated, the regenerate panel at the top of the page while it is (and for the rest of the visit, so its download link survives the reload). The master list itself comes from the console import (D-91) |
| **Scoring rules** | "Scoring rules" | Current version (published at/by, reason, bands, N/A policy, choice map; weighting and display rounding read-only, Q-09); one draft (bands with the descending rule checked as typed, searchable item weights, N/A policy; save, discard); preview per open round (facilities and LGAs changing band, state overall before → after; closed rounds listed as unaffected); publish with a reason, then each open round's rescore followed; version history with a diff (D-92) |
| **Implementation plan** | — (new) | One round at a time (`?round=`, open or closed): every LGA below the threshold from ImplementationPlanAggregator, "Generate plan for this round" (idempotent), and per row the owner (configured role, or "Other role…" written out: a role or unit, never a person), contact and notes (admin-only, never public), review date (on or after today when changed; a passed one is said in words) and status, saved inline and audited; actions by type with counts by status (D-99) |
| **Audit log** | — (new) | Every audited write and sign-in event (SECURITY.md §4), read only: filters (who, subject type, source, event, dates, reason), 50 a page, before/after per entry, auth events labelled; its export is the `audit_log` export type (D-97), audited; views are not (D-93) |

The rail lists these seven pages, then **My account** (`/admin/account`: change password, your last ten
sign-ins; D-93), "View public dashboard" and **Log out** (D-85). The Data issues tab shows a badge with the open
issues: open quarantine records plus deletions at source not yet reviewed ("Data issues, 12 open").

The whole of a round, from an empty quarter to a published one, is one Playwright journey on these
pages, keyboard first: `make e2e-round` (D-95). The administrators' guide is `docs/admin-guide.md`.


---

## 10. Non-functional targets

| Area | Target |
|---|---|
| Dashboard response | p95 < 1.5 s server time, any page, all filters |
| First meaningful paint | < 3 s on throttled 3G |
| JS budget | ≤ 300 KB gzipped first load; code-split per page; charts load last |
| Ingestion latency | ≤ 15 min from a submission on either source to the dashboard |
| Rescore | one round (~2,200 assessments) < 5 min |
| Export | 10,000-row CSV < 30 s, queued with progress |
| Volume | ~40,000 submissions, ~400,000 score rows over 5 years |
| Concurrency | 50 simultaneous administrators/known users; public traffic unknown — public pages served from cache, rate limited 120/min per IP, load-tested at 200 concurrent anonymous visitors |
| Availability | 99% monthly excl. maintenance window |
| Browsers | Chrome, Edge, Firefox, Safari — current + one prior |
| Responsive | usable from 360px; tables scroll horizontally |
| Accessibility | WCAG 2.1 AA (`ACCESSIBILITY.md`) |
| Localisation | English v1; all strings in language files |

How the 3G target is met: numbers arrive as Inertia props and render before hydration; heavy
blocks use Inertia 2 deferred props; charts are `React.lazy`; fonts self-hosted with
`font-display: swap`; tables paginate server-side at 50 rows.

---

## 11. Decisions, prototype deltas, open questions

### 11.1 Architecture decision records

| ID | Decision | Rationale |
|---|---|---|
| D-01 | Field-collection sources are the only way data enters, including history — **ODK Central and KoboToolbox** (amended by D-59) | One normaliser-parser-validation path; a second path is a second place validation can be bypassed |
| D-02 | One role: Administrator (2FA removed by D-84) | Saves ~1 week; audit trail becomes the control |
| D-03 | PostgreSQL 16 | jsonb + GIN, enforceable CHECKs, materialised views |
| D-04 | Unweighted aggregation (config flag) | Matches the live tool's published figures |
| D-05 | `assessment_scores` unique key includes `rule_version_id` | PRD requires old-version scores to remain readable after a rescore; the PRD's 3-column UQ would forbid that |
| D-06 | Build all **14** validation rules | PRD §19 says "thirteen"; §7 table lists 10 hard + 4 soft |
| D-07 | Round "published" = "closed" in v1 | PRD §5 mentions a publish state; §10.3 defines only open → closed |
| D-08 | Quarantine corrections stored as an overlay, not by editing `submissions.payload` | Payload must stay verbatim |
| D-09 | Fonts self-hosted | CSP and 3G performance |
| D-10 | Implementation plan owner/date/status editable, persisted in `plan_actions` | PRD §9.6; prototype was read-only |
| D-11 | Band classification server-side only | One source of truth; prototype's JS band logic is inconsistent |
| D-12 | Tests run on Postgres, never SQLite | Constraint and view behaviour must match production |
| D-13 | **Containerised with Docker**; one multi-stage `Dockerfile`, images promoted by digest; Docker Compose on a single host in staging/production; the same images in local dev and CI | Environment parity, one-command rollback, reproducible hosts, clean handover. Kubernetes rejected as disproportionate for 50 users (`DEPLOY.md` §2.2) |
| D-14 | cPanel/WHM hosting dropped as a fallback | Docker needs host control; cPanel's firewall and port ownership conflict with a container edge |
| D-15 | Caddy replaces Nginx as the web tier | Automatic TLS in a container, HTTP/3, precompressed Brotli for 3G, FastCGI retry during rolling restarts |
| D-16 | Laravel Sail rejected for local dev | Sail's image differs from production; `compose.dev.yml` uses our own `dev` target |
| D-17 | **React 19**, not the React 18 in the PRD | The official starter kit and Inertia 3 ship React 19 (19.3 at scaffold) with the React Compiler; staying on 18 would mean maintaining a fork of the kit. No PRD feature depends on the difference |
| D-18 | **Laravel 13 + Inertia 3**, not Laravel 12 + Inertia 2 | Laravel 12's security support ends ~Feb 2027, at go-live; 13 is supported to ~Mar 2028. The current kit targets 13; its last Laravel 12 version was frozen in Mar 2026. Knock-on: **Pest 4** (Pest 3 needs PHPUnit 11; Laravel 13 uses PHPUnit 12), and **ESLint 9** (`eslint-plugin-jsx-a11y` does not support ESLint 10 yet) |
| D-19 | Wayfinder generates typed route helpers (`resources/js/{actions,routes,wayfinder}`, git-ignored). *Not the facility master-list import: CLAUDE.md §3 and the Phase 5 prompts call that "D-19"; it is recorded as **D-91*** | Its Vite plugin runs `php artisan wayfinder:generate` at the start of every build. The Docker `assets` stage has no PHP (`DEPLOY.md` §6.2): generate the files in the `vendor` stage, copy them into `assets`, and point the plugin's `command` option at a no-op there |
| D-20 | **Superseded by D-84 (no MFA).** Starter kit trimmed to what one role with mandatory 2FA needs. *Not the audit log page: the Phase 5 prompts call that "D-20"; it is recorded as **D-93*** | Removed: registration, passkeys (a login path around TOTP 2FA), profile edit, delete account, appearance/dark mode, welcome and demo dashboard pages, Sail, `laravel/pao`, `vite-plus` (plain Vite + ESLint + Vitest instead). Kept: Fortify login, password reset, email verification, TOTP 2FA, password confirmation, settings → Security. Packages this adds to the stack: `laravel/fortify`, `laravel/wayfinder`, `larastan/larastan`, `pestphp/pest` + `-plugin-laravel` + `-plugin-arch`; npm: Radix UI primitives, `lucide-react`, `sonner`, `input-otp`, `clsx`/`tailwind-merge`/`class-variance-authority` (kit UI), `eslint` + `typescript-eslint`/`react`/`react-hooks`/`jsx-a11y` plugins, `vitest` |
| D-21 | **Node 24** (`node:24-bookworm-slim`) instead of Node 20 for the `assets` stage, the dev `vite` service and the `worker` image | Node 20 reached end of life in Apr 2026; Vite 8 needs ≥ 20.19. The `worker` copies Node and a pinned Puppeteer from the Node image rather than Debian's `nodejs` package (v18, also end of life). `/usr/bin/node` is symlinked so `BROWSERSHOT_NODE_BINARY` from `DEPLOY.md` §15.2 still works |
| D-22 | Assets precompressed at build with `vite-plugin-compression2` (`.br` + `.gz`) | `DEPLOY.md` §6.1/§10: Caddy serves them with `precompressed br gzip`; Brotli matters on 3G. New npm dev dependency |
| D-23 | Fonts from `@fontsource/archivo` (500/600/700) and `@fontsource/ibm-plex-sans` (400/500/600), bundled by Vite | CONVENTION.md §6 and D-09: self-hosted, so the CSP has no external host (SECURITY.md §8). Replaces the kit's build-time Bunny Fonts download. New npm dependencies |
| D-24 | **Superseded by D-84 (no MFA).** 2FA recovery codes stored as SHA-256 hashes and shown once, overriding Fortify's encrypted, re-displayable codes | SECURITY.md §2. Custom Fortify actions (enable, confirm, regenerate) and challenge request; Fortify's "show codes" endpoint returns 404. A fast hash suffices: each code carries ~119 bits of randomness. A used code is consumed, not replaced |
| D-25 | The starter kit's UI primitives (shadcn/Radix) and sidebar shell removed | Replaced by our own tokens and components (AppShell, Rail, TopBar, TextField, ErrorSummary). Removed npm packages: 13 `@radix-ui/*`, `class-variance-authority`, `clsx`, `tailwind-merge`, `input-otp`, `lucide-react`, `sonner`, `tw-animate-css`. Re-add a primitive only when a page needs it |
| D-26 | **Superseded by D-84 (no MFA).** 2FA requirement made switchable: `EDQA_REQUIRE_2FA` (config `edqa.auth.require_two_factor`) | Owner decision, 2026-10-03: no 2FA wanted for now. Amends D-02. Unset or empty means required (fail-safe, as `SESSION_SECURE_COOKIE`); the local `.env` sets false. When off, the `two-factor.confirmed` middleware passes everyone, 2FA can still be set up from Security settings, and an account that has it is still challenged. Tests force it on. New env key for `edqa-infra` (DEPLOY.md §1.3) Amended 2026-10-31 (Kobo credentials day, step 0): the switch works in `APP_ENV=local` only. Anywhere else `EDQA_REQUIRE_2FA=false` stops the app at boot with a clear error (`TwoFactorSwitch::assertAllowed`, as the `*_ALLOW_INSECURE_LOCAL` flags are refused outside local), and the middleware never skips the check there, whatever the config says |
| D-27 | `item_weights` keyed `"<dimension>.<item_code>"`, the same weight in every month slot; unlisted items weigh 1; weights must be positive. The calculator divides before multiplying (`passed / applicable × 100`) and sums items in item-code order | §7 gave no key format. Divide-first keeps a weighted all-pass slot at exactly 100 (× 100 first gave 100.00000000000001, caught by the 1,000-set property test); fixed summing order keeps the result independent of item order (G3). Rule version 1 has no weights, so no stored number changes |
| D-28 | A visit's round is decided by its visit date: `startedAt`, falling back to `submittedAt` when the start did not parse, as a local (Africa/Lagos) day. The date must fall inside exactly one round's window and that round must be open. When the form gives both `round_year` and `round_quarter` and they name a different round, `ROUND_WINDOW` quarantines; a form that gives neither (or only one) is placed by date alone | Owner decision, 2026-10-08 (answers Q-24). Never guess the nearest round (hard rule 12): no window, two overlapping windows, a closed round and a disagreeing form each quarantine with their own detail |
| D-29 | For `ASSESSOR_VOLUME` an assessor is the name (case and spacing ignored) plus the device ID when present. A visit with no device ID is matched to other no-device visits under that name only. Counted: distinct facilities per local day across accepted assessments, the batch's accepted submissions and the visit being checked. Soft-rule thresholds are never written into rule text: the "why" strings take `:points`, `:minutes`, `:maximum` from `edqa.validation.soft_rules` | Owner spec, 2026-10-08. Two people with the same name on different phones are counted apart; the same facility twice in a day counts once |
| D-30 | The Postgres session runs in the app time zone (`config/database.php` `timezone` = `APP_TIMEZONE`, Africa/Lagos), and `ProcessParsedSubmission` converts ODK's times to that zone before writing | Bug found in Phase 2A step 6: Laravel writes timestamps without an offset, and the session was UTC, so every stored time (every `now()`, every visit) was an hour off and a 23:30 visit landed on the next local day. Residual risk: any other code that writes a Carbon in a different zone through Eloquent or the query builder must convert it first. Dev data written before this fix is off by an hour: `make fresh` |
| D-31 | Quarantine resolution: a rejected record is `status = resolved, resolution = rejected` (the schema's CHECK has no `rejected` status); soft flags are overridden on the assessment (`POST /api/assessments/{id}/flags/{code}/override`), not through `/api/quarantine/{id}/resolve`, which takes `correct` or `reject`; bulk resolution needs every record to have the same set of hard-failure codes; corrections are snake_case keys of the parsed snapshot (`lga_ref`, `ward_ref`, `facility_ref`, `raw_start`, `raw_end`, `round_year`, `round_quarter`) and a corrected start or end is re-parsed. Each action writes one explicit audit entry (event, old, new, reason) and suppresses the model's own | Phase 2A step 8. A quarantined record never has soft flags to override: flags live on accepted assessments |
| D-32 | **Rule versions are pinned to rounds.** An open round uses the current published version; closing a round pins the version it was scored with (`rounds.rule_version_id`, CHECK `rounds_closed_has_rule_version`); a closed round is never rescored unless it is reopened (with a reason); `RuleVersionPublished` therefore asks for a rescore of **open** rounds only. Every read or write of a score goes through `RuleVersionForRound` (or, in SQL, the per-round version in `round_aggregates_v2.sql` and the SCORE_JUMP prior query), never "the highest published version" directly. One draft at a time (`scoring_rule_versions_one_draft`) | Owner decision, Phase 3 step 1 (requested as "D-18", which was already taken). Supersedes §7's "the dashboard reads the current version by default" for closed rounds. Without the per-round read, publishing v2 would drop every closed round out of the aggregates and blind SCORE_JUMP. Refined in Phase 3: `RescoreRound` (step 5) rescores open rounds after a publish and refuses a closed one; `CloseRound` (step 6) pins the version and is refused while any counted assessment lacks scores under it; `ReopenRound` returns the round to the current version and rescores it when the pin is no longer current (Q-29, D-35) |
| D-33 | **`round_aggregates` is a cache of the aggregators, reconciled to 1e-9.** v3 counts accepted/flagged assessments only (v2 counted every status, so a withdrawn visit inflated `facility_count`). Aggregators read the view for one round when the filter maps onto a view row, and their own SQL otherwise. The view is refreshed by a debounced `RefreshRoundAggregatesJob` (unique per round, 60 s), so a dashboard answer can lag a new score by up to about a minute, then the round cache flushes. `edqa.aggregation.read_view=false` turns the view path off. In tests the job is faked globally (`tests/Pest.php`), because on the sync queue it would rebuild the view on every score | Phase 3 step 4. The aggregators are the specification; the view is an optimisation that must agree with them |
| D-34 | **Rescore is chunked, idempotent and debounced.** `RescoreRound` refuses closed rounds (D-32); progress is kept in `rescore_runs` (the admin history; `job_batches` is the queue's own and is pruned). A chunk writes only rows whose value changes and audits each one (inserted or changed; Q-20 still open on volume). Score-based soft flags are recomputed by the same pure checks the rules use (`ScoreFlags`); an administrator's override, found by its `flag_overridden` audit entry, is permanent. Automatic triggers are collapsed per round by `RequestRescoreJob`; a manual request needs a reason. An assessment a new version can no longer score loses that version's rows instead of keeping stale ones. **Known limit:** after a publish each open round is rescored on its own, so a later open round's SCORE_JUMP may compare against an earlier open round not yet rescored under the new version (no prior score, so no flag); rescoring that round again settles it | Phase 3 step 5. Measured: 2,200 assessments in 4.0 s |
| D-35 | **Round lifecycle actions.** `CreateRound`, `UpdateRoundWindow`, `CloseRound`, `ReopenRound` each write one explicit audit entry (`created`, `window_updated`, `closed`, `reopened` with the reason) and suppress the model's own. Close pins the version and refreshes `round_aggregates` synchronously (the snapshot; the Round::saved hook that queued a refresh is gone) and is refused while any counted assessment lacks scores under that version, so a round is never pinned to scores it does not have. Window edits are refused on a closed round. Overlap is enforced in the actions under a table lock, not by an exclusion constraint, because older data and ROUND_WINDOW's ambiguity test rely on overlapping windows being representable | Phase 3 step 6 |
| D-36 | **The report cache unserializes only an allow-list.** Laravel 13 refuses to unserialize any class from the cache unless listed (`cache.serializable_classes`, against gadget chains). The list is `RoundCache::SERIALIZABLE`: the aggregators’ readonly DTOs and the enums inside them, never an Eloquent model (`RoundSelection` caches each round’s raw columns and hydrates them, no query). Found by `make fresh` on Redis: the array store the tests use never serializes, so `SerializingCacheTest` now runs every aggregator through the file store | Phase 3 step 7. Before this, every cached dashboard answer on Redis or the database store came back as `__PHP_Incomplete_Class` |
| D-37 | **Frontend test dependencies (dev only):** `@testing-library/react`, `@testing-library/dom`, `@testing-library/user-event`, `jsdom`, `vitest-axe` and `axe-core`. Vitest runs in jsdom; every component test finds elements by role or label and runs axe (`resources/js/test/render.tsx`). vitest-axe 0.1 types its matcher for an old Vitest, so `resources/js/test/vitest-axe.d.ts` declares it for Vitest 5. Components read translations from an `I18nProvider` the layouts fill from the shared Inertia prop, so they render in a test without an Inertia app. None of these ship in the bundle | Phase 4 step 0. ACCESSIBILITY.md asks for automated checks; axe-core is already what eslint-plugin-jsx-a11y depends on |
| D-38 | (`otplib` removed by D-84.) **End-to-end and budget harness.** Playwright (`@playwright/test` 1.64.1, `@axe-core/playwright`, `otplib`, all dev only) in its own compose service (profile `e2e`, image pinned to the same 1.64.1 by digest: bump both together), run by `make e2e` against the running dev stack. Inside the container a small TCP forwarder (`tests/e2e/support/forwarder.mjs`, its own process) makes localhost:8080 and :5173 reach `web` and `vite`, so the browser sees the developer's origins (APP_URL, CSP, the Vite dev server). It signs in as `E2eAdminSeeder`'s administrator (local and testing only; fixed password and TOTP secret, public in the repo, never in DatabaseSeeder). `checkA11y(page)` fails on serious or critical axe violations against WCAG 2.2 AA; viewports 360×740 and 1280×800. `npm run size` / `make size` sums the gzipped JS of the first visit to the public Home `/` (was /dashboard/home before Phase P; app entry, the page and their static imports) against 300 KB | Phase 4 step 1. First measure: 117.8 KB |
| D-39 | **Home (Phase 4 step 2).** `recharts` 3.10 added for the trend (in the §2 stack; loaded lazily, so outside the first-load budget). The trend uses `ReportFilter::withoutRound()`: every round, with the LGA, level and owner filters, and `trend.selected` marks the rounds the summaries cover (the prototype collapsed to one point). Its one-line takeaway is computed by `QuarterTrendAggregator::takeaway()` (a comparison of aggregates, so not in a component). `StateSummary` carries the rule version's `strongAt` and `weakBelow`, so no threshold is written in the UI. With an LGA chosen, `WeakestWardsAggregator` gives its wards. `ShareDashboardContext` compares queries as sets: Inertia reports page URLs with sorted keys, which a key-order redirect turned into a 302 on every deferred reload. Vite's watcher ignores `public/build`, `tests`, `database` and `docs`: polling them on the Windows bind mount kept it at ~70% CPU idle and slowed module requests to ~20 s | Phase 4 step 2 |
| D-40 | **LGA view (Phase 4 step 3).** The tile grid (`tiles`) leaves the LGA filter out, so another LGA can always be chosen; the chosen tile is pressed (`aria-pressed`) and toggles the filter in place (focus stays on it, the result is announced). The table (`lgas`) is in the filter's scope. Both come from `LgaTableAggregator`, driven by the lgas table, so never more than 23 rows whatever is in quarantine. The table sorts on the client (at most 23 rows of server figures; `lib/sortRows` only compares, no data last), default overall highest first as in the prototype. Month headings are the real months when the rounds in view share one quarter, else "Month 1/2/3" with a hidden "of the quarter" | Phase 4 step 3 |
| D-41 | **Facility view (Phase 4 step 4).** `@tanstack/react-table` pinned to ^8.21 (v9 changed the API; it is in the §2 stack) and used in manual mode only for the column model and sort state; rows arrive sorted and paged from `FacilityScoresQuery`. `?metric=`, `?sort=`, `?dir=`, `?page=` are read against whitelists (`Metric` enum, `FacilitySort::COLUMNS`, asc/desc): anything else falls back to the default, weakest first by the chosen measure, never into SQL. 50 rows per page, fixed; `per_page` is ignored. Measure, sort and page change through partial reloads (`only: ['facilities']`), so the shell, the filters and focus stay put and the new order is announced. The count says "facilities", not "assessments": a row is one facility's latest assessment in the rounds in view (Q-28). The facility name becomes the drawer button in step 4.5. Components using TanStack opt out of the React Compiler (`'use no memo'`) | Phase 4 step 4 |
| D-42 | **Facility detail drawer (Phase 4 step 5).** `GET /api/facilities/{id}` (`FacilityDetailAggregator`) takes `?round=` and the dashboard's `?year=&quarter=`: without a round it opens on the latest round in view the facility was assessed in (the round its table row shows), else the latest in view with no assessment, so a click and a shared link agree. Item results follow `ScoreCalculator`: not applicable, or `na` in the round's choice map, is N/A; else the mapped pass/fail. Item labels come from `lang/en/items.php`, empty until the form XML (Q-02); no label shows the code. The audit trail covers the facility's assessments and item responses, newest first, at most 100 entries; derived score rows are left out (9 entries per assessment for each rule version it is scored under, all noise), and IP and user agent never leave `activity_log`. The ODK instance ID and attachments come through `Ingestion\Queries\AssessmentSourceQuery`, so Reporting still never reads `submissions` (hard rule 6); it reads only the submission an accepted assessment points at. The deep link is `{ODK_CENTRAL_URL}/#/projects/{ODK_PROJECT_ID}/forms/{form_id}/submissions/{instance_id}` (config `edqa.odk.url`, `edqa.odk.project_id`, from the existing env keys), null while either is unset (Q-30). The drawer is ours, not a library: a portal with role=dialog, aria-modal, focus to the heading, Tab wrapped inside, Esc/Close/backdrop close, focus back to the opener (or the h1 for a shared link); full-screen under 760px. The open facility is `?facility={id}`, changed with `router.replace` (no request) | Phase 4 step 5 |
| D-43 | **Monitor (Phase 4 step 6).** `RankedBars` is an `<ol>`: each row's name and count are text, the bar, the repeated number and the state-mean line are `aria-hidden`, and the mean is said in words above the list ("State mean: 19.1 facilities visited per local government"). Bar lengths are relative to the longest row: layout, not a figure. `Coverage` gains `stateMeanDisplay` (1 dp, plain half-up: a mean count of facilities, not a score, so Q-09 does not apply), so no component rounds; `RoundCache::SCHEMA` 3. The prototype's assessment-window panel summarised across rounds (rounds in view, earliest and latest visit, total), which would mean counting and comparing in a component (hard rule 5); the table instead has one row per round from `AssessmentWindowAggregator`: round, window, first visit, last visit, assessments recorded. As built in Phase 3 it lists the rounds of `RoundSelection::series()`: with no year chosen that is every round, while the other blocks show the latest round | Phase 4 step 6 |
| D-44 | **Visuals (Phase 4 step 7).** `?owner_view=public|private` narrows both blocks through `ReportFilter::withOwner()` and leaves the global owner filter alone; while that filter is set the view follows it and the switch is `aria-disabled` (still focusable, so the reason linked by `aria-describedby` is reachable), with the reason in text. Anything else in `owner_view` reads as Overall. The month charts are one lazy `MonthGroupChart` shared by all three, so Recharts arrives once and stays out of the first load (it shares a chunk with `TrendChart`); no tooltip, a text legend in the fixed series order (`lib/series.ts`, apart from the chart so the legend does not pull Recharts in), and each Figure's caption is a takeaway from `MonthSlotAggregator::takeaways()` (latest quarter's overall for the slot, change on the quarter before, lowest dimension: comparisons of aggregates, so on the server). `MonthSlotPoint` gains `chartLabel` ("April (Q2 2026)"); `RoundCache::SCHEMA` 4. The four LGA rankings reuse `RankedBars`, now drawing each bar as a small SVG (viewBox 0–100, `preserveAspectRatio="none"`, height in rem inside an HTML row, so it grows with zoom and is never clipped) coloured by band, with the score and band printed by a ScoreTag; highest first as in the prototype, no data last (`lib/sortRows`, ordering only) | Phase 4 step 7 |
| D-45 | **Implementation plan, read-only (Phase 4 step 8).** `ImplementationPlanAggregator` works from `LgaTableAggregator`'s rows (the LGA view's figures) for the plan's round, the latest in view. An LGA whose overall is below the lower bound of the band in `edqa.plan.action_below` (default `acceptable`; the bound read from the rule version, compared on the displayed value as `Bands::classify` does, so a row never shows "Acceptable" with an action) gets the standard action for its weakest dimension; a tie goes to the first in alphabetical order (availability, consistency, validity), compared on full precision. At or above the bound: "No action needed"; no score in view: neither. Stored `plan_actions` rows for the round replace the generated row for that LGA, every stored dimension listed, even for an LGA now above the bound, marked "Saved plan" and reviewed on their stored date; generated rows review "After {round}". Action texts and meanings are in `lang/en/plan.php`; default owners are role keys in `edqa.plan.default_owners`, labelled there (hard rule 10). "Actions by type" counts the rows in the aggregator. Not cached: stored rows change outside the round cache. The prototype gave every LGA an action; the threshold is the Phase 4 brief's. Editing, statuses and the Excel workplan: Phase 6 | Phase 4 step 8 |
| D-46 | **Server performance (Phase 4 step 9).** `edqa:seed:perf` (local and testing only; `Database\Seeders\PerformanceSeeder`) fills every quarter of five years ending at the latest round with one scored assessment per active facility (~44,000), in set-based SQL, keeping what exists. Its scores are derived from the generated items in SQL with ScoreCalculator's formula, so it refuses a weighted or non-standard rule version (use `edqa:rescore`), and `SeedPerformanceDataTest` re-scores every assessment with the real calculator to prove they agree; bulk SQL bypasses the audit log (synthetic local data). `ServerTimingTest` (slow group) times every dashboard page's first response and deferred reload, and the GET API endpoints, over ten filter combinations each on that dataset: in-process, warm classes, cold round cache. Before: every p95 within 1.5 s, the Visuals deferred load at 1,263 ms (the month series recomputed every round from 391k score rows; sorts spilled to disk). Fix: `round_aggregates` v4 adds `{dimension}_m{slot}` (A8), reconciled with `MonthSlotAggregator`'s SQL to 1e-9, and the month series reads the view for any number of rounds: 48 ms. Raising `work_mem` gained ~20% and was not adopted (a role setting is a server-contract change for little). Report: `storage/app/perf/server-timing.md` | Phase 4 step 9 |
| D-47 | **Client performance measurement (Phase 4 step 9).** `lighthouse` 13.5 and `chrome-launcher` 1.2 (dev only), run by `make lighthouse` in the playwright service with its Chromium against a production build: Vite stopped and `public/hot` removed, PHP as in production for the run (`opcache.validate_timestamps=0`, config and routes cached; the dev setting re-checks every file on the Windows bind mount, ~6 s a request, which Lighthouse would charge to the page), then restored. Slow 3G is simulated at 400 ms RTT, 400 Kbps, CPU ×4, 360 × 740; median of three runs. Report: `storage/app/perf/lighthouse.md`. Charts are shown to load after numbers in `tests/e2e/audit.spec.ts` (page clock: figures paint before any chart; the chart code is fetched only after the data) | Phase 4 step 9 |
| D-48 | **ODK parser, field maps and fixtures (Phase 2B step 1).** `App\Domain\Ingestion\Odk\FieldMap` merges an additive version over the default (`array_replace`, the version's own `renames`); an unknown, blank or near-miss version is null, never the default. Paths are dot paths through the OData entry's nested groups; `__id`, `__system` and `meta` are ODK's own and read directly. `renames` are [this version's name => the default's]. `SubmissionParser::parse()` never throws on bad data (a 500-payload property test): an unknown version gives minimal fields (identity, version, submission time, device); a time is read only when strict ISO 8601 with in-range parts (a lenient parse reads "FEBRUARY" as a date and hides COLUMN_DRIFT), with `rawStart`/`rawEnd` keeping the text; an unreadable submission time becomes 1970-01-01 UTC, which ROUND_WINDOW holds; scored questions are found by the pattern after renames, wherever their group; answers map through the published rule version's `choice_map` (Q-25), and the field map no longer has `choices`; an unknown answer is `invalid` (`ItemResponseData::INVALID`, not applicable), and ITEMS_INCOMPLETE quarantines **any** invalid answer, since one among valid answers would otherwise score as a fail; a null answer is skipped (missing); a number or numeric text in a scored question is a typed score, kept in `rawNumericScores` keyed by question name and never an item (Q-16). Fixtures: Phase 2A built its cases in code, so its JSON fixtures were created here: `tests/Fixtures/{odk,parsed}/{valid,broken}`, 23 cases, written by `generate.mjs` from a spec and never by the parser, run against `Tests\Support\FixtureWorld`. Every map path is `CONTRACT-ASSUMED`. Checked against the ODK Central OData docs: geopoints are GeoJSON (lon, lat, alt) and Central omits GPS accuracy (read only if present); the docs name only some `__system` keys (submitterId, submissionDate, updatedAt, reviewState), so `deviceId` and `formVersion` under `__system` are assumed from Central's `$metadata` until checked on a live server | Phase 2B step 1. The real maps wait for the form XML (§11.5) |
| D-49 | **ODK Central client (Phase 2B step 2).** Ingestion talks to a `SubmissionSource` contract (`Ingestion\Contracts`): `ping()`, `formVersions()`, `submissionsPage(since, top, skip)` → `SubmissionPage {rows, totalCount, hasMore}`, `attachmentList()`, `downloadAttachment()` to a sink. `OdkCentralSource` implements it over `OdkCentralClient`; an ODK Aggregate adapter (Q-01) would implement the same. Settings: `config/edqa.php` `odk` (`url`, `email`, `password`, `project_id`, `form_id`, `page_size`, `timeouts`, `retry`, `token_refresh_minutes`), read from env there only, through `OdkConfig`; any of the five missing, or a URL that is not https, is "not configured": nothing is sent and every method throws `OdkNotConfigured` naming the env keys (never values), and `edqa:odk:check` says so. The drawer's link reads `edqa.odk.url` (was `base_url`). The session token (`POST /v1/sessions`) is cached **encrypted** (`Crypt`) until 30 min before it expires; a 401 drops it, makes one new session and retries once, a second 401 is `OdkAuthFailed`, as is a refused sign-in or a 403 (no Project Viewer role). TLS verified, connect 10 s, 60 s a request. Retries (3 attempts) only on 429, 5xx and connection errors, waiting 1 s × 2^n or the server's `Retry-After`, **capped at 30 s** a wait (the job's own backoff, §5.3, handles longer outages); other 4xx are not retried. Waits go through `Illuminate\Support\Sleep`, so tests fake the clock. Exceptions: `OdkNotConfigured`, `OdkAuthFailed`, `OdkUnreachable`, `OdkUnexpectedResponse`, each with the HTTP status and a `status()` kind, never a secret. Query values are encoded with RFC 3986 (`$filter=__system/submissionDate gt 2026-04-14T08:50:00.250Z`, UTC with milliseconds). Logs on the `edqa` channel: method, endpoint with IDs replaced by placeholders, status, attempt, time, retries; never the password, token or a payload (asserted). `edqa:odk:check [--json]` signs in, reads the project and form, and lists every published version with whether it has a field map | Phase 2B step 2. No network in tests (`Http::fake`, stray requests refused) |
| D-50 | **Mock ODK Central (Phase 2B step 3).** `docker/odk-mock/`: PHP's built-in server on the dev PHP image (no new image), `router.php` over a pure `odk_mock_handle()` so tests drive the real `OdkCentralSource` through it with `Http::fake`. It serves only what the source calls: sessions, current user, project, form, versions, the OData feed (`$filter` on `__system/submissionDate` or `updatedAt`, `$top`, `$skip`, `$count`), the attachment list and download. Read-only; wrong credentials 401. Data: JSON in `storage/app/odk-mock/` (`edqa.odk.mock_path`), written by `edqa:odk-mock:generate {--count} {--broken} {--round} {--form-version} {--seed}` (`--form-version` because the console reserves `--version`) through the version's field map, for active facilities with no visit in the round (so no accidental duplicates), at most four visits per assessor a day; a mix of broken (ten kinds, one each in turn), flagged, edited and valid visits and two generated JPEGs; seeded. **Edits follow Central:** one feed row per `__id`, its latest version; an edit keeps `__id` and `submissionDate`, takes a new `meta.instanceID` with `meta.deprecatedID`, sets `updatedAt` and `edits`; staged edits appear after `--apply-edits` (Q-32). Compose service `odk-mock` (profile `odk`, `127.0.0.1:8090`), `make odk-mock-up`, `make odk-mock-seed n=200`. **New env key** `ODK_ALLOW_INSECURE_LOCAL`: an http `ODK_CENTRAL_URL` is accepted only with it true **and** `APP_ENV=local`; set in any other environment it is itself a configuration problem, so ODK is "not configured" there (server contract: must be absent in staging and production) | Phase 2B step 3 |
| D-51 | **The pull (Phase 2B step 4).** `PullSubmissions` (the orchestrator) under `PullLock`, run by `PullSubmissionsJob` (queue `default`, 3 tries, backoff 30/120/600 s, timeout 900 s) and by `edqa:pull`. Two passes with their own cursors: `submissionDate ge last_pulled_at` then `updatedAt ge last_edit_at` (new column), each `$orderby` its field (Q-32: replaces the single OR-filter cursor proposed there, which could skip an edit after a crash because the feed cannot be ordered by "the newer of two fields"). Inclusive cursor + instance-ID de-dup within the run; a page of one timestamp steps on with `$skip`. One transaction a page, cursor last; raw insert `ON CONFLICT (instance_id) DO NOTHING RETURNING`; new rows parsed **from the stored payload** (jsonb reorders keys, so every later reparse sees the same order). Run rows gain `flagged` and a CHECKed `outcome` (`succeeded`, `failed`, `skipped_not_configured`); each attempt is its own row; the final failure sets `odk_form_syncs.last_error` and raises `PullFailed`. Heartbeat GET on success only (`edqa.heartbeats.odk_pull` from the existing `HEARTBEAT_ODK_PULL_URL`). `edqa:pull --dry-run` writes nothing. `CorrectAndReprocess` now rebuilds from `submissions.payload` through the parser plus corrections; the parsed snapshot stays for audit only (both agree for every broken fixture, tested). **Server contract:** the queue's `retry_after` must exceed 900 s: `config/queue.php` now defaults it to 960 (was 90) for Redis and database; `REDIS_QUEUE_RETRY_AFTER`, if set on a server, must stay above 900 | Phase 2B step 4 |
| D-52 | **Attachments (Phase 2B step 5).** Recorded, fetched, served. The OData feed does not name files, so the pull calls `attachmentList(__id)` for each new row expecting some, outside the page transaction (no HTTP while it holds locks), and inserts `pending` rows with the submissions (`RecordAttachments`, `insertOrIgnore` on the new unique key); `FetchAttachmentsJob` (`low`) is dispatched after commit. `FetchAttachments` downloads to `odk/.incoming/{uuid}`, then checks size (`edqa.odk.attachments.max_bytes`, 10 MB, config only: no env key) and type, sniffed with `finfo` against `edqa.odk.attachments.allowed_mimes` (SECURITY.md §7). Text types are told apart by extension among the sniffed text types, and GeoJSON must parse as GeoJSON. Rejected files are deleted, their row `rejected` with the reason (`unsafe_name`, `too_large`, `type_not_allowed`); ODK errors keep the row pending with the error kind and rethrow, so the job retries only what is missing; after the last try, `failed`. **Names are refused, not sanitised:** a name must be a plain file name (`[A-Za-z0-9][A-Za-z0-9._ ()-]{0,199}`, no `..`), so the stored name is ODK's own and the route can match it exactly (NUL and invalid UTF-8 are first made U+FFFD, since Postgres would cut the name at a NUL and make it look safe). Disk `odk` (`storage/app/odk`, `serve: false`). `GET /attachments/{submission}/{filename}` in the auth group (throttle 120/min): `AttachmentFileQuery` finds the fetched row for that submission and exact name; it serves the stored path, never one built from the request; 404 otherwise. `Content-Type` is the sniffed type, `X-Content-Type-Options: nosniff`, `Cache-Control: private, max-age=3600`, inline for images and attachment otherwise; every view is audited (`viewed`, the administrator, the file). Attachment rows are raw input, like submissions: not audited. The drawer's attachment props gain `reason` and `url` (fetched only). Settles Q-18 for attachments | Phase 2B step 5 |
| D-53 | **Backfill (Phase 2B step 6).** One page path: the pull's page code moved into `ProcessSubmissionsPage` (attachment list before the transaction; insert, parse, supersede, batch, attachments, counters, sync row inside), used by `PullSubmissions` and `BackfillSubmissions`. The sync row is now updated atomically in SQL, since a pull and a backfill may both write it: `submission_count + n`, `last_pulled_at`/`last_edit_at` = `GREATEST(COALESCE(col, v), v)` (forward only, now with microseconds), `backfill_skip` as given. The backfill reads the feed by `$skip` from `backfill_skip` (or `--from-page` × page size), ordered by `submissionDate` with no filter; new submissions append at the end, so positions are stable (a submission deleted in Central would shift them: Central does not delete, Q-33). It refuses to start until a round before the current quarter exists (`BackfillRefused`). Concurrency: `BackfillLock` (`odk-backfill`, non-blocking) for one backfill; each page commits under `PullLock` (waits up to `lock_wait_seconds`). Trigger `backfill` added to `odk_pull_runs`' CHECK; `ShellStatusAggregator` reads only non-backfill runs. Throttle `ODK_BACKFILL_THROTTLE` (already in `.env.example`; empty = 5, `0` = none). `--survey` and `--dry-run` write nothing; the dry run is now page by page (whole history in memory otherwise), so a duplicate split across pages is caught only by the real run. `edqa:backfill-report {--format=md|csv}` writes to the private `exports` disk (`storage/app/exports`): per round (each current submission once: an assessment's round, else its latest quarantine record's; superseded counted apart) with a total row from SQL (`GROUPING SETS`), quarantined by rule × visit year and rule × form version (open records, once per hard rule), and the three most frequent details per rule. `BackfillReportQuery` counts raw submissions and quarantine, so like `QuarantineQuery` it is allowed SQL aggregates outside Reporting (architecture test, by name). An unknown form version is placed by its submission date for the per-round table (its start cannot be read). CSV cells starting `= + - @` are prefixed with `'`. The mock generator gains `--quarters=2024Q1-2025Q2 --older-quarters= --older-version=` (unsaved rounds for quarters without one, so the survey comes first); its "outside every round" defect now lands three years before the window | Phase 2B step 6 |
| D-54 | **Webhook, Pull now, ODK status, cascade (Phase 2B step 7).** Webhook: off (404) without `ODK_WEBHOOK_SECRET` (already in `.env.example`); `X-Edqa-Timestamp` within 300 s and `X-Edqa-Signature` = `sha256=` + HMAC-SHA256 of `"{timestamp}.{raw body}"` (`hash_equals`). The timestamp is inside the signed string, not only beside it, so a captured request cannot be replayed with a fresh timestamp (SECURITY.md §6 says "over the raw body": this is that, plus the timestamp). Accepted → only `PullSubmissionsJob(Webhook)`, debounced to one a minute; the body is never read. Pull now: `StartManualPull` creates the run row at request time (so `run_id` comes back at once) under a short request lock, or returns the running pull's run (an open, non-backfill run younger than the pull lock); `PullSubmissionsJob` carries a `runId` and closes it in `failed()` if it never ran. Named limiter `odk-pull` (6/min per administrator, its own key: two `throttle:` middlewares on one route share a key and would count each request twice). Status: `ShellStatusAggregator` gains `not_configured` (neutral; the settings are missing), keeps `not_connected` for configured-but-never-pulled, ignores skipped runs, and returns `lastErrorClass`; the bar's failed text gives the time, the error class and the last success. `GET /api/odk/status` composes `OdkStatusQuery` (configured, problems, reachable via a cached `ping`, cursors, running run), the aggregator's sync and open-round counts, and `QuarantineQuery::openCountsByRound`. Cascade: `CascadeExporter` (three CSVs zipped, active facilities via the partial index, codes as names, sorted by label, formula-safe) to `exports/cascade/`; `POST /api/facility-list/export-cascade` → 201 with a `temporarySignedRoute` (30 min); the download route needs the auth group **and** `signed`, accepts only `odk-cascade-*.zip` names. Both audited; `AuditLog::record` takes a null subject for actions on no row | Phase 2B step 7 |
| D-55 | **Admin shell (Phase 5 step 0).** Five admin routes under `admin.*` (Inertia pages, placeholders until built); `/admin` redirects to `/admin/odk`; the Audit log tab is not shown until that page exists. Admin pages share `admin` (only on `admin.*`, so dashboard requests do not compute it) and the `admin` lang group. **Open-quarantine count cached** in `QuarantineQuery` (the one file allowed to count quarantine): `Cache::remember` 60 s, forgotten by `FlushOpenQuarantineCount` on `SubmissionQuarantined` and `QuarantineResolved` (both after commit). The shell's count reads the same cache. **Stale banner only when ODK is configured**: when it is not, nothing is being pulled at all and the status bar's `not_configured` already says so; a banner on top would say the same thing louder in every dev and demo environment. **ConfirmWithReasonDialog** is a native `<dialog>` (`showModal`, so the page behind is inert in browsers) with its own Tab wrap and Esc handling as well (consistent everywhere; jsdom has no `showModal`), the consequence as its description, the hint and any field error on the textarea's `aria-describedby`, the confirm button disabled until the trimmed reason has 10 characters (the server's `Reason::require` rule), failures in `role=alert`, focus back to the trigger. **ProgressPanel** polls with 2, 3, 5, 8, then 10 s, back to 2 s after a stage change, stops at a terminal stage, and announces through the page's one live region only when the stage changes; no `aria-busy`, which would make a screen reader hold the panel's content back for the whole job. **useApiAction** sends through `sendJson` (the `XSRF-TOKEN` cookie as `X-XSRF-TOKEN`), sets `pending` before the request leaves and refuses a second call while it is in flight, and turns 422/409/419/401/403/429 into words; 409 uses the domain's `{ code, message }` message, already from the lang files on the server. `Pagination` takes optional labels so `AdminTable` can name its rows. ACCESSIBILITY.md is still not in the repo: WCAG 2.2 AA and CONVENTION.md §6 applied | Phase 5 step 0 |
| D-56 | **ODK pull page (Phase 5 step 1).** `AdminOdkController` (Inertia `admin/odk`) sends `status` (`OdkStatusPayload`, the exact body of `GET /api/odk/status`, which now uses it too), `backfill` (`OdkStatusQuery::backfill()`: last backfill run, checkpoint as position and 0-based page, newest `backfill-report-*` on the exports disk with a 30-minute signed link to `GET /api/odk/backfill-report/{file}`, signed + auth group, audited, only report names served), `history` (`PullHistoryQuery`: 25 a page, newest first, `?trigger=` and `?outcome=` including `running`, validated by `PullHistoryRequest`) and `docs` links (`edqa.docs.base_url`, config only). **New counter `odk_pull_runs.parsed`** (CHECK ≥ 0): the rows a run stored and parsed, incremented by `ProcessSubmissionsPage`, so the progress shows fetched → parsed → accepted → quarantined without arithmetic in a resource or the browser. **Words computed on the server:** `sync.sinceSuccess` is the time since the last success in its largest whole unit (minutes under an hour, hours under two days, else days); `sync.lastErrorHttpStatus` is the status the ODK exceptions write into their message (`HTTP 503`); a history row's duration comes as minutes and seconds. The client only lays these out (the client architecture test forbids rounding). Pull now: `useApiAction` → `POST /api/odk/pull`, `ProgressPanel` on `GET /api/odk/pull/{run}`, or the run in `status.running_run_id`; at the end the page reloads `status`, `history`, `backfill`, `admin` and `shell` only. **`make e2e-odk`**: seeds the mock with a fresh seed, recreates `app` and `worker` with `compose.e2e-odk.yml` (the mock's own credentials, http allowed in local only), runs `tests/e2e/odk-pull.spec.ts` with `E2E_ODK=1`, then recreates them without it; `.env` is never touched | Phase 5 step 1 |
| D-57 | **Data issues page (Phase 5 step 2).** `DataIssuesPageController` (Inertia `admin/data-issues`): the rule summary (every code with its severity, name, why it exists and open count; a toggle filters by it), all filters in the URL (`tab` hard/soft, `state` open/resolved/rejected/superseded, `rule`, `lga`, `round`, `source`), 25 rows a page. Hard tab: quarantined records, Correct & reprocess (only `CorrectAndReprocess::CORRECTABLE` fields, each with what was sent; the facility picked from the LGA's active facilities, `GET /api/facility-options`), Reject, and bulk reject or bulk correct of one field **only when every selected record fails the same single rule**. Soft tab: flagged accepted assessments, Override per flag. Every resolution takes a reason (`ConfirmWithReasonDialog`), says its outcome inline and links the round's queued rescore; `GET /api/quarantine/{record}` gives the detail drawer (parsed values, raw payload read-only, history) | One page for both kinds of issue keeps the administrator's queue in one place. Bulk only within one rule, because one correction applied to records failing different rules would fix some and silently mis-correct others |
| D-58 | **Assessment corrections from the facility drawer (Phase 5 step 3).** `CorrectAssessment` (`POST /api/assessments/{id}/corrections`): while the round is open an administrator corrects item results (pass / fail / N/A) and the visit's start and end, with a required reason. Scores are never typed: the assessment is scored again at once through `ScoreCalculator` (the same code a rescore uses), the soft rules run again (an overridden flag is never raised again), the round's rescore is queued, and each changed value is audited with its old and new value. Refused, in words, for a closed round (D-32), an assessment not accepted, nothing to change, an end before its start, a start moved out of the round's window, or a correction that leaves a dimension with nothing to score. All or nothing, the assessment locked | SECURITY.md §4 item 5. A wrong answer found after acceptance is fixed where it was found, and the audit trail, not an edited score, is the control (D-02) |
| D-59 | **KoboToolbox added as a second source** alongside ODK Central. Both implement `SubmissionSource` + `PayloadNormalizer`; everything after normalisation is shared. Change request against PRD §4 ("any source other than ODK" was out of scope) | Client collects on both platforms. One pipeline keeps the "single gate" guarantee |
| D-60 | `instance_id` unique across sources; a repeat from the other source is counted as `cross_source_duplicate`, not inserted | The same submission copied between servers must not count twice |
| D-61 | Edits detected by deprecatedID **or** same `external_id` with a new instance ID; incremental pulls use last-modified filters where the API allows, plus a nightly reconcile sweep | Neither platform changes the submission date on edit, so date-only cursors miss edits |
| D-62 | Deletions at source are flagged for an administrator, never applied automatically | Removing a published assessment is a decision, not a sync |
| D-63 | Field maps are named and referenced by (source, version) | The same XLSForm on two platforms carries different version labels but one map |
| D-64 | Kobo webhook verified by a shared token in a custom header, not HMAC | Kobo REST Services can't sign requests; the webhook only enqueues a pull anyway |
| D-65 | **The KoboToolbox form is read as it is.** The client confirmed (2026-10-31) that the asset `aWHonAjWVohv43zzdrvrRt` ("Kaduna State eDQA") is the real DQA form. It does not meet the §5.1 contract: other names; yes/no, 0–3 count and three-number items; and the review period chosen by the assessor. The portal reads it through its field map instead of asking for a rewrite. The map gains **item rules** (D-66–D-68), so the parser turns each kind of answer into pass / fail / N/A. Only two small form changes are asked of the client (D-71, D-72). Owner decision, taken by the developer on the owner's instruction. Amends §5.1 for this source | The form is in use, with 2,118 scored items. Renaming them to the contract would be a large, error-prone rewrite and would split its history. Reading it keeps one scoring engine: items in, pass / fail / N/A out, scores computed only by `ScoreCalculator` (hard rule 2) |
| D-66 | **Availability items (Kobo):** `yes` = pass, `no` = fail. An item left unanswered, or one whose service the facility does not offer (`services/*` = `no`), is N/A | Client answer (Q3), and the form's own rule: it counts an item only when it is answered and the service is offered |
| D-67 | **Consistency items (Kobo):** an answer *k* (0–3, "how many of the three clients were transcribed correctly") counts as *k* passed out of 3 checks. An unanswered item is N/A | Owner decision, taken by the developer on the owner's instruction. With 3 checks per item, the portal's items-passed ratio is exactly the form's points ÷ maximum. **Deliberate difference from the form:** the form scores an unanswered item 1.5 of 1.5, a full pass. The portal never gives credit without a response (hard rule 2), so a facility's portal score can be lower than the form shows |
| D-68 | **Validity items (Kobo):** one item per register and month, from three numbers (DHIS, MSF, register). All three entered and equal = pass. Not all three entered, or any two different = fail. None entered = N/A. Exact equality, no tolerance | Owner decision, taken by the developer on the owner's instruction. It is the form's own rule (10 points when all three match, counted only when one of them is entered). A tolerance would be a new rule with no source |
| D-69 | **No item weights.** Within each dimension the form weights every item the same (2 points for availability, 1 per check for consistency, 10 for validity), so the unweighted ratio (D-04) gives the same percentage. The dimensions combine as §7 says. **Known difference:** the form pools all of a dimension's months into one ratio, while the portal scores each month slot and averages them (§7). They differ when the months have different numbers of applicable items. The portal method stays, because it is the one checked against the published figures (build step 7) | Owner decision, taken by the developer on the owner's instruction. One scoring method for both sources; the difference is reported, not hidden |
| D-70 | **Monthly and quarterly DQA both count.**<br>• A quarterly visit fills month slots 1–3 (each item's month comes from the note before it in the form).<br>• A monthly visit fills the slot of its `review_month`.<br>• Monthly visits to one facility for different months of one quarter make up that round's single assessment.<br>• A visit for a slot that is already filled is quarantined as `DUPLICATE_ASSESSMENT`.<br>Implementing this changes how an assessment is built from submissions; it is its own step | Client answer (Q7: both). The round keeps one assessment per facility (§4 unique key); the slots are what the visits fill |
| D-71 | **Round fields (Kobo):** the client agreed (Q8) to make `round_year` and `round_quarter` calculated, read-only fields of the form: the year from `review_year`, the quarter from `review_quarter` (q1–q4 as 1–4) or from `review_month`'s quarter. Until that form is deployed, the field map derives them in the same way. `ROUND_WINDOW` still checks the visit date against the round's window | Client answer (Q8). The reviewed period, not the visit date, says which round a visit belongs to; the window check catches a period that cannot be right |
| D-72 | **Facilities (Kobo):** the form picks the facility from its own list of 1,070 slugs, not the master list's codes. Each slug is matched to a facility code by `facility_reconcile.py`, signed off by the client and loaded as reference data (as D-19). A slug with no match quarantines as `FACILITY_UNKNOWN`. The client is asked to switch the three questions to `select_one_from_file`, using the portal's CSV media file (D-54); after that the map reads the codes directly and the slug table goes | Owner decision, taken by the developer on the owner's instruction. Never guess a facility. The switch removes a second list to maintain |
| D-73 | **Action plans recorded in the form** (`follow_up/*`: the plan, the responsible person, the due date, for each dimension) are read into the implementation plan (`plan_actions`, D-10) as actions from the source, beside the ones the portal generates | Client answer (Q10) |
| D-74 | **Community visits** (`field_visit_type` = `community_visit`) **and DDU at LGA level** (`ddu_dqa` = `ddu`) are not facility assessments. They are stored, never scored and never quarantined, and counted apart in each pull run and the backfill report | Owner decision, taken by the developer on the owner's instruction. They are valid submissions of another kind. Quarantining them would put them in front of an administrator for no action; scoring them would mix them into facility figures |
| D-75 | **Duration filter: a quarter or one month.** The Quarter filter becomes **Duration**: all quarters, Q1–Q4, or one month of a quarter with a round (`?month=1–12`, which implies its quarter; a link carries the month or the quarter, never both). A month switches every figure on every page to that month slot: each facility's dimension is its month score, and its overall is the mean of that month's dimensions that have a score (the `m{slot}` of `FacilityScoresSql`). Facility means then aggregate as usual (§7). `round_aggregates` holds quarters, so a month always reads from the scores. The quarter trend and the month-slot series stay quarterly. Also: Home and Visuals break the quarter in view down month by month (each month's three dimensions and overall); Visuals has a quarterly view (every round's three dimensions and overall); Monitor shows the overall of all facilities, public and private together, beside the two, narrowed by the Owner filter | Client request (2026-11-02); the owner chose each option. Every figure still comes from the item responses through the one scoring path. The month's overall follows §5's rule (mean of the dimensions) applied to one month |
| D-76 | **Public read-only dashboard; admin area separate under `/admin`.** Change request against the PRD (public dashboard was v2). Still one role | Client decision; dashboard is a public accountability tool |
| D-77 | Public data is closed rounds only, via a `PublicScope` inside Reporting; config switch for the open round | Published figures don't shift in public while data issues are resolved |
| D-78 | No login link on the public site; `/login` reached by URL only; `/admin/*` redirects guests to `/login`; after login → `/admin`, after logout → public dashboard | Client decision; hiding is convenience, not a control |
| D-79 | Implementation plan public read-only; owners stored and shown as roles/units, not personal names. **As built (Phase P step 4):** `plan_actions.assigned_to` became `owner_role` (CHECK, one of `edqa.plan.roles`); a name found in the old column moved to `owner_contact`, admin-only, and the row took the dimension's default role | Accountability without exposing individuals |
| D-80 | Exports admin-only | Client decision |
| D-81 | Public status shows only "Figures last updated {date}"; source health is admin-only. **As built (Phase P step 4):** for the public the date is the later of the latest round close and the latest score computed for a closed round (a rescore after a reopen and close); in the admin preview, the latest score computed (`FiguresUpdatedQuery`) | Source errors are operational detail, not public information |
| D-82 | `noindex` everywhere until the client decides | Avoid search exposure before sign-off |
| D-83 | Separate public resources + a leak test over every public route | The main new risk is private fields leaking through shared resources |
| D-84 | **No multi-factor authentication anywhere** (client decision). Compensating controls: stronger passwords, progressive lockout, new-device login emails, optional IP allow-list, shorter idle timeout (SECURITY.md §2) | Client decision; the remaining controls are documented so the residual risk is visible |
| D-85 | **Admin shell, extended (Phase 5 step 0, after Phase P).** Amends D-55. The rail adds **Audit log** (`/admin/audit`, a placeholder until built: D-55 had held the tab back) and **My account** (the existing password page), and takes **Log out**; the top-bar account menu goes, so there is one place for each. **`useApiAction`**: a 401 or 419 is a full page load of `/login?expired=1`, and the sign-in page then says "Your session has expired" (a fixed line from `lang/en/auth.php`; the query value is never echoed). A 403 stays on the page as a refusal. **`PublicImpactNote`**: one line ("Public dashboard: this changes what the public dashboard shows."), its words carrying it and the mark decorative, shown by `ConfirmWithReasonDialog` with `publicImpact` and read in the dialog's description after the consequence. No current dialog sets it: corrections, withdrawals and replacements are refused in a closed round (D-32), so they never change public figures. Closing and reopening a round and publishing a rule version will set it when those pages are built. The badge and the stale-ingestion banner stand as built: the badge counts open quarantine plus unreviewed deletions, flushed on `SubmissionQuarantined`, `QuarantineResolved`, `SourceDeletionDetected` and `DeletionReviewed`; the banner names every enabled source with no successful pull in 24 h while a round is open, whether ODK Central or KoboToolbox | Prompt for Phase 5 step 0 after the public split; one shell (D-76) |
| D-86 | **No email verification.** Amends D-20, which kept it. Fortify's `emailVerification` feature, `MustVerifyEmail` on `User`, the verify-email page and its three routes are removed. `edqa:admin:create` makes accounts verified and no route ever required it, so the flow could not run; it only added signed routes to the surface. `users.email_verified_at` stays (no migration) | Dead starter-kit code is attack surface; `RouteGuardTest` asserts the routes are gone |
| D-87 | **ODK-only commands retired** (after 0.7.0, as Phase 2C announced). `edqa:odk:check` is gone for `edqa:source:check odk`; `edqa:odk-mock:generate` and `make odk-mock-up` / `odk-mock-seed` are gone for `edqa:source-mock:generate odk` and `make sources-mock-up` / `sources-mock-seed`, which take over `--quarters`, `--older-quarters` and `--older-version` (ODK only; Kobo refuses `--quarters`). The `odk` compose profile goes (`sources` starts both mocks). `/admin/odk` no longer redirects: production never served it | One command per job across both sources; no aliases left to maintain |
| D-88 | **Pre-go-live trim.** `laravel/pail` (dev) removed: a starter-kit log viewer nothing used, as the containers log to stderr. `edqa:attachments:relocate` and the old `odk` disk removed: the Phase 2C one-off moved attachments to `storage/app/attachments/{source}/{instance}/`, and production has only ever had that layout | Fewer packages and no one-off migration code shipped to a server that never needed it |
| D-89 | **Clean redesign** (the clean-ui-design review, owner-approved before code). Amends D-23 and CONVENTION.md §6. **Kept:** the dark rail and the dark public header (owner's choice), the chart palette, every page, control and accessible pattern. **Changed:** a lighter canvas (`paper` #F6F7F5), hairline rules, a `subtle` fill for table header bands and inset tiles, 12px panels, 36px controls; **one font**, IBM Plex Sans, for headings too: `@fontsource/archivo` removed (three fewer font files, which also helps the slow-3G finding E1); **Acceptable gets its own colour** (`fine`, slate) instead of Strong's green, in ScoreTag, StatusLabel, tiles, ranked bars and bars; **month cells take their band's tint** instead of the prototype's value heat scale (`lib/heat.ts` no longer reads a value: off the client-maths allow-list); the public header puts the page tabs first and the filters, as compact fields with the label inside the border, beside the title; the sync line is neutral with each source's mark in its state colour; Data issues lists the records first and the rules as one compact list per severity; Pull all is one row; pull history keeps failed rows one line high. **New figure:** Home says how far the average shows below Strong (`StateSummary::strongGap`, from the displayed value, as the counts are, Q-26), computed in `StateSummaryAggregator`; and a public or private side of one facility says so | Calmer, denser screens with the same content; no figure computed outside an Aggregator |
| D-90 | **Rounds page; closing publishes (Phase 5 step 4).** `/admin/rounds` (`AdminRoundsController`, `RoundsPayload`) lists every round newest first: window, status in words ("Open — not public", "Closed — public"), accepted and flagged assessments per source (`Reporting\Queries\RoundAssessmentCountsQuery`), open issues (`QuarantineQuery::issueCountsByRound`: open quarantine, unreviewed deletions at source, open DUPLICATE_ASSESSMENT records clashing with the other source's accepted visit, the backfill report's test), the pinned and the read rule version, closed at/by, the last reopen reason; a fixed number of queries. **Close takes a reason** (`CloseRoundRequest`, 10 characters, audited) like reopen: it publishes (D-07, D-77). The close panel shows the round's open issues and offers **Reconcile all sources now** (each source's `/reconcile`, followed in a ProgressPanel) before the reasoned dialog with the public-impact note; open issues never block a close. **`rounds.closed_by`** (new, FK users, `ON DELETE SET NULL`, CHECK only on a closed round) is set on close and cleared on reopen. **Reopen flushes the round's cache** (`RoundCache::flushRound`) so the public stops seeing it at once (close already did, through the aggregate refresh); "Figures last updated" moves on close (D-81). **Edit window takes a reason** too. Create maps the API's 409 codes to fields (`round_exists` → quarter, overlap → start, past the grace → end) | The step that makes figures public is deliberate, explained and audited; a reopened round never lingers in a cache |
| D-91 | **Facility master list: the page and the console import (Phase 5 step 5).** *Numbering note: CLAUDE.md and the step's prompt call the console import "D-19", but D-19 in this table is the Wayfinder decision; the import is recorded here.* **Import** `edqa:facilities:import-master-list {path} {--dry-run} {--force}`: the client's signed-off reconciliation workbook (facility_reconcile.py's .xlsx, read by `XlsxSheets`, a small Office Open XML reader: no import library is added) is reference data, not assessment data, so it does not conflict with source-only ingestion (hard rule 1); there is no upload route (`RouteGuardTest` fails if a route or `app/Http` ever takes a file). Matched rows are read as MAP (unless a Decision column says REJECT); Review rows decided MAP (to one of the row's candidates, which gives the name and ward) or ADD (the submitted name and ward; the code from "Map to registry code", else the submitted code); REJECT is skipped. Level and ownership are taken as written, never guessed. An LGA outside the 23, an undecided or malformed row, or one code given two ways refuses the whole workbook, every problem with its sheet and line. The diff is printed first; `--dry-run` writes nothing; then one transaction creates wards as needed and upserts by code, each change audited with the reason "master list import {file}". Outside local, while synthetic (SYN-) facilities are active, `--force` is needed, and the synthetic facilities the workbook lacks are deactivated, never deleted. **Page** `/admin/facilities` (`AdminFacilityListController`, `Reporting\Queries\FacilityListQuery`): search on name, code, ward and LGA (ILIKE served by pg_trgm GIN indexes, a new migration), filters, 25 a page, visits, open-round visits and the latest score (the latest round's overall under its own version). `SaveFacility` adds and edits (a ward in another LGA moves the LGA; the code is immutable once any assessment holds it, 409 `code_locked`); `SetFacilityActive` deactivates/reactivates with a reason, never deletes; `SaveWard` adds and renames wards within an LGA. All audited. The editor carries the public-impact note (names and places show publicly, published rounds included); deactivating warns when open-round visits would fail FACILITY_UNKNOWN; deactivated facilities keep their published history (reporting does not read `is_active`). **Media file out of date**: an audit entry marked `cascade` (code, name, ward, LGA, active; ward add/rename) newer than the last `cascade_exported` (`CascadeStatusQuery`, by audit id, two partial indexes) shows a persistent notice on the Facility list and Data sources pages until the file is regenerated | The master list is the validation authority: every change deliberate, audited and visible in its consequences; the initial list arrives once, from a signed-off file, through the console only |
| D-92 | **Scoring rules page (Phase 5 step 6).** *Numbering note: the step's prompt calls "closed rounds are unaffected" D-18; that decision is D-32 here (D-18 is the Laravel 13 decision).* `/admin/scoring-rules` (`AdminScoringRulesController`, `ScoringRulesPayload`): every version with who published it (the audit log's `published` entry), when and why; the draft; the item catalog for the weights table (every scored item in `config/edqa_item_rules.php`, with the form's wording); the open and closed rounds; each open round's latest rescore under the current version. Weighting (`edqa.aggregation.weighting`) and display rounding (`edqa.presentation.rounding`, Q-09) are configuration, not a rule version, so they are shown read-only. **Draft**: `EditDraftRuleVersion` saves (the same config checks as a new draft, 409 otherwise; audited `draft_updated`) and discards (`draft_discarded`, the config kept in the audit entry; a draft has no scores to orphan); a published version is never edited or removed. The page checks the bands as typed (each 0–100, descending) and saves only weights other than 1. **Preview** reads only (a test counts six tables before and after) and now names facilities and sends its counts (`facilities_changing_count`, `lgas_changing_count`), so the browser counts nothing. **Publish** needs a saved, previewed draft, asks for a reason in a dialog that repeats the preview, then follows each open round's queued rescore (the page reloads until each has started, two minutes at most). Closed rounds are listed as unaffected and the public dashboard does not move until an open round is closed (tested anonymously). The Playwright spec (`tests/e2e/scoring.spec.ts`) publishes into the dev database, so it runs only with `E2E_SCORING=1` | The step that changes every open round's scores is previewed, explained, reasoned and followed to its end; nothing public moves until a close |
| D-93 | **A read-only audit log page is added; with one role, administrators need to see each other's actions** (Phase 5 step 7). *Numbering note: the step's prompt asked for this as "D-20", which is already taken (the starter-kit trim, superseded by D-84); it is recorded here.* `/admin/audit` (`AdminAuditController`, `App\Support\Audit\AuditLogQuery`): every audited write and sign-in event, newest first, 50 a page, filtered by actor, subject type (assessment, quarantine, facility or ward, round, rule version, administrator, sign-in, source), source (the `source` a pull, reconcile or backfill records), event, a date range in WAT and the reason; each row has who, what, when, a subject link where the admin area has a page, the reason and a before/after table. Auth events (log `auth`: login, failed login, lockout, unlock, password change, new-device login, logout) are labelled. Quarantine records and raw submissions are recognised by their stored subject type only: the log never reads those tables (hard rule 6). **Export**: `POST /api/audit/export` writes the filtered entries as CSV on the private exports disk (cells a spreadsheet would read as a formula are prefixed with an apostrophe) and answers with a 30-minute signed link, or, over `edqa.audit.export_sync_rows` (5,000), queues `ExportAuditLog` on the `exports` queue; the page lists recent exports from their `audit_exported` entries. Exports and downloads are audited; viewing the log is not. No route, action or command changes or removes an audit entry (a test scans the routes and the code), and the runtime role keeps INSERT and SELECT only (grants test). **My account** moves to `/admin/account` (the old `/admin/settings/security` redirects): change password as before (policy, history, other sessions ended, audited) and this user's last ten sign-ins (WAT, browser, the address only as its /24, or /48 for IPv6, new device or not, from the auth log); unlocking stays a console command (`edqa:admin:unlock`), named on the page | Password-only sign-in (D-84) leans on the audit trail as the main control: administrators can now read it, filter it and take it away, and cannot alter it |
| D-94 | **After acceptance: withdrawn status, replace from submission, deletion review, publish on close** (recorded at the Phase 5 close-out; built in Phase 5 steps 0–4). Every one is a reasoned, audited action (CONVENTION.md §6), refused in a closed round (D-32: reopen it first), and each ends in the round's rescore and cache refresh, never in a score taken from input. **Deletion review** (D-62): Reconcile flags a submission its source no longer returns (`submissions.deleted_at_source`); nothing is removed. **Keep** marks it reviewed (`ReviewSourceDeletion`; `deletion_reviewed_at`/`_by`, CHECK `submissions_deletion_review_valid`: a review exists only for a deletion and always names both); unreviewed deletions count in the Data issues badge and the Rounds page's open issues. **Withdrawn status**: `WithdrawAssessment::withdraw` sets `assessments.status = 'withdrawn'` (CHECK `assessments_status_valid`: accepted, flagged, withdrawn) and marks the deletion reviewed; a withdrawn assessment counts in no aggregator or `round_aggregates` (they read accepted and flagged only, D-33), and the facility may be assessed again in the round (the unique index `assessments_round_facility_live_unique` skips withdrawn rows). `restore` puts back flagged or accepted, refused once the facility has a live assessment in the round again. **Replace from submission**: on a DUPLICATE_ASSESSMENT that clashes with an accepted visit (from either source, §5.8), **Use this one instead** (`ReplaceAssessmentFromSubmission`) reprocesses the held submission as an edit of the accepted instance: the same assessment row keeps its id, plan actions and links, its items and scores come from the held submission through the pipeline, the old submission is marked superseded, both instance IDs are in the audit entry; **Keep the existing one** rejects the held one. A copy of the same instance from the other source never gets this far: it is counted, not stored (D-60). **Publish on close** (D-90, D-77): closing is the only way a round's figures reach the public; it pins the rule version, sets `closed_at`/`closed_by` and moves "Figures last updated" (D-81); reopening takes the round off the public dashboard at once | Removing, replacing or publishing figures are decisions, not syncs: each needs a person, a reason and a trail, and none may happen silently to a published round |
| D-95 | **The full-round journey (Phase 5 close-out).** `tests/e2e/admin-full-round.spec.ts`, keyboard first (Tab and Enter; the arrow keys on native selects), run by `make e2e-round` headless in the `playwright` container (`CI=1`). Setup: its own database `edqa_e2e` (`tests/e2e/support/full-round-db.sql`, same owner, extension and grants as `edqa`), selected for app and worker by `compose.e2e-round.yml` on top of `compose.e2e-sources.yml` together with their own cache and Redis prefixes, so the developer's data, cache and queues are never touched; the demo seed with Q2 2026 closed in July (`full-round-baseline.sql`); both mocks holding 300 submissions for Q3 2026, a round not yet created (`edqa:source-mock:generate --round` now accepts one, as `--quarters` did): ODK Central 100 (10 broken, 2 staged edits), KoboToolbox 197 (10 broken) plus 3 visits also on ODK (2 copies, 1 entered again). Part 1: (a) the visitor sees Q2 2026 and not Q3, (b) `/login` typed, email + password, (c) create Q3 2026, regenerate the media file, (d) Pull all, both progress panels to Finished, Q3 still not public. Between the parts the harness deletes one accepted Kobo visit at the mock and reconciles Kobo, and makes the ODK edits visible. Part 2: (e) Data issues: correct the blank LGA, reject one, bulk-reject ROUND_WINDOW, Use this one instead, withdraw the deleted visit; (f) correct an assessment in the admin facility view; (g) edit and deactivate a facility, regenerate the media file when prompted; (h) draft → preview → publish Strong 85; (i) Reconcile all sources, close; (j) the audit log has every step with its reason and source; (k) Log out lands on the public dashboard: Q3 2026 published, "Figures last updated" moved, and the leak checks (no assessor, instance ID, email or forbidden key in any public page or the facility API) pass. Screenshots for `docs/admin-guide.md` are taken on the way. The deleted visit is put back and the stack restarted without the overrides whatever the outcome. **Found by it, fixed:** the Facility list's out-of-date media panel remounted at the foot of the page after regenerating and lost its download link; `DemoScoringSeeder` failed after `migrate:fresh` whenever the cache still named a later rule version; `tests/e2e/support/kobo-mock-delete.php` resolved `Kernel` before its `use` lines and never ran (so `make e2e-sources`' third pass could not have passed). Still open: Q-34 | The PRD's Phase 5 acceptance ("an admin runs a round without developer help") is one continuous journey, not a set of page tests; it publishes and closes, so it needs a database of its own |
| D-96 | **Queued exports with signed downloads (Phase 6 step 0).** New Composer packages: **`maatwebsite/excel` ^4.0** (4.0.6; brings `phpoffice/phpspreadsheet` 5.11, `maennchen/zipstream-php`, `markbaker/complex` and `/matrix`, `phpoffice/wmf`, `composer/semver`; already named in CLAUDE.md §2) for the Excel format; CSV is written with `fputcsv`. **Domain:** exports have their own, `App\Domain\Export` (not `Reporting\Exporters` as §2 sketched): an export joins Reporting's figures (every AVG stays in Reporting, hard rule 5: `Reporting\Queries\AssessmentExportQuery` over FacilityScoresSql's `assessment_scores_in_view`, each round under its own rule version, D-32) with details only Ingestion and Assessment may read (`Ingestion\Queries\AssessmentSourceQuery::forSubmissions`, `Assessment\Queries\AssessmentPrivateDetailsQuery`, one query per chunk each), so Reporting never touches submissions (hard rule 6). **Table** `exports` (type, filter jsonb, options jsonb {format, include_private}, requested_by, status queued/running/done/failed/expired, progress 0–100, row_count, path, error, timestamps, expires_at; CHECKs for the status, progress, row count, a done export has its file, an expired one has none, options and filter are objects). **Flow:** `POST /admin/api/exports` (`RequestExport`: type from `ExportTypes`, format csv/xlsx, the dashboard filter by its URL names through `ReportFilter` for the admin audience; audited `export_requested` with the privacy option; `GenerateExportJob` queued on `exports` after commit; 202 `{export_id}`; `throttle:exports`, 10 a minute per administrator) → the job (`GenerateExport`: chunks of `edqa.exports.chunk_rows`, progress on the row, done with rows, path `{id}/{type}-{Ymd-Hi}[-with-assessor-details].{ext}` on the private `exports` disk and `expires_at` = +7 days, or failed with a plain message; `export_completed` / `export_failed` audited) → `GET /admin/api/exports/{id}` (status, progress, rows and, once done, a relative signed link for `edqa.exports.link_minutes`, 30) → `GET /admin/exports/{id}/download` (`signed:relative` + the admin session; streamed with nosniff and no-store; `export_downloaded` audited). **Privacy:** the default columns never include assessor names, device IDs, visit GPS or attachment links, and never select them; "Include assessor and source details" adds those plus the instance ID and the link at source, is recorded in the audit entries, in the file name and, for Excel, in the "About this export" sheet (Q-35 for CSV). Text cells cannot become formulas (CSV: leading apostrophe, `App\Support\Export\SpreadsheetCell`, now shared with the audit log export; Excel: written as text). **Retention:** `ExpireExportsJob` daily at 03:15 on `low` (`ExpireExports`: done exports past `expires_at`, or written over `edqa.exports.retention_days` ago, lose their file and become expired; audited `export_expired`). **UI:** `ExportButton` (format, the privacy option where the type offers it, then a ProgressPanel and the download link), in the admin preview bar for the assessments in view; never on the public site (the shared-props and leak tests check every public page and that every export endpoint refuses a guest). First type: `assessments`; the CSV, Excel and plan-workbook exports of build step 12 add types. **Measured:** 10,007 rows as CSV in 5.3 s (`ExportPerformanceTest`, `@slow`; §10 target 30 s). Server contract: a new scheduled job (`edqa:exports:expire`) and Laravel Excel's temporary files under `storage/framework/cache/laravel-excel` (ephemeral) | Exports leave the system (D-80, SECURITY.md §7): queued so a large one never ties up a request, signed and short-lived so a link that travels is soon useless, private details opt-in and recorded so personal data only leaves on purpose, and deleted after a week so copies do not pile up inside the portal either |
| D-97 | **CSV and Excel exports reconciled with the aggregators (Phase 6 step 1).** Amends D-96 (its first type, "assessments", is replaced) and D-93 (the audit log's own CSV route, job and 5,000-row threshold are gone: it is now the `audit_log` type). **Seven types**, each mirroring a page and reading that page's own aggregator or query, never SQL of its own for a number: `state_summary` (StateSummary, Dimension, OwnerSplit and Coverage aggregators), `lga_table` (LgaTableAggregator), `facility_scores` (FacilityScoresQuery: a new `each()` streams every page with the same SELECT as `paginate()`, now shared; `FacilityRow` gains the three dimensions and the round id, RoundCache::SCHEMA 7; the current metric's months and score plus all four metrics), `item_responses` (long format, one row per facility × round × dimension × month × item, `Reporting\Queries\ItemResponseExportQuery`: raw answers, no aggregate, each result from `Reporting\Support\ItemResult`, now shared with the facility drawer so the two never disagree), `data_issues` (QuarantineQuery: `exportCount()`/`exportRows()` on the page's own filtered builder, as plain values), `pull_history` (PullHistoryQuery: `count()`/`each()` on the page's builder, plus a started-between date range) and `audit_log` (AuditLogQuery::each). The report types take the dashboard filter (year, quarter, month, LGA, level, owner) for the admin audience; data issues, pull history and the audit log their page's own filters (data issues and pull history also a year and quarter). **Columns:** every score is three columns side by side, full precision, displayed (1 dp, the presentation rounding) and its band label; rows from an open round say "Provisional — round not closed" in a status column ("Published" otherwise); a source column wherever submissions are involved (facility scores: the sources of the facility's counted assessments, `Reporting\Queries\FacilitySourcesQuery`, reading `assessments.source_id` only; item responses, data issues, pull history). The privacy option belongs to item responses only (assessor, device, visit GPS, instance ID, link at source, attachment links); asked for another type it is refused (409 `private_not_offered`). **CSV:** UTF-8 with BOM, RFC 4180 (quotes doubled, fields quoted when needed, CRLF), formula injection neutralised, the displayed value written with its decimal ("85.0"). **Excel:** bold header row frozen with an autofilter, a number format and width per column kind (`ColumnKind`: precise `0.00########`, displayed `0.0`, counts, dates), text written as text, and "About this file": export, filters, generated at (WAT), generated by, rows, rule version per round, rounding mode, aggregation method, data sources, personal details, and "Figures from closed rounds are published; open-round figures are provisional." **Where:** the admin preview bar offers each dashboard page's exports (Home and Monitor the state summary, Facility view facility scores and item responses, LGA view and Implementation plan the LGA table, Visuals both summaries), Data issues (hard tab) its records, Data sources its pull history, Audit log its entries. **Tested:** for each type and five filter combinations every exported number equals the aggregator's value (`ExportReconciliationTest`); BOM, quoting, injection, Excel formatting, the About sheet, personal columns and provisional rows (`ExportFormatTest`); 10,007 facility rows as CSV in 9.3 s in the dev container (`@slow`) | "No separate SQL for numbers": an export is the page on paper, so it must show exactly what the page shows; reading the same aggregator is the only way that stays true as the aggregators change |
| D-98 | **The backfill report as Excel (Phase 6 step 2).** `edqa:backfill-report --format=xlsx [--source=]` (md and csv unchanged) and a download per source on the Data sources page (the backfill panel), through the export plumbing as the `backfill_report` type. One layout for both, `Export\Support\BackfillReportSheets`, over `BackfillReportQuery`, which gains a source filter, per-round duplicate counts (grouping sets, as its rounds) and unmatched facilities; every count stays in that query (the architecture test allows it by name). Sheets: **Summary** (per source × round: pulled, accepted, flagged, quarantined, rejected, not processed, duplicates held, duplicates across sources held; each source's total, which also carries the copies from the other source its pull runs counted, D-60: never stored, so not per round; the grand total), **By rule × year**, **By rule × form version**, **Examples** (the three commonest details per hard rule; hard only, so never ASSESSOR_VOLUME's assessor), **Unmatched facilities** (open FACILITY_UNKNOWN records by facility and LGA as sent, with their rounds and a detail), **About this file**. No personal field: the query reads no assessor, device, GPS or attachment. The plumbing gains multi-sheet exports (`MultiSheetExport`, `Sheet`, `ExportWriter::writeSheets`), Excel only (CSV refused, 409 `excel_only`); `ExportButton` takes a type's formats. The Data sources page's "latest report" link now also finds an `.xlsx`. **Found and fixed:** without Laravel Excel's `WithStrictNullComparison` a 0 was written as an empty cell in every Excel export; both sheet classes now write zeros | The backfill decides which historical copies the client keeps (Q-15, Q-19): it should open in the tool they use, one tab per question, and say who and what it covers |
| D-99 | **Editable implementation plan in admin, read-only publicly (Phase 6 step 3).** Amends D-10, D-45 and D-79. `/admin/plan?round=` (`Plan\AdminPlanController`, `AdminPlanPayload`): ImplementationPlanAggregator's rows for the round, admin audience, with each stored row's admin-only fields laid over them (`Plan\Queries\PlanActionsQuery`: id, role key, other role, contact, notes); the aggregator itself never reads the contact or notes. **Generate** (`POST /admin/api/plan/{round}/generate`, `Plan\Actions\GeneratePlan`): a row for every LGA the plan proposes an action for that has none (weakest dimension, standard action, the dimension's default role, review `edqa.plan.review_days` = 30 days after today), `firstOrCreate` on (round, LGA, dimension), so idempotent; audited per row (`created`) and per run (`plan_generated`). **Edit** (`PATCH /admin/api/plan-actions/{id}`, `UpdatePlanAction`): owner role (the configured roles, now also State HMIS team and LGA PHC coordinator, or `other` with `owner_role_other`, at most 80 characters; a CHECK ties the two), contact and notes (admin-only), review date (a changed one on or after today, 422 otherwise; an unchanged past one may stay, and the page says it has passed), status; no reason (a plan is follow-up, not a figure); audited old/new by the model. Closed rounds' plans stay editable. **Public:** each row's status, a role written under "Other role…" shown as written (it is a role or unit: the page's hint says so), counts by status in "Actions by type"; closed rounds only (PublicScope); never the contact or the notes (leak test markers in both; `notes` and `owner_role_other` are forbidden keys). The rail gains "Implementation plan" | The plan is the follow-up the client acts on: administrators keep it current, the public sees who is accountable as a role and how far each action has got, and personal contacts stay inside |
| D-100 | **The implementation workplan as Excel (Phase 6 step 4).** Export type `workplan` (`Export\Types\WorkplanExport`, a `MultiSheetExport`, Excel only) for a round: by id from the admin plan page, or the plan's round (the latest in view) for a dashboard filter from the admin preview of the public plan. Sheets: **Plan** (LGA, overall and the weakest dimension's score, each full precision, displayed and band, the weakest dimension, the recommended action, the owner role, the review date, the status; the contact and notes only with "Include private plan fields", read from `PlanActionsQuery` only then, the choice audited and said in About this file), **Summary** (actions by type × status), **Reference** (what each action means, its usual owner), **About this file**. Rows from ImplementationPlanAggregator, as on the admin plan page. The plumbing gains: a Status dropdown (an Excel list data validation on the column), the `DateLong` column kind (`dd mmm yyyy`), real Excel dates for every date column (the Y-m-d text is converted, so the formats now apply in every export, not only here), A4 landscape printing fitted to the page width with the header row repeated; `MultiSheetExport::sheets()` takes the privacy flag; `ExportButton` takes a type's own wording for its privacy option; a type may word its "Personal details" line | The plan is worked on away from the portal too, in meetings and on paper: the file should print well, keep statuses to the portal's three words, and keep personal contacts out unless asked for |
| D-101 | **The PDF round report (Phase 6 step 5).** New Composer packages: **`spatie/laravel-pdf` ^2.14** (brings `spatie/temporary-directory`; named in CLAUDE.md §2) and **`spatie/browsershot` ^5.5**, its Chromium driver (a separate package since laravel-pdf 2). Export type `round_report` (`Export\Types\RoundReportExport`, a new `DocumentExport`: one format, its own file name, `write()` instead of rows), format `pdf` (PDF for this type only, the report for no other format: `ExportRefused::wrongFormat()`, 409 `wrong_format`). Round: by id (Rounds page), by year and quarter (the admin preview of Home), else the **latest closed round**; an open round only when asked for by name, then a draft. **Figures:** `Export\Report\RoundReportBuilder` reads the dashboard's aggregators for the round (StateSummary, Dimension, OwnerSplit, QuarterTrend filtered to the round's year up to the round, MonthSlot, LgaTable, WeakestLgas, ImplementationPlan, AssessmentsBySource) with the **public audience for a closed round** (the report is what the public dashboard shows) and the admin audience for an open one; it computes nothing. The data quality note adds `QuarantineQuery::heldBackCounts()`: records of the round kept out of the figures (open or rejected), by hard-failure code and by source; counts and source names only. Method: formula, bands from the round's rule version, rule version, rounding mode, aggregation, configured sources, "Closed rounds only are published." **Rendering:** `resources/views/reports/round.blade.php` (print only, A4 portrait), tokens read from `resources/css/app.css` (`ReportStyle`, so they stay defined once) and IBM Plex Sans 400/600 embedded as data: URIs from `public/build` (else `node_modules`); charts drawn on the server as inline SVG by `Export\Report\SvgChart` (bars, grouped bars, line; 0–100 axis; colour by class), each beside its table (hard rule 11); `thead` repeats on every page; Chromium's running header (the round, DRAFT) and footer ("Page n of N", Support: HSDF, the date). An open round: a fixed diagonal "DRAFT — round not closed" watermark on every page, DRAFT in the header, `round-report-{year}-Q{q}-DRAFT.pdf`. `Export\Report\PdfRenderer` is the only class that drives Chromium (architecture test): `Pdf::html()` with the rendered HTML, never a URL; `noSandbox()`, JavaScript off, Chromium and Node paths from `edqa.pdf.*` (the image's `BROWSERSHOT_*` env; an empty key falls back to `/usr/bin/chromium` and `/usr/bin/node`), a temporary profile and HOME in `/tmp` (the app user's home is read-only and Chromium's crash handler will not start without one); **tagged PDF** with `/Lang` from `<html lang>`, title, author, subject and creator metadata. **Job:** `Export\Jobs\GenerateRoundReport` on `exports` (worker-exports only in staging and production), through `GenerateExport` (progress in percent, audited, signed download, `application/pdf`); `row_count` holds the page count (`PdfText::pages`, pdfinfo). **UI:** a Round report panel on the Rounds page (the latest closed round first; an open round is labelled a draft before it is asked for) and Home's export in the admin preview. **Checks:** `edqa:pdf-smoke` renders one page to `/tmp` and reads it back with pdftotext (DEPLOY.md §12); `make pdf` runs it and the `pdf` Pest group (a real render read back: headline, all 23 LGAs, DRAFT on every page, Tagged, A4, title) in the worker container; the default suite excludes `pdf` and fakes Chromium (`Pdf::fake()`). **Images:** `poppler-utils` (pdftotext, pdfinfo) in `worker`; a `dev-worker` target (dev + the worker's Chromium, fonts, Node, Puppeteer and poppler) for the local `worker` service, which gets `shm_size: 256m`. Server contract: the worker image gains poppler-utils; no new env key (optional `PDFTOTEXT_BINARY`), queue, schedule or route | A report people print, circulate and file needs to be the dashboard's own figures, on paper: the same aggregators, the public reading for published rounds, a draft that cannot be mistaken for a published one, nothing personal, and a renderer that never sees anything but the app's own HTML |

Add new decisions here with the next ID. Any new Composer/npm package needs a line.

### 11.2 Prototype vs PRD — the PRD wins

| Prototype shows | Build instead |
|---|---|
| CSV drag-drop import, template download, import history | Data sources page with Pull now per source and pull history |
| Data sources: KoboToolbox, DHIS2, Google Sheets, nightly schedule | ODK Central **and KoboToolbox** (D-59), every 10 min + manual + nightly reconcile; no DHIS2, no Google Sheets |
| Users & roles page with 4 roles, partner masking | No user management UI; single role |
| Scores entered as numbers per month | Computed from `select_one` item responses |
| Client-side scoring and filtering over all rows | Server-side aggregators + materialised view, paginated tables |
| Facility view capped at 60 rows | Server-side pagination, 50/page |
| Facility list "built from the loaded data" | Master list is the validation authority, edited in admin |
| Plan with fixed owners per dimension | Editable owner, due date, status |
| Status bar "Sample data / Import data" | Source sync status bar |

Keep from the prototype: visual design, layout, page set, chart types, weakest-first sort,
real month names for single-quarter views, LGA tile grid, Data issues "why it exists" copy.

### 11.3 PRD inconsistencies

1. PRD §15 says the data volume is "trivial for **MySQL**" — should read Postgres.
2. PRD §6 flowchart says "**Officer** resolves" — there is only Administrator.
3. PRD §5 reference math: Private 75/62/84 mean 73.67 displays as 74 with half-up rounding, not 73;
   state weighted figure is ≈ 86.3, not 86.5 (both still show 86). Test against unrounded client data.
4. PRD §19 "thirteen rules" vs 14 in §7 (D-06).
5. `assessment_scores` uniqueness vs rule-version history (D-05).
6. PRD §13 lists API path `/api/facility-list/exportcascade` — use `/export-cascade`.

### 11.4 Open questions for the client

| # | Question | Blocks |
|---|---|---|
| Q-01 | ODK Central (OData) or legacy ODK Aggregate? | Phase 2 |
| Q-02 | Does the form use a cascading facility select and `select_one` scored items? | Phase 2 estimate |
| Q-03 | How are dimensions scored today — pass rate or weighted rubric? | Phase 3 |
| Q-04 | Aggregation weighted or unweighted? | Every number |
| Q-05 | How many form versions exist and how far do names drift? | Backfill estimate |
| Q-06 | Who owns and signs off the facility master list, by when? | Phase 2+ |
| Q-07 | VPS acceptable (Postgres ≥ 14)? Who pays? | Deployment |
| Q-08 | How many administrator logins? (> 3 → revisit roles before launch) | Launch |
| Q-09 | Display rounding: half-up or truncate? (explains 73 vs 74) | Phase 3 tests |
| Q-10 | Round assessment windows for historical quarters — exact dates? | Backfill |
| Q-11 | Webhooks available on their ODK Central host? Kobo REST Services enabled on their Kobo account? | Optional latency |
| Q-15 | Why both ODK and Kobo? Split by LGA, by period (e.g. history on Kobo, current on ODK), by partner, or overlapping? Which is authoritative if the same facility+round appears on both? | Duplicate handling, backfill triage |
| Q-16 | Is the same XLSForm deployed on both platforms, and are versions kept in step? | Field-map effort |
| Q-17 | Which KoboToolbox server — global, EU, or self-hosted (URL)? Hosted servers sit outside Nigeria; any data-residency requirement? | Config, hosting, data protection |
| Q-20 | Should facility master-list coordinates (not visit GPS) be shown publicly, e.g. on a future map? Default: no | Public drawer |
| Q-21 | When should the public site be indexed by search engines? Default: never until the client says so | Go-live |
| Q-18 | Can the client create a dedicated read-only Kobo account (view submissions only on the asset) for the API token? | Security |
| Q-19 | **Where is the history?** The old Looker Studio report shows earlier quarters, but the KoboToolbox project has nothing before 22 October 2026 (discovery/KOBO_FINDINGS.md). Did earlier rounds come from ODK Central, another KoboToolbox project (the owning account has 4 others), or somewhere else? Restored at the Phase 5 close-out (it had dropped out of this table). Until answered, the backfill report explains each missing round as a gap | Backfill (build step 14) |
| Q-12 | Standard action texts and default owners for the plan — confirm wording | Phase 6 |
| Q-34 | **The scoring draft's item weights hold about 1,200 fields**, every one a Tab stop, so a keyboard user tabs for minutes to reach **Preview impact** and **Publish draft…** (found by the full-round journey, D-95, which focuses those two buttons directly). Options: show the weights only after a search or behind a "Change item weights" disclosure, or a skip link past them. The client's form has no item weights (D-69), so hiding them by default changes nothing they use. Owner decision before UAT | Accessibility (WCAG 2.4.1), UAT |
| Q-35 | **Where does a CSV export say it holds assessor and source details?** Phase 6 step 0 asks for the choice "in the file's metadata sheet"; an Excel export has one ("About this export"), a CSV has no sheets. Today a CSV says it in its file name (`…-with-assessor-details.csv`) and the audit entry. Options: a leading block of `#` lines (breaks some tools' import), a zip with `data.csv` and `about.csv`, or private details offered in Excel only. Default until decided: as built (D-96) | Exports (build step 12) |
