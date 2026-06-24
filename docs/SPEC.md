# Accessibility Scan Manager MVP Specification

## 1. Product Summary

Build an internal accessibility scanning and issue-tracking system for websites.

The system has two parts:

1. An external Node.js scanner service that uses Playwright and axe-core to scan pages for accessibility violations.
2. A WordPress plugin that stores scan results and provides an admin UI for managing sites, scans, and violation workflow.

For MVP, the WordPress plugin is installed on each WordPress site independently. Each install manages only the current site's local scan configuration and results. MVP does not include a central WordPress dashboard for managing multiple separate websites.

This MVP is not:
- an accessibility certification tool
- an auto-remediation tool
- a PDF remediation tool
- a full compliance platform

It is a practical internal operations tool for running scans and processing violations over time.

## 2. Primary Goal

Create a working system that can:
- scan a site or list of URLs
- return a list of accessibility violations
- show the associated rule and severity
- let a user process findings through a simple workflow

## 3. Architecture

Use a two-part architecture.

### 3.1 External Scanner Service
A Node.js service that:
- accepts scan requests
- loads pages in a real browser
- runs axe-core against each page
- normalizes findings
- returns results through an API

### 3.2 WordPress Admin Plugin
A WordPress plugin that:
- stores the current site's local configuration, scans, pages, and violations
- provides admin screens for review and triage
- triggers scans through the scanner service
- ingests and displays results

### 3.3 Deployment Model
For MVP, the scanner service will run on a small always-on Linux virtual machine controlled by the team, separate from the WordPress hosting environment.

Recommended characteristics:
- Ubuntu 22.04 or 24.04 LTS
- Node.js version compatible with current Playwright requirements
- persistent process manager such as PM2 or systemd
- HTTPS endpoint accessible to the WordPress plugin
- modest resource footprint suitable for small-to-medium batch scans

The WordPress plugin remains installed on the relevant WordPress site and acts as the management UI and persistence layer.

Do not deploy the scanner service inside shared WordPress hosting.
Do not attempt to run browser automation directly from WordPress/PHP.

## 4. Why This Architecture

Do not run headless browser scans directly inside WordPress/PHP.

Reasons:
- browser automation is better handled in Node
- scan jobs may be slow or resource-heavy
- plugin should focus on admin UX, persistence, and workflow
- architecture should leave room for future scale

## 5. Users

### 5.1 Primary Users
- internal developers
- project managers
- content/admin staff
- agency team members reviewing client sites

### 5.2 Future Users
- client staff inside WordPress admin
- QA reviewers
- accessibility specialists

## 6. MVP In Scope

### 6.1 Site Management
- create or update the current site's local scan configuration
- store base URL
- optionally store sitemap URL
- enable/disable site from scans

### 6.2 Scan Management
- run scan manually for a site
- choose scan mode:
  - sitemap import
  - manual URL list
- see scan job status:
  - queued
  - running
  - completed
  - failed

### 6.3 Violation Capture
For each violation instance, store:
- page URL
- rule ID
- preserved axe rule tags
- rule description/help text
- help URL
- impact/severity
- node target/selector if available
- HTML snippet if available
- scan timestamp
- stable fingerprint for issue matching across rescans
- a derived display classification in the admin UI based on stored tags

### 6.4 Workflow / Processing
Allow each violation to be marked as:
- New
- Accepted
- In Progress
- Needs Review
- Ignored
- Resolved

### 6.5 Filtering and Review
- filter violations by site
- filter by scan
- filter by page
- filter by rule ID
- filter by severity
- filter by status

### 6.6 Rescanning
- rescan a full site
- rescan a single page
- compare current results to previous results
- mark issues as persistent, new, or resolved

## 7. Out of Scope for MVP

Do not build these yet:
- auto-fixing
- AI-generated code fixes
- PDF scans
- authenticated flow testing
- screenshot capture
- screen reader simulation
- Jira/Asana integrations
- client-facing polished reports
- WCAG criteria beyond what axe reports
- multi-scanner comparison
- template-level aggregation UI

## 8. Functional Requirements

## 8.1 Site Management Requirements
The system must allow an admin to:
- create or maintain the current site's local configuration record
- set site name
- set base URL
- optionally set sitemap URL
- enable or disable scanning

## 8.2 Scan Execution Requirements
The system must allow an authorized user to:
- trigger a scan for a site
- import URLs from sitemap
- submit manual URLs
- view scan status and timestamps

### 8.2.1 Sitemap Import Behavior
For MVP, the scanner service must support:
- standard XML sitemap files
- sitemap indexes that reference child sitemaps

Before running a sitemap-based scan, the WordPress plugin should show a preview/confirmation step containing:
- total discovered URL count
- sample URLs

If sitemap retrieval fails or returns malformed XML, the scan must fail with a clear error message.

Password-protected staging environments are out of scope for automated sitemap discovery in MVP unless a manual URL list is provided.

## 8.3 Scan Result Requirements
The system must save scan results at:
- scan level
- page level
- violation level

