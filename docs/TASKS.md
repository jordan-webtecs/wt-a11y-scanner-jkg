# Tasks: Accessibility Scan Manager MVP

## How to Use This File

- Keep tasks small and concrete.
- Mark items done as they are completed.
- Add new tasks only if they are in scope for the MVP.
- If a task changes architecture, security, or the data model, update `DECISIONS.md` too.

## Current Milestone

Continue MVP completion after the first Phase 6 rescan comparison slice, while intentionally leaving pagination and remaining scanner hardening for later.

---

## Phase 1: Scanner Proof of Concept

### Goal
Create a minimal Node.js TypeScript scanner service in `/scanner-service` that:
- accepts a single URL
- loads it with Playwright
- runs axe-core
- returns normalized violations as JSON

### Tasks
- [x] Confirm `scanner-service` dependencies install successfully
- [x] Run `npx playwright install`
- [x] Run `npm run build`
- [x] Run `npm run dev`
- [x] Test `POST /scan` with one valid public URL
- [x] Test `POST /scan` with a missing URL
- [x] Test `POST /scan` with an invalid URL
- [x] Confirm normalized JSON response shape matches Phase 1 expectations
- [ ] Confirm README is accurate
- [x] Update `CURRENT_STATE.md` when Phase 1 verification is complete

### Acceptance Criteria
- [x] Service starts locally without errors
- [x] Playwright launches successfully
- [x] `/scan` returns JSON for a valid URL
- [x] Invalid input returns clear error messaging
- [ ] No database, auth, job queue, or sitemap support has been added yet

---

## Phase 2: Scanner Expansion

### Goal
Expand the scanner service to support sitemap/manual URL scanning and job-based execution.

### Tasks
- [x] Define Phase 2 response shape for multi-page scan results
- [x] Add scan job model in the scanner service
- [x] Add `POST /api/scans`
- [x] Add `GET /api/scans/:jobId`
- [x] Add `GET /api/scans/:jobId/results`
- [x] Add manual URL list scan mode
- [x] Add standard XML sitemap parsing
- [x] Add sitemap index parsing
- [x] Add URL normalization logic
- [x] Add fingerprint generation using normalized URL + rule ID + target selectors
- [x] Add scan progress reporting
- [x] Add basic per-URL failure handling
- [x] Add clear sitemap fetch/parsing error messages
- [ ] Document API shape in README or docs
- [x] Update `CURRENT_STATE.md` when Phase 2 is complete

### Acceptance Criteria
- [x] Scanner can accept a sitemap or manual URL list
- [x] Scanner can track job status
- [x] Results are returned in normalized multi-page format
- [x] Fingerprints are generated for violations
- [x] Failed URLs do not crash the entire scan

---

## Phase 3: WordPress Plugin Data Layer

### Goal
Create the WordPress plugin data foundation for storing the current site's local configuration, scans, URLs, and violations.

### Tasks
- [x] Create plugin scaffold in `/wordpress-plugin`
- [x] Add activation hook
- [ ] Create custom DB tables:
  - [x] `wp_acc_sites`
  - [x] `wp_acc_scans`
  - [x] `wp_acc_scan_urls`
  - [x] `wp_acc_violations`
- [x] Add required indexes from the spec
- [x] Add DB versioning strategy
- [x] Create DB helper layer or repository classes
- [x] Add basic CRUD methods for sites
- [x] Add storage methods for scans
- [x] Add storage methods for violations
- [x] Add update methods for workflow status and notes
- [ ] Add uninstall/deactivation decision if needed
- [x] Update `CURRENT_STATE.md`

### Acceptance Criteria
- [x] Plugin activates cleanly
- [x] Tables are created correctly
- [x] Required indexes exist
- [x] Data layer can save and retrieve sites, scans, and violations

---

## Phase 4: WordPress Admin UI

### Goal
Build the internal admin UI for current-site settings/configuration, scan jobs, and violations.

