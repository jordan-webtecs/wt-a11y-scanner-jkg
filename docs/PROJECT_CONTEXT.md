# Project Context: Accessibility Scan Manager MVP

## Project Summary

This project is an MVP for an internal accessibility scanning and issue-tracking system.

The system has two parts:

1. An external Node.js scanner service that uses Playwright and axe-core to scan pages for accessibility violations.
2. A WordPress plugin that stores scan results and provides an admin UI for managing sites, scans, and violation workflow.

For MVP, the plugin is installed on each WordPress site independently. Each install manages only the current site's local scan configuration and results. There is no central WordPress manager site in MVP.

This is not a certification tool, auto-remediation tool, or full compliance platform. It is a practical internal operations tool for scanning websites and processing violations over time.

## Core Product Goal

The main goal is to let a user:
- run a scan against a site or URL list
- retrieve accessibility violations
- see the associated rule and severity
- process findings through a basic workflow

## Scope Boundaries

In scope for MVP:
- site management
- scan job management
- sitemap import
- scan results ingestion
- violation listing
- violation detail view
- workflow status updates
- rescan comparison using fingerprints

Out of scope for MVP:
- auto-fixing
- AI-generated remediations
- PDF scanning
- authenticated flow testing
- screenshots
- third-party integrations
- public reporting
- client-facing dashboards
- template-level aggregation UI

## Architecture

### Scanner Service
- Node.js
- TypeScript
- Playwright
- axe-core
- REST API
- responsible for running scans and returning normalized results

### WordPress Plugin
- PHP
- custom WordPress admin pages
- custom database tables
- REST communication with scanner service
- responsible for storing, displaying, and triaging results for the current install's site

Do not try to run headless browser scans inside WordPress/PHP for this MVP.

## Deployment Model

For MVP, the scanner service runs on a small always-on Linux virtual machine controlled by the team, separate from the WordPress hosting environment.

Expected environment:
- Ubuntu 22.04 or 24.04 LTS
- persistent Node process using PM2 or systemd
- HTTPS endpoint accessible by WordPress
- enough resources for small-to-medium scan jobs

WordPress remains the admin/control interface and source of stored results.

Do not deploy the scanner service inside shared WordPress hosting.

## Orchestration Model

The scanner service owns:
- scan execution
- job progress
- URL processing
- final result generation

The WordPress plugin owns:
- job initiation
- periodic status polling
- result ingestion
- persistence
- workflow UI

For MVP, WordPress will poll the scanner service using Action Scheduler.

Do not rely on a browser tab staying open.
Do not use WP-Cron alone as the primary orchestration mechanism.

## Technical Priorities

Prioritize:
1. correctness
2. clarity
3. maintainability
4. simple, explicit architecture
5. minimal dependencies
6. predictable data structures

Avoid overengineering.

## Coding Expectations

- Write clear, modular, production-leaning code
- Keep functions and classes focused
- Prefer explicit naming over clever abstractions
- Do not add speculative features
- Do not introduce auto-fix logic
- Do not introduce PDF support
- Do not introduce AI workflow logic unless explicitly requested

## WordPress Plugin Expectations

- Use custom DB tables for scan data and violations
- Use admin screens that are simple and functional
- Follow WordPress capability, nonce, sanitization, and escaping best practices
- Separate admin UI, DB logic, REST logic, and scheduling logic into distinct files/classes
- Keep the plugin structure easy to understand
- Add pagination to the violations screen
- Include required DB indexes from the spec

## Scanner Service Expectations

- Use Playwright to load pages
- Run axe-core against the rendered page
- Normalize axe results into a clean API response
- Generate stable fingerprints for violations using URL + rule ID + target selectors
- Support scan modes:
  - sitemap
  - manual URL list
- Support sitemap indexes in addition to standard sitemaps

## Data Model

### Site
Stores:
- the current site's local configuration for this install:
  - site name
  - base URL
  - optional sitemap URL
  - active flag

