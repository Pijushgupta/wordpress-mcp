<?php
/**
 * Images Endpoint - Generate AI images via WordPress plugins
 * Supports: AI Engine, Suspended, custom integrations
 */

defined('ABSPATH') || exit;

class MCP_Bridge_Images_Endpoint extends MCP_Bridge_REST_Controller {

    protected string $rest_base = 'images/generate';

    public function register_routes(): void {
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'generate_image'],
                'permission_callback' => [$this, 'check_image_permission'],
                'args' => [
                    'prompt' => [
                        'required' => true,
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_textarea_field',
                    ],
                    'size' => [
                        'type' => 'string',
                        'default' => '1024x1024',
                        'enum' => ['256x256', '512x512', '1024x1024', '1792x1024', '1024x1792'],
                    ],
                    'provider' => [
                        'type' => 'string',
                        'default' => 'auto',
                        'enum' => ['openai', 'stability', 'midjourney', 'auto'],
                    ],
                    'style' => [
                        'type' => 'string',
                    ],
                    'negative_prompt' => [
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_textarea_field',
                    ],
                    'save_to_media' => [
                        'type' => 'boolean',
                        'default' => true,
                    ],
                    'filename' => [
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_file_name',
                    ],
                ],
            ],
        ]);
    }

    public function check_image_permission(WP_REST_Request $request): bool|WP_Error {
        $base_check = $this->check_permission($request);
        if (is_wp_error($base_check)) {
            return $base_check;
        }

        if (!current_user_can('upload_files')) {
            return $this->error_response('forbidden', 'Cannot upload media', 403);
        }

        return true;
    }

    public function generate_image(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $prompt = $request->get_param('prompt');
        $size = $request->get_param('size') ?: '1024x1024';
        $provider = $request->get_param('provider') ?: 'auto';
        $style = $request->get_param('style');
        $negative_prompt = $request->get_param('negative_prompt');
        $save_to_media = $request->get_param('save_to_media') !== false;
        $filename = $request->get_param('filename');

        // Try AI image generation plugins in order of preference
        $result = null;

        if ($provider === 'auto' || $provider === 'openai') {
            // Try AI Engine plugin (Meow Apps)
            $result = $this->try_ai_engine($prompt, $size, $style);
        }

        if (!$result && ($provider === 'auto' || $provider === 'stability')) {
            // Try direct API if configured
            $result = $this->try_direct_api($prompt, $size, $provider, $style, $negative_prompt);
        }

        if (!$result) {
            return $this->error_response(
                'no_image_provider',
                'No AI image generation available. Install AI Engine plugin or configure API keys.',
                400
            );
        }

        // Save to media library if requested
        $attachment_id = null;
        if ($save_to_media && isset($result['image_url'])) {
            $attachment_id = $this->save_to_media_library($result['image_url'], $prompt, $filename);
        }

        return $this->success_response([
            'image_url' => $result['image_url'],
            'attachment_id' => $attachment_id,
            'provider' => $result['provider'],
            'prompt' => $prompt,
            'size' => $size,
            'created_at' => current_time('c'),
        ]);
    }

    private function try_ai_engine(string $prompt, string $size, ?string $style): ?array {
        // Check if AI Engine is active
        if (!class_exists('Meow_MWAI_Core')) {
            return null;
        }

        try {
            global $mwai_core;

            if (!$mwai_core) {
                return null;
            }

            // Parse size
            [$width, $height] = explode('x', $size);

            $query = new Meow_MWAI_Query_Image($prompt);
            $query->set_resolution($width, $height);

            if ($style) {
                $query->set_style($style);
            }

            $reply = $mwai_core->run_query($query);

            if ($reply && $reply->result) {
                return [
                    'image_url' => $reply->result,
                    'provider' => 'AI Engine (OpenAI)',
                ];
            }
        } catch (Exception $e) {
            // AI Engine not properly configured
            error_log('MCP Bridge AI Engine error: ' . $e->getMessage());
        }

        return null;
    }

    private function try_direct_api(string $prompt, string $size, string $provider, ?string $style, ?string $negative_prompt): ?array {
        // Get API key from options
        $openai_key = get_option('mcp_bridge_openai_api_key');

        if ($provider === 'openai' && $openai_key) {
            return $this->generate_via_openai($prompt, $size, $style, $openai_key);
        }

        // Add more providers as needed
        return null;
    }

    private function generate_via_openai(string $prompt, string $size, ?string $style, string $api_key): ?array {
        $full_prompt = $prompt;
        if ($style) {
            $full_prompt = "Style: {$style}. " . $prompt;
        }

        $response = wp_remote_post('https://api.openai.com/v1/images/generations', [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'model' => 'dall-e-3',
                'prompt' => $full_prompt,
                'size' => $size,
                'n' => 1,
            ]),
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['data'][0]['url'])) {
            return [
                'image_url' => $body['data'][0]['url'],
                'provider' => 'OpenAI DALL-E 3',
            ];
        }

        return null;
    }

    private function save_to_media_library(string $image_url, string $prompt, ?string $filename): ?int {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Download image
        $temp_file = download_url($image_url);

        if (is_wp_error($temp_file)) {
            return null;
        }

        // Determine filename
        if (!$filename) {
            $filename = 'ai-generated-' . wp_generate_password(8, false);
        }

        $file_array = [
            'name' => $filename . '.png',
            'tmp_name' => $temp_file,
        ];

        // Upload to media library
        $attachment_id = media_handle_sideload($file_array, 0);

        if (is_wp_error($attachment_id)) {
            @unlink($temp_file);
            return null;
        }

        // Add metadata
        update_post_meta($attachment_id, '_mcp_bridge_ai_prompt', $prompt);
        update_post_meta($attachment_id, '_mcp_bridge_ai_generated', true);

        // Set alt text from prompt
        update_post_meta($attachment_id, '_wp_attachment_image_alt', substr($prompt, 0, 125));

        return $attachment_id;
    }
}
