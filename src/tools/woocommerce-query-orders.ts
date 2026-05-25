import { z } from 'zod';
import { wpClient } from '../utils/wordpress-client.js';

export const woocommerceQueryOrdersSchema = z.object({
  status: z.enum([
    'pending', 'processing', 'on-hold', 'completed',
    'cancelled', 'refunded', 'failed', 'any'
  ]).optional().describe('Order status filter'),
  date_from: z.string().optional().describe('Start date (YYYY-MM-DD)'),
  date_to: z.string().optional().describe('End date (YYYY-MM-DD)'),
  customer_id: z.number().optional().describe('Customer ID'),
  customer_email: z.string().optional().describe('Customer email'),
  product_id: z.number().optional().describe('Filter by product ID'),
  min_total: z.number().optional().describe('Minimum order total'),
  max_total: z.number().optional().describe('Maximum order total'),
  page: z.number().default(1).describe('Page number'),
  per_page: z.number().default(20).describe('Items per page (max 100)'),
  orderby: z.enum(['date', 'id', 'total']).default('date').describe('Order by field'),
  order: z.enum(['asc', 'desc']).default('desc').describe('Sort order'),
});

export type WooCommerceQueryOrdersInput = z.infer<typeof woocommerceQueryOrdersSchema>;

interface OrderLineItem {
  id: number;
  name: string;
  quantity: number;
  total: string;
}

interface Order {
  id: number;
  status: string;
  date_created: string;
  total: string;
  currency: string;
  customer_id: number;
  billing: {
    first_name: string;
    last_name: string;
    email: string;
  };
  line_items: OrderLineItem[];
}

interface OrdersResponse {
  orders: Order[];
  total: number;
  pages: number;
}

export async function woocommerceQueryOrders(input: WooCommerceQueryOrdersInput): Promise<string> {
  const response = await wpClient.get<OrdersResponse>('/orders', {
    status: input.status,
    date_from: input.date_from,
    date_to: input.date_to,
    customer_id: input.customer_id,
    customer_email: input.customer_email,
    product_id: input.product_id,
    min_total: input.min_total,
    max_total: input.max_total,
    page: input.page,
    per_page: Math.min(input.per_page, 100),
    orderby: input.orderby,
    order: input.order,
  });

  if (!response.success || !response.data) {
    return JSON.stringify({ error: response.error || 'Failed to query orders' });
  }

  return JSON.stringify({
    success: true,
    orders: response.data.orders,
    pagination: {
      total: response.data.total,
      pages: response.data.pages,
      current_page: input.page,
      per_page: input.per_page,
    },
  });
}

export const woocommerceQueryOrdersTool = {
  name: 'woocommerce_query_orders',
  description: 'Query WooCommerce orders with filtering, sorting, and pagination',
  inputSchema: {
    type: 'object' as const,
    properties: {
      status: {
        type: 'string',
        enum: ['pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed', 'any'],
        description: 'Order status filter',
      },
      date_from: { type: 'string', description: 'Start date (YYYY-MM-DD)' },
      date_to: { type: 'string', description: 'End date (YYYY-MM-DD)' },
      customer_id: { type: 'number', description: 'Customer ID' },
      customer_email: { type: 'string', description: 'Customer email' },
      product_id: { type: 'number', description: 'Filter by product ID' },
      min_total: { type: 'number', description: 'Minimum order total' },
      max_total: { type: 'number', description: 'Maximum order total' },
      page: { type: 'number', default: 1, description: 'Page number' },
      per_page: { type: 'number', default: 20, description: 'Items per page (max 100)' },
      orderby: { type: 'string', enum: ['date', 'id', 'total'], default: 'date', description: 'Order by field' },
      order: { type: 'string', enum: ['asc', 'desc'], default: 'desc', description: 'Sort order' },
    },
    required: [],
  },
};
