<?php
/**
 * CRM Endpoint - Search CRM contacts
 * Supports: FluentCRM, Jetpack CRM, WP ERP
 */

defined('ABSPATH') || exit;

class MCP_Bridge_CRM_Endpoint extends MCP_Bridge_REST_Controller {

    protected string $rest_base = 'crm/search';

    public function register_routes(): void {
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'search_contacts'],
                'permission_callback' => [$this, 'check_crm_permission'],
                'args' => [
                    'query' => ['type' => 'string'],
                    'email' => ['type' => 'string', 'format' => 'email'],
                    'tags' => ['type' => 'string'],
                    'status' => ['type' => 'string'],
                    'custom_fields' => ['type' => 'string'],
                    'limit' => ['type' => 'integer', 'default' => 20, 'maximum' => 100],
                    'offset' => ['type' => 'integer', 'default' => 0],
                    'orderby' => ['type' => 'string', 'default' => 'name', 'enum' => ['name', 'email', 'created', 'updated']],
                    'order' => ['type' => 'string', 'default' => 'asc', 'enum' => ['asc', 'desc']],
                ],
            ],
        ]);
    }

    public function check_crm_permission(WP_REST_Request $request): bool|WP_Error {
        $base_check = $this->check_permission($request);
        if (is_wp_error($base_check)) {
            return $base_check;
        }

        // Check for any CRM plugin
        if (!$this->detect_crm_plugin()) {
            return $this->error_response('no_crm_plugin', 'No supported CRM plugin detected (FluentCRM, Jetpack CRM, or WP ERP)', 400);
        }

        if (!current_user_can('manage_options') && !current_user_can('fluentcrm_manage_contacts')) {
            return $this->error_response('forbidden', 'Cannot access CRM data', 403);
        }

        return true;
    }

    private function detect_crm_plugin(): ?string {
        if (defined('FLUENTCRM')) {
            return 'fluentcrm';
        }

        if (class_exists('Jetpack_CRM')) {
            return 'jetpack_crm';
        }

        if (class_exists('WeDevs_ERP')) {
            return 'wp_erp';
        }

        // Check for Zero BS CRM (now Jetpack CRM)
        if (function_exists('zeroBS_getContacts')) {
            return 'jetpack_crm';
        }

        return null;
    }

    public function search_contacts(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $crm_plugin = $this->detect_crm_plugin();

        switch ($crm_plugin) {
            case 'fluentcrm':
                return $this->search_fluentcrm($request);
            case 'jetpack_crm':
                return $this->search_jetpack_crm($request);
            case 'wp_erp':
                return $this->search_wp_erp($request);
            default:
                return $this->error_response('no_crm_plugin', 'No supported CRM plugin found', 400);
        }
    }

    private function search_fluentcrm(WP_REST_Request $request): WP_REST_Response {
        $query = $request->get_param('query');
        $email = $request->get_param('email');
        $tags = $request->get_param('tags');
        $status = $request->get_param('status');
        $limit = min($request->get_param('limit') ?: 20, 100);
        $offset = $request->get_param('offset') ?: 0;

        $subscriber_model = FluentCrm\App\Models\Subscriber::query();

        if ($query) {
            $subscriber_model->where(function($q) use ($query) {
                $q->where('first_name', 'LIKE', "%{$query}%")
                  ->orWhere('last_name', 'LIKE', "%{$query}%")
                  ->orWhere('email', 'LIKE', "%{$query}%");
            });
        }

        if ($email) {
            $subscriber_model->where('email', $email);
        }

        if ($status) {
            $subscriber_model->where('status', $status);
        }

        if ($tags) {
            $tag_array = array_map('trim', explode(',', $tags));
            $subscriber_model->filterByTags($tag_array);
        }

        $total = $subscriber_model->count();
        $subscribers = $subscriber_model->skip($offset)->take($limit)->get();

        $contacts = [];
        foreach ($subscribers as $subscriber) {
            $contacts[] = [
                'id' => $subscriber->id,
                'first_name' => $subscriber->first_name,
                'last_name' => $subscriber->last_name,
                'email' => $subscriber->email,
                'phone' => $subscriber->phone ?? '',
                'company' => $subscriber->company_name ?? '',
                'status' => $subscriber->status,
                'tags' => $subscriber->tags->pluck('title')->toArray(),
                'custom_fields' => $subscriber->custom_fields() ?? [],
                'created_at' => $subscriber->created_at,
                'updated_at' => $subscriber->updated_at,
            ];
        }

        return $this->success_response([
            'contacts' => $contacts,
            'total' => $total,
            'crm_plugin' => 'FluentCRM',
        ]);
    }

    private function search_jetpack_crm(WP_REST_Request $request): WP_REST_Response {
        $query = $request->get_param('query');
        $email = $request->get_param('email');
        $limit = min($request->get_param('limit') ?: 20, 100);
        $offset = $request->get_param('offset') ?: 0;

        // Use Zero BS CRM / Jetpack CRM API
        $args = [
            'perPage' => $limit,
            'page' => floor($offset / $limit) + 1,
            'sortByField' => 'zbsc_fname',
            'sortOrder' => 'ASC',
        ];

        if ($query) {
            $args['searchPhrase'] = $query;
        }

        if ($email) {
            $args['email'] = $email;
        }

        if (function_exists('zeroBS_getContacts')) {
            $result = zeroBS_getContacts($args);
        } else {
            $result = [];
        }

        $contacts = [];
        foreach ($result as $contact) {
            $contacts[] = [
                'id' => $contact['id'] ?? 0,
                'first_name' => $contact['fname'] ?? '',
                'last_name' => $contact['lname'] ?? '',
                'email' => $contact['email'] ?? '',
                'phone' => $contact['hometel'] ?? $contact['worktel'] ?? '',
                'company' => $contact['company'] ?? '',
                'status' => $contact['status'] ?? '',
                'tags' => $contact['tags'] ?? [],
                'custom_fields' => [],
                'created_at' => $contact['created'] ?? '',
                'updated_at' => $contact['lastupdated'] ?? '',
            ];
        }

        return $this->success_response([
            'contacts' => $contacts,
            'total' => count($contacts),
            'crm_plugin' => 'Jetpack CRM',
        ]);
    }

    private function search_wp_erp(WP_REST_Request $request): WP_REST_Response {
        $query = $request->get_param('query');
        $email = $request->get_param('email');
        $limit = min($request->get_param('limit') ?: 20, 100);
        $offset = $request->get_param('offset') ?: 0;

        if (!function_exists('erp_get_peoples')) {
            return $this->error_response('wp_erp_not_ready', 'WP ERP not properly initialized', 500);
        }

        $args = [
            'type' => 'contact',
            'number' => $limit,
            'offset' => $offset,
        ];

        if ($query) {
            $args['s'] = $query;
        }

        $people = erp_get_peoples($args);
        $total = erp_get_peoples(['type' => 'contact', 'count' => true]);

        $contacts = [];
        foreach ($people as $person) {
            if ($email && $person->email !== $email) {
                continue;
            }

            $contacts[] = [
                'id' => $person->id,
                'first_name' => $person->first_name,
                'last_name' => $person->last_name,
                'email' => $person->email,
                'phone' => $person->phone ?? '',
                'company' => $person->company ?? '',
                'status' => $person->life_stage ?? '',
                'tags' => [],
                'custom_fields' => [],
                'created_at' => $person->created ?? '',
                'updated_at' => '',
            ];
        }

        return $this->success_response([
            'contacts' => $contacts,
            'total' => $total,
            'crm_plugin' => 'WP ERP',
        ]);
    }
}
