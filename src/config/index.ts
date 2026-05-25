import { z } from 'zod';
import dotenv from 'dotenv';
import { fileURLToPath } from 'url';
import { dirname, resolve } from 'path';

const __dirname = dirname(fileURLToPath(import.meta.url));
dotenv.config({ path: resolve(__dirname, '../../.env') });

const configSchema = z.object({
  wordpress: z.object({
    siteUrl: z.string().url(),
    username: z.string().min(1).optional(),
    appPassword: z.string().min(1).optional(),
    apiKey: z.string().min(1).optional(),
  }).refine(
    (data) => (data.username && data.appPassword) || data.apiKey,
    { message: 'Either username+appPassword or apiKey must be provided' }
  ),
  n8n: z.object({
    webhookBaseUrl: z.string().url().optional(),
  }),
  rateLimitPerMinute: z.number().default(100),
  requestTimeout: z.number().default(30000),
});

export type Config = z.infer<typeof configSchema>;

function loadConfig(): Config {
  const rawConfig = {
    wordpress: {
      siteUrl: process.env.WORDPRESS_SITE_URL,
      username: process.env.WORDPRESS_USERNAME,
      appPassword: process.env.WORDPRESS_APP_PASSWORD,
      apiKey: process.env.WORDPRESS_API_KEY,
    },
    n8n: {
      webhookBaseUrl: process.env.N8N_WEBHOOK_BASE_URL,
    },
    rateLimitPerMinute: process.env.RATE_LIMIT_PER_MINUTE
      ? parseInt(process.env.RATE_LIMIT_PER_MINUTE, 10)
      : 100,
    requestTimeout: process.env.REQUEST_TIMEOUT
      ? parseInt(process.env.REQUEST_TIMEOUT, 10)
      : 30000,
  };

  const result = configSchema.safeParse(rawConfig);

  if (!result.success) {
    const errors = result.error.issues
      .map((issue) => `${issue.path.join('.')}: ${issue.message}`)
      .join('\n');
    throw new Error(`Configuration error:\n${errors}`);
  }

  return result.data;
}

export const config = loadConfig();
