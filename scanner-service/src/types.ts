import type { CrossTreeSelector } from 'axe-core';

export interface ScanRequest {
  mode?: 'manual' | 'sitemap';
  sitemapUrl?: string;
  url?: string;
  urls?: string[];
}

export type ScanJobStatus = 'queued' | 'running' | 'completed' | 'failed';

export interface NormalizedElement {
  target: CrossTreeSelector[];
  htmlSnippet: string;
  failureSummary: string;
}

export interface NormalizedViolation {
  ruleId: string;
  impact: string | null;
  tags: string[];
  description: string;
  help: string;
  helpUrl: string;
  elements: NormalizedElement[];
}

export interface ScanResponse {
  url: string;
  normalizedUrl: string;
  httpStatus: number | null;
  violations: NormalizedViolation[];
}

export interface ScanFailure {
  url: string;
  error: string;
}

export interface ScanJob {
  id: string;
  status: ScanJobStatus;
  urls: string[];
  createdAt: string;
  startedAt?: string;
  finishedAt?: string;
  results?: ScanResponse[];
  failures?: ScanFailure[];
  error?: string;
}
