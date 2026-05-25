<?php
/**
 * Logs Endpoint - Read WordPress and server logs
 */

defined('ABSPATH') || exit;

class MCP_Bridge_Logs_Endpoint extends MCP_Bridge_REST_Controller {

    protected string $rest_base = 'logs';

    public function register_routes(): void {
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_logs'],
                'permission_callback' => [$this, 'check_admin_permission'],
                'args' => [
                    'log_type' => [
                        'type' => 'string',
                        'default' => 'debug',
                        'enum' => ['debug', 'error', 'access', 'php', 'custom'],
                    ],
                    'lines' => [
                        'type' => 'integer',
                        'default' => 100,
                        'minimum' => 1,
                        'maximum' => 1000,
                    ],
                    'filter' => [
                        'type' => 'string',
                    ],
                    'since' => [
                        'type' => 'string',
                        'format' => 'date-time',
                    ],
                    'severity' => [
                        'type' => 'string',
                        'default' => 'all',
                        'enum' => ['all', 'error', 'warning', 'notice', 'info'],
                    ],
                    'custom_path' => [
                        'type' => 'string',
                    ],
                ],
            ],
        ]);
    }

    public function get_logs(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $log_type = $request->get_param('log_type') ?: 'debug';
        $lines = min($request->get_param('lines') ?: 100, 1000);
        $filter = $request->get_param('filter');
        $since = $request->get_param('since');
        $severity = $request->get_param('severity') ?: 'all';
        $custom_path = $request->get_param('custom_path');

        // Get log file path
        $log_file = $this->get_log_path($log_type, $custom_path);

        if (is_wp_error($log_file)) {
            return $log_file;
        }

        if (!file_exists($log_file)) {
            return $this->error_response('log_not_found', "Log file not found: {$log_type}", 404);
        }

        if (!is_readable($log_file)) {
            return $this->error_response('log_not_readable', 'Log file is not readable', 403);
        }

        // Read log file
        $entries = $this->read_log_file($log_file, $lines, $filter, $since, $severity);

        $file_size = filesize($log_file);
        $last_modified = filemtime($log_file);

        return $this->success_response([
            'entries' => $entries,
            'total_lines' => count($entries),
            'log_file' => basename($log_file),
            'file_size_bytes' => $file_size,
            'last_modified' => date('c', $last_modified),
        ]);
    }

    private function get_log_path(string $log_type, ?string $custom_path): string|WP_Error {
        switch ($log_type) {
            case 'debug':
                return WP_CONTENT_DIR . '/debug.log';

            case 'error':
                // Try common error log locations
                $paths = [
                    ini_get('error_log'),
                    ABSPATH . 'error_log',
                    ABSPATH . 'php_errors.log',
                    '/var/log/php/error.log',
                    '/var/log/apache2/error.log',
                    '/var/log/nginx/error.log',
                ];
                foreach ($paths as $path) {
                    if ($path && file_exists($path) && is_readable($path)) {
                        return $path;
                    }
                }
                return $this->error_response('error_log_not_found', 'Error log not found', 404);

            case 'access':
                $paths = [
                    '/var/log/apache2/access.log',
                    '/var/log/nginx/access.log',
                    '/var/log/httpd/access_log',
                ];
                foreach ($paths as $path) {
                    if (file_exists($path) && is_readable($path)) {
                        return $path;
                    }
                }
                return $this->error_response('access_log_not_found', 'Access log not found', 404);

            case 'php':
                $path = ini_get('error_log');
                if ($path && file_exists($path)) {
                    return $path;
                }
                return $this->error_response('php_log_not_found', 'PHP error log not found', 404);

            case 'custom':
                if (!$custom_path) {
                    return $this->error_response('custom_path_required', 'custom_path is required for custom log type', 400);
                }
                return $this->validate_custom_path($custom_path);

            default:
                return $this->error_response('invalid_log_type', 'Invalid log type', 400);
        }
    }

    private function validate_custom_path(string $path): string|WP_Error {
        // Prevent directory traversal
        if (str_contains($path, '..')) {
            return $this->error_response('invalid_path', 'Invalid path', 400);
        }

        // Only allow paths within WordPress or /var/log
        $allowed_prefixes = [
            realpath(ABSPATH),
            realpath(WP_CONTENT_DIR),
            '/var/log',
        ];

        $real_path = realpath($path);
        if (!$real_path) {
            return $this->error_response('path_not_found', 'Path not found', 404);
        }

        $allowed = false;
        foreach ($allowed_prefixes as $prefix) {
            if ($prefix && str_starts_with($real_path, $prefix)) {
                $allowed = true;
                break;
            }
        }

        if (!$allowed) {
            return $this->error_response('path_not_allowed', 'Path is outside allowed directories', 403);
        }

        return $real_path;
    }

    private function read_log_file(string $path, int $max_lines, ?string $filter, ?string $since, string $severity): array {
        $entries = [];

        // Read file from end (tail behavior)
        $file = new SplFileObject($path, 'r');
        $file->seek(PHP_INT_MAX);
        $total_lines = $file->key();

        $start_line = max(0, $total_lines - ($max_lines * 2)); // Read extra for filtering

        $since_timestamp = $since ? strtotime($since) : null;
        $severity_levels = $this->get_severity_levels($severity);

        $file->seek($start_line);
        $buffer = [];

        while (!$file->eof()) {
            $line = $file->fgets();

            if (empty(trim($line))) {
                continue;
            }

            $entry = $this->parse_log_entry($line);

            // Apply filters
            if ($filter && !preg_match('/' . preg_quote($filter, '/') . '/i', $entry['message'])) {
                continue;
            }

            if ($since_timestamp && $entry['timestamp']) {
                $entry_time = strtotime($entry['timestamp']);
                if ($entry_time && $entry_time < $since_timestamp) {
                    continue;
                }
            }

            if ($severity !== 'all' && !in_array($entry['severity'], $severity_levels)) {
                continue;
            }

            $buffer[] = $entry;
        }

        // Return last N entries
        return array_slice($buffer, -$max_lines);
    }

    private function parse_log_entry(string $line): array {
        $entry = [
            'timestamp' => null,
            'severity' => 'info',
            'message' => trim($line),
            'context' => null,
        ];

        // Try to parse WordPress debug.log format: [DD-Mon-YYYY HH:MM:SS UTC] PHP Notice: ...
        if (preg_match('/^\[([^\]]+)\]\s+(PHP\s+)?(Fatal error|Warning|Notice|Deprecated|Error|Parse error)?:?\s*(.*)$/i', $line, $matches)) {
            $entry['timestamp'] = $matches[1];
            $entry['severity'] = $this->normalize_severity($matches[3] ?? '');
            $entry['message'] = trim($matches[4]);
        }
        // Try standard log format: YYYY-MM-DD HH:MM:SS severity message
        elseif (preg_match('/^(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\s+(\w+)\s+(.*)$/i', $line, $matches)) {
            $entry['timestamp'] = $matches[1];
            $entry['severity'] = $this->normalize_severity($matches[2]);
            $entry['message'] = trim($matches[3]);
        }

        return $entry;
    }

    private function normalize_severity(string $level): string {
        $level = strtolower(trim($level));

        $map = [
            'fatal' => 'error',
            'fatal error' => 'error',
            'parse error' => 'error',
            'error' => 'error',
            'warning' => 'warning',
            'notice' => 'notice',
            'deprecated' => 'notice',
            'info' => 'info',
            'debug' => 'info',
        ];

        return $map[$level] ?? 'info';
    }

    private function get_severity_levels(string $min_severity): array {
        $all_levels = ['error', 'warning', 'notice', 'info'];

        switch ($min_severity) {
            case 'error':
                return ['error'];
            case 'warning':
                return ['error', 'warning'];
            case 'notice':
                return ['error', 'warning', 'notice'];
            default:
                return $all_levels;
        }
    }
}
