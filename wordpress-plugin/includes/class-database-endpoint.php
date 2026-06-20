<?php
/**
 * Database Query & Export Endpoint
 *
 * Provides SQL query execution and database export via mysqldump-php.
 * DISABLED by default - must be explicitly enabled in WP admin.
 */

defined('ABSPATH') || exit;

use Druidfi\Mysqldump\Mysqldump;
use Druidfi\Mysqldump\Compress\CompressManagerFactory;

class MCP_Bridge_Database_Endpoint extends MCP_Bridge_REST_Controller {

    protected string $rest_base = 'database';

    public function register_routes(): void {
        register_rest_route($this->namespace, '/' . $this->rest_base . '/query', [
            'methods'             => 'POST',
            'callback'            => [$this, 'execute_query'],
            'permission_callback' => [$this, 'check_database_permission'],
            'args'                => [
                'query' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'description'       => 'SQL query to execute',
                ],
                'type' => [
                    'required'          => true,
                    'type'              => 'string',
                    'enum'              => ['select', 'insert', 'update', 'delete'],
                    'description'       => 'Query type for validation',
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/export', [
            'methods'             => 'POST',
            'callback'            => [$this, 'export_database'],
            'permission_callback' => [$this, 'check_database_permission'],
            'args'                => [
                'tables' => [
                    'required'          => false,
                    'type'              => 'array',
                    'items'             => ['type' => 'string'],
                    'default'           => [],
                    'description'       => 'Specific tables to export (empty = all)',
                ],
                'compress' => [
                    'required'          => false,
                    'type'              => 'boolean',
                    'default'           => true,
                    'description'       => 'Compress output with gzip',
                ],
                'exclude_tables' => [
                    'required'          => false,
                    'type'              => 'array',
                    'items'             => ['type' => 'string'],
                    'default'           => [],
                    'description'       => 'Tables to exclude from export',
                ],
                'no_data' => [
                    'required'          => false,
                    'type'              => 'boolean',
                    'default'           => false,
                    'description'       => 'Export structure only (no data)',
                ],
            ],
        ]);

        // Download export file
        register_rest_route($this->namespace, '/' . $this->rest_base . '/download/(?P<filename>[a-zA-Z0-9_\-\.]+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'download_export'],
            'permission_callback' => [$this, 'check_database_permission'],
            'args'                => [
                'filename' => [
                    'required'          => true,
                    'type'              => 'string',
                    'validate_callback' => function($param) {
                        return preg_match('/^export_[\d\-_]+\.sql(\.gz)?$/', $param);
                    },
                    'description'       => 'Export filename to download',
                ],
            ],
        ]);

        // List available exports
        register_rest_route($this->namespace, '/' . $this->rest_base . '/exports', [
            'methods'             => 'GET',
            'callback'            => [$this, 'list_exports'],
            'permission_callback' => [$this, 'check_database_permission'],
        ]);
    }

    /**
     * Check database access permission
     */
    public function check_database_permission(WP_REST_Request $request): bool|WP_Error {
        $base_check = $this->check_admin_permission($request);
        if (is_wp_error($base_check)) {
            return $base_check;
        }

        $settings = MCP_Bridge::get_settings();
        if (empty($settings['enable_database'])) {
            return $this->error_response(
                'database_disabled',
                'Database access is disabled. Enable it in Settings > MCP Bridge.',
                403
            );
        }

        return true;
    }

