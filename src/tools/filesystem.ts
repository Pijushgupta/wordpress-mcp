import { z } from 'zod';
import { wpClient } from '../utils/wordpress-client.js';

// List Directory
export const listDirectorySchema = z.object({
  path: z.string().describe('Relative path from WordPress root'),
  pattern: z.string().default('*').describe('Glob pattern (e.g., *.php, *.js)'),
  recursive: z.boolean().default(false).describe('Search recursively'),
});

export type ListDirectoryInput = z.infer<typeof listDirectorySchema>;

interface FileItem {
  name: string;
  path: string;
  type: 'file' | 'directory';
  size: number | null;
  modified: string;
  permissions: string;
}

interface ListDirectoryResponse {
  path: string;
  count: number;
  items: FileItem[];
}

export async function listDirectory(input: ListDirectoryInput): Promise<string> {
  const response = await wpClient.get<ListDirectoryResponse>('/filesystem/list', {
    path: input.path,
    pattern: input.pattern,
    recursive: input.recursive,
  });

  if (!response.success || !response.data) {
    return JSON.stringify({ error: response.error || 'Failed to list directory' });
  }

  return JSON.stringify({
    success: true,
    ...response.data,
  });
}

// Read File
export const readFileSchema = z.object({
  path: z.string().describe('Relative path from WordPress root'),
  lines: z.number().default(0).describe('Limit to first N lines (0 = all)'),
});

export type ReadFileInput = z.infer<typeof readFileSchema>;

interface ReadFileResponse {
  path: string;
  size: number;
  lines: number;
  content: string;
  mime_type: string;
}

export async function readFile(input: ReadFileInput): Promise<string> {
  const response = await wpClient.get<ReadFileResponse>('/filesystem/read', {
    path: input.path,
    lines: input.lines,
  });

  if (!response.success || !response.data) {
    return JSON.stringify({ error: response.error || 'Failed to read file' });
  }

  return JSON.stringify({
    success: true,
    ...response.data,
  });
}

// Write File
export const writeFileSchema = z.object({
  path: z.string().describe('Relative path from WordPress root'),
  content: z.string().describe('File content to write'),
  backup: z.boolean().default(true).describe('Create backup before overwriting'),
});

export type WriteFileInput = z.infer<typeof writeFileSchema>;

interface WriteFileResponse {
  path: string;
  bytes_written: number;
  backup_path: string | null;
}

export async function writeFile(input: WriteFileInput): Promise<string> {
  const response = await wpClient.post<WriteFileResponse>('/filesystem/write', {
    path: input.path,
    content: input.content,
    backup: input.backup,
  });

  if (!response.success || !response.data) {
    return JSON.stringify({ error: response.error || 'Failed to write file' });
  }

  return JSON.stringify({
    success: true,
    ...response.data,
    message: 'File written successfully',
  });
}

// Create Zip
export const createZipSchema = z.object({
  path: z.string().describe('Directory or file to zip'),
  filename: z.string().optional().describe('Output zip filename'),
});

export type CreateZipInput = z.infer<typeof createZipSchema>;

interface CreateZipResponse {
  zip_path: string;
  zip_size: number;
  download_url: string;
}

export async function createZip(input: CreateZipInput): Promise<string> {
  const response = await wpClient.post<CreateZipResponse>('/filesystem/zip', {
    path: input.path,
    filename: input.filename,
  });

  if (!response.success || !response.data) {
    return JSON.stringify({ error: response.error || 'Failed to create zip' });
  }

  return JSON.stringify({
    success: true,
    ...response.data,
    message: 'Zip archive created successfully',
  });
}

// Extract Zip
export const extractZipSchema = z.object({
  zip_path: z.string().describe('Path to zip file'),
  destination: z.string().describe('Extraction destination'),
  overwrite: z.boolean().default(false).describe('Overwrite existing files'),
});

export type ExtractZipInput = z.infer<typeof extractZipSchema>;

interface ExtractZipResponse {
  destination: string;
  files_extracted: number;
}

export async function extractZip(input: ExtractZipInput): Promise<string> {
  const response = await wpClient.post<ExtractZipResponse>('/filesystem/unzip', {
    zip_path: input.zip_path,
    destination: input.destination,
    overwrite: input.overwrite,
  });

  if (!response.success || !response.data) {
    return JSON.stringify({ error: response.error || 'Failed to extract zip' });
  }

  return JSON.stringify({
    success: true,
    ...response.data,
    message: 'Zip extracted successfully',
  });
}

// Delete Path
export const deletePathSchema = z.object({
  path: z.string().describe('Path to delete'),
  recursive: z.boolean().default(false).describe('Delete directory recursively'),
});

export type DeletePathInput = z.infer<typeof deletePathSchema>;

interface DeletePathResponse {
  deleted: string;
  type: 'file' | 'directory';
}

export async function deletePath(input: DeletePathInput): Promise<string> {
  const response = await wpClient.delete<DeletePathResponse>('/filesystem/delete', {
    path: input.path,
    recursive: input.recursive,
  });

  if (!response.success || !response.data) {
    return JSON.stringify({ error: response.error || 'Failed to delete path' });
  }

  return JSON.stringify({
    success: true,
    ...response.data,
    message: 'Deleted successfully',
  });
}
