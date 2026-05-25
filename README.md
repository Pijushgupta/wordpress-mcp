# WordPress MCP Bridge

A Model Context Protocol (MCP) server that enables AI assistants like Claude to interact with WordPress and WooCommerce sites.

## What is MCP?

**Model Context Protocol (MCP)** is an open standard by Anthropic that allows AI assistants to connect to external tools and data sources. Think of it as a universal plugin system for AI.

**Without MCP:** You copy-paste data between your AI assistant and WordPress admin.

**With MCP:** Your AI assistant directly creates posts, queries orders, generates images, and more — all through natural conversation.

### How It Works

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                              YOUR COMPUTER                                   │
│  ┌─────────────────┐         stdio          ┌─────────────────┐            │
│  │   AI Assistant  │◄──────────────────────►│   MCP Server    │            │
│  │  (Claude Code,  │   (MCP Protocol)       │   (Node.js)     │            │
│  │   Cursor, etc.) │                        │                 │            │
│  └─────────────────┘                        └────────┬────────┘            │
└──────────────────────────────────────────────────────┼─────────────────────┘
                                                       │
                                                       │ HTTPS REST API
                                                       │
┌──────────────────────────────────────────────────────┼─────────────────────┐
│                         WORDPRESS SERVER             │                      │
│                        ┌─────────────────┐           │                      │
│                        │   MCP Bridge    │◄──────────┘                      │
│                        │   Plugin (PHP)  │                                  │
│                        └────────┬────────┘                                  │
│                                 │                                           │
│         ┌───────────────────────┼───────────────────────┐                  │
│         │                       │                       │                  │
│         ▼                       ▼                       ▼                  │
│  ┌─────────────┐        ┌─────────────┐        ┌─────────────┐            │
│  │  WordPress  │        │ WooCommerce │        │   Plugins   │            │
│  │   (Posts,   │        │  (Orders,   │        │ (CRM, n8n,  │            │
│  │   Pages)    │        │  Invoices)  │        │  AI Engine) │            │
│  └─────────────┘        └─────────────┘        └─────────────┘            │
└─────────────────────────────────────────────────────────────────────────────┘
```

**Flow:**
1. You ask Claude: "Create a blog post about AI trends"
2. Claude calls the `wordpress_create_post` MCP tool
3. MCP Server sends HTTPS request to your WordPress site
4. MCP Bridge plugin creates the post via WordPress API
5. Success response flows back to Claude
6. Claude confirms: "Created draft post 'AI Trends' (ID: 123)"

## Features

| Tool | Description |
|------|-------------|
| `wordpress_create_post` | Create/update posts, pages, custom post types |
| `woocommerce_query_orders` | Query orders with filters and pagination |
| `generate_invoice` | Generate PDF/HTML invoices from orders |
| `crm_search` | Search CRM contacts (FluentCRM, Jetpack CRM, WP ERP) |
| `n8n_trigger` | Trigger n8n workflows via webhook |
| `generate_image` | Generate AI images (requires AI plugin) |
| `deploy_code` | Execute deployment commands (requires shell_exec) |
| `read_logs` | Read WordPress and server logs |

### Filesystem Tools (Works on ALL Hosting)

| Tool | Description |
|------|-------------|
| `list_directory` | List files/folders with glob patterns |
| `read_file` | Read file contents |
| `write_file` | Write/update files with auto-backup |
| `create_zip` | Create zip backups |
| `extract_zip` | Extract zip archives |
| `delete_path` | Delete files or directories |

These use PHP-native functions (no shell_exec), so they work on shared hosting.

---

## Installation

### 1. Install WordPress Plugin

Copy `wordpress-plugin/` folder to your WordPress server:

```bash
# On your WordPress server
cd /path/to/wordpress/wp-content/plugins/
git clone <this-repo> mcp-bridge
# Or manually copy the wordpress-plugin folder as mcp-bridge
```

Activate plugin in WordPress admin → Plugins.

### 2. Generate API Key

1. Go to WordPress Admin → Settings → MCP Bridge
2. In "API Key" section, check **"Generate new API key"**
3. Select **API Key User** (determines permissions for API requests)
4. Click **Save Settings**
5. **Copy the generated key** (use the Copy button)

### 3. Install MCP Server

```bash
cd /path/to/wordpress-mcp
npm install
npm run build
```

### 4. Configure MCP Client

This MCP server works with any MCP-compatible AI client. Choose your client below.

#### Environment Variables

```json
"env": {
  "WORDPRESS_SITE_URL": "https://your-site.com",
  "WORDPRESS_API_KEY": "your-api-key-from-plugin"
}
```

---

#### Claude Code (CLI)

Add to `.mcp.json` in project root or `~/.claude.json` for global:

```json
{
  "mcpServers": {
    "wordpress": {
      "command": "node",
      "args": ["/path/to/mcp/dist/index.js"],
      "env": {
        "WORDPRESS_SITE_URL": "https://your-site.com",
        "WORDPRESS_API_KEY": "your-api-key"
      }
    }
  }
}
```

#### Claude Desktop

Add to `~/Library/Application Support/Claude/claude_desktop_config.json` (macOS) or `%APPDATA%\Claude\claude_desktop_config.json` (Windows):

```json
{
  "mcpServers": {
    "wordpress": {
      "command": "node",
      "args": ["/path/to/mcp/dist/index.js"],
      "env": {
        "WORDPRESS_SITE_URL": "https://your-site.com",
        "WORDPRESS_API_KEY": "your-api-key"
      }
    }
  }
}
```

#### Cursor IDE

Add to `.cursor/mcp.json` in project root:

```json
{
  "mcpServers": {
    "wordpress": {
      "command": "node",
      "args": ["/path/to/mcp/dist/index.js"],
      "env": {
        "WORDPRESS_SITE_URL": "https://your-site.com",
        "WORDPRESS_API_KEY": "your-api-key"
      }
    }
  }
}
```

#### Windsurf

Add to `~/.codeium/windsurf/mcp_config.json`:

```json
{
  "mcpServers": {
    "wordpress": {
      "command": "node",
      "args": ["/path/to/mcp/dist/index.js"],
      "env": {
        "WORDPRESS_SITE_URL": "https://your-site.com",
        "WORDPRESS_API_KEY": "your-api-key"
      }
    }
  }
}
```

#### Cline (VS Code Extension)

Add to VS Code settings or `.vscode/mcp.json`:

```json
{
  "mcpServers": {
    "wordpress": {
      "command": "node",
      "args": ["/path/to/mcp/dist/index.js"],
      "env": {
        "WORDPRESS_SITE_URL": "https://your-site.com",
        "WORDPRESS_API_KEY": "your-api-key"
      }
    }
  }
}
```

#### Continue.dev

Add to `~/.continue/config.json` under `experimental.modelContextProtocolServers`:

```json
{
  "experimental": {
    "modelContextProtocolServers": [
      {
        "transport": {
          "type": "stdio",
          "command": "node",
          "args": ["/path/to/mcp/dist/index.js"],
          "env": {
            "WORDPRESS_SITE_URL": "https://your-site.com",
            "WORDPRESS_API_KEY": "your-api-key"
          }
        }
      }
    ]
  }
}
```

#### Zed Editor

Add to `~/.config/zed/settings.json`:

```json
{
  "context_servers": {
    "wordpress": {
      "command": {
        "path": "node",
        "args": ["/path/to/mcp/dist/index.js"],
        "env": {
          "WORDPRESS_SITE_URL": "https://your-site.com",
          "WORDPRESS_API_KEY": "your-api-key"
        }
      }
    }
  }
}
```

### 5. Configure Plugin Settings (Optional)

Go to WordPress Admin → Settings → MCP Bridge:

- **Rate Limit**: Requests per minute (default: 100)
- **Enable Deploy**: Enable deployment endpoint (security risk)
- **Allowed Commands**: Whitelist for custom deploy commands
- **n8n Webhooks**: Configure webhook IDs and URLs (format: `webhook_id|https://n8n.example.com/webhook/xxx`, one per line)