## 8.4 Violation Review Requirements
The system must allow an authorized user to:
- open a violation detail view
- see the associated page URL
- see rule ID and severity
- see available selector/snippet data
- change status
- add internal notes

## 8.5 Re-scan Comparison Requirements
On re-scan, the system must:
- identify same violation instance when possible
- treat unresolved repeated issues as existing/persistent
- mark missing prior issues as resolved
- create new records for newly detected issues

## 9. Non-Functional Requirements

- secure admin-only access in WordPress
- secure authenticated communication between WordPress and scanner service
- support initial use on small to medium sitemap sizes
- avoid duplicate violation inflation across rescans
- structured codebase with clear separation of concerns
- logging for failed scan jobs
- stable enough for internal use, not yet production-hardened SaaS

## 10. Tech Stack

## 10.1 Scanner Service
- Node.js
- TypeScript
- Playwright
- axe-core
- Express or Fastify

Optional:
- SQLite for local dev if needed
- scanner may remain stateless if WordPress is the source of truth

## 10.2 WordPress Plugin
- PHP
- WordPress REST API
- custom DB tables
- wp-admin pages
- no Gutenberg dependency required for MVP

## 11. Suggested Database Schema for WordPress Plugin

Use custom tables.

### 11.1 `wp_acc_sites`
- id
- name
- base_url
- sitemap_url
- is_active
- created_at
- updated_at

Under the MVP single-site-per-install model, this table may contain a single local site record for the current install.

### 11.2 `wp_acc_scans`
- id
- site_id
- status
- scan_mode
- initiated_by_user_id
- started_at
- finished_at
- error_message

### 11.3 `wp_acc_scan_urls`
- id
- scan_id
- url
- normalized_url
- http_status
- scanned_at

### 11.4 `wp_acc_violations`
- id
- scan_url_id
- rule_id
- impact
- help
- help_url
- description
- html_snippet
- target_json
- tags_json
- fingerprint
- workflow_status
- notes
- first_seen_at
- last_seen_at
- resolved_at
- status_changed_at
- status_changed_by_user_id
- ignored_at

## 11.5 Required Indexes

At minimum, create indexes on:

### `wp_acc_scans`
- `site_id`
- `status`

### `wp_acc_scan_urls`
- `scan_id`
- `normalized_url`

### `wp_acc_violations`
- `scan_url_id`
- `rule_id`
- `workflow_status`
- `fingerprint`
- composite index on (`rule_id`, `workflow_status`)
- composite index on (`scan_url_id`, `fingerprint`)

These indexes are required for acceptable filter and comparison performance in MVP.

## 12. Fingerprinting Strategy

Each violation needs a stable fingerprint so the system can match recurring issues across rescans.

### 12.1 Initial Fingerprint Formula
Hash of:
- normalized URL
- rule_id
- normalized target selector list

Example conceptual input:
`{normalized_url}|{rule_id}|{selector_1,selector_2,...}`

This is imperfect but acceptable for MVP.

### 12.2 Future Grouping Support
The data model should not assume all accessibility issues are purely page-local forever.

In future phases, the system may support grouped issues at a higher level such as:
- site + rule ID
- site + rule ID + repeated selector pattern
- site + template/component signature

This is especially useful for theme-level or builder-level repeated defects that appear across many pages.

This grouping logic is not required in MVP, but the implementation should avoid making it difficult later.

## 13. Scanner Service Responsibilities

The scanner service must:
- accept scan requests
- resolve URL list from sitemap or input
- visit each URL in Playwright
- wait for page load to stabilize
- inject/run axe
- collect violations
- normalize the output
- generate fingerprints
- return results to WordPress

For MVP, normalized violations should preserve the original axe rule tags so WordPress can store them and derive simple admin classifications later.

### 13.1 Suggested Scan Wait Strategy for MVP
- wait until DOM content loaded
- small additional delay for client-side rendering
- no advanced SPA handling in v1

## 14. WordPress Plugin Responsibilities

The plugin must:
- manage the current site's local configuration
- trigger scans
- receive/store results
- expose admin pages
- provide filtering and workflow updates
- maintain permissions and security

## 15. Scan Orchestration and API Design

## 15.1 Scan Orchestration Model
The WordPress plugin will trigger scan jobs on the external scanner service.

The scanner service owns:
- scan execution
- job progress
- per-URL processing
- final result generation

The WordPress plugin owns:
- job initiation
- periodic job status checks
- result ingestion
- persistence
- admin UI

For MVP, WordPress will poll the scanner service for job status and results using scheduled background actions.

## 15.2 Polling Implementation
Use Action Scheduler inside the WordPress plugin to:
- schedule periodic checks for active jobs
- fetch results when a job completes
- retry transient failures
- stop polling failed or completed jobs

Do not rely on a browser tab staying open for polling.
Do not rely on WP-Cron alone as the primary job orchestration mechanism.

## 15.3 Scanner Service Endpoints

### `POST /api/scans`
Create a scan job.

