# AGENTS.md

Read these files before making changes:
- /docs/SPEC.md
- /docs/PROJECT_CONTEXT.md
- /docs/CURRENT_STATE.md
- /docs/TASKS.md
- /docs/DECISIONS.md

## Project Goal

Build an MVP accessibility scanning and issue-tracking system with:

1. an external Node.js scanner service using Playwright and axe-core
2. a WordPress plugin for admin UI, persistence, and workflow

## Core Rules

- Keep scope tightly aligned to the MVP
- Do not add features outside the current phase
- Do not redesign the architecture unless explicitly asked
- Prefer small, explicit, maintainable implementations
- Avoid clever abstractions unless they clearly simplify the code
- When in doubt, choose the smaller implementation

## Architecture Rules

- Do not run browser automation inside WordPress/PHP
- The scanner service owns scan execution
- The WordPress plugin owns admin UI, persistence, and workflow
- WordPress and the scanner communicate via API
- For MVP, use a static shared API key when authentication is added

## Current Scope

In scope for MVP:
- site management
- sitemap/manual URL scans
- normalized violation capture
- workflow statuses
- filtering
- rescans

Out of scope for MVP:
- auto-fixing
- AI-generated remediation
- PDF scanning
- authenticated flow testing
- screenshots
- screen reader simulation
- Jira/Asana integrations
- polished client reporting
- template-level grouping UI

## Implementation Expectations

- Keep functions/classes focused
- Use clear names
- Do not add speculative dependencies
- Do not add premature extensibility
- Follow the current phase only
- If a change affects architecture, security, or the data model, note it clearly

## WordPress Rules

- Use custom DB tables for scan data
- Do not use posts/postmeta for scan storage
- Respect required indexes from the spec
- Use capability checks, nonces, sanitization, and escaping
- Keep admin UI simple and functional

## Scanner Service Rules

- Use Playwright to load pages
- Use axe-core for scanning
- Return normalized JSON
- For MVP, keep scan behavior explicit and easy to trace
- Do not add extra scan engines unless explicitly asked

## Workflow Rules

Use only these statuses unless explicitly changed:
- New
- Accepted
- In Progress
- Needs Review
- Ignored
- Resolved

Definitions:
- Accepted = real issue confirmed for work
- Ignored = false positive, duplicate, non-actionable, or intentionally excluded

## Working Style

When given a task:
1. Read the project docs first
2. Identify the current phase
3. Make only the requested changes
4. Do not quietly expand scope
5. Summarize:
   - what you changed
   - why
   - anything still incomplete

## If You Are Unsure

- If uncertainty affects core architecture, data model, or security, pause and call it out
- Otherwise make a conservative choice and continue