---

## Tool Documentation

### wordpress_create_post

Creates or updates WordPress posts, pages, or custom post types.

#### How It Works

```
User Request                MCP Server              WordPress
     │                          │                       │
     │ "Create post about AI"   │                       │
     ├─────────────────────────►│                       │
     │                          │  POST /posts          │
     │                          ├──────────────────────►│
     │                          │                       │ wp_insert_post()
     │                          │                       │
     │                          │  {id: 123, link: ...} │
     │                          │◄──────────────────────┤
     │  "Created post ID 123"   │                       │
     │◄─────────────────────────┤                       │
```

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `title` | string | Yes | Post title |
| `content` | string | Yes | Post content (HTML supported) |
| `status` | string | No | `draft`, `publish`, `pending`, `private` (default: `draft`) |
| `type` | string | No | `post`, `page`, or custom post type (default: `post`) |
| `post_id` | number | No | Existing post ID to update |
| `excerpt` | string | No | Post excerpt/summary |
| `categories` | number[] | No | Array of category IDs |
| `tags` | number[] | No | Array of tag IDs |
| `featured_image` | number | No | Attachment ID for featured image |
| `meta` | object | No | Custom meta fields (keys must start with `mcp_` or `_mcp_`) |

#### Example Conversation

```
You: Create a blog post titled "10 AI Trends for 2024" with some placeholder
     content. Set it as draft and add it to category 5.

Claude: I'll create that post for you.
        [Calls wordpress_create_post with title, content, status="draft", categories=[5]]

        Created draft post "10 AI Trends for 2024" (ID: 456)
        Preview: https://your-site.com/?p=456
```

