import express from 'express';
import { parseScanUrls, scanSingleUrl, scanUrls } from './lib/scan';
import { parseScanUrl, sanitizeErrorMessage } from './lib/url';
import type { ScanJob, ScanRequest } from './types';

const DEFAULT_PORT = 3000;
const SCANNER_API_KEY_HEADER = 'x-acc-api-key';

const app = express();
app.use(express.json({ limit: '1mb' }));

const scannerApiKey = process.env.SCANNER_API_KEY?.trim() ?? '';
const scanJobs = new Map<string, ScanJob>();
let nextJobNumber = 1;

function createJobId(): string {
  const jobId = `scan_${String(nextJobNumber).padStart(3, '0')}`;
  nextJobNumber += 1;
  return jobId;
}

function getRequestApiKey(req: express.Request): string {
  const authorizationHeader = req.header('authorization');

  if (authorizationHeader && authorizationHeader.startsWith('Bearer ')) {
    return authorizationHeader.slice('Bearer '.length).trim();
  }

  return req.header(SCANNER_API_KEY_HEADER)?.trim() ?? '';
}

function requireApiKey(req: express.Request, res: express.Response, next: express.NextFunction): void {
  if (!scannerApiKey) {
    res.status(500).json({ error: 'Scanner API key is not configured on the server' });
    return;
  }

  const requestApiKey = getRequestApiKey(req);

  if (!requestApiKey || requestApiKey !== scannerApiKey) {
    res.status(401).json({ error: 'A valid scanner API key is required' });
    return;
  }

  next();
}

function getJobOrThrow(jobId: string): ScanJob {
  const job = scanJobs.get(jobId);

  if (!job) {
    throw new Error('Scan job not found');
  }

  return job;
}

async function runScanJob(jobId: string): Promise<void> {
  const job = getJobOrThrow(jobId);

  job.status = 'running';
  job.startedAt = new Date().toISOString();

  try {
    const parsedUrls = await parseScanUrls({ urls: job.urls });
    const { results, failures } = await scanUrls(parsedUrls);

    job.results = results;
    job.failures = failures;
    job.finishedAt = new Date().toISOString();

    if (results.length > 0) {
      job.status = 'completed';
      job.error = failures.length > 0 ? 'Some submitted URLs failed to scan' : undefined;
      return;
    }

    job.status = 'failed';
    job.error = failures[0]?.error ?? 'All submitted URLs failed to scan';
  } catch (error: unknown) {
    job.status = 'failed';
    job.finishedAt = new Date().toISOString();
    job.error = error instanceof Error ? sanitizeErrorMessage(error.message) : 'Unknown scan error';
  }
}

app.post('/scan', async (req: express.Request, res: express.Response) => {
  try {
    const parsedUrl = parseScanUrl((req.body as ScanRequest).url);
    const payload = await scanSingleUrl(parsedUrl);

    return res.json(payload);
  } catch (error: unknown) {
    const message = error instanceof Error ? error.message : 'Unknown scan error';
    const statusCode = message.includes('URL') ? 400 : 500;

    return res.status(statusCode).json({ error: message });
  }
});

app.post('/api/scans', requireApiKey, async (req: express.Request, res: express.Response) => {
  try {
    const parsedUrls = await parseScanUrls(req.body as ScanRequest);
    const jobId = createJobId();
    const job: ScanJob = {
      id: jobId,
      status: 'queued',
      urls: parsedUrls.map((url) => url.toString()),
      createdAt: new Date().toISOString()
    };

    scanJobs.set(jobId, job);

    // Run the scan asynchronously so callers can poll job status.
    setTimeout(() => {
      void runScanJob(jobId);
    }, 0);

    return res.status(202).json({
      jobId: job.id,
      status: job.status,
      urls: job.urls
    });
  } catch (error: unknown) {
    const message = error instanceof Error ? error.message : 'Unknown scan error';
    const statusCode = message.includes('URL') ? 400 : 500;

    return res.status(statusCode).json({ error: message });
  }
});

app.get('/api/scans/:jobId', requireApiKey, (req: express.Request, res: express.Response) => {
  try {
    const job = getJobOrThrow(req.params.jobId);

    return res.json({
      jobId: job.id,
      status: job.status,
      urls: job.urls,
      createdAt: job.createdAt,
      startedAt: job.startedAt ?? null,
      finishedAt: job.finishedAt ?? null,
      failures: job.failures ?? [],
      error: job.error ?? null
    });
  } catch (error: unknown) {
    const message = error instanceof Error ? error.message : 'Unknown scan error';
    const statusCode = message === 'Scan job not found' ? 404 : 500;

    return res.status(statusCode).json({ error: message });
  }
});

app.get('/api/scans/:jobId/results', requireApiKey, (req: express.Request, res: express.Response) => {
  try {
    const job = getJobOrThrow(req.params.jobId);

    if (job.status === 'failed') {
      return res.status(500).json({
        jobId: job.id,
        status: job.status,
        failures: job.failures ?? [],
        error: job.error ?? 'Unknown scan error'
      });
    }

    if (job.status !== 'completed' || !job.results) {
      return res.status(409).json({
        jobId: job.id,
        status: job.status,
        error: 'Scan results are not ready yet'
      });
    }

    return res.json({
      jobId: job.id,
      status: job.status,
      results: job.results,
      failures: job.failures ?? []
    });
  } catch (error: unknown) {
    const message = error instanceof Error ? error.message : 'Unknown scan error';
    const statusCode = message === 'Scan job not found' ? 404 : 500;

    return res.status(statusCode).json({ error: message });
  }
});

const port = Number(process.env.PORT ?? DEFAULT_PORT);
app.listen(port, () => {
  console.log(`Scanner service running on port ${port}`);
});
