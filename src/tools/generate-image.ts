import { z } from 'zod';
import { wpClient } from '../utils/wordpress-client.js';

export const generateImageSchema = z.object({
  prompt: z.string().describe('Image generation prompt'),
  size: z.enum(['256x256', '512x512', '1024x1024', '1792x1024', '1024x1792']).default('1024x1024').describe('Image size'),
  provider: z.enum(['openai', 'stability', 'midjourney', 'auto']).default('auto').describe('AI provider'),
  style: z.string().optional().describe('Style preset (vivid, natural, etc.)'),
  negative_prompt: z.string().optional().describe('What to avoid in the image'),
  save_to_media: z.boolean().default(true).describe('Save to WordPress media library'),
  filename: z.string().optional().describe('Custom filename (without extension)'),
});

export type GenerateImageInput = z.infer<typeof generateImageSchema>;

interface GenerateImageResponse {
  image_url: string;
  attachment_id?: number;
  provider: string;
  prompt: string;
  size: string;
  created_at: string;
}

export async function generateImage(input: GenerateImageInput): Promise<string> {
  const response = await wpClient.post<GenerateImageResponse>('/images/generate', {
    prompt: input.prompt,
    size: input.size,
    provider: input.provider,
    style: input.style,
    negative_prompt: input.negative_prompt,
    save_to_media: input.save_to_media,
    filename: input.filename,
  });

  if (!response.success || !response.data) {
    return JSON.stringify({ error: response.error || 'Failed to generate image' });
  }

  return JSON.stringify({
    success: true,
    image: {
      url: response.data.image_url,
      attachment_id: response.data.attachment_id,
      provider: response.data.provider,
      prompt: response.data.prompt,
      size: response.data.size,
      created_at: response.data.created_at,
    },
    message: 'Image generated successfully',
  });
}

export const generateImageTool = {
  name: 'generate_image',
  description: 'Generate AI image via WordPress (requires AI image plugin)',
  inputSchema: {
    type: 'object' as const,
    properties: {
      prompt: { type: 'string', description: 'Image generation prompt' },
      size: {
        type: 'string',
        enum: ['256x256', '512x512', '1024x1024', '1792x1024', '1024x1792'],
        default: '1024x1024',
        description: 'Image size',
      },
      provider: {
        type: 'string',
        enum: ['openai', 'stability', 'midjourney', 'auto'],
        default: 'auto',
        description: 'AI provider',
      },
      style: { type: 'string', description: 'Style preset (vivid, natural, etc.)' },
      negative_prompt: { type: 'string', description: 'What to avoid in the image' },
      save_to_media: { type: 'boolean', default: true, description: 'Save to WordPress media library' },
      filename: { type: 'string', description: 'Custom filename (without extension)' },
    },
    required: ['prompt'],
  },
};
