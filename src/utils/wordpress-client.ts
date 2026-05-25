import { config } from '../config/index.js';

export interface WordPressResponse<T> {
  success: boolean;
  data?: T;
  error?: string;
}

export class WordPressClient {
  private readonly baseUrl: string;
  private readonly authHeaders: Record<string, string>;
  private readonly timeout: number;

  constructor() {
    this.baseUrl = config.wordpress.siteUrl.replace(/\/$/, '');
    this.timeout = config.requestTimeout;

    // Prefer API key (bypasses Cloudflare issues), fallback to Basic Auth
    if (config.wordpress.apiKey) {
      this.authHeaders = {
        'X-MCP-API-Key': config.wordpress.apiKey,
      };
    } else if (config.wordpress.username && config.wordpress.appPassword) {
      this.authHeaders = {
        'Authorization': 'Basic ' + Buffer.from(
          `${config.wordpress.username}:${config.wordpress.appPassword}`
        ).toString('base64'),
      };
    } else {
      throw new Error('WordPress authentication not configured');
    }
  }

  async request<T>(
    endpoint: string,
    options: {
      method?: 'GET' | 'POST' | 'PUT' | 'DELETE';
      body?: Record<string, unknown>;
      params?: Record<string, string | number | boolean | undefined>;
    } = {}
  ): Promise<WordPressResponse<T>> {
    const { method = 'GET', body, params } = options;

    let url = `${this.baseUrl}/wp-json/mcp-bridge/v1${endpoint}`;

    if (params) {
      const searchParams = new URLSearchParams();
      Object.entries(params).forEach(([key, value]) => {
        if (value !== undefined) {
          searchParams.append(key, String(value));
        }
      });
      const queryString = searchParams.toString();
      if (queryString) {
        url += `?${queryString}`;
      }
    }

    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), this.timeout);

    try {
      const response = await fetch(url, {
        method,
        headers: {
          ...this.authHeaders,
          'Content-Type': 'application/json',
          'Accept': 'application/json',
        },
        body: body ? JSON.stringify(body) : undefined,
        signal: controller.signal,
      });

      clearTimeout(timeoutId);

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({}));
        return {
          success: false,
          error: errorData.message || `HTTP ${response.status}: ${response.statusText}`,
        };
      }

      const data = await response.json() as T;
      return { success: true, data };
    } catch (error) {
      clearTimeout(timeoutId);

      if (error instanceof Error) {
        if (error.name === 'AbortError') {
          return { success: false, error: 'Request timeout' };
        }
        return { success: false, error: error.message };
      }
      return { success: false, error: 'Unknown error' };
    }
  }

  // Convenience methods
  async get<T>(endpoint: string, params?: Record<string, string | number | boolean | undefined>) {
    return this.request<T>(endpoint, { method: 'GET', params });
  }

  async post<T>(endpoint: string, body: Record<string, unknown>) {
    return this.request<T>(endpoint, { method: 'POST', body });
  }

  async delete<T>(endpoint: string, params?: Record<string, string | number | boolean | undefined>) {
    return this.request<T>(endpoint, { method: 'DELETE', params });
  }
}

export const wpClient = new WordPressClient();
