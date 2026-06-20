import { z } from 'zod';
import { wpClient } from '../utils/wordpress-client.js';

// Query Posts
export const wordpressQueryPostsSchema = z.object({
  type: z.string().default('post').describe('Post type (post, page, or custom post type)'),
  status: z.enum(['publish', 'draft', 'pending', 'private', 'trash', 'any']).default('publish').describe('Post status filter'),
  search: z.string().optional().describe('Search posts by keyword'),
  category: z.number().optional().describe('Filter by category ID'),
  tag: z.string().optional().describe('Filter by tag slug'),
  author: z.number().optional().describe('Filter by author ID'),
  orderby: z.enum(['date', 'title', 'modified', 'ID', 'menu_order', 'rand']).default('date').describe('Order by field'),
  order: z.enum(['ASC', 'DESC']).default('DESC').describe('Sort direction'),
  per_page: z.number().min(1).max(100).default(10).describe('Results per page'),
  page: z.number().min(1).default(1).describe('Page number'),
  meta_key: z.string().optional().describe('Filter by meta key'),
  meta_value: z.string().optional().describe('Filter by meta value (requires meta_key)'),
});

export type WordPressQueryPostsInput = z.infer<typeof wordpressQueryPostsSchema>;

interface PostSummary {
  id: number;
  title: string;
  slug: string;
  status: string;
  type: string;
  excerpt: string;
  date: string;
  modified: string;
  author: number;
  link: string;
  featured_image: number | null;
  categories: Array<{ id: number; name: string; slug: string }>;
  tags: Array<{ id: number; name: string; slug: string }>;
}

interface QueryPostsResponse {
  posts: PostSummary[];
  total: number;
  pages: number;
  page: number;
  per_page: number;
}

export async function wordpressQueryPosts(input: WordPressQueryPostsInput): Promise<string> {
  const params: Record<string, string | number | boolean | undefined> = {
    type: input.type,
    status: input.status,
    search: input.search,
    category: input.category,
    tag: input.tag,
    author: input.author,
    orderby: input.orderby,
    order: input.order,
    per_page: input.per_page,
    page: input.page,
    meta_key: input.meta_key,
    meta_value: input.meta_value,
  };

  const response = await wpClient.get<QueryPostsResponse>('/posts', params);

  if (!response.success || !response.data) {
    return JSON.stringify({ error: response.error || 'Failed to query posts' });
  }

  return JSON.stringify({
    success: true,
    ...response.data,
  });
}

// Get Single Post
export const wordpressGetPostSchema = z.object({
  id: z.number().describe('Post ID'),
});

export type WordPressGetPostInput = z.infer<typeof wordpressGetPostSchema>;

interface GetPostResponse extends PostSummary {
  content: string;
  content_rendered: string;
}

export async function wordpressGetPost(input: WordPressGetPostInput): Promise<string> {
  const response = await wpClient.get<GetPostResponse>(`/posts/${input.id}`);

  if (!response.success || !response.data) {
    return JSON.stringify({ error: response.error || 'Failed to get post' });
  }

  return JSON.stringify({
    success: true,
    post: response.data,
  });
}
