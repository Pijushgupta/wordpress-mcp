<?php
/**
 * Deploy Endpoint - Execute deployment commands on the server
 * SECURITY WARNING: This endpoint can execute shell commands. Enable with caution.
 */

defined('ABSPATH') || exit;

class MCP_Bridge_Deploy_Endpoint extends MCP_Bridge_REST_Controller {

    protected string $rest_base = 'deploy';

    // Predefined safe commands
    private array $safe_actions = [
        'git_pull' => 'git pull',
        'composer_install' => 'composer install --no-dev --optimize-autoloader',
        'npm_install' => 'npm ci',
        'npm_build' => 'npm run build',
    ];

    public function register_routes(): void {
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'execute_deploy'],
                'permission_callback' => [$this, 'check_deploy_permission'],
                'args' => [
                    'action' => [
                        'required' => true,
                        'type' => 'string',
                        'enum' => ['git_pull', 'composer_install', 'npm_install', 'npm_build', 'custom'],
                    ],
                    'branch' => [
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    'path' => [
                        'type' => 'string',
                    ],
                    'custom_command' => [
                        'type' => 'string',
                    ],
                    'clear_cache' => [
                        'type' => 'boolean',
                        'default' => true,
                    ],
                    'maintenance_mode' => [
                        'type' => 'boolean',
                        'default' => false,
                    ],
                ],
            ],
        ]);
    }

    public function check_deploy_permission(WP_REST_Request $request): bool|WP_Error {
        $base_check = $this->check_admin_permission($request);
        if (is_wp_error($base_check)) {
            return $base_check;
        }

        // Check if deploy is enabled
        $settings = MCP_Bridge::get_settings();
        if (empty($settings['enable_deploy'])) {
            return $this->error_response(
                'deploy_disabled',
                'Deploy endpoint is disabled. Enable it in MCP Bridge settings.',
                403
            );
        }

        return true;
    }

    public function execute_deploy(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $action = $request->get_param('action');
        $branch = $request->get_param('branch');
        $path = $request->get_param('path');
        $custom_command = $request->get_param('custom_command');
        $clear_cache = $request->get_param('clear_cache') !== false;
        $maintenance_mode = (bool) $request->get_param('maintenance_mode');

        // Determine working directory
        $work_dir = ABSPATH;
        if ($path) {
            $sanitized_path = $this->sanitize_path($path);
            if (!$sanitized_path) {
                return $this->error_response('invalid_path', 'Invalid path specified', 400);
            }
            $work_dir = $sanitized_path;
        }

        // Build command
        $command = $this->build_command($action, $branch, $custom_command);

        if (is_wp_error($command)) {
            return $command;
        }

        // Enable maintenance mode if requested
        if ($maintenance_mode) {
            $this->enable_maintenance_mode();
        }

        // Execute command
        $start_time = microtime(true);
        $result = $this->execute_command($command, $work_dir);
        $duration = (int) ((microtime(true) - $start_time) * 1000);

        // Disable maintenance mode
        if ($maintenance_mode) {
            $this->disable_maintenance_mode();
        }

        // Clear cache if requested
        $cache_cleared = false;
        if ($clear_cache && $result['success']) {
            $cache_cleared = $this->clear_wordpress_cache();
        }

        return $this->success_response([
            'success' => $result['success'],
            'action' => $action,
            'output' => $result['output'],
            'duration_ms' => $duration,
            'cache_cleared' => $cache_cleared,
            'maintenance_mode_used' => $maintenance_mode,
        ]);
    }

    private function build_command(string $action, ?string $branch, ?string $custom_command): string|WP_Error {
        if ($action === 'custom') {
            if (empty($custom_command)) {
                return $this->error_response('missing_command', 'Custom command is required for custom action', 400);
            }

            // Validate against allowed commands
            if (!$this->is_command_allowed($custom_command)) {
                return $this->error_response(
                    'command_not_allowed',
                    'This command is not in the allowed list. Configure allowed commands in MCP Bridge settings.',
                    403
                );
            }

            return $custom_command;
        }

        $command = $this->safe_actions[$action] ?? null;

        if (!$command) {
            return $this->error_response('invalid_action', 'Unknown action', 400);
        }

        // Add branch for git pull
        if ($action === 'git_pull' && $branch) {
            $command = "git pull origin " . escapeshellarg($branch);
        }

        return $command;
    }

    private function is_command_allowed(string $command): bool {
        $settings = MCP_Bridge::get_settings();
        $allowed = $settings['allowed_deploy_commands'] ?? '';

        if (empty($allowed)) {
            return false;
        }

        $allowed_commands = array_filter(array_map('trim', explode("\n", $allowed)));

        foreach ($allowed_commands as $allowed_cmd) {
            // Exact match or command starts with allowed prefix
            if ($command === $allowed_cmd || str_starts_with($command, $allowed_cmd . ' ')) {
                return true;
            }
        }

        return false;
    }

    private function sanitize_path(string $path): ?string {
        // Prevent directory traversal
        $path = str_replace(['..', '~'], '', $path);

        // Build absolute path
        $full_path = realpath(ABSPATH . ltrim($path, '/'));

        // Ensure path is within WordPress root
        if (!$full_path || !str_starts_with($full_path, realpath(ABSPATH))) {
            return null;
        }

        return $full_path;
    }

    private function execute_command(string $command, string $work_dir): array {
        // Change to working directory
        $old_dir = getcwd();
        chdir($work_dir);

        // Execute command
        $output = [];
        $return_var = 0;

        exec($command . ' 2>&1', $output, $return_var);

        // Restore directory
        chdir($old_dir);

        return [
            'success' => $return_var === 0,
            'output' => implode("\n", $output),
            'exit_code' => $return_var,
        ];
    }

    private function enable_maintenance_mode(): void {
        $maintenance_file = ABSPATH . '.maintenance';
        file_put_contents($maintenance_file, '<?php $upgrading = ' . time() . '; ?>');
    }

    private function disable_maintenance_mode(): void {
        $maintenance_file = ABSPATH . '.maintenance';
        if (file_exists($maintenance_file)) {
            unlink($maintenance_file);
        }
    }

    private function clear_wordpress_cache(): bool {
        $cleared = false;

        // Clear WordPress object cache
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
            $cleared = true;
        }

        // Clear popular caching plugins
        // WP Super Cache
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
            $cleared = true;
        }

        // W3 Total Cache
        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
            $cleared = true;
        }

        // WP Rocket
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
            $cleared = true;
        }

        // LiteSpeed Cache
        if (class_exists('LiteSpeed_Cache_API')) {
            LiteSpeed_Cache_API::purge_all();
            $cleared = true;
        }

        return $cleared;
    }
}
