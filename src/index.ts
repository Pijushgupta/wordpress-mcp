#!/usr/bin/env node

import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { z } from 'zod';
import { config } from './config/index.js';
import {
  wordpressCreatePost,
  wordpressCreatePostSchema,
  wordpressQueryPosts,
  wordpressQueryPostsSchema,
  wordpressGetPost,
  wordpressGetPostSchema,
  woocommerceQueryOrders,
  woocommerceQueryOrdersSchema,
  generateInvoice,
  generateInvoiceSchema,
  crmSearch,
  crmSearchSchema,
  n8nTrigger,
  n8nTriggerSchema,
  generateImage,
  generateImageSchema,
  deployCode,
  deployCodeSchema,
  readLogs,
  readLogsSchema,
  // Filesystem tools (PHP-native)
  listDirectory,
  listDirectorySchema,
  readFile,
  readFileSchema,
  writeFile,
  writeFileSchema,
  createZip,
  createZipSchema,
  extractZip,
  extractZipSchema,
  deletePath,
  deletePathSchema,
  // Database tools
  databaseQuery,
  databaseQuerySchema,
  databaseExport,
  databaseExportSchema,
  databaseListExports,
  databaseListExportsSchema,
} from './tools/index.js';

const server = new McpServer({
  name: 'wordpress-mcp-bridge',
  version: '1.0.0',
});

// Register all tools
server.tool(
  'wordpress_create_post',
  'Create or update a WordPress post, page, or custom post type',
  wordpressCreatePostSchema.shape,
  async (args) => {
    const parsed = wordpressCreatePostSchema.parse(args);
    const result = await wordpressCreatePost(parsed);
    return { content: [{ type: 'text', text: result }] };
  }
);

server.tool(
  'wordpress_query_posts',
  'Query WordPress posts with filtering, search, pagination. Supports posts, pages, and custom post types.',
  wordpressQueryPostsSchema.shape,
  async (args) => {
    const parsed = wordpressQueryPostsSchema.parse(args);
    const result = await wordpressQueryPosts(parsed);
    return { content: [{ type: 'text', text: result }] };
  }
);

server.tool(
  'wordpress_get_post',
  'Get a single WordPress post by ID with full content',
  wordpressGetPostSchema.shape,
  async (args) => {
    const parsed = wordpressGetPostSchema.parse(args);
    const result = await wordpressGetPost(parsed);
    return { content: [{ type: 'text', text: result }] };
  }
);

server.tool(
  'woocommerce_query_orders',
  'Query WooCommerce orders with filtering, sorting, and pagination',
  woocommerceQueryOrdersSchema.shape,
  async (args) => {
    const parsed = woocommerceQueryOrdersSchema.parse(args);
    const result = await woocommerceQueryOrders(parsed);
    return { content: [{ type: 'text', text: result }] };
  }
);

server.tool(
  'generate_invoice',
  'Generate an invoice PDF or HTML from a WooCommerce order',
  generateInvoiceSchema.shape,
  async (args) => {
    const parsed = generateInvoiceSchema.parse(args);
    const result = await generateInvoice(parsed);
    return { content: [{ type: 'text', text: result }] };
  }
);

server.tool(
  'crm_search',
  'Search CRM contacts (supports FluentCRM, Jetpack CRM, WP ERP)',
  crmSearchSchema.shape,
  async (args) => {
    const parsed = crmSearchSchema.parse(args);
    const result = await crmSearch(parsed);
    return { content: [{ type: 'text', text: result }] };
  }
);

server.tool(
  'n8n_trigger',
  'Trigger an n8n workflow via webhook',
  n8nTriggerSchema.shape,
  async (args) => {
    const parsed = n8nTriggerSchema.parse(args);
    const result = await n8nTrigger(parsed);
    return { content: [{ type: 'text', text: result }] };
  }
);

