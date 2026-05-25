import { z } from 'zod';
import { wpClient } from '../utils/wordpress-client.js';

export const readLogsSchema = z.object({
  log_type: z.enum(['debug', 'error', 'access', 'php', 'custom']).default('debug').describe('Log type'),
  lines: z.number().default(100).describe('Number of lines to return (max 1000)'),
  filter: z.string().optional().describe('Filter pattern (regex supported)'),
  since: z.string().optional().describe('Filter entries since datetime (ISO 8601)'),
  severity: z.enum(['all', 'error', 'warning', 'notice', 'info']).default('all').describe('Minimum severity level'),
  custom_path: z.string().optional().describe('Custom log file path (requires custom log_type)'),
});

export type ReadLogsInput = z.infer<typeof readLogsSchema>;

interface LogEntry {
  timestamp: string;
  severity: string;
  message: string;
  context?: Record<string, unknown>;
}

interface ReadLogsResponse {
  entries: LogEntry[];
  total_lines: number;
  log_file: string;
  file_size_bytes: number;
  last_modified: string;
}

export async function readLogs(input: ReadLogsInput): Promise<string> {
  const response = await wpClient.get<ReadLogsResponse>('/logs', {
    log_type: input.log_type,
    lines: Math.min(input.lines, 1000),
    filter: input.filter,
    since: input.since,
    severity: input.severity,
    custom_path: input.custom_path,
  });

  if (!response.success || !response.data) {
    return JSON.stringify({ error: response.error || 'Failed to read logs' });
  }

  return JSON.stringify({
    success: true,
    entries: response.data.entries,
    meta: {
      total_lines: response.data.total_lines,
      log_file: response.data.log_file,
      file_size_bytes: response.data.file_size_bytes,
      last_modified: response.data.last_modified,
    },
  });
}

export const readLogsTool = {
  name: 'read_logs',
  description: 'Read WordPress debug.log, PHP error logs, or custom log files',
  inputSchema: {
    type: 'object' as const,
    properties: {
      log_type: {
        type: 'string',
        enum: ['debug', 'error', 'access', 'php', 'custom'],
        default: 'debug',
        description: 'Log type',
      },
      lines: { type: 'number', default: 100, description: 'Number of lines to return (max 1000)' },
      filter: { type: 'string', description: 'Filter pattern (regex supported)' },
      since: { type: 'string', description: 'Filter entries since datetime (ISO 8601)' },
      severity: {
        type: 'string',
        enum: ['all', 'error', 'warning', 'notice', 'info'],
        default: 'all',
        description: 'Minimum severity level',
      },
      custom_path: { type: 'string', description: 'Custom log file path (requires custom log_type)' },
    },
    required: [],
  },
};
