# Kaduna eDQA Portal

A web portal for Kaduna State's quarterly **electronic Data Quality Assessment (eDQA)** of health
facilities. It replaces the Looker Studio report "Data Quality Assessment e-tool version 3".

Assessors visit about 2,200 facilities across the state's 23 LGAs every quarter and record their
checks on **ODK Central** or **KoboToolbox**. The portal:

1. **pulls** those submissions from both platforms through one shared pipeline;
2. **validates** every submission before it can count. A submission that fails a check is
   quarantined for an administrator to correct or reject, never guessed at;
3. **computes every score on the server** from the item responses. A score is never taken from
   the form;
4. **publishes** the results on a public, read-only dashboard, closed rounds only, with no login;
5. gives administrators a separate area to run each round: data sources, data issues, rounds,
   the facility list, scoring rules, the implementation plan, exports and the audit log.

> A score that cannot be true never reaches a chart.

---

## What it does

### Public dashboard (no login)

Six pages, showing **closed rounds only**:

| Page | Shows |
|---|---|
| **Home** | Average quality score, facilities visited, LGAs covered, the three dimensions, public vs private, quality by quarter, weakest LGAs |
| **Facility view** | Every facility's scores, searchable and sortable, with a detail drawer per facility |
| **LGA view** | The 23 LGAs: dimensions, month by month, overall and band |
| **Monitor** | Coverage per LGA and by facility level, and assessments by source |
| **Visuals** | Month-by-month charts for each dimension |
| **Implementation plan** | Recommended action, owner role, review date and status for each LGA below the threshold |

Every chart has a table with the same figures. Status is never shown by colour alone.
Assessor names, contacts, source IDs, quarantine and open-round figures never reach a public page.

### Admin area (`/login`, administrators only)

| Page | Lets an administrator |
|---|---|
| **Data sources** | See each platform's connection, pull now ("Pull all"), reconcile edits and deletions, run a historical backfill, read the pull history |
| **Data issues** | Correct and reprocess, reject, or bulk-resolve quarantined submissions; decide duplicates between the two platforms; review deletions at source; override flags |
| **Rounds** | Create a round, edit its window, rescore, **close** it (which publishes it) or reopen it. Each action needs a reason. Export the PDF round report |
| **Facility list** | Edit facilities and wards, deactivate a facility, regenerate the facility media file the forms use |
| **Scoring rules** | Draft new bands or item weights, preview what changes, publish (rescores the open rounds) |
| **Implementation plan** | Generate the plan for a round; set each action's owner, contact, notes, review date and status |
| **Audit log** | Every change and sign-in: who, when, old and new values, and the reason |
| **Admin preview** | The public pages as they will look, including the open round, with exports |

### Exports

Queued in the background, with progress, a signed download link that works for 30 minutes,
and an audit entry. Files are deleted after 7 days.

- **CSV or Excel:** state summary, LGA table, facility scores, item responses, data issues,
  pull history, audit log. Excel files carry an "About this file" sheet (filters, rule version,
  rounding, aggregation, sources).
- **Excel only:** the backfill report and the implementation workplan (statuses as a dropdown;
  prints on A4 landscape).
- **PDF:** the round report. A4, charts drawn on the server, every LGA with its band, the plan,
  a data quality note and the method. An open round's report is watermarked
  "DRAFT — round not closed" on every page.
- Personal details (assessor names, device IDs, GPS, plan contacts) are left out unless the
  administrator ticks the option, and that choice is recorded.
- CSV cells can never run as spreadsheet formulas.

### Scoring

```
month score     = items passed / items applicable × 100   (N/A excluded from both)
dimension score = mean of its month scores                 (Availability, Consistency, Validity)
overall score   = mean of the three dimensions
LGA / state     = unweighted mean of facility scores
bands           = Strong ≥ 90 · Acceptable 80–89 · Review 70–79 · Needs action < 70
```

Scores are stored at full precision and rounded only for display. Bands and item weights are
versioned: a closed round keeps the rule version it closed with.

---

## Tech stack

| Layer | |
|---|---|
| Back end | Laravel 13, PHP 8.3, PostgreSQL 16 (`jsonb`, CHECK constraints), Redis queues and cache |
| Front end | Inertia 3, React 19, TypeScript (strict), Tailwind CSS 4, Recharts and hand-built SVG |
| Auth | Laravel Fortify: password login, lockout, new-device alerts; one role, Administrator |
| Files | maatwebsite/excel (CSV, Excel), spatie/laravel-pdf with Browsershot and Chromium (PDF) |
| Audit | spatie/laravel-activitylog |
| Quality | Pest, Larastan (level 6), Pint, Vitest with axe, Playwright, Laravel Pulse |
| Runtime | Docker: one multi-stage `Dockerfile`, Caddy as the web tier, Docker Compose |

---

## Running it locally

### Requirements

- **Docker** (Docker Desktop on Windows or macOS; on Windows, keep the repository inside WSL2 for
  speed)