server.tool(
  'generate_image',
  'Generate AI image via WordPress (requires AI image plugin)',
  generateImageSchema.shape,
  async (args) => {
    const parsed = generateImageSchema.parse(args);
    const result = await generateImage(parsed);
    return { content: [{ type: 'text', text: result }] };
  }
);

server.tool(
  'deploy_code',
  'Deploy code changes to WordPress server (git pull, composer, npm, custom)',
  deployCodeSchema.shape,
  async (args) => {
    const parsed = deployCodeSchema.parse(args);
    const result = await deployCode(parsed);
    return { content: [{ type: 'text', text: result }] };
  }
);

server.tool(
  'read_logs',
  'Read WordPress debug.log, PHP error logs, or custom log files',
  readLogsSchema.shape,
  async (args) => {
    const parsed = readLogsSchema.parse(args);
    const result = await readLogs(parsed);
    return { content: [{ type: 'text', text: result }] };
  }
);

// Filesystem tools (PHP-native, works on all hosting)
server.tool(
  'list_directory',
  'List directory contents with glob pattern support',
  listDirectorySchema.shape,
  async (args) => {
    const parsed = listDirectorySchema.parse(args);
    const result = await listDirectory(parsed);
    return { content: [{ type: 'text', text: result }] };
  }
);

server.tool(
  'read_file',
  'Read file contents from WordPress server',
  readFileSchema.shape,
  async (args) => {
    const parsed = readFileSchema.parse(args);
    const result = await readFile(parsed);
    return { content: [{ type: 'text', text: result }] };
  }
);

server.tool(
  'write_file',
  'Write text or binary content to a file on WordPress server. Use encoding=base64 for images, PDFs, fonts, etc.',
  writeFileSchema.shape,
  async (args) => {
    const parsed = writeFileSchema.parse(args);
    const result = await writeFile(parsed);
    return { content: [{ type: 'text', text: result }] };
  }
);

server.tool(
  'create_zip',
  'Create a zip backup of a file or directory',
  createZipSchema.shape,
  async (args) => {
    const parsed = createZipSchema.parse(args);
    const result = await createZip(parsed);
    return { content: [{ type: 'text', text: result }] };
  }
);

server.tool(
  'extract_zip',
  'Extract a zip file to a destination directory',
  extractZipSchema.shape,
  async (args) => {
    const parsed = extractZipSchema.parse(args);
    const result = await extractZip(parsed);
    return { content: [{ type: 'text', text: result }] };
  }
);

server.tool(
  'delete_path',
  'Delete a file or directory',
  deletePathSchema.shape,
  async (args) => {
    const parsed = deletePathSchema.parse(args);
    const result = await deletePath(parsed);
    return { content: [{ type: 'text', text: result }] };
  }
);

// Database tools
server.tool(
  'database_query',
  'Execute SQL query (SELECT/INSERT/UPDATE/DELETE) on WordPress database',
  databaseQuerySchema.shape,
  async (args) => {
    const parsed = databaseQuerySchema.parse(args);
    const result = await databaseQuery(parsed);
    return { content: [{ type: 'text', text: result }] };
  }
);

server.tool(
  'database_export',
  'Export WordPress database using mysqldump-php',
  databaseExportSchema.shape,
  async (args) => {
    const parsed = databaseExportSchema.parse(args);
    const result = await databaseExport(parsed);
    return { content: [{ type: 'text', text: result }] };
  }
);

server.tool(
  'database_list_exports',
  'List available database export files with download URLs',
  databaseListExportsSchema.shape,
  async () => {
    const result = await databaseListExports();
    return { content: [{ type: 'text', text: result }] };
  }
);

// Start server
async function main() {
  const transport = new StdioServerTransport();
  await server.connect(transport);
  console.error(`WordPress MCP Bridge connected to ${config.wordpress.siteUrl}`);
}

main().catch((error) => {
  console.error('Fatal error:', error);
  process.exit(1);
});
