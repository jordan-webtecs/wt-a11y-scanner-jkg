import { normalizeUrl, parseScanUrl } from './url';

const MAX_SITEMAP_URLS = 100;

function decodeXmlEntities(value: string): string {
  return value
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#39;/g, "'");
}

function extractLocValues(xml: string): string[] {
  const matches = xml.matchAll(/<loc>\s*([^<]+?)\s*<\/loc>/gi);
  return Array.from(matches, (match) => decodeXmlEntities(match[1].trim()));
}

async function fetchSitemapXml(sitemapUrl: string): Promise<string> {
  const response = await fetch(sitemapUrl);

  if (!response.ok) {
    throw new Error(`Failed to fetch sitemap: HTTP ${response.status}`);
  }

  return await response.text();
}

async function collectUrlsFromSitemap(
  sitemapUrl: URL,
  discoveredUrls: URL[],
  seenPageUrls: Set<string>,
  seenSitemaps: Set<string>
): Promise<void> {
  const normalizedSitemapUrl = normalizeUrl(sitemapUrl);

  if (seenSitemaps.has(normalizedSitemapUrl)) {
    return;
  }

  seenSitemaps.add(normalizedSitemapUrl);

  const xml = await fetchSitemapXml(normalizedSitemapUrl);
  const locValues = extractLocValues(xml);

  if (/<sitemapindex[\s>]/i.test(xml)) {
    if (locValues.length === 0) {
      throw new Error('Sitemap index did not contain any child sitemap URLs');
    }

    for (const locValue of locValues) {
      if (discoveredUrls.length >= MAX_SITEMAP_URLS) {
        return;
      }

      await collectUrlsFromSitemap(parseScanUrl(locValue), discoveredUrls, seenPageUrls, seenSitemaps);
    }

    return;
  }

  if (!/<urlset[\s>]/i.test(xml)) {
    throw new Error('Sitemap XML must be a <urlset> or <sitemapindex> document');
  }

  if (locValues.length === 0) {
    throw new Error('Sitemap did not contain any page URLs');
  }

  for (const locValue of locValues) {
    if (discoveredUrls.length >= MAX_SITEMAP_URLS) {
      return;
    }

    const parsedUrl = parseScanUrl(locValue);
    const normalizedPageUrl = normalizeUrl(parsedUrl);

    if (seenPageUrls.has(normalizedPageUrl)) {
      continue;
    }

    seenPageUrls.add(normalizedPageUrl);
    discoveredUrls.push(parsedUrl);
  }
}

export async function parseSitemapUrls(sitemapUrl: string | undefined): Promise<URL[]> {
  const parsedSitemapUrl = parseScanUrl(sitemapUrl);
  const discoveredUrls: URL[] = [];
  const seenPageUrls = new Set<string>();
  const seenSitemaps = new Set<string>();

  await collectUrlsFromSitemap(parsedSitemapUrl, discoveredUrls, seenPageUrls, seenSitemaps);

  if (discoveredUrls.length === 0) {
    throw new Error('Sitemap did not produce any scan URLs');
  }

  return discoveredUrls;
}