    /**
     * Execute SQL query
     */
    public function execute_query(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;

        $query = trim($request->get_param('query'));
        $type = strtolower($request->get_param('type'));

        // Validate query type matches actual query
        $query_start = strtoupper(substr(ltrim($query), 0, 10));
        $expected_starts = [
            'select' => 'SELECT',
            'insert' => 'INSERT',
            'update' => 'UPDATE',
            'delete' => 'DELETE',
        ];

        if (!str_starts_with($query_start, $expected_starts[$type])) {
            return $this->error_response(
                'query_type_mismatch',
                "Query type '{$type}' does not match actual query",
                400
            );
        }

        // Auto-add LIMIT to SELECT queries (max 10000)
        if ($type === 'select') {
            $query = $this->ensure_limit($query, 10000);
        }

        // Log query if enabled
        $this->log_query($query, $type);

        // Execute query
        $start_time = microtime(true);

        if ($type === 'select') {
            $results = $wpdb->get_results($query, ARRAY_A);
            $execution_time = round((microtime(true) - $start_time) * 1000, 2);

            if ($wpdb->last_error) {
                return $this->error_response('query_error', $wpdb->last_error, 400);
            }

            return $this->success_response([
                'success'        => true,
                'type'           => 'select',
                'rows'           => $results,
                'row_count'      => count($results),
                'columns'        => $results ? array_keys($results[0]) : [],
                'execution_time' => $execution_time . 'ms',
            ]);
        }

        // Mutation queries
        $result = $wpdb->query($query);
        $execution_time = round((microtime(true) - $start_time) * 1000, 2);

        if ($wpdb->last_error) {
            return $this->error_response('query_error', $wpdb->last_error, 400);
        }

        return $this->success_response([
            'success'        => true,
            'type'           => $type,
            'affected_rows'  => $result,
            'insert_id'      => $type === 'insert' ? $wpdb->insert_id : null,
            'execution_time' => $execution_time . 'ms',
        ]);
    }