---

### woocommerce_query_orders

Query WooCommerce orders with powerful filtering options.

#### How It Works

```
User Request                    MCP Server              WordPress/WooCommerce
     │                              │                           │
     │ "Show orders from January"   │                           │
     ├─────────────────────────────►│                           │
     │                              │  GET /orders?date_from=...│
     │                              ├──────────────────────────►│
     │                              │                           │ wc_get_orders()
     │                              │                           │
     │                              │  [{id, total, items...}]  │
     │                              │◄──────────────────────────┤
     │  "Found 23 orders..."        │                           │
     │◄─────────────────────────────┤                           │
```

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `status` | string | No | `pending`, `processing`, `on-hold`, `completed`, `cancelled`, `refunded`, `failed`, `any` |
| `date_from` | string | No | Start date (YYYY-MM-DD) |
| `date_to` | string | No | End date (YYYY-MM-DD) |
| `customer_id` | number | No | Filter by customer ID |
| `customer_email` | string | No | Filter by customer email |
| `product_id` | number | No | Filter orders containing this product |
| `min_total` | number | No | Minimum order total |
| `max_total` | number | No | Maximum order total |
| `page` | number | No | Page number (default: 1) |
| `per_page` | number | No | Items per page, max 100 (default: 20) |
| `orderby` | string | No | `date`, `id`, `total` (default: `date`) |
| `order` | string | No | `asc`, `desc` (default: `desc`) |

#### Example Conversation

```
You: Find all completed orders over $100 from last month for john@example.com

Claude: I'll search for those orders.
        [Calls woocommerce_query_orders with status="completed", min_total=100,
         date_from="2024-01-01", date_to="2024-01-31", customer_email="john@example.com"]

        Found 3 orders:
        - #1001 - $150.00 - Jan 5, 2024 - 2 items
        - #1015 - $220.00 - Jan 12, 2024 - 1 item
        - #1042 - $180.00 - Jan 28, 2024 - 3 items
```

---

### generate_invoice

Generate PDF or HTML invoices from WooCommerce orders.

#### How It Works

```
User Request                    MCP Server              WordPress
     │                              │                       │
     │ "Generate invoice for #123"  │                       │
     ├─────────────────────────────►│                       │
     │                              │  POST /invoices       │
     │                              ├──────────────────────►│
     │                              │                       │ Check PDF plugins:
     │                              │                       │ 1. WPO WCPDF?
     │                              │                       │ 2. bewpi?
     │                              │                       │ 3. Fallback HTML
     │                              │                       │
     │                              │                       │ Generate & save to:
     │                              │                       │ /uploads/mcp-bridge-invoices/
     │                              │                       │
     │                              │  {invoice_id, url}    │
     │                              │◄──────────────────────┤
     │  "Invoice ready: [link]"     │                       │
     │◄─────────────────────────────┤                       │
```

#### Supported Invoice Plugins

The tool tries these in order:
1. **WooCommerce PDF Invoices & Packing Slips** (WPO WCPDF) - Most popular
2. **WooCommerce PDF Invoices** (bewpi) - By Bas Elbers
3. **Built-in HTML Generator** - Fallback if no plugin installed

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `order_id` | number | Yes | WooCommerce order ID |
| `format` | string | No | `pdf` or `html` (default: `pdf`) |
| `template` | string | No | Invoice template name |
| `include_notes` | boolean | No | Include order notes (default: false) |
| `regenerate` | boolean | No | Force regenerate even if exists (default: false) |

#### Example Conversation

```
You: Generate a PDF invoice for order 1234

Claude: I'll generate that invoice.
        [Calls generate_invoice with order_id=1234, format="pdf"]

        Invoice generated successfully:
        - Invoice #: INV-2024-001234
        - Order: #1234
        - Download: https://your-site.com/wp-content/uploads/mcp-bridge-invoices/invoice-1234.pdf
```

---

### crm_search

Search contacts across multiple CRM plugins.

#### Supported CRM Plugins

