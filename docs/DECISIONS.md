# Decisions: Accessibility Scan Manager MVP

## How to Use This File

- Record decisions that affect architecture, scope, security, data model, or workflow.
- Keep entries short and concrete.
- If a decision changes later, do not delete the old one. Add a new entry noting the change.

---

## Decision Log

### 2026-03-23 — Product shape
**Decision:** Build an internal accessibility scanning and issue-tracking MVP, not a certification platform.

**Reason:**
This keeps the first version realistic, useful, and much smaller to ship.

**Implications:**
- Focus on scanning, storing, triaging, and rescanning
- Do not position MVP as proving full compliance
- Do not add compliance theater features

---

### 2026-03-23 — Core architecture
**Decision:** Use a two-part architecture:
1. external Node.js scanner service
2. WordPress admin plugin

**Reason:**
Browser automation belongs outside WordPress/PHP. WordPress is better suited to admin UI, persistence, workflow, and internal operational use. This architecture is explicitly defined in the project files. :contentReference[oaicite:2]{index=2} :contentReference[oaicite:3]{index=3}

**Implications:**
- Do not try to run Playwright inside WordPress
- Treat the scanner and plugin as separate codebases/modules
- Communication happens over API

---

### 2026-03-23 — Scanner stack
**Decision:** Use Node.js + TypeScript + Playwright + axe-core for the scanner service.

**Reason:**
This stack is already defined as the MVP direction and is appropriate for rendered-page accessibility scanning. :contentReference[oaicite:4]{index=4} :contentReference[oaicite:5]{index=5}

**Implications:**
- Do not swap in a different scan stack unless there is a strong reason
- Keep the first implementation small and explicit
- Avoid adding multiple scan engines in MVP

---

### 2026-03-23 — WordPress plugin role
**Decision:** The WordPress plugin is the control panel, persistence layer, and workflow UI.

**Reason:**
This aligns with the intended admin experience and the current project spec. :contentReference[oaicite:6]{index=6} :contentReference[oaicite:7]{index=7}

**Implications:**
- Plugin owns sites, scans, URLs, violations, workflow status, notes, and UI
- Plugin does not own browser execution
- Plugin should use custom DB tables

---

### 2026-03-23 — Deployment model
**Decision:** Run the scanner service on a small always-on Linux VM controlled by the team.

**Reason:**
This is a practical fit for Playwright and separates browser automation from WordPress hosting. The deployment model is already set in the project files. :contentReference[oaicite:8]{index=8} :contentReference[oaicite:9]{index=9}

**Implications:**
- Do not deploy the scanner service inside shared WordPress hosting
- Expect Ubuntu 22.04 or 24.04 LTS
- Use PM2 or systemd for a persistent process

---

### 2026-03-23 — MVP scope boundaries
**Decision:** MVP includes site management, sitemap/manual URL scans, normalized violation capture, workflow statuses, filtering, and rescans.

**Reason:**
This scope is the agreed useful minimum. :contentReference[oaicite:10]{index=10} :contentReference[oaicite:11]{index=11} :contentReference[oaicite:12]{index=12}

**Implications:**
- Build only the minimum needed to support those flows
- Push extras to later phases

---

### 2026-03-23 — Explicitly out of scope for MVP
**Decision:** Do not build auto-fixing, PDF scanning, authenticated flow testing, screenshots, polished client reporting, or template-level grouping UI in MVP.

**Reason:**
These are intentionally excluded to keep the first version shippable. :contentReference[oaicite:13]{index=13} :contentReference[oaicite:14]{index=14} :contentReference[oaicite:15]{index=15}

**Implications:**
- If these come up during implementation, defer them
- Keep backlog items separate from active tasks

---

### 2026-03-23 — Orchestration model
**Decision:** The scanner service owns scan execution; the WordPress plugin owns initiation, polling, ingestion, persistence, and UI.

**Reason:**
This keeps responsibilities clean and prevents WordPress from becoming the scan runtime. The model is already defined in the project files. :contentReference[oaicite:16]{index=16} :contentReference[oaicite:17]{index=17}

**Implications:**
- Do not push scan state management into browser tabs
- Do not make WordPress responsible for browser execution

---

### 2026-03-23 — Polling implementation
**Decision:** Use Action Scheduler in the WordPress plugin for polling and ingestion orchestration.

