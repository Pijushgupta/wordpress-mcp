<?php
/**
 * Invoice Endpoint - Generate invoices from WooCommerce orders
 */

defined('ABSPATH') || exit;

class MCP_Bridge_Invoice_Endpoint extends MCP_Bridge_REST_Controller {

    protected string $rest_base = 'invoices';

    public function register_routes(): void {
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'generate_invoice'],
                'permission_callback' => [$this, 'check_invoice_permission'],
                'args' => [
                    'order_id' => [
                        'required' => true,
                        'type' => 'integer',
                    ],
                    'format' => [
                        'type' => 'string',
                        'default' => 'pdf',
                        'enum' => ['pdf', 'html'],
                    ],
                    'template' => [
                        'type' => 'string',
                    ],
                    'include_notes' => [
                        'type' => 'boolean',
                        'default' => false,
                    ],
                    'regenerate' => [
                        'type' => 'boolean',
                        'default' => false,
                    ],
                ],
            ],
        ]);
    }

    public function check_invoice_permission(WP_REST_Request $request): bool|WP_Error {
        $base_check = $this->check_permission($request);
        if (is_wp_error($base_check)) {
            return $base_check;
        }

        if (!class_exists('WooCommerce')) {
            return $this->error_response('woocommerce_not_active', 'WooCommerce is not active', 400);
        }

        if (!current_user_can('edit_shop_orders')) {
            return $this->error_response('forbidden', 'Cannot generate invoices', 403);
        }

        return true;
    }

    public function generate_invoice(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $order_id = absint($request->get_param('order_id'));
        $format = $request->get_param('format') ?: 'pdf';
        $template = $request->get_param('template');
        $include_notes = (bool) $request->get_param('include_notes');
        $regenerate = (bool) $request->get_param('regenerate');

        $order = wc_get_order($order_id);
        if (!$order) {
            return $this->error_response('order_not_found', 'Order not found', 404);
        }

        // Check for existing invoice plugin support
        $invoice_data = $this->try_external_invoice_plugins($order, $format, $template, $regenerate);

        if ($invoice_data) {
            return $this->success_response($invoice_data);
        }

        // Fallback: Generate basic HTML invoice
        if ($format === 'html') {
            return $this->generate_html_invoice($order, $include_notes);
        }

        // PDF requires an invoice plugin
        return $this->error_response(
            'pdf_plugin_required',
            'PDF generation requires a compatible invoice plugin (WooCommerce PDF Invoices, WPO WCPDF, etc.)',
            400
        );
    }

    private function try_external_invoice_plugins(WC_Order $order, string $format, ?string $template, bool $regenerate): ?array {
        // Try WooCommerce PDF Invoices & Packing Slips (WPO WCPDF)
        if (class_exists('WPO_WCPDF')) {
            return $this->generate_wcpdf_invoice($order, $format, $regenerate);
        }

        // Try WooCommerce PDF Invoices (Bas Elbers)
        if (function_exists('bewpi_get_invoice')) {
            return $this->generate_bewpi_invoice($order, $format, $regenerate);
        }

        return null;
    }

    private function generate_wcpdf_invoice(WC_Order $order, string $format, bool $regenerate): ?array {
        try {
            $document = wcpdf_get_document('invoice', $order);

            if (!$document) {
                return null;
            }

            if ($format === 'pdf') {
                $pdf_path = $document->get_pdf_path();

                if (!$pdf_path || $regenerate) {
                    // Generate PDF
                    $pdf = $document->get_pdf();
                    $upload_dir = wp_upload_dir();
                    $pdf_dir = $upload_dir['basedir'] . '/mcp-bridge-invoices/';

                    if (!file_exists($pdf_dir)) {
                        wp_mkdir_p($pdf_dir);
                    }

                    $invoice_number = $document->get_number()->get_formatted();
                    $filename = sanitize_file_name("invoice-{$order->get_id()}-{$invoice_number}.pdf");
                    $pdf_path = $pdf_dir . $filename;

                    file_put_contents($pdf_path, $pdf);
                }

                return [
                    'invoice_id' => $order->get_id(),
                    'order_id' => $order->get_id(),
                    'invoice_number' => $document->get_number()->get_formatted(),
                    'download_url' => str_replace(ABSPATH, home_url('/'), $pdf_path),
                    'format' => 'pdf',
                    'created_at' => current_time('c'),
                ];
            }

            // HTML format
            return [
                'invoice_id' => $order->get_id(),
                'order_id' => $order->get_id(),
                'invoice_number' => $document->get_number()->get_formatted(),
                'download_url' => $document->get_link(),
                'format' => 'html',
                'created_at' => current_time('c'),
            ];

        } catch (Exception $e) {
            return null;
        }
    }

    private function generate_bewpi_invoice(WC_Order $order, string $format, bool $regenerate): ?array {
        // Basic support for WooCommerce PDF Invoices plugin
        $invoice = bewpi_get_invoice($order);

        if (!$invoice) {
            return null;
        }

        return [
            'invoice_id' => $invoice->get_id(),
            'order_id' => $order->get_id(),
            'invoice_number' => $invoice->get_formatted_number(),
            'download_url' => $invoice->get_download_url(),
            'format' => $format,
            'created_at' => current_time('c'),
        ];
    }

    private function generate_html_invoice(WC_Order $order, bool $include_notes): WP_REST_Response {
        ob_start();
        $this->render_html_invoice($order, $include_notes);
        $html = ob_get_clean();

        // Save to uploads
        $upload_dir = wp_upload_dir();
        $invoice_dir = $upload_dir['basedir'] . '/mcp-bridge-invoices/';

        if (!file_exists($invoice_dir)) {
            wp_mkdir_p($invoice_dir);
        }

        $invoice_number = $this->generate_invoice_number($order);
        $filename = sanitize_file_name("invoice-{$order->get_id()}-{$invoice_number}.html");
        $file_path = $invoice_dir . $filename;

        file_put_contents($file_path, $html);

        $download_url = $upload_dir['baseurl'] . '/mcp-bridge-invoices/' . $filename;

        return $this->success_response([
            'invoice_id' => $order->get_id(),
            'order_id' => $order->get_id(),
            'invoice_number' => $invoice_number,
            'download_url' => $download_url,
            'format' => 'html',
            'created_at' => current_time('c'),
        ]);
    }

    private function generate_invoice_number(WC_Order $order): string {
        $prefix = apply_filters('mcp_bridge_invoice_prefix', 'INV-');
        return $prefix . str_pad((string) $order->get_id(), 6, '0', STR_PAD_LEFT);
    }

    private function render_html_invoice(WC_Order $order, bool $include_notes): void {
        $blog_name = get_bloginfo('name');
        $invoice_number = $this->generate_invoice_number($order);
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Invoice <?php echo esc_html($invoice_number); ?></title>
            <style>
                body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; }
                .header { text-align: center; margin-bottom: 30px; }
                .invoice-details { display: flex; justify-content: space-between; margin-bottom: 30px; }
                .billing, .shipping { width: 45%; }
                table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #f4f4f4; }
                .totals { text-align: right; }
                .totals td { border: none; }
                .total-row { font-weight: bold; font-size: 1.2em; }
            </style>
        </head>
        <body>
            <div class="header">
                <h1><?php echo esc_html($blog_name); ?></h1>
                <h2>Invoice #<?php echo esc_html($invoice_number); ?></h2>
                <p>Date: <?php echo esc_html($order->get_date_created()->format('F j, Y')); ?></p>
            </div>

            <div class="invoice-details">
                <div class="billing">
                    <h3>Bill To:</h3>
                    <p>
                        <?php echo esc_html($order->get_formatted_billing_full_name()); ?><br>
                        <?php echo wp_kses_post($order->get_formatted_billing_address()); ?>
                    </p>
                    <p>Email: <?php echo esc_html($order->get_billing_email()); ?></p>
                </div>

                <?php if ($order->has_shipping_address()): ?>
                <div class="shipping">
                    <h3>Ship To:</h3>
                    <p><?php echo wp_kses_post($order->get_formatted_shipping_address()); ?></p>
                </div>
                <?php endif; ?>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Qty</th>
                        <th>Price</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($order->get_items() as $item): ?>
                    <tr>
                        <td><?php echo esc_html($item->get_name()); ?></td>
                        <td><?php echo esc_html($item->get_quantity()); ?></td>
                        <td><?php echo wc_price($item->get_total() / $item->get_quantity()); ?></td>
                        <td><?php echo wc_price($item->get_total()); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <table class="totals">
                <tr>
                    <td>Subtotal:</td>
                    <td><?php echo wc_price($order->get_subtotal()); ?></td>
                </tr>
                <?php if ($order->get_shipping_total() > 0): ?>
                <tr>
                    <td>Shipping:</td>
                    <td><?php echo wc_price($order->get_shipping_total()); ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($order->get_total_tax() > 0): ?>
                <tr>
                    <td>Tax:</td>
                    <td><?php echo wc_price($order->get_total_tax()); ?></td>
                </tr>
                <?php endif; ?>
                <tr class="total-row">
                    <td>Total:</td>
                    <td><?php echo wc_price($order->get_total()); ?></td>
                </tr>
            </table>

            <?php if ($include_notes && $order->get_customer_note()): ?>
            <div class="notes">
                <h3>Notes:</h3>
                <p><?php echo esc_html($order->get_customer_note()); ?></p>
            </div>
            <?php endif; ?>
        </body>
        </html>
        <?php
    }
}