- **GNU make**

Everything runs inside the containers: you do not need PHP, Composer, Node or PostgreSQL on
your machine.

### First run

```bash
git clone https://github.com/naynie41/kaduna-eDQA-dashboard.git
cd kaduna-eDQA-dashboard
make up
```

`make up` creates `.env` from `.env.example`, builds the images, installs the PHP packages,
generates the app key, starts the stack and runs the migrations.

| Service | URL |
|---|---|
| App | http://localhost:8080 |
| Mail (Mailpit) | http://localhost:8025 |
| Vite dev server | http://localhost:5173 |

### Demo data and an administrator

```bash
make fresh                                   # drop, migrate and seed: 23 LGAs, wards, facilities, a round
make artisan c="edqa:admin:create"           # create an administrator (asks for name, email, password)
```

Then sign in at http://localhost:8080/login. The public dashboard is at http://localhost:8080/.

### Connecting ODK Central and KoboToolbox

Put the credentials in `.env` (never commit it):

```
ODK_ENABLED=true
ODK_CENTRAL_URL=   ODK_CENTRAL_EMAIL=   ODK_CENTRAL_PASSWORD=   ODK_PROJECT_ID=   ODK_FORM_ID=
KOBO_ENABLED=true
KOBO_BASE_URL=     KOBO_API_TOKEN=      KOBO_ASSET_UID=
```

```bash
make artisan c="edqa:source:check odk"       # test a connection (read-only)
make artisan c="edqa:pull kobo"              # one pull now (or --all)
make artisan c="edqa:reconcile odk"          # catch edits and deletions made at the source
make artisan c="edqa:backfill kobo"          # load history, resumable, one source at a time
make artisan c="edqa:backfill-report"        # what the history became (md, csv or xlsx)
```

No real platform? Run the local mocks instead:

```bash
make sources-mock-up
make sources-mock-seed n=200
```

### The scheduler

Scheduled pulls (every 10 minutes per source), the nightly reconcile (02:30) and export
clean-up (03:15) are off locally unless you start the scheduler:

```bash
docker compose -f compose.dev.yml --profile scheduler up -d scheduler
```

### Everyday commands

```bash
make help          # every target
make sh            # shell in the app container
make logs s=app    # follow a service's logs
make artisan c="…" # run an artisan command
make down          # stop the stack (data is kept)
```

---

## Tests

```bash
make check         # Pint, Larastan, and the Pest suite against PostgreSQL (must be green)
make test          # the Pest suite only, in parallel
make test-slow     # performance tests: rescore time, server p95, a 10,000-row export
make pdf           # PDF smoke test and PDF tests in the worker container (Chromium, pdftotext)
make e2e           # Playwright against the running stack
make e2e-round     # a whole round, keyboard first, on its own database against both mocks
make size          # bundle budget for the public Home page
make npm c="run test"   # Vitest component tests (with axe accessibility checks)
```

Tests always run on PostgreSQL, never SQLite.

---

## Deployment

The same images run in development, CI, staging and production. Build them with:

```bash
make prod-build    # app (PHP-FPM), worker (adds Chromium for PDFs), web (Caddy)
```

| Image | Runs |
|---|---|
| `app` | the web application, the default queue workers, the scheduler and migrations |
| `worker` | the exports queue (CSV, Excel, PDF), with Chromium, fonts and pdftotext |
| `web` | Caddy, serving the built front end and proxying to `app` |

Containers are stateless: persistent files live under `storage/app`, logs go to stderr, and
migrations run only in the `migrate` role. After a deploy, check PDF rendering with
`php artisan edqa:pdf-smoke` in the exports worker.

On natview's VM, staging and production each run [`compose.prod.yml`](compose.prod.yml) as their own
stack, behind one front Caddy that terminates TLS. A push to the `staging` or `prod` branch builds
the images and moves that environment's tag in the registry
([`.github/workflows/deploy.yml`](.github/workflows/deploy.yml)); the VM notices within a few
minutes, runs the migrations, starts the release, checks `/up` and rolls back if it fails. A manual
run of the workflow with an older `sha-<commit>` tag rolls back. The VM itself is set up by
natview-ng/ansible (`roles/app_compose`).

---

## Project layout

```
app/Domain/        Ingestion (sources, normalisers, parser), Validation (rules, quarantine),
                   Scoring, Reporting (aggregators), Round, Facility, Plan, Export, Assessment
app/Http/          Public and admin controllers, requests, resources
resources/js/      Inertia + React + TypeScript pages and components
resources/views/   Blade views for the PDF round report
config/edqa*.php   Scoring, validation, sources, field maps, exports, PDF
database/          Migrations (with CHECK constraints), seeders, factories
tests/             Pest (Arch, Unit, Feature), Vitest (components), Playwright (e2e)
docker/            Entrypoint, PHP, Caddy and Postgres config, source mocks
```

## Support

```bash
testermangang247@gmail.com
```