import axe from 'axe-core';
import { chromium, type Browser } from 'playwright';
import type { ScanFailure, ScanRequest, ScanResponse } from '../types';
import { normalizeViolations } from './normalize';
import { parseSitemapUrls } from './sitemap';
import { normalizeUrl, parseScanUrl, sanitizeErrorMessage } from './url';

const RENDER_DELAY_MS = 1000;
const NAVIGATION_TIMEOUT_MS = 30000;

export async function parseScanUrls(payload: ScanRequest): Promise<URL[]> {
  if (payload.mode === 'sitemap' || payload.sitemapUrl) {
    return await parseSitemapUrls(payload.sitemapUrl);
  }

  if (Array.isArray(payload.urls)) {
    if (payload.urls.length === 0) {
      throw new Error('At least one URL is required');
    }

    return payload.urls.map((url) => parseScanUrl(url));
  }

  return [parseScanUrl(payload.url)];
}

export async function scanSingleUrl(url: URL): Promise<ScanResponse> {
  let browser: Browser | undefined;

  try {
    const normalizedUrl = normalizeUrl(url);

    browser = await chromium.launch();
    const page = await browser.newPage();
    page.setDefaultNavigationTimeout(NAVIGATION_TIMEOUT_MS);

    const response = await page.goto(normalizedUrl, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(RENDER_DELAY_MS);
    await page.addScriptTag({ content: axe.source });

    const results = await page.evaluate(async () => {
      return await (window as typeof window & { axe: typeof axe }).axe.run();
    });

    return {
      url: url.toString(),
      normalizedUrl,
      httpStatus: response?.status() ?? null,
      violations: normalizeViolations(results)
    };
  } finally {
    if (browser) {
      await browser.close();
    }
  }
}

export async function scanUrls(urls: URL[]): Promise<{ results: ScanResponse[]; failures: ScanFailure[] }> {
  const results: ScanResponse[] = [];
  const failures: ScanFailure[] = [];

  for (const parsedUrl of urls) {
    try {
      const result = await scanSingleUrl(parsedUrl);
      results.push(result);
    } catch (error: unknown) {
      const message = error instanceof Error ? sanitizeErrorMessage(error.message) : 'Unknown scan error';
      failures.push({
        url: parsedUrl.toString(),
        error: message
      });
    }
  }

  return { results, failures };
}