Request example:
```json
{
  "siteId": 12,
  "baseUrl": "https://example.gov",
  "mode": "sitemap",
  "sitemapUrl": "https://example.gov/sitemap.xml",
  "urls": []
}

GET /api/scans/:jobId/results

Return normalized scan results.

Response:

{
  "jobId": "scan_001",
  "status": "completed",
  "results": [
    {
      "url": "https://example.gov/about/",
      "normalizedUrl": "https://example.gov/about/",
      "httpStatus": 200,
      "violations": [
        {
          "ruleId": "color-contrast",
          "impact": "serious",
          "help": "Elements must meet minimum color contrast ratio thresholds",
          "helpUrl": "https://dequeuniversity.com/rules/axe/4.10/color-contrast",
          "description": "Ensures the contrast between foreground and background colors meets WCAG 2 AA minimum contrast ratio thresholds",
          "htmlSnippet": "<a class=\"btn\">Read More</a>",
          "target": [
            ".hero .btn"
          ],
          "fingerprint": "abc123"
        }
      ]
    }
  ]
}
15.4 WordPress Plugin Internal Endpoints

These are optional if the plugin uses admin-post or internal handlers instead.

Suggested:

POST /wp-json/acc/v1/scans/run
GET /wp-json/acc/v1/scans/{id}
POST /wp-json/acc/v1/violations/{id}/status
POST /wp-json/acc/v1/violations/{id}/notes
16. Admin UI Requirements
16.1 Top-Level Menu

Accessibility Scans

16.2 Screens
Site Settings / Site Configuration

For MVP under the single-site-per-install model, this is the current install's local site settings/overview screen, not a central multi-site manager.

Columns:

name
base URL
sitemap URL
active
last scan
actions

Actions:

run scan
edit
disable
Scan Jobs

Columns:

site
status
mode
started
finished
URL count
violation count
actions
Violations

Columns:

site
page URL
rule ID
classification
severity
status
first seen
last seen

Filters:

site
scan
rule ID
severity
workflow status
Violation Detail

Fields shown:

page URL
rule ID
severity
description/help
help URL
selector target
HTML snippet
fingerprint
status dropdown
notes
history of appearances across scans

For the MVP stored-tags slice, derive admin classification from stored axe tags using this priority rule:
- `WCAG A` if tags contain `wcag2a` or `wcag21a`
- else `WCAG AA` if tags contain `wcag2aa` or `wcag21aa`
- else `Best Practice` when usable tags exist
- `Other` only when tags are missing or unusable

This display rule is intentionally simple and may be revised later while still preserving the stored source tags.
16.3 Workflow Status Definitions
New: newly detected and not yet reviewed
Accepted: reviewed and confirmed as a real issue to work on
In Progress: currently being addressed
Needs Review: requires human judgment or verification
Ignored: false positive, duplicate, non-actionable, or intentionally excluded
Resolved: issue no longer appears on re-scan
16.4 Violations Table Usability

The violations table must support pagination in the WordPress admin UI.

For MVP:

default page size may be 25, 50, or 100
filters must work with pagination
sort order should default to most recent or highest severity first
17. Roles and Permissions

For MVP:

only administrators can manage sites and scans
editors or custom roles can be added later if needed

At minimum:

capability to view scans
capability to manage scans
capability to update violation statuses
18. Security Requirements
shared secret or token between WordPress and scanner service
validate inbound and outbound requests
sanitize and escape all stored/displayed data
capability checks on all admin actions
nonce validation for admin forms/actions
18.1 Service Authentication

For MVP, use a static shared API key between WordPress and the scanner service.

Requirements:

scanner service stores key in environment variable
WordPress stores key in configuration or secured plugin setting
WordPress sends key in Authorization header or custom header
scanner validates key on all protected endpoints
do not hardcode secrets in the codebase

This is sufficient for internal MVP use.

19. Error Handling

The system should handle:

sitemap fetch failure
page load timeout
scanner service unavailable
malformed scan results
duplicate ingestion attempts

Failed URLs should not fail the entire scan if avoidable.

20. Logging

For MVP, basic logging is enough:

scan start
scan finish
number of URLs scanned
number of violations found
per-URL failures
API communication failures
21. Definition of Done

The MVP is complete when:

a WordPress admin can configure the current site
a WordPress admin can trigger a scan
the scanner service can import sitemap URLs and scan pages with Playwright + axe
results are saved in WordPress
violations display in a filterable admin table
each violation can be assigned a workflow status
rescanning can identify recurring and resolved issues
22. Development Phases
Phase 1

Build scanner proof of concept

scan one URL
return normalized axe results
Phase 2

Expand scanner

scan sitemap
track job status
normalize results and fingerprints
Phase 3

Build WP plugin data layer

activation hook
custom tables
CRUD helpers
indexes
Phase 4

Build WP admin UI

sites
scans
violations
detail view
pagination
Phase 5

Wire plugin to scanner service

trigger jobs
poll status with Action Scheduler
ingest results
authenticate requests with shared API key
Phase 6

Add rescan comparison logic

match fingerprints
resolve prior issues
show persistent/new/resolved

---
