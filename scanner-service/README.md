# Scanner Service

Node.js + TypeScript accessibility scanner service for the WT Accessibility Scanner MVP.

This service is one half of the MVP architecture:
- the scanner service owns browser-based scan execution
- the WordPress plugin owns admin UI, persistence, ingestion, and workflow

If you want the project-wide overview, start with the repo README:
- [../README.md](../README.md)

## Current Status

The current scanner implementation supports:
- `POST /scan` for a direct single-page scan
- authenticated scan job creation with `POST /api/scans`
- authenticated job status polling with `GET /api/scans/:jobId`
- authenticated completed results retrieval with `GET /api/scans/:jobId/results`
- standalone HTML report generation from the CLI
- manual URL list scans
- sitemap scans
- sitemap index scans
- normalized URL handling
- per-URL failure capture
- normalized axe violations
- preserved axe rule tags on normalized violations

Current implementation details:
- scan jobs are stored in memory
- results are not persisted by the scanner service itself
- the WordPress plugin is expected to fetch and store completed results locally

Not implemented here:
- scanner-side database persistence
- screenshot capture
- authenticated user-flow scanning
- PDF scanning
- rescan comparison logic
- auto-remediation
- historical report comparison
- WCAG criterion grouping beyond stored axe data

## Requirements

- Node.js
- npm
- Playwright browser binaries installed with `npx playwright install`

## Environment Variables

### `SCANNER_API_KEY`

Required for the authenticated job endpoints:
- `POST /api/scans`
- `GET /api/scans/:jobId`
- `GET /api/scans/:jobId/results`

The scanner reads `SCANNER_API_KEY` once at process startup from `process.env.SCANNER_API_KEY`.

Important:
- set the variable before starting Node
- restart the scanner after changing it
- if the scanner starts without it, protected endpoints will return:

```json
{
  "error": "Scanner API key is not configured on the server"
}
```

### `PORT`

Optional. Defaults to `3000`.

## Setup

```powershell
cd scanner-service
npm install
npx playwright install
```

## Run

### API Service

Development:

```powershell
$env:SCANNER_API_KEY = 'replace-with-shared-key'
npm run dev
```

Build + run:

```powershell
$env:SCANNER_API_KEY = 'replace-with-shared-key'
npm run build
npm start
```

Default local URL:

```text
http://127.0.0.1:3000
```

### Standalone HTML Reports

The report CLI runs inside this package and reuses the same scanner logic as the API service. It writes a self-contained HTML file with no external assets.

Only one input mode may be used per command:
- `--url`
- `--sitemap`
- `--urls-file`

The `--out` flag is required. `--title` is optional.

Single URL report:

```powershell
npm run report -- --url https://example.com --out ./reports/example-report.html --title "Example Accessibility Report"
```

Sitemap report:

```powershell
npm run report -- --sitemap https://example.com/sitemap.xml --out ./reports/example-sitemap-report.html --title "Example Sitemap Accessibility Report"
```

URL file report:

```powershell
npm run report -- --urls-file ./urls.txt --out ./reports/example-url-list-report.html --title "Example URL List Accessibility Report"
```

The URL file should contain one absolute `http` or `https` URL per line.

After building, the compiled CLI can also be run with:

```powershell
npm run build
npm run report:dist -- --url https://example.com --out ./reports/example-report.html
```

The v1 report includes:
- report title and generation timestamp
- input mode used
- scanned URL count
- failed URL count and failure messages
- total violations
- totals by severity
- page-by-page violation sections
- rule ID, impact, help text, help URL, selectors, HTML snippets, and failure summaries where axe provides them

## Authentication

Protected scan job endpoints accept either:
- `Authorization: Bearer <SCANNER_API_KEY>`
- `X-ACC-API-Key: <SCANNER_API_KEY>`

If the key is missing or invalid, the service returns:

```json
{
  "error": "A valid scanner API key is required"
}
```

## API

### `POST /scan`

Runs an immediate single-page scan without scanner API key auth.

Request body:

```json
{
  "url": "https://example.com"
}
```

Example response shape:

```json
{
  "url": "https://example.com/",
  "normalizedUrl": "https://example.com/",
  "httpStatus": 200,
  "violations": [
    {
      "ruleId": "image-alt",
      "impact": "critical",
      "tags": [
        "cat.text-alternatives",
        "wcag2a",
        "wcag111"
      ],
      "description": "Ensures <img> elements have alternate text or a role of none or presentation",
      "help": "Images must have alternate text",
      "helpUrl": "https://dequeuniversity.com/rules/axe/4.8/image-alt",
      "elements": [
        {
          "target": [
            "img"
          ],
          "htmlSnippet": "<img src=\"image.jpg\">",
          "failureSummary": "Element does not have an alt attribute"
        }
      ]
    }
  ]
}
```

Validation rules:
- `url` is required
- `url` must be an absolute `http` or `https` URL

Current scan behavior:
- waits for `domcontentloaded`
- applies a small fixed render delay
- injects bundled `axe-core`

### `POST /api/scans`

Creates an authenticated scan job.

Request body examples:

Single URL:

```json
{
  "url": "https://example.com"
}
```

Manual URL list:

```json
{
  "urls": [
    "https://example.com",
    "https://example.org"
  ]
}
```

Sitemap mode:

```json
{
  "mode": "sitemap",
  "sitemapUrl": "https://example.com/sitemap.xml"
}
```

Successful response:

```json
{
  "jobId": "scan_001",
  "status": "queued",
  "urls": [
    "https://example.com/",
    "https://example.org/"
  ]
}
```

Sitemap behavior:
- supports standard XML sitemap files
- supports sitemap index files
- resolves sitemap URLs before creating the job
- scans up to `100` discovered URLs per sitemap job

### `GET /api/scans/:jobId`

Returns job status.

Example response:

```json
{
  "jobId": "scan_001",
  "status": "running",
  "urls": [
    "https://example.com/",
    "https://example.org/"
  ],
  "createdAt": "2026-03-23T12:00:00.000Z",
  "startedAt": "2026-03-23T12:00:01.000Z",
  "finishedAt": null,
  "failures": [],
  "error": null
}
```

### `GET /api/scans/:jobId/results`

Returns completed results for a job.

If the job is not ready yet:

```json
{
  "jobId": "scan_001",
  "status": "queued",
  "error": "Scan results are not ready yet"
}
```

When complete:

```json
{
  "jobId": "scan_001",
  "status": "completed",
  "results": [
    {
      "url": "https://example.com/",
      "normalizedUrl": "https://example.com/",
      "httpStatus": 200,
      "violations": []
    }
  ],
  "failures": []
}
```

If all submitted URLs fail, the job result endpoint returns a failed response with failures and an error message.

## Job Statuses

Current in-memory scan job statuses:
- `queued`
- `running`
- `completed`
- `failed`