    /**
     * Export database using mysqldump-php
     */
    public function export_database(WP_REST_Request $request): WP_REST_Response|WP_Error {
        // Check if library is available
        $autoload_path = MCP_BRIDGE_PLUGIN_DIR . 'vendor/autoload.php';
        if (!file_exists($autoload_path)) {
            return $this->error_response(
                'dependency_missing',
                'mysqldump-php not installed. Run: cd wordpress-plugin && composer install --no-dev',
                500
            );
        }

        require_once $autoload_path;

        $tables = $request->get_param('tables') ?: [];
        $compress = $request->get_param('compress');
        $exclude_tables = $request->get_param('exclude_tables') ?: [];
        $no_data = $request->get_param('no_data');

        // Prepare export directory
        $export_dir = $this->get_export_directory();
        if (is_wp_error($export_dir)) {
            return $export_dir;
        }

        // Generate filename
        $timestamp = date('Y-m-d_H-i-s');
        $filename = "export_{$timestamp}.sql" . ($compress ? '.gz' : '');
        $filepath = $export_dir . '/' . $filename;

        $compress_method = $compress
            ? CompressManagerFactory::GZIP
            : CompressManagerFactory::NONE;

        $dump_settings = [
            'compress' => $compress_method,
            'no-data'  => $no_data,
        ];

        if (!empty($exclude_tables)) {
            $dump_settings['exclude-tables'] = $exclude_tables;
        }

        if (!empty($tables)) {
            $dump_settings['include-tables'] = $tables;
        }

        try {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s',
                DB_HOST,
                DB_NAME
            );

            $dump = new Mysqldump($dsn, DB_USER, DB_PASSWORD, $dump_settings);
            $dump->start($filepath);

            // Log export
            $this->log_query("DATABASE EXPORT: {$filename}", 'export');

            $download_url = rest_url($this->namespace . '/' . $this->rest_base . '/download/' . $filename);

            return $this->success_response([
                'success'        => true,
                'filename'       => $filename,
                'path'           => str_replace(ABSPATH, '', $filepath),
                'size'           => filesize($filepath),
                'size_human'     => size_format(filesize($filepath)),
                'download_url'   => $download_url,
                'tables'         => empty($tables) ? 'all' : $tables,
                'excluded'       => $exclude_tables,
                'compressed'     => $compress,
                'structure_only' => $no_data,
            ]);

        } catch (Exception $e) {
            return $this->error_response('export_failed', $e->getMessage(), 500);
        }
    }

    /**
     * Download export file
     */
    public function download_export(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $filename = $request->get_param('filename');

        $upload_dir = wp_upload_dir();
        $export_dir = $upload_dir['basedir'] . '/mcp-bridge-exports';
        $filepath = $export_dir . '/' . $filename;

        // Security: Validate filename format again
        if (!preg_match('/^export_[\d\-_]+\.sql(\.gz)?$/', $filename)) {
            return $this->error_response('invalid_filename', 'Invalid export filename format', 400);
        }

        if (!file_exists($filepath)) {
            return $this->error_response('file_not_found', 'Export file not found', 404);
        }

        // Log download
        $this->log_query("DATABASE DOWNLOAD: {$filename}", 'download');

        // Determine content type
        $is_gzip = str_ends_with($filename, '.gz');
        $content_type = $is_gzip ? 'application/gzip' : 'application/sql';

        // Stream file
        header('Content-Type: ' . $content_type);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');

        readfile($filepath);
        exit;
    }

    /**
     * List available export files
     */
    public function list_exports(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $upload_dir = wp_upload_dir();
        $export_dir = $upload_dir['basedir'] . '/mcp-bridge-exports';

        if (!is_dir($export_dir)) {
            return $this->success_response([
                'exports' => [],
                'count'   => 0,
            ]);
        }

        $files = glob($export_dir . '/export_*.sql*');
        $exports = [];

        foreach ($files as $file) {
            $filename = basename($file);
            $exports[] = [
                'filename'     => $filename,
                'size'         => filesize($file),
                'size_human'   => size_format(filesize($file)),
                'created'      => date('c', filemtime($file)),
                'download_url' => rest_url($this->namespace . '/' . $this->rest_base . '/download/' . $filename),
            ];
        }

        // Sort by date descending
        usort($exports, fn($a, $b) => strcmp($b['created'], $a['created']));

        return $this->success_response([
            'exports' => $exports,
            'count'   => count($exports),
        ]);
    }

    /**
     * Ensure SELECT query has LIMIT clause
     */
    private function ensure_limit(string $query, int $max_limit): string {
        // Check if LIMIT already exists (case-insensitive)
        if (preg_match('/\bLIMIT\s+\d+/i', $query)) {
            // Extract existing limit and cap it
            return preg_replace_callback(
                '/\bLIMIT\s+(\d+)/i',
                function($matches) use ($max_limit) {
                    $limit = min((int)$matches[1], $max_limit);
                    return "LIMIT {$limit}";
                },
                $query
            );
        }

        // Add LIMIT if not present
        $query = rtrim($query, '; ');
        return "{$query} LIMIT {$max_limit}";
    }

    /**
     * Get or create protected export directory
     */
    private function get_export_directory(): string|WP_Error {
        $upload_dir = wp_upload_dir();
        $export_dir = $upload_dir['basedir'] . '/mcp-bridge-exports';

        if (!file_exists($export_dir)) {
            if (!wp_mkdir_p($export_dir)) {
                return $this->error_response(
                    'directory_creation_failed',
                    'Failed to create export directory',
                    500
                );
            }

            // Create .htaccess to deny direct access
            $htaccess = $export_dir . '/.htaccess';
            file_put_contents($htaccess, "Deny from all\n");

            // Create index.php for extra protection
            file_put_contents($export_dir . '/index.php', '<?php // Silence is golden');
        }

        return $export_dir;
    }

    /**
     * Log query if logging is enabled
     */
    private function log_query(string $query, string $type): void {
        $settings = MCP_Bridge::get_settings();
        if (empty($settings['enable_database_logging'])) {
            return;
        }

        $log_dir = WP_CONTENT_DIR . '/mcp-bridge-logs';
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
            file_put_contents($log_dir . '/.htaccess', "Deny from all\n");
        }

        $log_file = $log_dir . '/database-queries.log';
        $timestamp = date('Y-m-d H:i:s');
        $user = wp_get_current_user();
        $username = $user->user_login ?? 'unknown';
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        $log_entry = "[{$timestamp}] [{$username}] [{$ip}] [{$type}] {$query}\n";

        file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }
}
