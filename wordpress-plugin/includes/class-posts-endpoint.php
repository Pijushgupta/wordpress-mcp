<?php
/**
 * Posts Endpoint - Create/update WordPress posts
 */

defined('ABSPATH') || exit;

class MCP_Bridge_Posts_Endpoint extends MCP_Bridge_REST_Controller {

    protected string $rest_base = 'posts';

    public function register_routes(): void {
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'create_or_update_post'],
                'permission_callback' => [$this, 'check_edit_posts_permission'],
                'args' => $this->get_create_args(),
            ],
        ]);
    }

    public function check_edit_posts_permission(WP_REST_Request $request): bool|WP_Error {
        $base_check = $this->check_permission($request);
        if (is_wp_error($base_check)) {
            return $base_check;
        }

        $post_type = $request->get_param('type') ?: 'post';
        $post_type_obj = get_post_type_object($post_type);

        if (!$post_type_obj) {
            return $this->error_response('invalid_post_type', 'Invalid post type', 400);
        }

        $post_id = $request->get_param('post_id');
        if ($post_id) {
            if (!current_user_can('edit_post', $post_id)) {
                return $this->error_response('forbidden', 'Cannot edit this post', 403);
            }
        } else {
            if (!current_user_can($post_type_obj->cap->create_posts)) {
                return $this->error_response('forbidden', 'Cannot create posts of this type', 403);
            }
        }

        return true;
    }

    private function get_create_args(): array {
        return [
            'title' => [
                'required' => true,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'content' => [
                'required' => true,
                'type' => 'string',
                'sanitize_callback' => 'wp_kses_post',
            ],
            'status' => [
                'type' => 'string',
                'default' => 'draft',
                'enum' => ['draft', 'publish', 'pending', 'private'],
            ],
            'type' => [
                'type' => 'string',
                'default' => 'post',
                'sanitize_callback' => 'sanitize_key',
            ],
            'excerpt' => [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_textarea_field',
            ],
            'categories' => [
                'type' => 'array',
                'items' => ['type' => 'integer'],
            ],
            'tags' => [
                'type' => 'array',
                'items' => ['type' => 'integer'],
            ],
            'featured_image' => [
                'type' => 'integer',
            ],
            'meta' => [
                'type' => 'object',
            ],
            'post_id' => [
                'type' => 'integer',
                'description' => 'Post ID for updates',
            ],
        ];
    }

    public function create_or_update_post(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $post_id = $request->get_param('post_id');
        $is_update = !empty($post_id);

        $post_data = [
            'post_title' => $request->get_param('title'),
            'post_content' => $request->get_param('content'),
            'post_status' => $request->get_param('status') ?: 'draft',
            'post_type' => $request->get_param('type') ?: 'post',
        ];

        if ($request->get_param('excerpt')) {
            $post_data['post_excerpt'] = $request->get_param('excerpt');
        }

        if ($is_update) {
            $post_data['ID'] = $post_id;
            $result = wp_update_post($post_data, true);
        } else {
            $result = wp_insert_post($post_data, true);
        }

        if (is_wp_error($result)) {
            return $result;
        }

        $new_post_id = $result;

        // Set categories
        $categories = $request->get_param('categories');
        if (!empty($categories) && is_array($categories)) {
            wp_set_post_categories($new_post_id, array_map('absint', $categories));
        }

        // Set tags
        $tags = $request->get_param('tags');
        if (!empty($tags) && is_array($tags)) {
            wp_set_post_tags($new_post_id, array_map('absint', $tags));
        }

        // Set featured image
        $featured_image = $request->get_param('featured_image');
        if (!empty($featured_image)) {
            set_post_thumbnail($new_post_id, absint($featured_image));
        }

        // Set meta
        $meta = $request->get_param('meta');
        if (!empty($meta) && is_array($meta)) {
            foreach ($meta as $key => $value) {
                $sanitized_key = sanitize_key($key);
                // Only allow whitelisted meta keys or those starting with _mcp_
                if (str_starts_with($sanitized_key, '_mcp_') || str_starts_with($sanitized_key, 'mcp_')) {
                    update_post_meta($new_post_id, $sanitized_key, $value);
                }
            }
        }

        $post = get_post($new_post_id);

        return $this->success_response([
            'id' => $post->ID,
            'title' => $post->post_title,
            'status' => $post->post_status,
            'link' => get_permalink($post->ID),
            'type' => $post->post_type,
        ], $is_update ? 200 : 201);
    }
}
