<?php
/**
 * Base REST Controller for MCP Bridge
 */

defined('ABSPATH') || exit;

abstract class MCP_Bridge_REST_Controller {

    protected string $namespace = 'mcp-bridge/v1';
    protected string $rest_base = '';

    abstract public function register_routes(): void;

    /**
     * Check if user has required capability
     * Supports: WordPress session, Basic Auth (Application Password), or API Key
     */
    protected function check_permission(WP_REST_Request $request): bool|WP_Error {
        // First check if already logged in (session or Basic Auth processed by WP)
        if (is_user_logged_in()) {
            return true;
        }

        // Fallback: Check for API key header (bypasses Cloudflare stripping)
        $api_key = $request->get_header('X-MCP-API-Key');
        if ($api_key) {
            return $this->validate_api_key($api_key);
        }

        return new WP_Error(
            'rest_not_logged_in',
            'Authentication required. Use Basic Auth (Application Password) or X-MCP-API-Key header.',
            ['status' => 401]
        );
    }

    /**
     * Validate API key and set current user
     */
    private function validate_api_key(string $api_key): bool|WP_Error {
        $settings = get_option('mcp_bridge_settings', []);
        $stored_key = $settings['api_key'] ?? '';
        $api_user_id = $settings['api_key_user_id'] ?? 0;

        if (empty($stored_key)) {
            return new WP_Error(
                'api_key_not_configured',
                'API key authentication not configured. Set it in MCP Bridge settings.',
                ['status' => 500]
            );
        }

        if (!hash_equals($stored_key, $api_key)) {
            return new WP_Error(
                'invalid_api_key',
                'Invalid API key',
                ['status' => 401]
            );
        }

        // Set current user for permission checks
        if ($api_user_id) {
            wp_set_current_user($api_user_id);
        }

        return true;
    }

    /**
     * Check admin-level permission
     */
    protected function check_admin_permission(WP_REST_Request $request): bool|WP_Error {
        $base_check = $this->check_permission($request);
        if (is_wp_error($base_check)) {
            return $base_check;
        }

        if (!current_user_can('manage_options')) {
            return new WP_Error(
                'rest_forbidden',
                'Administrator access required',
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * Validate and sanitize string parameter
     */
    protected function sanitize_string(mixed $value): string {
        return sanitize_text_field((string) $value);
    }

    /**
     * Validate and sanitize integer parameter
     */
    protected function sanitize_int(mixed $value): int {
        return absint($value);
    }

    /**
     * Build success response
     */
    protected function success_response(array $data, int $status = 200): WP_REST_Response {
        return new WP_REST_Response($data, $status);
    }

    /**
     * Build error response
     */
    protected function error_response(string $code, string $message, int $status = 400): WP_Error {
        return new WP_Error($code, $message, ['status' => $status]);
    }
}
