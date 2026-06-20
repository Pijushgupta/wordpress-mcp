<?php
/**
 * Posts Endpoint - Create/update WordPress posts
 */

defined('ABSPATH') || exit;

class MCP_Bridge_Posts_Endpoint extends MCP_Bridge_REST_Controller {

    protected string $rest_base = 'posts';

    public function register_routes(): void {
        // Query posts
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'query_posts'],
                'permission_callback' => [$this, 'check_read_posts_permission'],
                'args' => [
                    'type' => [
                        'type' => 'string',
                        'default' => 'post',
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    'status' => [
                        'type' => 'string',
                        'default' => 'publish',
                        'enum' => ['publish', 'draft', 'pending', 'private', 'trash', 'any'],
                    ],
                    'search' => [
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'category' => [
                        'type' => 'integer',
                    ],
                    'tag' => [
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'author' => [
                        'type' => 'integer',
                    ],
                    'orderby' => [
                        'type' => 'string',
                        'default' => 'date',
                        'enum' => ['date', 'title', 'modified', 'ID', 'menu_order', 'rand'],
                    ],
                    'order' => [
                        'type' => 'string',
                        'default' => 'DESC',
                        'enum' => ['ASC', 'DESC'],
                    ],
                    'per_page' => [
                        'type' => 'integer',
                        'default' => 10,
                        'minimum' => 1,
                        'maximum' => 100,
                    ],
                    'page' => [
                        'type' => 'integer',
                        'default' => 1,
                        'minimum' => 1,
                    ],
                    'meta_key' => [
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    'meta_value' => [
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'create_or_update_post'],
                'permission_callback' => [$this, 'check_edit_posts_permission'],
                'args' => $this->get_create_args(),
            ],
        ]);

        // Get single post by ID
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_post'],
                'permission_callback' => [$this, 'check_read_posts_permission'],
                'args' => [
                    'id' => [
                        'required' => true,
                        'type' => 'integer',
                        'validate_callback' => fn($val) => is_numeric($val) && (int)$val > 0,
                    ],
                ],
            ],
        ]);
    }

    public function check_read_posts_permission(WP_REST_Request $request): bool|WP_Error {
        $base_check = $this->check_permission($request);
        if (is_wp_error($base_check)) {
            return $base_check;
        }

        if (!current_user_can('read')) {
            return $this->error_response('forbidden', 'Read access required', 403);
        }

        return true;
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

    public function query_posts(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $per_page = $request->get_param('per_page') ?: 10;
        $page = $request->get_param('page') ?: 1;

        $query_args = [
            'post_type' => $request->get_param('type') ?: 'post',
            'post_status' => $request->get_param('status') ?: 'publish',
            'posts_per_page' => min((int) $per_page, 100),
            'paged' => (int) $page,
            'orderby' => $request->get_param('orderby') ?: 'date',
            'order' => $request->get_param('order') ?: 'DESC',
        ];

        $search = $request->get_param('search');
        if ($search) {
            $query_args['s'] = $search;
        }

        $category = $request->get_param('category');
        if ($category) {
            $query_args['cat'] = (int) $category;
        }

        $tag = $request->get_param('tag');
        if ($tag) {
            $query_args['tag'] = $tag;
        }

        $author = $request->get_param('author');
        if ($author) {
            $query_args['author'] = (int) $author;
        }

        $meta_key = $request->get_param('meta_key');
        $meta_value = $request->get_param('meta_value');
        if ($meta_key) {
            $query_args['meta_key'] = $meta_key;
            if ($meta_value !== null) {
                $query_args['meta_value'] = $meta_value;
            }
        }

        // Non-publish statuses require edit capability
        if ($query_args['post_status'] !== 'publish' && $query_args['post_status'] !== 'any') {
            $post_type_obj = get_post_type_object($query_args['post_type']);
            if ($post_type_obj && !current_user_can($post_type_obj->cap->edit_others_posts)) {
                $query_args['author'] = get_current_user_id();
            }
        }

        $query = new WP_Query($query_args);
        $posts = [];

        foreach ($query->posts as $post) {
            $posts[] = $this->format_post($post);
        }

        return $this->success_response([
            'posts' => $posts,
            'total' => (int) $query->found_posts,
            'pages' => (int) $query->max_num_pages,
            'page' => (int) $page,
            'per_page' => (int) $per_page,
        ]);
    }

    public function get_post(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $post_id = (int) $request->get_param('id');
        $post = get_post($post_id);

        if (!$post) {
            return $this->error_response('post_not_found', 'Post not found', 404);
        }

        if ($post->post_status !== 'publish' && !current_user_can('edit_post', $post_id)) {
            return $this->error_response('forbidden', 'Cannot view this post', 403);
        }

        $data = $this->format_post($post);
        $data['content'] = $post->post_content;
        $data['content_rendered'] = apply_filters('the_content', $post->post_content);

        return $this->success_response($data);
    }

    private function format_post(WP_Post $post): array {
        $categories = wp_get_post_categories($post->ID, ['fields' => 'all']);
        $tags = wp_get_post_tags($post->ID);

        return [
            'id' => $post->ID,
            'title' => $post->post_title,
            'slug' => $post->post_name,
            'status' => $post->post_status,
            'type' => $post->post_type,
            'excerpt' => $post->post_excerpt,
            'date' => $post->post_date,
            'modified' => $post->post_modified,
            'author' => (int) $post->post_author,
            'link' => get_permalink($post->ID),
            'featured_image' => get_post_thumbnail_id($post->ID) ?: null,
            'categories' => array_map(fn($cat) => [
                'id' => $cat->term_id,
                'name' => $cat->name,
                'slug' => $cat->slug,
            ], is_array($categories) ? $categories : []),
            'tags' => array_map(fn($t) => [
                'id' => $t->term_id,
                'name' => $t->name,
                'slug' => $t->slug,
            ], is_array($tags) ? $tags : []),
        ];
    }
}