| Plugin | Detection | Search Method |
|--------|-----------|---------------|
| **FluentCRM** | `defined('FLUENTCRM')` | Eloquent ORM queries |
| **Jetpack CRM** | `class_exists('Jetpack_CRM')` | `zeroBS_getContacts()` |
| **WP ERP** | `class_exists('WeDevs_ERP')` | `erp_get_peoples()` |

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `query` | string | No | Search name or company |
| `email` | string | No | Filter by email |
| `status` | string | No | Contact status (active, lead, customer, etc.) |
| `tags` | string[] | No | Filter by tags |
| `custom_fields` | object | No | Filter by custom field values |
| `limit` | number | No | Max results, max 100 (default: 20) |
| `offset` | number | No | Result offset for pagination (default: 0) |
| `orderby` | string | No | `name`, `email`, `created`, `updated` (default: `name`) |
| `order` | string | No | `asc`, `desc` (default: `asc`) |

#### Example Conversation

```
You: Find all VIP customers in FluentCRM

Claude: I'll search your CRM.
        [Calls crm_search with tags=["vip"], status="customer"]

        Found 12 VIP customers:
        1. John Smith (john@example.com) - Customer since Jan 2023
        2. Jane Doe (jane@example.com) - Customer since Mar 2023
        ...
```

---

### n8n_trigger

Trigger n8n automation workflows via webhooks.

#### How It Works

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           n8n INTEGRATION FLOW                              │
└─────────────────────────────────────────────────────────────────────────────┘

Step 1: Configure webhook in WordPress
┌─────────────────────────────────────┐
│ WordPress Admin → MCP Bridge        │
│                                     │
│ n8n Webhooks:                       │
│ ┌─────────────────────────────────┐ │
│ │ send-email|https://n8n.io/abc   │ │
│ │ process-order|https://n8n.io/xy │ │
│ └─────────────────────────────────┘ │
└─────────────────────────────────────┘

Step 2: Trigger via AI assistant
┌──────────┐     ┌──────────┐     ┌──────────┐     ┌──────────┐
│   You    │────►│  Claude  │────►│   MCP    │────►│WordPress │
│          │     │          │     │  Server  │     │  Plugin  │
└──────────┘     └──────────┘     └──────────┘     └────┬─────┘
                                                        │
                                                        │ POST webhook URL
                                                        ▼
                                                  ┌──────────┐
                                                  │   n8n    │
                                                  │ Workflow │
                                                  └────┬─────┘
                                                       │
                                        ┌──────────────┼──────────────┐
                                        ▼              ▼              ▼
                                   ┌────────┐    ┌────────┐    ┌────────┐
                                   │ Email  │    │ Slack  │    │Database│
                                   └────────┘    └────────┘    └────────┘
```

#### Setting Up n8n Webhooks

1. **In n8n**: Create a workflow with a Webhook trigger node
   - Copy the webhook URL (e.g., `https://your-n8n.com/webhook/abc123`)

2. **In WordPress**: Go to Settings → MCP Bridge → n8n Integration
   - Add line: `webhook-id|https://your-n8n.com/webhook/abc123`
   - Save settings

3. **In conversation**: Ask Claude to trigger the webhook

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `webhook_id` | string | Yes | Webhook ID configured in WordPress |
| `payload` | object | No | Data to send to n8n workflow |
| `wait_for_response` | boolean | No | Wait for workflow completion (default: false) |
| `timeout` | number | No | Timeout in seconds if waiting, max 120 (default: 30) |

#### Auto-Added Metadata

Every webhook call automatically includes:
```json
{
  "your_payload": "...",
  "_mcp_bridge": {
    "triggered_at": "2024-01-15T10:30:00Z",
    "triggered_by": 1,
    "site_url": "https://your-site.com"
  }
}
```

#### Example Conversation

```
You: Trigger the send-welcome-email workflow for new customer sarah@example.com

Claude: I'll trigger that n8n workflow.
        [Calls n8n_trigger with webhook_id="send-welcome-email",
         payload={"email": "sarah@example.com", "name": "Sarah"}]

        Workflow triggered successfully.
        Execution ID: exec_abc123
```

#### Use Cases

- **Send automated emails** when certain conditions are met
- **Sync data** to external services (CRM, spreadsheets, etc.)
- **Process orders** through custom fulfillment workflows
- **Generate reports** and send to Slack/Discord
- **Backup data** to cloud storage

---

### generate_image

Generate AI images and upload to WordPress media library.

#### How It Works

```
┌──────────┐     ┌──────────┐     ┌──────────┐     ┌──────────┐     ┌──────────┐
│   You    │────►│  Claude  │────►│   MCP    │────►│WordPress │────►│ OpenAI/  │
│          │     │          │     │  Server  │     │  Server  │     │AI Engine │
└──────────┘     └──────────┘     └──────────┘     └────┬─────┘     └────┬─────┘
                                                        │                 │
                                                        │◄────────────────┘
                                                        │  Image URL returned
                                                        │
                                                        ▼
                                                  ┌───────────┐
                                                  │ Download  │
                                                  │ & Save to │
                                                  │  Media    │
                                                  │  Library  │
                                                  └───────────┘
```

