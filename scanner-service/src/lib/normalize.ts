import type { AxeResults } from 'axe-core';
import type { NormalizedViolation } from '../types';

export function normalizeViolations(results: AxeResults): NormalizedViolation[] {
  return results.violations.map((violation) => ({
    ruleId: violation.id,
    impact: violation.impact ?? null,
    tags: Array.isArray(violation.tags)
      ? violation.tags.filter((tag): tag is string => typeof tag === 'string')
      : [],
    description: violation.description,
    help: violation.help,
    helpUrl: violation.helpUrl,
    elements: violation.nodes.map((node) => ({
      target: [...node.target],
      htmlSnippet: node.html,
      failureSummary: node.failureSummary ?? ''
    }))
  }));
}
