import { z } from 'zod';
import { wpClient } from '../utils/wordpress-client.js';

export const n8nTriggerSchema = z.object({
  webhook_id: z.string().describe('n8n webhook ID or name configured in WordPress'),
  payload: z.record(z.unknown()).optional().describe('Data to send to n8n workflow'),
  wait_for_response: z.boolean().default(false).describe('Wait for workflow to complete'),
  timeout: z.number().default(30).describe('Timeout in seconds if waiting for response'),
});

export type N8nTriggerInput = z.infer<typeof n8nTriggerSchema>;

interface N8nTriggerResponse {
  triggered: boolean;
  webhook_id: string;
  execution_id?: string;
  response?: unknown;
  message: string;
}

export async function n8nTrigger(input: N8nTriggerInput): Promise<string> {
  const response = await wpClient.post<N8nTriggerResponse>('/n8n/trigger', {
    webhook_id: input.webhook_id,
    payload: input.payload || {},
    wait_for_response: input.wait_for_response,
    timeout: input.timeout,
  });

  if (!response.success || !response.data) {
    return JSON.stringify({ error: response.error || 'Failed to trigger n8n workflow' });
  }

  return JSON.stringify({
    success: true,
    triggered: response.data.triggered,
    webhook_id: response.data.webhook_id,
    execution_id: response.data.execution_id,
    response: response.data.response,
    message: response.data.message,
  });
}

export const n8nTriggerTool = {
  name: 'n8n_trigger',
  description: 'Trigger an n8n workflow via webhook',
  inputSchema: {
    type: 'object' as const,
    properties: {
      webhook_id: { type: 'string', description: 'n8n webhook ID or name configured in WordPress' },
      payload: { type: 'object', description: 'Data to send to n8n workflow' },
      wait_for_response: { type: 'boolean', default: false, description: 'Wait for workflow to complete' },
      timeout: { type: 'number', default: 30, description: 'Timeout in seconds if waiting for response' },
    },
    required: ['webhook_id'],
  },
};