### Scan
Stores:
- site
- status
- mode
- timestamps
- initiating user
- error details if failed

### Scan URL
Stores:
- URL
- normalized URL
- HTTP status
- scanned timestamp

### Violation
Stores:
- rule ID
- impact
- help text
- help URL
- description
- HTML snippet
- target selector JSON
- fingerprint
- workflow status
- notes
- first seen
- last seen
- resolved timestamp
- status_changed_at
- status_changed_by_user_id
- ignored_at

## Required Workflow Statuses

Use these statuses:
- New
- Accepted
- In Progress
- Needs Review
- Ignored
- Resolved

Definitions:
- New: newly detected and not yet reviewed
- Accepted: reviewed and confirmed as a real issue to work on
- In Progress: currently being addressed
- Needs Review: requires human judgment or verification
- Ignored: false positive, duplicate, non-actionable, or intentionally excluded
- Resolved: issue no longer appears on re-scan

Do not invent more statuses unless requested.

## Fingerprinting Rules

The system should attempt to identify the same issue across rescans.

Initial MVP fingerprint formula:
- normalized URL
- rule ID
- normalized target selector array

Store fingerprint as a hash.

This is acceptable for MVP even if imperfect.

## Required Indexes

At minimum, the WordPress plugin schema should include indexes on:

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
- composite (`rule_id`, `workflow_status`)
- composite (`scan_url_id`, `fingerprint`)

These are not optional for MVP.

## API Shape Expectations

The scanner service should expose:
- create scan job
- get scan status
- get scan results

The WordPress plugin should be able to:
- trigger a scan
- poll status
- ingest and store normalized results

## Authentication Expectations

For MVP, use a static shared API key between WordPress and the scanner service.

Implementation expectations:
- scanner service reads API key from environment variable
- WordPress reads API key from configuration or secured plugin setting
- WordPress sends key in an authorization header or custom header
- scanner validates the key on protected endpoints
- do not hardcode secrets in the repo

This is sufficient for internal MVP use.

## UI Expectations

WordPress admin should include:
- Site Settings / Site Configuration screen
- Scan Jobs screen
- Violations screen
- Violation Detail screen

The Violations screen is the most important.

It should support filtering by:
- site
- scan
- page URL
- rule ID
- severity
- workflow status

It must also support:
- pagination
- sensible default sorting
- internal notes
- status updates

## Sitemap Expectations

For sitemap-based scans:
- support standard XML sitemaps
- support sitemap indexes
- show a preview/confirmation step before launching the scan
- display total discovered URL count and sample URLs
- fail clearly if sitemap retrieval or parsing fails

Password-protected staging environments are out of scope for automated sitemap discovery in MVP unless manual URLs are provided.

## Important Constraints

- Keep MVP narrow
- Do not add features outside scope
- Do not redesign the architecture
- Do not swap out the stack without a strong reason
- Prefer straightforward implementation over abstract frameworks
- No front-end polish is required beyond usability
- No auto-remediation logic
- No PDF support in v1
- No template-level grouping UI in v1

## Future-Friendly Constraint

The implementation should avoid making it difficult to later support grouping repeated issues at a higher level such as:
- site + rule ID
- site + rule ID + repeated selector pattern
- site + template/component signature

This matters because builder/theme-level defects may repeat across many pages.

Do not build that now, but do not block it.

## Definition of Done

The MVP is complete when:
- a site can be added in WordPress
- a scan can be triggered
- a sitemap can be scanned by the Node service
- violations can be saved into WordPress
- violations can be filtered in wp-admin
- each violation can be assigned a workflow status
- rescans can identify recurring and resolved issues

## Notes for AI Coding Agents

When implementing:
- do not broaden scope
- do not redesign the architecture
- do not swap out the stack without a strong reason
- do not add optional extras unless specifically requested
- if a decision affects core architecture, data model, or security, pause and call it out
- otherwise make a reasonable, conservative choice and continue

If uncertain, choose the option that keeps the MVP smaller, clearer, and easier to ship.