**Reason:**
It is a practical WordPress-native background task approach and already chosen in the spec/context. :contentReference[oaicite:18]{index=18} :contentReference[oaicite:19]{index=19}

**Implications:**
- Do not rely on open admin tabs for polling
- Do not rely on WP-Cron alone as the primary orchestration mechanism

---

### 2026-03-23 — Authentication model
**Decision:** Use a static shared API key between WordPress and the scanner service for MVP.

**Reason:**
It is simple and sufficient for internal MVP use, and it is the current documented expectation. :contentReference[oaicite:20]{index=20} :contentReference[oaicite:21]{index=21}

**Implications:**
- Store the scanner key in environment configuration
- Store the WordPress-side key in config or secured settings
- Do not hardcode secrets in the repo

---

### 2026-03-23 — Workflow statuses
**Decision:** Use exactly these workflow statuses in MVP:
- New
- Accepted
- In Progress
- Needs Review
- Ignored
- Resolved

**Reason:**
These are already defined in the project context and spec. :contentReference[oaicite:22]{index=22} :contentReference[oaicite:23]{index=23}

**Implications:**
- Do not invent extra statuses without a scope change
- Use these definitions consistently in UI and code

---

### 2026-03-23 — Meaning of Accepted vs Ignored
**Decision:**  
- Accepted = reviewed and confirmed as a real issue to work on  
- Ignored = false positive, duplicate, non-actionable, or intentionally excluded

**Reason:**
This distinction was identified as important for consistent triage.

**Implications:**
- UI and documentation should reflect this difference
- Team should not use Ignored as a vague placeholder

---

### 2026-03-23 — Fingerprinting strategy
**Decision:** For MVP, fingerprint violations using:
- normalized URL
- rule ID
- normalized target selector array

**Reason:**
This is the agreed initial matching strategy for rescans. :contentReference[oaicite:24]{index=24} :contentReference[oaicite:25]{index=25}

**Implications:**
- Matching will be imperfect but acceptable for MVP
- Do not overcomplicate fingerprinting in v1

---

### 2026-03-23 — Database strategy
**Decision:** Use custom WordPress DB tables for sites, scans, scan URLs, and violations.

**Reason:**
This is the required data model direction for MVP. :contentReference[oaicite:26]{index=26} :contentReference[oaicite:27]{index=27}

**Implications:**
- Do not store scan data in posts/postmeta
- Respect the required indexes from the spec

---

### 2026-03-23 â€” Single-site plugin model
**Decision:** MVP uses a single-site-per-install WordPress plugin model, not a central WordPress dashboard managing multiple separate websites.

**Reason:**
The plugin should manage scans and violations for the same WordPress site where it is installed, while the external scanner service remains shared infrastructure outside WordPress.

**Implications:**
- Each plugin install manages only its own local site configuration and results
- Do not frame the MVP admin UI as a central multi-site manager
- The existing `wp_acc_sites` table may remain in place for MVP and can be interpreted as containing the current install's local site record

---

### 2026-03-23 — Required indexes are mandatory
**Decision:** The required indexes in the spec are not optional.

**Reason:**
Filtering and comparison performance will degrade quickly without them. The project context explicitly says these are not optional for MVP. :contentReference[oaicite:28]{index=28}

**Implications:**
- Index creation belongs in the initial table schema implementation
- Do not defer them as “later optimization”

---

### 2026-03-23 — Sitemap expectations
**Decision:** MVP sitemap scans must support standard XML sitemaps and sitemap indexes, and should include a preview/confirmation step before launch.

**Reason:**
This is part of the documented MVP behavior. :contentReference[oaicite:29]{index=29} :contentReference[oaicite:30]{index=30}

**Implications:**
- Treat sitemap parsing as a real feature, not a naive one-file assumption
- Password-protected staging sitemap discovery is out of scope unless manual URLs are provided

---

### 2026-03-23 — Current implementation milestone
**Decision:** Finish Phase 1 verification before starting Phase 2.

**Reason:**
The current scanner scaffold has been created and audited, but runtime verification still needs to happen before expanding scope.

**Implications:**
- Next steps are install, build, run, and test
- Do not start jobs, sitemap support, persistence, or plugin work until Phase 1 is confirmed working

---

### 2026-03-23 - Manual result ingestion behavior
**Decision:** Repeated manual result fetches for the same completed scan replace that scan's previously ingested `scan_urls` and `violations` rows instead of appending duplicates.

