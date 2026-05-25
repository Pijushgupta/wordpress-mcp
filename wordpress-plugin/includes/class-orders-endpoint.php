<?php
/**
 * Orders Endpoint - Query WooCommerce orders
 */

defined('ABSPATH') || exit;

class MCP_Bridge_Orders_Endpoint extends MCP_Bridge_REST_Controller {

    protected string $rest_base = 'orders';

    public function register_routes(): void {
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_orders'],
                'permission_callback' => [$this, 'check_orders_permission'],
                'args' => $this->get_query_args(),
            ],
        ]);
    }

    public function check_orders_permission(WP_REST_Request $request): bool|WP_Error {
        $base_check = $this->check_permission($request);
        if (is_wp_error($base_check)) {
            return $base_check;
        }

        if (!class_exists('WooCommerce')) {
            return $this->error_response('woocommerce_not_active', 'WooCommerce is not active', 400);
        }

        if (!current_user_can('edit_shop_orders')) {
            return $this->error_response('forbidden', 'Cannot view orders', 403);
        }

        return true;
    }

    private function get_query_args(): array {
        return [
            'status' => [
                'type' => 'string',
                'enum' => ['pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed', 'any'],
            ],
            'date_from' => [
                'type' => 'string',
                'format' => 'date',
            ],
            'date_to' => [
                'type' => 'string',
                'format' => 'date',
            ],
            'customer_id' => [
                'type' => 'integer',
            ],
            'customer_email' => [
                'type' => 'string',
                'format' => 'email',
            ],
            'product_id' => [
                'type' => 'integer',
            ],
            'min_total' => [
                'type' => 'number',
            ],
            'max_total' => [
                'type' => 'number',
            ],
            'page' => [
                'type' => 'integer',
                'default' => 1,
                'minimum' => 1,
            ],
            'per_page' => [
                'type' => 'integer',
                'default' => 20,
                'minimum' => 1,
                'maximum' => 100,
            ],
            'orderby' => [
                'type' => 'string',
                'default' => 'date',
                'enum' => ['date', 'id', 'total'],
            ],
            'order' => [
                'type' => 'string',
                'default' => 'desc',
                'enum' => ['asc', 'desc'],
            ],
        ];
    }

    public function get_orders(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $args = [
            'limit' => min($request->get_param('per_page') ?: 20, 100),
            'page' => $request->get_param('page') ?: 1,
            'orderby' => $request->get_param('orderby') ?: 'date',
            'order' => strtoupper($request->get_param('order') ?: 'DESC'),
            'paginate' => true,
        ];

        // Status filter
        $status = $request->get_param('status');
        if ($status && $status !== 'any') {
            $args['status'] = 'wc-' . $status;
        }

        // Date filters
        $date_from = $request->get_param('date_from');
        $date_to = $request->get_param('date_to');
        if ($date_from || $date_to) {
            $args['date_created'] = [];
            if ($date_from) {
                $args['date_created']['after'] = $date_from . ' 00:00:00';
            }
            if ($date_to) {
                $args['date_created']['before'] = $date_to . ' 23:59:59';
            }
        }

        // Customer filters
        $customer_id = $request->get_param('customer_id');
        if ($customer_id) {
            $args['customer_id'] = absint($customer_id);
        }

        $customer_email = $request->get_param('customer_email');
        if ($customer_email) {
            $args['billing_email'] = sanitize_email($customer_email);
        }

        // Query orders
        $results = wc_get_orders($args);

        // Apply post-query filters
        $product_id = $request->get_param('product_id');
        $min_total = $request->get_param('min_total');
        $max_total = $request->get_param('max_total');

        $orders = [];
        foreach ($results->orders as $order) {
            // Product filter
            if ($product_id) {
                $has_product = false;
                foreach ($order->get_items() as $item) {
                    if ($item->get_product_id() == $product_id || $item->get_variation_id() == $product_id) {
                        $has_product = true;
                        break;
                    }
                }
                if (!$has_product) continue;
            }

            // Total filters
            $total = (float) $order->get_total();
            if ($min_total !== null && $total < $min_total) continue;
            if ($max_total !== null && $total > $max_total) continue;

            $orders[] = $this->format_order($order);
        }

        return $this->success_response([
            'orders' => $orders,
            'total' => $results->total,
            'pages' => $results->max_num_pages,
        ]);
    }

    private function format_order(WC_Order $order): array {
        $line_items = [];
        foreach ($order->get_items() as $item) {
            $line_items[] = [
                'id' => $item->get_id(),
                'name' => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'total' => $item->get_total(),
            ];
        }

        return [
            'id' => $order->get_id(),
            'status' => $order->get_status(),
            'date_created' => $order->get_date_created()?->format('c'),
            'total' => $order->get_total(),
            'currency' => $order->get_currency(),
            'customer_id' => $order->get_customer_id(),
            'billing' => [
                'first_name' => $order->get_billing_first_name(),
                'last_name' => $order->get_billing_last_name(),
                'email' => $order->get_billing_email(),
            ],
            'line_items' => $line_items,
        ];
    }
}