**Step-by-step:**
1. You ask Claude: "Generate a hero image for my blog post"
2. Claude calls `generate_image` MCP tool
3. MCP Server sends POST request to WordPress
4. **WordPress calls OpenAI API** (API key stored on WordPress server)
5. OpenAI returns generated image URL
6. WordPress downloads image and saves to Media Library
7. Returns attachment ID and URL to Claude

**Important:** The API key for image generation is stored on your **WordPress server**, not locally. This keeps your API key secure on your server.

#### Setting Up Image Generation

**Option 1: AI Engine Plugin (Recommended)**

1. Install "AI Engine" plugin by Meow Apps from WordPress plugins
2. Go to **Meow → AI Engine → Settings → OpenAI**
3. Enter your OpenAI API key
4. Save settings

The MCP Bridge will automatically detect and use AI Engine.

**Option 2: Direct OpenAI API Key**

If you don't want AI Engine plugin, add the API key directly:

```bash
# Via WP-CLI
wp option update mcp_bridge_openai_api_key "sk-your-openai-key-here"
```

Or in your theme's `functions.php` (run once):
```php
add_action('init', function() {
    if (!get_option('mcp_bridge_openai_api_key')) {
        update_option('mcp_bridge_openai_api_key', 'sk-your-openai-key-here');
    }
});
```

#### Provider Detection Order

| Priority | Provider | Detection |
|----------|----------|-----------|
| 1 | AI Engine (Meow Apps) | `class_exists('Meow_MWAI_Core')` |
| 2 | Direct OpenAI | `get_option('mcp_bridge_openai_api_key')` |

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `prompt` | string | Yes | Image generation prompt |
| `negative_prompt` | string | No | What to avoid in the image |
| `size` | string | No | `256x256`, `512x512`, `1024x1024`, `1792x1024`, `1024x1792` (default: `1024x1024`) |
| `style` | string | No | Style preset (vivid, natural, etc.) |
| `provider` | string | No | `openai`, `stability`, `midjourney`, `auto` (default: `auto`) |
| `save_to_media` | boolean | No | Save to WordPress media library (default: true) |
| `filename` | string | No | Custom filename (without extension) |

#### Example Conversation

```
You: Generate a hero image for my AI trends blog post. Make it futuristic
     with blue tones, 1792x1024 for a wide banner.

Claude: I'll generate that image for you.
        [Calls generate_image with prompt="Futuristic AI technology landscape,
         blue tones, digital neural networks, modern tech aesthetic",
         size="1792x1024", save_to_media=true]

        Image generated and uploaded to media library:
        - Attachment ID: 789
        - URL: https://your-site.com/wp-content/uploads/2024/01/ai-hero-image.png
        - Size: 1792x1024

        You can now use this as your featured image.
```

---

### deploy_code

Execute deployment commands on your WordPress server.

#### Security Warning

This tool can execute shell commands on your server. It is:
- **Disabled by default**
- **Requires admin permission**
- **Only runs whitelisted commands**

#### Hosting Compatibility

This tool requires PHP shell functions (`exec`, `shell_exec`) which are **disabled on most shared hosting**.

| Hosting Type | Works? | Notes |
|--------------|--------|-------|
| VPS / Dedicated | Yes | Full control over PHP config |
| Self-hosted | Yes | You control the server |
| Docker | Yes | Shell functions enabled by default |
| Shared hosting (GoDaddy, Bluehost, etc.) | No | Shell functions disabled |
| Managed WordPress (WP Engine, Kinsta, Flywheel) | No | Restricted for security |

If `deploy_code` returns empty output or errors, check if `shell_exec` is in PHP's `disable_functions`.

#### How It Works

```
User Request                    MCP Server              WordPress Server
     │                              │                       │
     │ "Deploy latest code"         │                       │
     ├─────────────────────────────►│                       │
     │                              │  POST /deploy         │
     │                              ├──────────────────────►│
     │                              │                       │
     │                              │           ┌───────────┴───────────┐
     │                              │           │ 1. Check deploy       │
     │                              │           │    enabled?           │
     │                              │           │ 2. Check admin        │
     │                              │           │    permission?        │
     │                              │           │ 3. Validate command   │
     │                              │           │    in whitelist?      │
     │                              │           └───────────┬───────────┘
     │                              │                       │
     │                              │                       │ Optional: Enable
     │                              │                       │ maintenance mode
     │                              │                       │
     │                              │                       │ Execute command:
     │                              │                       │ - git pull
     │                              │                       │ - composer install
     │                              │                       │ - npm ci && npm run build
     │                              │                       │
     │                              │                       │ Clear cache:
     │                              │                       │ WP Super Cache / W3TC /
     │                              │                       │ WP Rocket / LiteSpeed
     │                              │                       │
     │                              │  {exit_code, output}  │
     │                              │◄──────────────────────┤
     │  "Deploy complete"           │                       │
     │◄─────────────────────────────┤                       │
```

