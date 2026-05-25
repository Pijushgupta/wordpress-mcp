<?php
/**
 * n8n Endpoint - Trigger n8n workflows via webhook
 */

defined('ABSPATH') || exit;

class MCP_Bridge_N8N_Endpoint extends MCP_Bridge_REST_Controller {

    protected string $rest_base = 'n8n/trigger';

    public function register_routes(): void {
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'trigger_webhook'],
                'permission_callback' => [$this, 'check_n8n_permission'],
                'args' => [
                    'webhook_id' => [
                        'required' => true,
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    'payload' => [
                        'type' => 'object',
                        'default' => [],
                    ],
                    'wait_for_response' => [
                        'type' => 'boolean',
                        'default' => false,
                    ],
                    'timeout' => [
                        'type' => 'integer',
                        'default' => 30,
                        'minimum' => 1,
                        'maximum' => 120,
                    ],
                ],
            ],
        ]);
    }

    public function check_n8n_permission(WP_REST_Request $request): bool|WP_Error {
        $base_check = $this->check_permission($request);
        if (is_wp_error($base_check)) {
            return $base_check;
        }

        if (!current_user_can('manage_options')) {
            return $this->error_response('forbidden', 'Administrator access required for n8n triggers', 403);
        }

        return true;
    }

    public function trigger_webhook(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $webhook_id = $request->get_param('webhook_id');
        $payload = $request->get_param('payload') ?: [];
        $wait_for_response = (bool) $request->get_param('wait_for_response');
        $timeout = min($request->get_param('timeout') ?: 30, 120);

        // Get webhook URL from settings
        $webhook_url = $this->get_webhook_url($webhook_id);

        if (!$webhook_url) {
            return $this->error_response(
                'webhook_not_found',
                "Webhook '{$webhook_id}' not configured. Add it in MCP Bridge settings.",
                404
            );
        }

        // Add metadata to payload
        $payload['_mcp_bridge'] = [
            'triggered_at' => current_time('c'),
            'triggered_by' => get_current_user_id(),
            'site_url' => home_url(),
        ];

        // Make request to n8n
        $args = [
            'method' => 'POST',
            'timeout' => $timeout,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($payload),
        ];

        $response = wp_remote_post($webhook_url, $args);

        if (is_wp_error($response)) {
            return $this->error_response(
                'webhook_request_failed',
                'Failed to trigger webhook: ' . $response->get_error_message(),
                500
            );
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        // n8n typically returns 200 for success
        if ($status_code >= 200 && $status_code < 300) {
            $response_data = null;
            if ($wait_for_response && !empty($body)) {
                $response_data = json_decode($body, true);
            }

            return $this->success_response([
                'triggered' => true,
                'webhook_id' => $webhook_id,
                'execution_id' => $this->extract_execution_id($body),
                'response' => $response_data,
                'message' => 'Workflow triggered successfully',
            ]);
        }

        return $this->error_response(
            'webhook_error',
            "Webhook returned status {$status_code}: {$body}",
            $status_code
        );
    }

    private function get_webhook_url(string $webhook_id): ?string {
        $settings = MCP_Bridge::get_settings();
        $webhooks = $settings['n8n_webhooks'] ?? [];

        return $webhooks[$webhook_id] ?? null;
    }

    private function extract_execution_id(string $body): ?string {
        $data = json_decode($body, true);

        if (isset($data['executionId'])) {
            return $data['executionId'];
        }

        // Try alternative formats n8n might use
        if (isset($data['execution_id'])) {
            return $data['execution_id'];
        }

        if (isset($data['id'])) {
            return $data['id'];
        }

        return null;
    }
}
