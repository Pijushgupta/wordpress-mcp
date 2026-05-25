import { z } from 'zod';
import { wpClient } from '../utils/wordpress-client.js';

export const wordpressCreatePostSchema = z.object({
  title: z.string().describe('Post title'),
  content: z.string().describe('Post content (HTML supported)'),
  status: z.enum(['draft', 'publish', 'pending', 'private']).default('draft').describe('Post status'),
  type: z.string().default('post').describe('Post type (post, page, or custom post type)'),
  excerpt: z.string().optional().describe('Post excerpt'),
  categories: z.array(z.number()).optional().describe('Category IDs'),
  tags: z.array(z.number()).optional().describe('Tag IDs'),
  featured_image: z.number().optional().describe('Featured image attachment ID'),
  meta: z.record(z.unknown()).optional().describe('Custom meta fields'),
  post_id: z.number().optional().describe('Post ID for updates (omit for new post)'),
});

export type WordPressCreatePostInput = z.infer<typeof wordpressCreatePostSchema>;

interface PostResponse {
  id: number;
  title: string;
  status: string;
  link: string;
  type: string;
}

export async function wordpressCreatePost(input: WordPressCreatePostInput): Promise<string> {
  const response = await wpClient.post<PostResponse>('/posts', {
    title: input.title,
    content: input.content,
    status: input.status,
    type: input.type,
    excerpt: input.excerpt,
    categories: input.categories,
    tags: input.tags,
    featured_image: input.featured_image,
    meta: input.meta,
    post_id: input.post_id,
  });

  if (!response.success || !response.data) {
    return JSON.stringify({ error: response.error || 'Failed to create/update post' });
  }

  return JSON.stringify({
    success: true,
    post: {
      id: response.data.id,
      title: response.data.title,
      status: response.data.status,
      link: response.data.link,
      type: response.data.type,
    },
    message: input.post_id ? 'Post updated successfully' : 'Post created successfully',
  });
}

export const wordpressCreatePostTool = {
  name: 'wordpress_create_post',
  description: 'Create or update a WordPress post, page, or custom post type',
  inputSchema: {
    type: 'object' as const,
    properties: {
      title: { type: 'string', description: 'Post title' },
      content: { type: 'string', description: 'Post content (HTML supported)' },
      status: {
        type: 'string',
        enum: ['draft', 'publish', 'pending', 'private'],
        default: 'draft',
        description: 'Post status',
      },
      type: { type: 'string', default: 'post', description: 'Post type (post, page, or custom post type)' },
      excerpt: { type: 'string', description: 'Post excerpt' },
      categories: { type: 'array', items: { type: 'number' }, description: 'Category IDs' },
      tags: { type: 'array', items: { type: 'number' }, description: 'Tag IDs' },
      featured_image: { type: 'number', description: 'Featured image attachment ID' },
      meta: { type: 'object', description: 'Custom meta fields' },
      post_id: { type: 'number', description: 'Post ID for updates (omit for new post)' },
    },
    required: ['title', 'content'],
  },
};