### Tasks
- [x] Add top-level admin menu: Accessibility Scans
- [x] Build Site Settings / Site Configuration screen
- [x] Build Scan Jobs screen
- [x] Build Scan Detail screen
- [x] Build Violations screen
- [x] Build Violation Detail screen
- [ ] Add filters for violations:
  - [ ] scan
  - [ ] page URL
  - [x] rule ID
  - [x] severity
  - [x] workflow status
- [ ] Add pagination to violations table
- [x] Add status update UI
- [x] Add notes field/editing
- [x] Show violation classification/tag badges in the admin UI
- [ ] Add filters for violations:
  - [ ] WCAG A
  - [ ] WCAG AA
  - [ ] Best Practice
- [ ] Add CSV export for violations
- [ ] Add CSV export for scan detail data
- [x] Add capability checks for the Site Settings slice
- [x] Add nonce validation for the Site Settings slice
- [x] Escape/sanitize all output and input for the Site Settings slice
- [x] Update `CURRENT_STATE.md`

### Acceptance Criteria
- [x] Admin can view current-site settings/configuration, scan jobs, and violations
- [ ] Violations table is filterable and paginated
- [x] Violation detail view supports notes and workflow status updates

---

## Phase 5: Wire WordPress Plugin to Scanner Service

### Goal
Connect the WordPress plugin to the external scanner service.

### Tasks
- [x] Add plugin settings for scanner service base URL
- [x] Add plugin settings or config for shared API key
- [x] Implement request authentication
- [x] Add scan trigger action from WordPress
- [x] Store outgoing scan request metadata
- [x] Add manual remote status refresh for existing scans
- [x] Add manual completed-result fetch for existing scans
- [x] Ingest completed results into WP tables
- [x] Add Action Scheduler integration
- [x] Schedule polling for active jobs
- [x] Fetch completed results
- [x] Preserve axe rule tags in normalized scanner results
- [x] Ingest and store axe rule tags with local violations
- [x] Derive display classifications from stored tags where applicable
- [ ] Handle scanner service unavailable errors
- [ ] Handle malformed response errors
- [x] Update `CURRENT_STATE.md`

### Acceptance Criteria
- [x] WordPress can trigger a scan job
- [x] WordPress can manually refresh job status
- [x] WordPress can manually fetch and ingest completed results
- [x] WordPress can poll job status
- [x] WordPress can ingest completed results
- [x] API key authentication works

---

## Phase 6: Rescan Comparison Logic

### Goal
Track recurring, new, and resolved issues across scans.

### Tasks
- [x] Define recurring issue matching rules
- [x] Match violations by fingerprint across scans
- [x] Update `first_seen_at` and `last_seen_at`
- [x] Mark prior missing issues as resolved
- [x] Preserve manual workflow statuses where appropriate
- [x] Show recurring/new/resolved state in UI
- [x] Update `CURRENT_STATE.md`

### Acceptance Criteria
- [x] Same issue is recognized across rescans when possible
- [x] Resolved issues are marked correctly
- [x] New issues are clearly distinguishable
- [x] Recurring issues preserve continuity across scans

---

## Backlog: Explicitly Not in MVP

Do not work on these unless the scope changes.

- [ ] Auto-fixing
- [ ] AI-generated remediation suggestions
- [ ] PDF scanning
- [ ] Authenticated user-flow testing
- [ ] Screenshot capture
- [ ] Screen reader simulation
- [ ] Jira/Asana integrations
- [ ] Client-facing reporting
- [ ] Template-level aggregation UI
- [ ] Public dashboard
- [ ] Full audit/history system beyond minimal audit fields

---

## Known Open Questions

These are not blockers yet, but should be resolved when relevant.

- [ ] What exact hosting environment will be used for the scanner service VM?
- [ ] Will plugin settings store the API key, or will it live in config only?
- [ ] What default page size should the violations table use: 25, 50, or 100?
- [ ] What should the scanner timeout defaults be for slow sites?
- [ ] What level of logging is enough for MVP before it becomes noisy?
