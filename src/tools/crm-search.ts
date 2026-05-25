import { z } from 'zod';
import { wpClient } from '../utils/wordpress-client.js';

export const crmSearchSchema = z.object({
  query: z.string().optional().describe('Search query (name, company)'),
  email: z.string().optional().describe('Filter by email'),
  tags: z.array(z.string()).optional().describe('Filter by tags'),
  status: z.string().optional().describe('Contact status (active, lead, customer, etc.)'),
  custom_fields: z.record(z.string()).optional().describe('Filter by custom field values'),
  limit: z.number().default(20).describe('Max results (max 100)'),
  offset: z.number().default(0).describe('Result offset for pagination'),
  orderby: z.enum(['name', 'email', 'created', 'updated']).default('name').describe('Order by field'),
  order: z.enum(['asc', 'desc']).default('asc').describe('Sort order'),
});

export type CrmSearchInput = z.infer<typeof crmSearchSchema>;

interface Contact {
  id: number;
  first_name: string;
  last_name: string;
  email: string;
  phone: string;
  company: string;
  status: string;
  tags: string[];
  custom_fields: Record<string, string>;
  created_at: string;
  updated_at: string;
}

interface CrmSearchResponse {
  contacts: Contact[];
  total: number;
  crm_plugin: string;
}

export async function crmSearch(input: CrmSearchInput): Promise<string> {
  const response = await wpClient.get<CrmSearchResponse>('/crm/search', {
    query: input.query,
    email: input.email,
    tags: input.tags?.join(','),
    status: input.status,
    custom_fields: input.custom_fields ? JSON.stringify(input.custom_fields) : undefined,
    limit: Math.min(input.limit, 100),
    offset: input.offset,
    orderby: input.orderby,
    order: input.order,
  });

  if (!response.success || !response.data) {
    return JSON.stringify({ error: response.error || 'Failed to search CRM' });
  }

  return JSON.stringify({
    success: true,
    contacts: response.data.contacts,
    total: response.data.total,
    crm_plugin: response.data.crm_plugin,
    pagination: {
      limit: input.limit,
      offset: input.offset,
    },
  });
}

export const crmSearchTool = {
  name: 'crm_search',
  description: 'Search CRM contacts (supports FluentCRM, Jetpack CRM, WP ERP)',
  inputSchema: {
    type: 'object' as const,
    properties: {
      query: { type: 'string', description: 'Search query (name, company)' },
      email: { type: 'string', description: 'Filter by email' },
      tags: { type: 'array', items: { type: 'string' }, description: 'Filter by tags' },
      status: { type: 'string', description: 'Contact status (active, lead, customer, etc.)' },
      custom_fields: { type: 'object', description: 'Filter by custom field values' },
      limit: { type: 'number', default: 20, description: 'Max results (max 100)' },
      offset: { type: 'number', default: 0, description: 'Result offset for pagination' },
      orderby: { type: 'string', enum: ['name', 'email', 'created', 'updated'], default: 'name' },
      order: { type: 'string', enum: ['asc', 'desc'], default: 'asc' },
    },
    required: [],
  },
};