#### Safe Actions (Pre-defined)

| Action | Command Executed |
|--------|------------------|
| `git_pull` | `git pull origin [branch]` |
| `composer_install` | `composer install --no-dev --optimize-autoloader` |
| `npm_install` | `npm ci` |
| `npm_build` | `npm run build` |
| `custom` | Must be in whitelist |

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | string | Yes | `git_pull`, `composer_install`, `npm_install`, `npm_build`, `custom` |
| `path` | string | No | Relative path from WordPress root |
| `branch` | string | No | Git branch to pull (for git_pull action) |
| `custom_command` | string | No | Custom command (requires custom action, must be whitelisted) |
| `maintenance_mode` | boolean | No | Enable maintenance mode during deploy (default: false) |
| `clear_cache` | boolean | No | Clear WordPress cache after deploy (default: true) |

#### Example Conversation

```
You: Pull the latest code from the staging branch and rebuild assets

Claude: I'll deploy those changes.
        [Calls deploy_code with action="git_pull", branch="staging"]
        [Calls deploy_code with action="npm_install"]
        [Calls deploy_code with action="npm_build"]

        Deployment complete:
        1. Git pull (staging): Success - 3 files changed
        2. npm ci: Success - 234 packages installed
        3. npm run build: Success - Built in 12.3s

        Cache cleared automatically.
```

---

### read_logs

Read WordPress debug logs, PHP error logs, and server access logs.

#### Log Types

| Type | Location | Description |
|------|----------|-------------|
| `debug` | `wp-content/debug.log` | WordPress debug log |
| `error` | System PHP error log | PHP errors |
| `access` | Apache/Nginx access log | HTTP request logs |
| `php` | `ini_get('error_log')` | PHP error log from php.ini |
| `custom` | User-specified | Any log file (with path validation) |

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `log_type` | string | No | `debug`, `error`, `access`, `php`, `custom` (default: `debug`) |
| `custom_path` | string | No | Custom log file path (requires custom log_type) |
| `lines` | number | No | Number of lines to return, max 1000 (default: 100) |
| `filter` | string | No | Filter pattern (regex supported) |
| `severity` | string | No | Minimum severity: `all`, `error`, `warning`, `notice`, `info` (default: `all`) |
| `since` | string | No | Filter entries since datetime (ISO 8601) |

#### Example Conversation

```
You: Show me the last 50 PHP errors from today

Claude: I'll check the debug log.
        [Calls read_logs with log_type="debug", lines=50,
         severity="error", since="2024-01-15T00:00:00Z"]

        Found 8 errors today:

        [10:23:15] ERROR: Undefined variable $user in /themes/starter/header.php:45
        [10:45:02] ERROR: Call to undefined function get_custom_field() in /plugins/custom/main.php:123
        [11:02:33] ERROR: MySQL connection timeout in wp-includes/wp-db.php:1234
        ...
```

---

## Filesystem Tools

These tools use PHP-native functions (`glob`, `file_get_contents`, `ZipArchive`) instead of shell commands. They work on **all hosting** including shared hosting where `shell_exec` is disabled.

### Enabling Filesystem Tools

1. Go to WordPress Admin → Settings → MCP Bridge
2. Check **"Enable Filesystem Operations"**
3. Save Settings

### list_directory

List files and directories with glob pattern support.

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `path` | string | Yes | Relative path from WordPress root |
| `pattern` | string | No | Glob pattern (default: `*`) |
| `recursive` | boolean | No | Search recursively (default: false) |

#### Example

```
You: List all PHP files in the starter theme

Claude: [Calls list_directory with path="wp-content/themes/starter", pattern="*.php"]

        Found 12 files:
        - functions.php (4.2 KB)
        - header.php (1.8 KB)
        - footer.php (0.9 KB)
        - index.php (2.1 KB)
        ...
```

### read_file

Read contents of a file from WordPress server.

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `path` | string | Yes | Relative path from WordPress root |
| `lines` | number | No | Limit to first N lines (0 = all) |

#### Security Restrictions

- Maximum file size: 5 MB
- Blocked files: `wp-config.php`, `.htaccess`, `.env`
- Path must be within WordPress directories

#### Example

```
You: Show me the functions.php file from the starter theme

Claude: [Calls read_file with path="wp-content/themes/starter/functions.php"]

        File: functions.php (4,231 bytes, 142 lines)

        <?php
        /**
         * Starter Theme functions
         */
        ...
```

