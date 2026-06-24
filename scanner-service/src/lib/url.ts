export function parseScanUrl(rawUrl: string | undefined): URL {
  if (!rawUrl || rawUrl.trim() === '') {
    throw new Error('URL is required');
  }

  let parsedUrl: URL;

  try {
    parsedUrl = new URL(rawUrl);
  } catch {
    throw new Error('URL must be a valid absolute URL');
  }

  if (!['http:', 'https:'].includes(parsedUrl.protocol)) {
    throw new Error('URL must use http or https');
  }

  return parsedUrl;
}

export function normalizeUrl(url: URL): string {
  url.hash = '';
  return url.toString();
}

export function sanitizeErrorMessage(message: string): string {
  return message.replace(/\u001b\[[0-9;]*m/g, '');
}
