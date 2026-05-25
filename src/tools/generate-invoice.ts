import { z } from 'zod';
import { wpClient } from '../utils/wordpress-client.js';

export const generateInvoiceSchema = z.object({
  order_id: z.number().describe('WooCommerce order ID'),
  format: z.enum(['pdf', 'html']).default('pdf').describe('Output format'),
  template: z.string().optional().describe('Invoice template name'),
  include_notes: z.boolean().default(false).describe('Include order notes'),
  regenerate: z.boolean().default(false).describe('Force regenerate even if exists'),
});

export type GenerateInvoiceInput = z.infer<typeof generateInvoiceSchema>;

interface InvoiceResponse {
  invoice_id: number;
  order_id: number;
  invoice_number: string;
  download_url: string;
  format: string;
  created_at: string;
}

export async function generateInvoice(input: GenerateInvoiceInput): Promise<string> {
  const response = await wpClient.post<InvoiceResponse>('/invoices', {
    order_id: input.order_id,
    format: input.format,
    template: input.template,
    include_notes: input.include_notes,
    regenerate: input.regenerate,
  });

  if (!response.success || !response.data) {
    return JSON.stringify({ error: response.error || 'Failed to generate invoice' });
  }

  return JSON.stringify({
    success: true,
    invoice: {
      id: response.data.invoice_id,
      order_id: response.data.order_id,
      invoice_number: response.data.invoice_number,
      download_url: response.data.download_url,
      format: response.data.format,
      created_at: response.data.created_at,
    },
    message: 'Invoice generated successfully',
  });
}

export const generateInvoiceTool = {
  name: 'generate_invoice',
  description: 'Generate an invoice PDF or HTML from a WooCommerce order',
  inputSchema: {
    type: 'object' as const,
    properties: {
      order_id: { type: 'number', description: 'WooCommerce order ID' },
      format: { type: 'string', enum: ['pdf', 'html'], default: 'pdf', description: 'Output format' },
      template: { type: 'string', description: 'Invoice template name' },
      include_notes: { type: 'boolean', default: false, description: 'Include order notes' },
      regenerate: { type: 'boolean', default: false, description: 'Force regenerate even if exists' },
    },
    required: ['order_id'],
  },
};
