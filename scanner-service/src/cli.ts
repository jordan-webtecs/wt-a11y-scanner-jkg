import { mkdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { parseScanUrls, scanUrls } from './lib/scan';
import { renderHtmlReport } from './report/html-report';
import type { ScanRequest } from './types';

interface CliOptions {
  url?: string;
  sitemap?: string;
  urlsFile?: string;
  out?: string;
  title: string;
}

const DEFAULT_TITLE = 'Accessibility Scan Report';

function readFlagValue(args: string[], index: number, flag: string): string {
  const value = args[index + 1];

  if (!value || value.startsWith('--')) {
    throw new Error(`${flag} requires a value`);
  }

  return value;
}

function parseArgs(args: string[]): CliOptions {
  const options: CliOptions = {
    title: DEFAULT_TITLE
  };

  for (let index = 0; index < args.length; index += 1) {
    const arg = args[index];

    switch (arg) {
      case '--url':
        options.url = readFlagValue(args, index, arg);
        index += 1;
        break;
      case '--sitemap':
        options.sitemap = readFlagValue(args, index, arg);
        index += 1;
        break;
      case '--urls-file':
        options.urlsFile = readFlagValue(args, index, arg);
        index += 1;
        break;
      case '--out':
        options.out = readFlagValue(args, index, arg);
        index += 1;
        break;
      case '--title':
        options.title = readFlagValue(args, index, arg);
        index += 1;
        break;
      case '--help':
        throw new Error(usage());
      default:
        throw new Error(`Unknown argument: ${arg}`);
    }
  }

  const inputCount = [options.url, options.sitemap, options.urlsFile].filter(Boolean).length;

  if (inputCount !== 1) {
    throw new Error('Provide exactly one input mode: --url, --sitemap, or --urls-file');
  }

  if (!options.out) {
    throw new Error('--out is required');
  }

  return options;
}

function usage(): string {
  return [
    'Usage:',
    '  npm run report -- --url https://example.com --out ./report.html',
    '  npm run report -- --sitemap https://example.com/sitemap.xml --out ./report.html',
    '  npm run report -- --urls-file ./urls.txt --out ./report.html',
    '',
    'Options:',
    '  --title "Client Name Accessibility Report"'
  ].join('\n');
}

async function readUrlsFile(urlsFile: string): Promise<string[]> {
  const contents = await readFile(urlsFile, 'utf8');
  const urls = contents
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter((line) => line !== '');

  if (urls.length === 0) {
    throw new Error('URL file must contain at least one URL');
  }

  return urls;
}

async function buildScanRequest(options: CliOptions): Promise<{ inputMode: string; request: ScanRequest }> {
  if (options.url) {
    return {
      inputMode: `single URL (${options.url})`,
      request: { url: options.url }
    };
  }

  if (options.sitemap) {
    return {
      inputMode: `sitemap (${options.sitemap})`,
      request: { mode: 'sitemap', sitemapUrl: options.sitemap }
    };
  }

  if (!options.urlsFile) {
    throw new Error('No scan input was provided');
  }

  return {
    inputMode: `URLs file (${options.urlsFile})`,
    request: { urls: await readUrlsFile(options.urlsFile) }
  };
}

async function main(): Promise<void> {
  const options = parseArgs(process.argv.slice(2));
  const { inputMode, request } = await buildScanRequest(options);
  const urls = await parseScanUrls(request);

  console.log(`Scanning ${urls.length} URL(s)...`);

  const { results, failures } = await scanUrls(urls);
  const html = renderHtmlReport({
    title: options.title,
    generatedAt: new Date().toISOString(),
    inputMode,
    results,
    failures
  });
  const outPath = path.resolve(options.out ?? 'report.html');
  const outDir = path.dirname(outPath);

  await mkdir(outDir, { recursive: true });
  await writeFile(outPath, html, 'utf8');

  console.log(`Report written to ${outPath}`);
  console.log(`Scanned: ${results.length}; failed: ${failures.length}`);
}

main().catch((error: unknown) => {
  const message = error instanceof Error ? error.message : 'Unknown CLI error';
  console.error(message);

  if (!message.startsWith('Usage:')) {
    console.error('');
    console.error(usage());
  }

  process.exitCode = 1;
});
