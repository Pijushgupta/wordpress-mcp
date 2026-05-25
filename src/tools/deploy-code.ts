import { z } from 'zod';
import { wpClient } from '../utils/wordpress-client.js';

export const deployCodeSchema = z.object({
  action: z.enum(['git_pull', 'composer_install', 'npm_install', 'npm_build', 'custom']).describe('Deployment action'),
  branch: z.string().optional().describe('Git branch to pull (for git_pull action)'),
  path: z.string().optional().describe('Relative path from WordPress root'),
  custom_command: z.string().optional().describe('Custom shell command (requires custom action)'),
  clear_cache: z.boolean().default(true).describe('Clear WordPress cache after deploy'),
  maintenance_mode: z.boolean().default(false).describe('Enable maintenance mode during deploy'),
});

export type DeployCodeInput = z.infer<typeof deployCodeSchema>;

interface DeployCodeResponse {
  success: boolean;
  action: string;
  output: string;
  duration_ms: number;
  cache_cleared: boolean;
  maintenance_mode_used: boolean;
}

export async function deployCode(input: DeployCodeInput): Promise<string> {
  // Security: Only allow custom commands if explicitly enabled server-side
  if (input.action === 'custom' && !input.custom_command) {
    return JSON.stringify({ error: 'custom_command required for custom action' });
  }

  const response = await wpClient.post<DeployCodeResponse>('/deploy', {
    action: input.action,
    branch: input.branch,
    path: input.path,
    custom_command: input.custom_command,
    clear_cache: input.clear_cache,
    maintenance_mode: input.maintenance_mode,
  });

  if (!response.success || !response.data) {
    return JSON.stringify({ error: response.error || 'Deployment failed' });
  }

  return JSON.stringify({
    success: response.data.success,
    action: response.data.action,
    output: response.data.output,
    duration_ms: response.data.duration_ms,
    cache_cleared: response.data.cache_cleared,
    maintenance_mode_used: response.data.maintenance_mode_used,
    message: response.data.success ? 'Deployment completed successfully' : 'Deployment completed with warnings',
  });
}

export const deployCodeTool = {
  name: 'deploy_code',
  description: 'Deploy code changes to WordPress server (git pull, composer, npm, custom)',
  inputSchema: {
    type: 'object' as const,
    properties: {
      action: {
        type: 'string',
        enum: ['git_pull', 'composer_install', 'npm_install', 'npm_build', 'custom'],
        description: 'Deployment action',
      },
      branch: { type: 'string', description: 'Git branch to pull (for git_pull action)' },
      path: { type: 'string', description: 'Relative path from WordPress root' },
      custom_command: { type: 'string', description: 'Custom shell command (requires custom action)' },
      clear_cache: { type: 'boolean', default: true, description: 'Clear WordPress cache after deploy' },
      maintenance_mode: { type: 'boolean', default: false, description: 'Enable maintenance mode during deploy' },
    },
    required: ['action'],
  },
};
