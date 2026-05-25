<?php
/**
 * Filesystem Endpoint - PHP-native file operations
 * Works on all hosting (no shell_exec required)
 */

defined('ABSPATH') || exit;

class MCP_Bridge_Filesystem_Endpoint extends MCP_Bridge_REST_Controller {

    protected string $rest_base = 'filesystem';

    // Allowed base paths (security)
    private array $allowed_paths = [];

    public function __construct() {
        $this->allowed_paths = [
            ABSPATH,
            WP_CONTENT_DIR,
            get_theme_root(),
            WP_PLUGIN_DIR,
        ];
    }

    public function register_routes(): void {
        // List directory
        register_rest_route($this->namespace, '/' . $this->rest_base . '/list', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'list_directory'],
                'permission_callback' => [$this, 'check_filesystem_permission'],
                'args' => [
                    'path' => [
                        'required' => true,
                        'type' => 'string',
                        'description' => 'Relative path from WordPress root',
                    ],
                    'pattern' => [
                        'type' => 'string',
                        'default' => '*',
                        'description' => 'Glob pattern (e.g., *.php, *.js)',
                    ],
                    'recursive' => [
                        'type' => 'boolean',
                        'default' => false,
                    ],
                ],
            ],
        ]);

        // Read file
        register_rest_route($this->namespace, '/' . $this->rest_base . '/read', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'read_file'],
                'permission_callback' => [$this, 'check_filesystem_permission'],
                'args' => [
                    'path' => [
                        'required' => true,
                        'type' => 'string',
                    ],
                    'lines' => [
                        'type' => 'integer',
                        'default' => 0,
                        'description' => 'Limit to first N lines (0 = all)',
                    ],
                ],
            ],
        ]);

        // Write file
        register_rest_route($this->namespace, '/' . $this->rest_base . '/write', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'write_file'],
                'permission_callback' => [$this, 'check_filesystem_permission'],
                'args' => [
                    'path' => [
                        'required' => true,
                        'type' => 'string',
                    ],
                    'content' => [
                        'required' => true,
                        'type' => 'string',
                    ],
                    'backup' => [
                        'type' => 'boolean',
                        'default' => true,
                        'description' => 'Create backup before overwriting',
                    ],
                ],
            ],
        ]);

        // Create zip backup
        register_rest_route($this->namespace, '/' . $this->rest_base . '/zip', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'create_zip'],
                'permission_callback' => [$this, 'check_filesystem_permission'],
                'args' => [
                    'path' => [
                        'required' => true,
                        'type' => 'string',
                        'description' => 'Directory or file to zip',
                    ],
                    'filename' => [
                        'type' => 'string',
                        'description' => 'Output zip filename',
                    ],
                ],
            ],
        ]);

        // Extract zip
        register_rest_route($this->namespace, '/' . $this->rest_base . '/unzip', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'extract_zip'],
                'permission_callback' => [$this, 'check_filesystem_permission'],
                'args' => [
                    'zip_path' => [
                        'required' => true,
                        'type' => 'string',
                        'description' => 'Path to zip file',
                    ],
                    'destination' => [
                        'required' => true,
                        'type' => 'string',
                        'description' => 'Extraction destination',
                    ],
                    'overwrite' => [
                        'type' => 'boolean',
                        'default' => false,
                    ],
                ],
            ],
        ]);

        // Delete file/directory
        register_rest_route($this->namespace, '/' . $this->rest_base . '/delete', [
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'delete_path'],
                'permission_callback' => [$this, 'check_filesystem_permission'],
                'args' => [
                    'path' => [
                        'required' => true,
                        'type' => 'string',
                    ],
                    'recursive' => [
                        'type' => 'boolean',
                        'default' => false,
                    ],
                ],
            ],
        ]);
    }

    public function check_filesystem_permission(WP_REST_Request $request): bool|WP_Error {
        $base_check = $this->check_permission($request);
        if (is_wp_error($base_check)) {
            return $base_check;
        }

        // Require admin for filesystem operations
        if (!current_user_can('manage_options')) {
            return $this->error_response('forbidden', 'Administrator access required for filesystem operations', 403);
        }

        // Check if filesystem operations are enabled
        $settings = MCP_Bridge::get_settings();
        if (empty($settings['enable_filesystem'])) {
            return $this->error_response('filesystem_disabled', 'Filesystem operations are disabled. Enable in MCP Bridge settings.', 403);
        }

        return true;
    }

    /**
     * Validate and resolve path (security)
     */
    private function resolve_path(string $relative_path): string|WP_Error {
        // Remove leading slash
        $relative_path = ltrim($relative_path, '/');

        // Build absolute path
        $absolute_path = ABSPATH . $relative_path;

        // Resolve to real path (handles ../ etc)
        $real_path = realpath($absolute_path);

        // For new files, check parent directory
        if (!$real_path) {
            $parent_dir = realpath(dirname($absolute_path));
            if (!$parent_dir) {
                return $this->error_response('invalid_path', 'Path does not exist', 404);
            }
            $real_path = $parent_dir . '/' . basename($absolute_path);
        }

        // Security: Ensure path is within allowed directories
        $is_allowed = false;
        foreach ($this->allowed_paths as $allowed) {
            if (strpos($real_path, realpath($allowed)) === 0) {
                $is_allowed = true;
                break;
            }
        }

        if (!$is_allowed) {
            return $this->error_response('path_not_allowed', 'Path is outside allowed directories', 403);
        }

        // Block sensitive files
        $blocked_patterns = [
            'wp-config.php',
            '.htaccess',
            '.env',
            'wp-config-sample.php',
        ];

        foreach ($blocked_patterns as $pattern) {
            if (basename($real_path) === $pattern) {
                return $this->error_response('file_blocked', 'Access to this file is blocked for security', 403);
            }
        }

        return $real_path;
    }

    /**
     * List directory contents
     */
    public function list_directory(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $path = $request->get_param('path');
        $pattern = $request->get_param('pattern') ?: '*';
        $recursive = $request->get_param('recursive');

        $resolved = $this->resolve_path($path);
        if (is_wp_error($resolved)) {
            return $resolved;
        }

        if (!is_dir($resolved)) {
            return $this->error_response('not_directory', 'Path is not a directory', 400);
        }

        $glob_pattern = rtrim($resolved, '/') . '/' . $pattern;

        if ($recursive) {
            $files = $this->glob_recursive($glob_pattern);
        } else {
            $files = glob($glob_pattern);
        }

        $result = [];
        foreach ($files as $file) {
            $stat = stat($file);
            $result[] = [
                'name' => basename($file),
                'path' => str_replace(ABSPATH, '', $file),
                'type' => is_dir($file) ? 'directory' : 'file',
                'size' => is_file($file) ? filesize($file) : null,
                'modified' => date('c', $stat['mtime']),
                'permissions' => substr(sprintf('%o', fileperms($file)), -4),
            ];
        }

        // Sort: directories first, then by name
        usort($result, function($a, $b) {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'directory' ? -1 : 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });

        return $this->success_response([
            'path' => $path,
            'count' => count($result),
            'items' => $result,
        ]);
    }

    private function glob_recursive(string $pattern, int $flags = 0): array {
        $files = glob($pattern, $flags);
        foreach (glob(dirname($pattern) . '/*', GLOB_ONLYDIR | GLOB_NOSORT) as $dir) {
            $files = array_merge($files, $this->glob_recursive($dir . '/' . basename($pattern), $flags));
        }
        return $files;
    }

    /**
     * Read file contents
     */
    public function read_file(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $path = $request->get_param('path');
        $lines = $request->get_param('lines');

        $resolved = $this->resolve_path($path);
        if (is_wp_error($resolved)) {
            return $resolved;
        }

        if (!is_file($resolved)) {
            return $this->error_response('not_file', 'Path is not a file', 400);
        }

        // Check file size (max 5MB)
        $size = filesize($resolved);
        if ($size > 5 * 1024 * 1024) {
            return $this->error_response('file_too_large', 'File exceeds 5MB limit', 400);
        }

        $content = file_get_contents($resolved);

        if ($content === false) {
            return $this->error_response('read_failed', 'Failed to read file', 500);
        }

        // Limit lines if requested
        if ($lines > 0) {
            $content_lines = explode("\n", $content);
            $content = implode("\n", array_slice($content_lines, 0, $lines));
        }

        return $this->success_response([
            'path' => $path,
            'size' => $size,
            'lines' => $lines > 0 ? min($lines, substr_count($content, "\n") + 1) : substr_count($content, "\n") + 1,
            'content' => $content,
            'mime_type' => mime_content_type($resolved),
        ]);
    }

    /**
     * Write file contents
     */
    public function write_file(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $path = $request->get_param('path');
        $content = $request->get_param('content');
        $backup = $request->get_param('backup');

        $resolved = $this->resolve_path($path);
        if (is_wp_error($resolved)) {
            return $resolved;
        }

        // Create backup if file exists and backup requested
        $backup_path = null;
        if ($backup && file_exists($resolved)) {
            $backup_path = $resolved . '.bak.' . date('YmdHis');
            if (!copy($resolved, $backup_path)) {
                return $this->error_response('backup_failed', 'Failed to create backup', 500);
            }
        }

        // Ensure directory exists
        $dir = dirname($resolved);
        if (!is_dir($dir)) {
            if (!wp_mkdir_p($dir)) {
                return $this->error_response('mkdir_failed', 'Failed to create directory', 500);
            }
        }

        // Write file
        $result = file_put_contents($resolved, $content);

        if ($result === false) {
            return $this->error_response('write_failed', 'Failed to write file', 500);
        }

        return $this->success_response([
            'path' => $path,
            'bytes_written' => $result,
            'backup_path' => $backup_path ? str_replace(ABSPATH, '', $backup_path) : null,
        ]);
    }

    /**
     * Create zip archive
     */
    public function create_zip(WP_REST_Request $request): WP_REST_Response|WP_Error {
        if (!class_exists('ZipArchive')) {
            return $this->error_response('zip_not_available', 'ZipArchive extension not installed', 500);
        }

        $path = $request->get_param('path');
        $filename = $request->get_param('filename');

        $resolved = $this->resolve_path($path);
        if (is_wp_error($resolved)) {
            return $resolved;
        }

        if (!file_exists($resolved)) {
            return $this->error_response('path_not_found', 'Path does not exist', 404);
        }

        // Generate zip filename
        if (!$filename) {
            $filename = basename($resolved) . '-' . date('YmdHis') . '.zip';
        }

        $upload_dir = wp_upload_dir();
        $zip_dir = $upload_dir['basedir'] . '/mcp-bridge-backups';

        if (!is_dir($zip_dir)) {
            wp_mkdir_p($zip_dir);
            file_put_contents($zip_dir . '/.htaccess', 'deny from all');
        }

        $zip_path = $zip_dir . '/' . sanitize_file_name($filename);

        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return $this->error_response('zip_create_failed', 'Failed to create zip file', 500);
        }

        if (is_dir($resolved)) {
            $this->add_directory_to_zip($zip, $resolved, basename($resolved));
        } else {
            $zip->addFile($resolved, basename($resolved));
        }

        $zip->close();

        return $this->success_response([
            'zip_path' => str_replace(ABSPATH, '', $zip_path),
            'zip_size' => filesize($zip_path),
            'download_url' => $upload_dir['baseurl'] . '/mcp-bridge-backups/' . basename($zip_path),
        ]);
    }

    private function add_directory_to_zip(ZipArchive $zip, string $dir, string $zip_path): void {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if ($file->isFile()) {
                $file_path = $file->getRealPath();
                $relative_path = $zip_path . '/' . substr($file_path, strlen($dir) + 1);
                $zip->addFile($file_path, $relative_path);
            }
        }
    }

    /**
     * Extract zip archive
     */
    public function extract_zip(WP_REST_Request $request): WP_REST_Response|WP_Error {
        if (!class_exists('ZipArchive')) {
            return $this->error_response('zip_not_available', 'ZipArchive extension not installed', 500);
        }

        $zip_path = $request->get_param('zip_path');
        $destination = $request->get_param('destination');
        $overwrite = $request->get_param('overwrite');

        $resolved_zip = $this->resolve_path($zip_path);
        if (is_wp_error($resolved_zip)) {
            return $resolved_zip;
        }

        $resolved_dest = $this->resolve_path($destination);
        if (is_wp_error($resolved_dest)) {
            return $resolved_dest;
        }

        if (!file_exists($resolved_zip)) {
            return $this->error_response('zip_not_found', 'Zip file does not exist', 404);
        }

        if (is_dir($resolved_dest) && !$overwrite) {
            return $this->error_response('destination_exists', 'Destination exists. Use overwrite=true to replace.', 400);
        }

        $zip = new ZipArchive();
        if ($zip->open($resolved_zip) !== true) {
            return $this->error_response('zip_open_failed', 'Failed to open zip file', 500);
        }

        // Create destination directory
        if (!is_dir($resolved_dest)) {
            wp_mkdir_p($resolved_dest);
        }

        $zip->extractTo($resolved_dest);
        $count = $zip->numFiles;
        $zip->close();

        return $this->success_response([
            'destination' => $destination,
            'files_extracted' => $count,
        ]);
    }

    /**
     * Delete file or directory
     */
    public function delete_path(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $path = $request->get_param('path');
        $recursive = $request->get_param('recursive');

        $resolved = $this->resolve_path($path);
        if (is_wp_error($resolved)) {
            return $resolved;
        }

        if (!file_exists($resolved)) {
            return $this->error_response('path_not_found', 'Path does not exist', 404);
        }

        if (is_dir($resolved)) {
            if (!$recursive) {
                return $this->error_response('directory_not_empty', 'Directory is not empty. Use recursive=true to delete.', 400);
            }
            $this->delete_directory_recursive($resolved);
        } else {
            if (!unlink($resolved)) {
                return $this->error_response('delete_failed', 'Failed to delete file', 500);
            }
        }

        return $this->success_response([
            'deleted' => $path,
            'type' => is_dir($resolved) ? 'directory' : 'file',
        ]);
    }

    private function delete_directory_recursive(string $dir): bool {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }

        return rmdir($dir);
    }
}
