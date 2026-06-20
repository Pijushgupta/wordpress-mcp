<?php
/**
 * Plugin Name: MCP Bridge
 * Plugin URI: https://github.com/your-repo/mcp-bridge
 * Description: REST API bridge for Model Context Protocol (MCP) integration with Claude Code
 * Version: 1.0.0
 * Author: Your Name
 * License: MIT
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

defined('ABSPATH') || exit;

define('MCP_BRIDGE_VERSION', '1.0.0');
define('MCP_BRIDGE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MCP_BRIDGE_PLUGIN_URL', plugin_dir_url(__FILE__));

// Load dependencies
require_once MCP_BRIDGE_PLUGIN_DIR . 'includes/class-rest-controller.php';
require_once MCP_BRIDGE_PLUGIN_DIR . 'includes/class-posts-endpoint.php';
require_once MCP_BRIDGE_PLUGIN_DIR . 'includes/class-orders-endpoint.php';
require_once MCP_BRIDGE_PLUGIN_DIR . 'includes/class-invoice-endpoint.php';
require_once MCP_BRIDGE_PLUGIN_DIR . 'includes/class-crm-endpoint.php';
require_once MCP_BRIDGE_PLUGIN_DIR . 'includes/class-n8n-endpoint.php';
require_once MCP_BRIDGE_PLUGIN_DIR . 'includes/class-images-endpoint.php';
require_once MCP_BRIDGE_PLUGIN_DIR . 'includes/class-deploy-endpoint.php';
require_once MCP_BRIDGE_PLUGIN_DIR . 'includes/class-logs-endpoint.php';
require_once MCP_BRIDGE_PLUGIN_DIR . 'includes/class-filesystem-endpoint.php';
require_once MCP_BRIDGE_PLUGIN_DIR . 'includes/class-database-endpoint.php';

/**
 * Main plugin class
 */
class MCP_Bridge {

    private static ?MCP_Bridge $instance = null;

    public static function get_instance(): MCP_Bridge {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);

