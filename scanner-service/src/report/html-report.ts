import type { ScanFailure, ScanResponse } from '../types';

export interface HtmlReportInput {
  title: string;
  generatedAt: string;
  inputMode: string;
  results: ScanResponse[];
  failures: ScanFailure[];
}

const SEVERITIES = ['critical', 'serious', 'moderate', 'minor', 'unknown'] as const;

function escapeHtml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function formatSeverity(impact: string | null): string {
  return impact && impact.trim() !== '' ? impact : 'unknown';
}

function countViolationsBySeverity(results: ScanResponse[]): Record<string, number> {
  const totals: Record<string, number> = {
    critical: 0,
    serious: 0,
    moderate: 0,
    minor: 0,
    unknown: 0
  };

  for (const result of results) {
    for (const violation of result.violations) {
      const severity = formatSeverity(violation.impact);
      totals[severity] = (totals[severity] ?? 0) + 1;
    }
  }

  return totals;
}

export function renderHtmlReport(input: HtmlReportInput): string {
  const totalViolations = input.results.reduce((count, result) => count + result.violations.length, 0);
  const severityTotals = countViolationsBySeverity(input.results);
  const safeTitle = escapeHtml(input.title);

  return `<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>${safeTitle}</title>
  <style>
    :root {
      color-scheme: light;
      --bg: #f7f7f5;
      --panel: #ffffff;
      --text: #171717;
      --muted: #595959;
      --border: #d9d7d0;
      --accent: #006d77;
      --soft: #edf6f9;
    }

    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      font-family: Arial, Helvetica, sans-serif;
      line-height: 1.5;
      color: var(--text);
      background: var(--bg);
    }

    main {
      max-width: 1100px;
      margin: 0 auto;
      padding: 32px 20px 56px;
    }

    h1,
    h2,
    h3 {
      line-height: 1.2;
    }

    h1 {
      margin: 0 0 8px;
      font-size: 32px;
    }

    h2 {
      margin: 32px 0 16px;
      font-size: 22px;
    }

    h3 {
      margin: 0 0 8px;
      font-size: 18px;
    }

    a {
      color: var(--accent);
      overflow-wrap: anywhere;
    }

    code,
    pre {
      font-family: Consolas, Monaco, monospace;
      font-size: 13px;
    }

    pre {
      white-space: pre-wrap;
      overflow-wrap: anywhere;
      padding: 12px;
      margin: 8px 0 0;
      background: #f1f1ef;
      border: 1px solid var(--border);
      border-radius: 6px;
    }

    .meta {
      margin: 0;
      color: var(--muted);
    }

    .summary-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
      gap: 12px;
      margin-top: 24px;
    }

    .summary-card,
    .page,
    .violation,
    .failure-list {
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: 8px;
    }

    .summary-card {
      padding: 16px;
    }

    .summary-card strong {
      display: block;
      font-size: 26px;
    }

    .summary-card span {
      color: var(--muted);
    }

    .page {
      padding: 20px;
      margin-top: 18px;
    }

    .page-meta,
    .violation-meta {
      margin: 0 0 12px;
      color: var(--muted);
    }

    .violation {
      padding: 16px;
      margin-top: 14px;
      background: var(--soft);
    }

    .element {
      margin-top: 14px;
      padding-top: 14px;
      border-top: 1px solid var(--border);
    }

    .failure-list {
      padding: 16px 20px;
    }

    .failure-list li {
      margin-top: 8px;
    }

    .empty {
      color: var(--muted);
      font-style: italic;
    }
  </style>
</head>
<body>
  <main>
    <header>
      <h1>${safeTitle}</h1>
      <p class="meta">Generated ${escapeHtml(input.generatedAt)} | Input: ${escapeHtml(input.inputMode)}</p>
    </header>

    <section aria-labelledby="summary-heading">
      <h2 id="summary-heading">Summary</h2>
      <div class="summary-grid">
        <div class="summary-card"><strong>${input.results.length}</strong><span>URLs scanned</span></div>
        <div class="summary-card"><strong>${input.failures.length}</strong><span>Failed URLs</span></div>
        <div class="summary-card"><strong>${totalViolations}</strong><span>Total violations</span></div>
        ${SEVERITIES.map((severity) => `<div class="summary-card"><strong>${severityTotals[severity] ?? 0}</strong><span>${escapeHtml(severity)} severity</span></div>`).join('\n        ')}
      </div>
    </section>

    ${renderFailures(input.failures)}

    <section aria-labelledby="pages-heading">
      <h2 id="pages-heading">Pages</h2>
      ${input.results.length > 0 ? input.results.map(renderPage).join('\n      ') : '<p class="empty">No pages were scanned successfully.</p>'}
    </section>
  </main>
</body>
</html>`;
}

function renderFailures(failures: ScanFailure[]): string {
  if (failures.length === 0) {
    return '';
  }

  return `<section aria-labelledby="failures-heading">
      <h2 id="failures-heading">Failed URLs</h2>
      <ul class="failure-list">
        ${failures.map((failure) => `<li><a href="${escapeHtml(failure.url)}">${escapeHtml(failure.url)}</a>: ${escapeHtml(failure.error)}</li>`).join('\n        ')}
      </ul>
    </section>`;
}

function renderPage(result: ScanResponse): string {
  return `<article class="page">
        <h3><a href="${escapeHtml(result.normalizedUrl)}">${escapeHtml(result.normalizedUrl)}</a></h3>
        <p class="page-meta">HTTP status: ${result.httpStatus ?? 'unknown'} | Violations: ${result.violations.length}</p>
        ${result.violations.length > 0 ? result.violations.map(renderViolation).join('\n        ') : '<p class="empty">No violations found on this page.</p>'}
      </article>`;
}

function renderViolation(violation: ScanResponse['violations'][number]): string {
  return `<section class="violation">
          <h3>${escapeHtml(violation.ruleId)}</h3>
          <p class="violation-meta">Impact: ${escapeHtml(formatSeverity(violation.impact))}</p>
          <p>${escapeHtml(violation.help)}</p>
          <p><a href="${escapeHtml(violation.helpUrl)}">${escapeHtml(violation.helpUrl)}</a></p>
          ${violation.elements.length > 0 ? violation.elements.map(renderElement).join('\n          ') : '<p class="empty">No affected elements were reported.</p>'}
        </section>`;
}

function renderElement(element: ScanResponse['violations'][number]['elements'][number]): string {
  const target = element.target.map((selector) => String(selector)).join(', ');

  return `<div class="element">
            <p><strong>Selector:</strong> <code>${escapeHtml(target || 'unknown')}</code></p>
            ${element.htmlSnippet ? `<p><strong>HTML snippet:</strong></p><pre>${escapeHtml(element.htmlSnippet)}</pre>` : ''}
            ${element.failureSummary ? `<p><strong>Failure summary:</strong></p><pre>${escapeHtml(element.failureSummary)}</pre>` : ''}
          </div>`;
}