### write_file

Write content to a file. Automatically creates backup before overwriting.

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `path` | string | Yes | Relative path from WordPress root |
| `content` | string | Yes | File content to write |
| `backup` | boolean | No | Create backup before overwriting (default: true) |

#### Example

```
You: Add a new function to handle custom post types in functions.php

Claude: [Reads current file first]
        [Calls write_file with updated content]

        File updated successfully.
        - Bytes written: 4,523
        - Backup created: functions.php.bak.20240115103045
```

### create_zip

Create a zip backup of a file or directory.

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `path` | string | Yes | Directory or file to zip |
| `filename` | string | No | Output zip filename |

#### Example

```
You: Create a backup of the starter theme before making changes

Claude: [Calls create_zip with path="wp-content/themes/starter"]

        Zip created successfully:
        - Path: wp-content/uploads/mcp-bridge-backups/starter-20240115103215.zip
        - Size: 1.2 MB
        - Download: https://your-site.com/wp-content/uploads/mcp-bridge-backups/starter-20240115103215.zip
```

### extract_zip

Extract a zip archive to a destination directory.

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `zip_path` | string | Yes | Path to zip file |
| `destination` | string | Yes | Extraction destination |
| `overwrite` | boolean | No | Overwrite existing files (default: false) |

#### Example

```
You: Extract the theme backup to a new folder called starter-old

Claude: [Calls extract_zip with destination="wp-content/themes/starter-old"]

        Extracted successfully:
        - Destination: wp-content/themes/starter-old
        - Files extracted: 24
```

### delete_path

Delete a file or directory.

#### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `path` | string | Yes | Path to delete |
| `recursive` | boolean | No | Delete directory recursively (default: false) |

#### Example

```
You: Delete the old theme backup folder

Claude: [Calls delete_path with path="wp-content/themes/starter-old", recursive=true]

        Deleted successfully:
        - Path: wp-content/themes/starter-old
        - Type: directory
```

### Common Workflows

#### 1. Safe Theme Editing

```
You: I need to modify the header.php in my theme. Create a backup first.

Claude: 1. [Calls create_zip with path="wp-content/themes/starter"]
           Backup created: starter-20240115.zip

        2. [Calls read_file with path="wp-content/themes/starter/header.php"]
           Reading current file...

        3. [Calls write_file with updated content, backup=true]
           File updated. Backup: header.php.bak.20240115

        Changes complete. You have 2 backups:
        - Full theme: starter-20240115.zip
        - Original file: header.php.bak.20240115
```

#### 2. Plugin Inspection

```
You: Check what files are in the contact-form plugin and show me the main file

Claude: 1. [Calls list_directory with path="wp-content/plugins/contact-form"]

           Found 8 files:
           - contact-form.php (main)
           - includes/form-handler.php
           - includes/validation.php
           - assets/style.css
           ...

        2. [Calls read_file with path="wp-content/plugins/contact-form/contact-form.php"]

           Plugin: Contact Form v2.1.0
           Author: Developer Name
           ...
```

#### 3. Deploy Theme from Zip

```
You: I have a new theme zip at uploads/starter-v2.zip. Deploy it.

Claude: 1. [Calls create_zip with path="wp-content/themes/starter"]
           Backed up current theme first.

        2. [Calls extract_zip with zip_path="wp-content/uploads/starter-v2.zip",
            destination="wp-content/themes/starter", overwrite=true]

           Deployed successfully:
           - 32 files extracted
           - Old theme backed up to: starter-20240115.zip
```

#### 4. Clean Up Old Backups

```
You: Delete all backup zips older than this month

Claude: 1. [Calls list_directory with path="wp-content/uploads/mcp-bridge-backups"]

           Found 5 backups:
           - starter-20231201.zip (Dec)
           - starter-20231215.zip (Dec)
           - starter-20240101.zip (Jan) ← Keep
           - starter-20240115.zip (Jan) ← Keep

        2. [Calls delete_path for each December backup]

           Deleted 2 old backups, kept 2 recent ones.
```

### Filesystem vs deploy_code

| Feature | Filesystem Tools | deploy_code |
|---------|-----------------|-------------|
| Shared hosting | ✅ Works | ❌ Blocked |
| Git operations | ❌ No | ✅ Yes |
| File read/write | ✅ Yes | ❌ No |
| Zip backups | ✅ Yes | ❌ No |
| npm/composer | ❌ No | ✅ Yes |
| Directory listing | ✅ Yes | Limited |

**Use Filesystem when:**
- On shared hosting
- Reading/editing theme or plugin files
- Creating backups before changes
- Deploying from zip files

