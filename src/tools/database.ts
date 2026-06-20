import { z } from 'zod';
import { wpClient } from '../utils/wordpress-client.js';

// Database Query
export const databaseQuerySchema = z.object({
  query: z.string().describe('SQL query to execute'),
  type: z.enum(['select', 'insert', 'update', 'delete']).describe('Query type for validation'),
});

export type DatabaseQueryInput = z.infer<typeof databaseQuerySchema>;

interface SelectQueryResponse {
  success: boolean;
  type: 'select';
  rows: Record<string, unknown>[];
  row_count: number;
  columns: string[];
  execution_time: string;
}

interface MutationQueryResponse {
  success: boolean;
  type: 'insert' | 'update' | 'delete';
  affected_rows: number;
  insert_id: number | null;
  execution_time: string;
}

type QueryResponse = SelectQueryResponse | MutationQueryResponse;

export async function databaseQuery(input: DatabaseQueryInput): Promise<string> {
  const response = await wpClient.post<QueryResponse>('/database/query', {
    query: input.query,
    type: input.type,
  });

  if (!response.success || !response.data) {
    return JSON.stringify({ error: response.error || 'Failed to execute query' });
  }

  return JSON.stringify(response.data);
}

// Database Export
export const databaseExportSchema = z.object({
  tables: z
    .array(z.string())
    .optional()
    .describe('Specific tables to export (empty = all)'),
  compress: z.boolean().default(true).describe('Compress output with gzip'),
  exclude_tables: z
    .array(z.string())
    .optional()
    .describe('Tables to exclude from export'),
  no_data: z
    .boolean()
    .default(false)
    .describe('Export structure only (no data)'),
});

export type DatabaseExportInput = z.infer<typeof databaseExportSchema>;

interface DatabaseExportResponse {
  success: boolean;
  filename: string;
  path: string;
  size: number;
  size_human: string;
  download_url: string;
  tables: string[] | 'all';
  excluded: string[];
  compressed: boolean;
  structure_only: boolean;
}

export async function databaseExport(input: DatabaseExportInput): Promise<string> {
  const response = await wpClient.post<DatabaseExportResponse>('/database/export', {
    tables: input.tables,
    compress: input.compress,
    exclude_tables: input.exclude_tables,
    no_data: input.no_data,
  });

  if (!response.success || !response.data) {
    return JSON.stringify({ error: response.error || 'Failed to export database' });
  }

  return JSON.stringify({
    ...response.data,
    message: 'Database exported successfully. Use download_url to fetch the file.',
  });
}

// List Database Exports
export const databaseListExportsSchema = z.object({});

export type DatabaseListExportsInput = z.infer<typeof databaseListExportsSchema>;

interface ExportFile {
  filename: string;
  size: number;
  size_human: string;
  created: string;
  download_url: string;
}

interface DatabaseListExportsResponse {
  exports: ExportFile[];
  count: number;
}

export async function databaseListExports(): Promise<string> {
  const response = await wpClient.get<DatabaseListExportsResponse>('/database/exports');

  if (!response.success || !response.data) {
    return JSON.stringify({ error: response.error || 'Failed to list exports' });
  }

  return JSON.stringify(response.data);
}