        // Rate limiting
        add_filter('rest_pre_dispatch', [$this, 'check_rate_limit'], 10, 3);
    }

    public function register_routes(): void {
        $endpoints = [
            new MCP_Bridge_Posts_Endpoint(),
            new MCP_Bridge_Orders_Endpoint(),
            new MCP_Bridge_Invoice_Endpoint(),
            new MCP_Bridge_CRM_Endpoint(),
            new MCP_Bridge_N8N_Endpoint(),
            new MCP_Bridge_Images_Endpoint(),
            new MCP_Bridge_Deploy_Endpoint(),
            new MCP_Bridge_Logs_Endpoint(),
            new MCP_Bridge_Filesystem_Endpoint(),
            new MCP_Bridge_Database_Endpoint(),
        ];

        foreach ($endpoints as $endpoint) {
            $endpoint->register_routes();
        }
    }

    public function add_admin_menu(): void {
        add_options_page(
            'MCP Bridge Settings',
            'MCP Bridge',
            'manage_options',
            'mcp-bridge',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings(): void {
        register_setting('mcp_bridge', 'mcp_bridge_settings', [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);

        add_settings_section(
            'mcp_bridge_general',
            'General Settings',
            null,
            'mcp-bridge'
        );

        add_settings_field(
            'api_key',
            'API Key',
            [$this, 'render_api_key_field'],
            'mcp-bridge',
            'mcp_bridge_general'
        );

        add_settings_field(
            'api_key_user',
            'API Key User',
            [$this, 'render_api_key_user_field'],
            'mcp-bridge',
            'mcp_bridge_general'
        );

        add_settings_field(
            'rate_limit',
            'Rate Limit (per minute)',
            [$this, 'render_rate_limit_field'],
            'mcp-bridge',
            'mcp_bridge_general'
        );

        add_settings_field(
            'enable_deploy',
            'Enable Deploy Endpoint',
            [$this, 'render_enable_deploy_field'],
            'mcp-bridge',
            'mcp_bridge_general'
        );

        add_settings_field(
            'enable_filesystem',
            'Enable Filesystem Operations',
            [$this, 'render_enable_filesystem_field'],
            'mcp-bridge',
            'mcp_bridge_general'
        );

        add_settings_field(
            'allowed_deploy_commands',
            'Allowed Deploy Commands',
            [$this, 'render_deploy_commands_field'],
            'mcp-bridge',
            'mcp_bridge_general'
        );

        add_settings_field(
            'enable_database',
            'Enable Database Access',
            [$this, 'render_enable_database_field'],
            'mcp-bridge',
            'mcp_bridge_general'
        );

        add_settings_field(
            'enable_database_logging',
            'Enable Database Query Logging',
            [$this, 'render_enable_database_logging_field'],
            'mcp-bridge',
            'mcp_bridge_general'
        );

        add_settings_section(
            'mcp_bridge_n8n',
            'n8n Integration',
            null,
            'mcp-bridge'
        );

        add_settings_field(
            'n8n_webhooks',
            'Webhook Configurations',
            [$this, 'render_n8n_webhooks_field'],
            'mcp-bridge',
            'mcp_bridge_n8n'
        );
    }

    public function sanitize_settings(array $input): array {
        $sanitized = [];

        // API key - generate if requested, otherwise keep existing
        $existing = get_option('mcp_bridge_settings', []);
        if (!empty($input['generate_api_key'])) {
            $sanitized['api_key'] = wp_generate_password(32, false);
        } else {
            $sanitized['api_key'] = $existing['api_key'] ?? '';
        }
        $sanitized['api_key_user_id'] = absint($input['api_key_user_id'] ?? 0);

        $sanitized['rate_limit'] = absint($input['rate_limit'] ?? 100);
        $sanitized['enable_deploy'] = !empty($input['enable_deploy']);
        $sanitized['enable_filesystem'] = !empty($input['enable_filesystem']);
        $sanitized['enable_database'] = !empty($input['enable_database']);
        $sanitized['enable_database_logging'] = !empty($input['enable_database_logging']);
        $sanitized['allowed_deploy_commands'] = sanitize_textarea_field($input['allowed_deploy_commands'] ?? '');
        $webhooks_input = $input['n8n_webhooks'] ?? '';
        // Handle both string (from textarea) and array (from existing option)
        if (is_array($webhooks_input)) {
            $sanitized['n8n_webhooks'] = $webhooks_input;
        } else {
            $sanitized['n8n_webhooks'] = $this->sanitize_webhooks($webhooks_input);
        }

        return $sanitized;
    }

    private function sanitize_webhooks(string $webhooks): array {
        $lines = explode("\n", $webhooks);
        $result = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $parts = explode('|', $line, 2);
            if (count($parts) === 2) {
                $id = sanitize_key($parts[0]);
                $url = esc_url_raw($parts[1]);
                if ($id && $url) {
                    $result[$id] = $url;
                }
            }
        }

        return $result;
    }

    public function render_settings_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('mcp_bridge');
                do_settings_sections('mcp-bridge');
                submit_button('Save Settings');
                ?>
            </form>

            <hr>
            <h2>API Endpoints</h2>
            <table class="widefat">
                <thead>
                    <tr>
                        <th>Endpoint</th>
                        <th>Method</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td>/wp-json/mcp-bridge/v1/posts</td><td>GET</td><td>Query posts with filters</td></tr>
                    <tr><td>/wp-json/mcp-bridge/v1/posts/{id}</td><td>GET</td><td>Get single post by ID</td></tr>
                    <tr><td>/wp-json/mcp-bridge/v1/posts</td><td>POST</td><td>Create/update posts</td></tr>
                    <tr><td>/wp-json/mcp-bridge/v1/orders</td><td>GET</td><td>Query WooCommerce orders</td></tr>
                    <tr><td>/wp-json/mcp-bridge/v1/invoices</td><td>POST</td><td>Generate invoices</td></tr>
                    <tr><td>/wp-json/mcp-bridge/v1/crm/search</td><td>GET</td><td>Search CRM contacts</td></tr>
                    <tr><td>/wp-json/mcp-bridge/v1/n8n/trigger</td><td>POST</td><td>Trigger n8n workflows</td></tr>
                    <tr><td>/wp-json/mcp-bridge/v1/images/generate</td><td>POST</td><td>Generate AI images</td></tr>
                    <tr><td>/wp-json/mcp-bridge/v1/deploy</td><td>POST</td><td>Deploy code</td></tr>
                    <tr><td>/wp-json/mcp-bridge/v1/logs</td><td>GET</td><td>Read server logs</td></tr>
                    <tr><td>/wp-json/mcp-bridge/v1/database/query</td><td>POST</td><td>Execute SQL queries</td></tr>
                    <tr><td>/wp-json/mcp-bridge/v1/database/export</td><td>POST</td><td>Export database</td></tr>
                    <tr><td>/wp-json/mcp-bridge/v1/database/exports</td><td>GET</td><td>List available exports</td></tr>
                    <tr><td>/wp-json/mcp-bridge/v1/database/download/{filename}</td><td>GET</td><td>Download export file</td></tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function render_api_key_field(): void {
        $settings = get_option('mcp_bridge_settings', []);
        $api_key = $settings['api_key'] ?? '';
        ?>
        <?php if ($api_key): ?>
            <input type="text" id="mcp-api-key" value="<?php echo esc_attr($api_key); ?>" readonly
                   style="font-family: monospace; width: 300px; font-size: 14px;">
            <button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById('mcp-api-key').value); this.textContent='Copied!'; setTimeout(() => this.textContent='Copy', 2000);">Copy</button>
        <?php else: ?>
            <em>Not generated yet</em>
        <?php endif; ?>
        <br><br>
        <label>
            <input type="checkbox" name="mcp_bridge_settings[generate_api_key]" value="1">
            Generate new API key (will invalidate existing key)
        </label>
        <p class="description">
            Use header <code>X-MCP-API-Key: YOUR_KEY</code> as alternative to Basic Auth.<br>
            Useful when Cloudflare strips Authorization headers.
        </p>
        <?php
    }

    public function render_api_key_user_field(): void {
        $settings = get_option('mcp_bridge_settings', []);
        $user_id = $settings['api_key_user_id'] ?? 0;
        $users = get_users(['role__in' => ['administrator', 'editor'], 'number' => 50]);
        ?>
        <select name="mcp_bridge_settings[api_key_user_id]">
            <option value="0">— Select User —</option>
            <?php foreach ($users as $user): ?>
                <option value="<?php echo esc_attr($user->ID); ?>" <?php selected($user_id, $user->ID); ?>>
                    <?php echo esc_html($user->display_name); ?> (<?php echo esc_html($user->user_login); ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description">API key requests will run as this user (determines permissions)</p>
        <?php
    }

    public function render_rate_limit_field(): void {
        $settings = get_option('mcp_bridge_settings', []);
        $value = $settings['rate_limit'] ?? 100;
        ?>
        <input type="number" name="mcp_bridge_settings[rate_limit]" value="<?php echo esc_attr($value); ?>" min="1" max="1000">
        <p class="description">Maximum API requests per minute per user</p>
        <?php
    }

    public function render_enable_deploy_field(): void {
        $settings = get_option('mcp_bridge_settings', []);
        $checked = !empty($settings['enable_deploy']);
        ?>
        <input type="checkbox" name="mcp_bridge_settings[enable_deploy]" value="1" <?php checked($checked); ?>>
        <p class="description">⚠️ Security risk: Enable only if you understand the implications. Requires shell_exec.</p>
        <?php
    }

    public function render_enable_filesystem_field(): void {
        $settings = get_option('mcp_bridge_settings', []);
        $checked = !empty($settings['enable_filesystem']);
        ?>
        <input type="checkbox" name="mcp_bridge_settings[enable_filesystem]" value="1" <?php checked($checked); ?>>
        <p class="description">Enable PHP-native file operations (list, read, write, zip). Works on all hosting.</p>
        <?php
    }

    public function render_deploy_commands_field(): void {
        $settings = get_option('mcp_bridge_settings', []);
        $value = $settings['allowed_deploy_commands'] ?? "git pull\ncomposer install --no-dev\nnpm ci\nnpm run build";
        ?>
        <textarea name="mcp_bridge_settings[allowed_deploy_commands]" rows="5" cols="50"><?php echo esc_textarea($value); ?></textarea>
        <p class="description">One command per line. Only these commands can be executed via custom action.</p>
        <?php
    }

    public function render_enable_database_field(): void {
        $settings = get_option('mcp_bridge_settings', []);
        $checked = !empty($settings['enable_database']);
        ?>
        <input type="checkbox" name="mcp_bridge_settings[enable_database]" value="1" <?php checked($checked); ?>>
        <p class="description">
            <strong style="color: #d63638;">SECURITY WARNING:</strong> Enables direct SQL query execution and database export.<br>
            Only enable if you understand the security implications. Requires <code>manage_options</code> capability.
        </p>
        <?php
    }

    public function render_enable_database_logging_field(): void {
        $settings = get_option('mcp_bridge_settings', []);
        $checked = !empty($settings['enable_database_logging']);
        ?>
        <input type="checkbox" name="mcp_bridge_settings[enable_database_logging]" value="1" <?php checked($checked); ?>>
        <p class="description">Log all database queries to <code>wp-content/mcp-bridge-logs/database-queries.log</code></p>
        <?php
    }

    public function render_n8n_webhooks_field(): void {
        $settings = get_option('mcp_bridge_settings', []);
        $webhooks = $settings['n8n_webhooks'] ?? [];
        $value = '';
        foreach ($webhooks as $id => $url) {
            $value .= "{$id}|{$url}\n";
        }
        ?>
        <textarea name="mcp_bridge_settings[n8n_webhooks]" rows="5" cols="50"><?php echo esc_textarea(trim($value)); ?></textarea>
        <p class="description">Format: webhook_id|https://n8n.example.com/webhook/xxx (one per line)</p>
        <?php
    }

    public function check_rate_limit($result, $server, $request): mixed {
        // Only apply to our namespace
        if (strpos($request->get_route(), '/mcp-bridge/') === false) {
            return $result;
        }

        $user_id = get_current_user_id();
        if (!$user_id) {
            return $result;
        }

        $settings = get_option('mcp_bridge_settings', []);
        $limit = $settings['rate_limit'] ?? 100;

        $transient_key = "mcp_bridge_rate_{$user_id}";
        $current = get_transient($transient_key) ?: 0;

        if ($current >= $limit) {
            return new WP_Error(
                'rate_limit_exceeded',
                'Rate limit exceeded. Try again in 1 minute.',
                ['status' => 429]
            );
        }

        set_transient($transient_key, $current + 1, MINUTE_IN_SECONDS);

        return $result;
    }

    public static function get_settings(): array {
        return get_option('mcp_bridge_settings', [
            'rate_limit' => 100,
            'enable_deploy' => false,
            'enable_filesystem' => false,
            'enable_database' => false,
            'enable_database_logging' => false,
            'allowed_deploy_commands' => '',
            'n8n_webhooks' => [],
        ]);
    }
}

// Initialize plugin
MCP_Bridge::get_instance();