**Use deploy_code when:**
- On VPS/dedicated server
- Need git pull, composer, npm
- CI/CD style deployments

### Filesystem Security

- **Admin only**: Requires `manage_options` capability
- **Path validation**: Prevents directory traversal (`../`)
- **Blocked files**: `wp-config.php`, `.htaccess`, `.env` cannot be read/written
- **Allowed directories**: Only ABSPATH, wp-content, themes, plugins
- **Auto-backup**: Write operations create `.bak` files by default

---

## Security Considerations

1. **HTTPS Required**: Always use HTTPS for WordPress site
2. **API Key Security**: Store API key securely, rotate if compromised
3. **Minimal Permissions**: Create a dedicated user with only required capabilities
4. **Rate Limiting**: Enabled by default (100/minute)
5. **Deploy Endpoint**: Disabled by default, enable with caution
6. **Command Whitelist**: Only whitelisted commands can run via deploy

### Permission Matrix

| Tool | Required Capability | Notes |
|------|---------------------|-------|
| `wordpress_create_post` | `edit_posts` | Or `edit_pages` for pages |
| `woocommerce_query_orders` | `edit_shop_orders` | WooCommerce required |
| `generate_invoice` | `edit_shop_orders` | WooCommerce required |
| `crm_search` | `manage_options` or `fluentcrm_manage_contacts` | CRM plugin required |
| `n8n_trigger` | `manage_options` | Admin only |
| `generate_image` | `upload_files` | AI Engine or OpenAI key required |
| `deploy_code` | `manage_options` | Admin only, must enable in settings |
| `read_logs` | `manage_options` | Admin only |
| `list_directory` | `manage_options` | Admin only, must enable filesystem |
| `read_file` | `manage_options` | Admin only, must enable filesystem |
| `write_file` | `manage_options` | Admin only, must enable filesystem |
| `create_zip` | `manage_options` | Admin only, must enable filesystem |
| `extract_zip` | `manage_options` | Admin only, must enable filesystem |
| `delete_path` | `manage_options` | Admin only, must enable filesystem |

### Error Codes Reference

| Error Code | HTTP Status | Description |
|------------|-------------|-------------|
| `rest_not_logged_in` | 401 | Authentication required |
| `invalid_api_key` | 401 | API key is incorrect |
| `api_key_not_configured` | 500 | API key not set in plugin settings |
| `rest_forbidden` | 403 | User lacks required capability |
| `rate_limit_exceeded` | 429 | Too many requests, wait 1 minute |
| `woocommerce_not_active` | 400 | WooCommerce plugin not installed |
| `no_crm_plugin` | 400 | No supported CRM plugin found |
| `order_not_found` | 404 | WooCommerce order doesn't exist |
| `deploy_disabled` | 403 | Deploy endpoint not enabled |
| `command_not_allowed` | 403 | Command not in whitelist |
| `webhook_not_configured` | 400 | n8n webhook ID not found |
| `no_image_provider` | 400 | No AI image plugin available |
| `filesystem_disabled` | 403 | Filesystem operations not enabled |
| `path_not_allowed` | 403 | Path is outside allowed directories |
| `file_blocked` | 403 | Access to sensitive file blocked |
| `file_too_large` | 400 | File exceeds 5MB limit |
| `zip_not_available` | 500 | ZipArchive extension not installed |

## Plugin Dependencies

### Required
- WordPress 6.0+
- PHP 8.0+

### Optional (for full functionality)
- WooCommerce 8.0+ (orders, invoices)
- FluentCRM / Jetpack CRM / WP ERP (CRM search)
- WooCommerce PDF Invoices & Packing Slips (PDF invoices)
- AI Engine by Meow Apps (image generation)

## Troubleshooting

### "Authentication required" error
- Verify API key is correct
- Check API key user has required capabilities
- Ensure HTTPS is enabled

### "WooCommerce is not active" error
- Install and activate WooCommerce plugin
- Ensure user has `edit_shop_orders` capability

### Rate limit exceeded
- Wait 1 minute or increase limit in settings
- Check for runaway automation
- Rate limit is per-user, resets every 60 seconds

### Deploy not working
- Enable deploy in plugin settings
- Add commands to whitelist
- Ensure PHP has shell_exec permissions

### Image generation fails
- Install AI Engine plugin OR
- Add `define('OPENAI_API_KEY', 'sk-...');` to wp-config.php
- Check API quota/billing

### n8n webhook not triggering
- Verify webhook URL is correct in settings
- Test webhook URL directly with curl
- Check n8n workflow is active

## Development

```bash
# Install dependencies
npm install

# Development mode (with hot reload)
npm run dev

# Build for production
npm run build

# Run tests
npm test
```

## License

MIT