**Reason:**
This keeps the manual Phase 5 ingestion slice small and prevents duplicate row inflation when an admin clicks `Fetch Results` more than once for the same remote job.

**Implications:**
- Manual result fetch remains safe to rerun for the same scan
- This replacement behavior applies only within one scan's stored results
- Cross-scan dedupe, recurrence matching, and resolution logic remain Phase 6 work


### 2026-03-24 — Rule classification and export direction
**Decision:**
Keep full axe results in MVP scans, and later support admin filtering/export by stored rule tags/classifications rather than limiting the scanner to a smaller ruleset.

**Reason:**
This preserves useful findings while still allowing the admin UI to distinguish WCAG-related issues from best-practice findings.

**Implications:**
- Preserve axe rule tags with stored violations
- Add admin classification badges/filters later
- Support lightweight CSV export for internal spreadsheet analysis
- Do not reduce the scanner to WCAG-only output by default

---

### 2026-03-24 — Polling cadence and duplicate prevention
**Decision:** For the MVP polling slice, schedule at most one pending Action Scheduler poll per active scan and use a fixed 60-second polling interval. When a poll sees the remote job reach `completed`, it should fetch and ingest results in that same background flow.

**Reason:**
This keeps the orchestration explicit and small while preventing duplicate scheduled actions from piling up for the same remote job.

**Implications:**
- Successful scan submissions should enqueue the first poll immediately after the remote job ID is stored
- `queued` and `running` scans should reschedule one next poll
- `completed` and `failed` scans should stop scheduling further polls
- Result ingestion remains scan-local only; rescan comparison and cross-scan dedupe stay out of scope

---

### 2026-03-24 - MVP WCAG classification rule
**Decision:** Preserve raw axe rule tags on each stored violation and derive the admin display classification from those stored tags instead of hardcoding classification by rule ID.

**Reason:**
This keeps the scanner output intact, lets WordPress distinguish WCAG-related findings from broader best-practice findings, and keeps the first classification slice small and revisable.

**Implications:**
- Store preserved axe tags locally on `wp_acc_violations`
- Use this deterministic MVP priority rule for display:
- `WCAG A` if tags contain `wcag2a` or `wcag21a`
- else `WCAG AA` if tags contain `wcag2aa` or `wcag21aa`
- else `Best Practice` when usable tags exist
- `Other` only when stored tags are missing or unusable
- Grouped rule summaries may use the latest local representative stored tags value for that group
- This rule is intentionally revisable later without changing the stored source tags

---

### 2026-06-23 - MVP rescan comparison behavior
**Decision:** Implement Phase 6 comparison in the WordPress ingestion layer by matching the existing fingerprint across earlier scans for the same local site.

**Reason:**
The scanner already returns normalized page results, while WordPress owns historical scan data, workflow status, notes, and resolution state.

**Implications:**
- Recurring issues inherit the earliest known `first_seen_at`, update `last_seen_at` to the current scan timestamp, and preserve prior manual workflow status/notes when the prior issue was not resolved
- A previously resolved issue that reappears is treated as a new occurrence for MVP
- Missing prior open issues are auto-marked `Resolved` only when their page was successfully included in the new result set
- Auto-resolution applies to `New`, `Accepted`, `In Progress`, and `Needs Review`
- `Ignored` issues stay ignored and are not auto-resolved
- Comparisons only look at earlier scan IDs to avoid older result re-fetches changing newer scan history
- The UI may derive simple scan state labels (`New`, `Persistent`, `Resolved`) from existing stored fields instead of adding a new database column

---

### 2026-06-24 - Sites as the WordPress admin organizing entity
**Decision:** Treat `wp_acc_sites` as the top-level admin entity in the WordPress plugin UI. Admins can create, edit, delete, open, scan, and review violations for explicit site records.

**Reason:**
The product direction now requires site-scoped administration rather than implicitly using the first local site record.

**Implications:**
- Site configuration such as name, base URL, sitemap URL, and active status lives on each site record
- Scan initiation, scan history, scan detail, violation summaries, grouped details, and violation occurrence edits carry an explicit `site_id`
- Existing scanner client and scan orchestration boundaries remain unchanged
- Deleting a site also deletes its locally stored scans, scan URLs, and violations
