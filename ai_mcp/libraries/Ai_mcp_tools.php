<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Ai_mcp_tool_exception extends Exception
{
}

/**
 * The CRM tool registry shared by the MCP endpoint and the in-app chat.
 *
 * Security model: every tool runs as one staff member. A tool is only *listed* when that
 * staff member has the matching Perfex permission, every query is scoped with the same
 * "view own / assigned / member" rules Perfex applies in its own screens, and every write
 * re-checks permission against the specific record. The AI therefore never sees or changes
 * anything the staff member could not see or change by logging in themselves.
 *
 * The caller must make get_staff_user_id() return the acting staff member (the chat runs
 * inside the staff session; the MCP controller sets the session for the request), because
 * Perfex's own models record "addedfrom", activity logs and notifications from it.
 */
class Ai_mcp_tools
{
    private $CI;
    private $staff_id;
    private $registry;

    private $task_statuses = [1 => 'Not Started', 2 => 'Awaiting Feedback', 3 => 'Testing', 4 => 'In Progress', 5 => 'Complete'];
    private $task_priorities = [1 => 'Low', 2 => 'Medium', 3 => 'High', 4 => 'Urgent'];
    private $project_statuses = [1 => 'Not Started', 2 => 'In Progress', 3 => 'On Hold', 4 => 'Cancelled', 5 => 'Finished'];
    private $invoice_statuses = [1 => 'Unpaid', 2 => 'Paid', 3 => 'Partially Paid', 4 => 'Overdue', 5 => 'Cancelled', 6 => 'Draft'];
    private $estimate_statuses = [1 => 'Draft', 2 => 'Sent', 3 => 'Declined', 4 => 'Accepted', 5 => 'Expired'];
    private $proposal_statuses = [1 => 'Open', 2 => 'Declined', 3 => 'Accepted', 4 => 'Sent', 5 => 'Revised', 6 => 'Draft'];

    public function __construct()
    {
        $this->CI = &get_instance();
    }

    public function for_staff($staff_id)
    {
        $this->staff_id = (int) $staff_id;
        $this->registry = null;

        return $this;
    }

    // ---------------------------------------------------------------------------------
    // Public API
    // ---------------------------------------------------------------------------------

    /**
     * Tools this staff member may use, keyed by name.
     */
    public function available()
    {
        $out = [];
        foreach ($this->registry() as $name => $tool) {
            if (call_user_func($tool['access'])) {
                $out[$name] = $tool;
            }
        }

        return $out;
    }

    /**
     * Tool list in MCP "tools/list" shape.
     */
    public function mcp_definitions()
    {
        $list = [];
        foreach ($this->available() as $name => $tool) {
            $list[] = [
                'name'        => $name,
                'title'       => $tool['title'],
                'description' => $tool['description'],
                'inputSchema' => $this->schema($tool),
                'annotations' => [
                    'title'           => $tool['title'],
                    'readOnlyHint'    => !$tool['write'],
                    'destructiveHint' => !empty($tool['destructive']),
                    'openWorldHint'   => false,
                ],
            ];
        }

        return $list;
    }

    /**
     * Provider-neutral list used by the chat agent: [name, description, schema, write].
     */
    public function agent_definitions()
    {
        $list = [];
        foreach ($this->available() as $name => $tool) {
            $list[] = [
                'name'        => $name,
                'description' => $tool['description'],
                'schema'      => $this->schema($tool),
                'write'       => $tool['write'],
            ];
        }

        return $list;
    }

    /**
     * call() wrapped with rate limiting, error capture and the audit log.
     * Returns ['ok' => bool, 'data' => mixed, 'error' => string|null].
     */
    public function execute($name, $args, $channel, $token_id = null)
    {
        $this->CI->load->model(AI_MCP_MODULE_NAME . '/ai_mcp_model');
        $limit = (int) get_option('ai_mcp_rate_limit');
        if ($limit > 0 && $this->CI->ai_mcp_model->calls_in_last_minute($this->staff_id) >= $limit) {
            return ['ok' => false, 'data' => null, 'error' => 'Rate limit reached (' . $limit . ' calls per minute). Try again shortly.'];
        }

        try {
            $data = $this->call($name, $args);
            $this->CI->ai_mcp_model->log($this->staff_id, $channel, $name, $args, true, null, $token_id);

            return ['ok' => true, 'data' => $data, 'error' => null];
        } catch (Ai_mcp_tool_exception $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            log_message('error', 'ai_mcp tool ' . $name . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $error = 'The CRM could not complete this action (' . $e->getMessage() . ').';
        }
        $this->CI->ai_mcp_model->log($this->staff_id, $channel, $name, $args, false, $error, $token_id);

        return ['ok' => false, 'data' => null, 'error' => $error];
    }

    public function is_write($name)
    {
        $registry = $this->registry();

        return isset($registry[$name]) ? (bool) $registry[$name]['write'] : true;
    }

    /**
     * Runs a tool. Throws Ai_mcp_tool_exception with a user-safe message on failure.
     */
    public function call($name, $args)
    {
        if (!$this->staff_id || (int) get_staff_user_id() !== $this->staff_id) {
            throw new Ai_mcp_tool_exception('Session is not bound to the acting staff member.');
        }
        $registry = $this->registry();
        if (!isset($registry[$name])) {
            throw new Ai_mcp_tool_exception('Unknown tool: ' . $name);
        }
        $tool = $registry[$name];
        if (!call_user_func($tool['access'])) {
            throw new Ai_mcp_tool_exception('You do not have permission to use ' . $name . '.');
        }
        $args = is_array($args) ? $args : [];
        foreach ($tool['required'] as $key) {
            if (!isset($args[$key]) || $args[$key] === '' || $args[$key] === null) {
                throw new Ai_mcp_tool_exception('Missing required argument: ' . $key);
            }
        }

        if (is_string($tool['handler']) && method_exists($this, $tool['handler'])) {
            return call_user_func([$this, $tool['handler']], $args);
        }
        if (is_callable($tool['handler'])) {
            // Module-contributed tool: handler($args, $tools) where $tools exposes the helpers below.
            return call_user_func($tool['handler'], $args, $this);
        }

        throw new Ai_mcp_tool_exception('Tool ' . $name . ' has no handler.');
    }

    // ---------------------------------------------------------------------------------
    // Helpers for tools contributed by other modules
    // ---------------------------------------------------------------------------------

    /** The staff member the AI is acting as. */
    public function staff_id()
    {
        return $this->staff_id;
    }

    /** staff_can() for the acting staff member. */
    public function staff_can($capability, $feature)
    {
        return $this->can($capability, $feature);
    }

    /** Throws a user-facing error that is returned to the AI. */
    public function fail($message)
    {
        throw new Ai_mcp_tool_exception($message);
    }

    /** Whether the acting staff member may see this customer (Perfex customer visibility rules). */
    public function can_access_customer($customer_id)
    {
        return $this->customer_accessible($customer_id);
    }

    // ---------------------------------------------------------------------------------
    // Registry
    // ---------------------------------------------------------------------------------

    private function registry()
    {
        if ($this->registry !== null) {
            return $this->registry;
        }

        $always = function () {
            return true;
        };
        $limit_params = [
            'search' => ['type' => 'string', 'description' => 'Optional text to search for.'],
            'limit'  => ['type' => 'integer', 'description' => 'Max rows (default 25, max 100).'],
            'offset' => ['type' => 'integer', 'description' => 'Rows to skip, for paging.'],
        ];

        $r = [];

        $r['whoami'] = [
            'title'       => 'Who am I',
            'description' => 'Returns the current CRM user, their role and what they are allowed to view, create, edit and delete. Call this first if unsure what is permitted.',
            'params'      => [], 'required' => [], 'write' => false,
            'access'      => $always, 'handler' => 'tool_whoami',
        ];
        $r['search_crm'] = [
            'title'       => 'Search CRM',
            'description' => 'Searches customers, contacts, leads, projects, tasks, invoices and tickets the user can access. Use it to find record IDs by name, email, number or subject.',
            'params'      => ['query' => ['type' => 'string', 'description' => 'Text to search for.']],
            'required'    => ['query'], 'write' => false,
            'access'      => $always, 'handler' => 'tool_search',
        ];
        $r['get_reference_data'] = [
            'title'       => 'Reference data',
            'description' => 'Lists lookup values needed to create records: staff (id, name), lead statuses and sources, task statuses and priorities, ticket departments/priorities/statuses, taxes, payment modes, expense categories and currencies.',
            'params'      => [], 'required' => [], 'write' => false,
            'access'      => $always, 'handler' => 'tool_reference_data',
        ];
        $r['dashboard_summary'] = [
            'title'       => 'My dashboard',
            'description' => 'Summary of the user\'s open and overdue tasks, plus overdue invoices, open leads and open tickets where the user has access.',
            'params'      => [], 'required' => [], 'write' => false,
            'access'      => $always, 'handler' => 'tool_dashboard',
        ];

        // Customers --------------------------------------------------------------------
        $r['list_customers'] = [
            'title'       => 'List customers',
            'description' => 'Lists customers (companies) the user can access.',
            'params'      => $limit_params + ['active' => ['type' => 'boolean', 'description' => 'Only active (true) or inactive (false) customers.']],
            'required'    => [], 'write' => false,
            'access'      => $always, 'handler' => 'tool_list_customers',
        ];
        $r['get_customer'] = [
            'title'       => 'Get customer',
            'description' => 'Full details of one customer including contacts, notes and an overview of invoices/projects the user can see.',
            'params'      => ['customer_id' => ['type' => 'integer', 'description' => 'Customer ID.']],
            'required'    => ['customer_id'], 'write' => false,
            'access'      => $always, 'handler' => 'tool_get_customer',
        ];
        $customer_fields = [
            'company'     => ['type' => 'string', 'description' => 'Company / customer name.'],
            'vat'         => ['type' => 'string', 'description' => 'VAT / GST number.'],
            'phonenumber' => ['type' => 'string'],
            'website'     => ['type' => 'string'],
            'address'     => ['type' => 'string'],
            'city'        => ['type' => 'string'],
            'state'       => ['type' => 'string'],
            'zip'         => ['type' => 'string'],
            'country'     => ['type' => 'string', 'description' => 'Country name, e.g. "India".'],
        ];
        $r['create_customer'] = [
            'title'       => 'Create customer',
            'description' => 'Creates a new customer. Optionally also creates the primary contact (no welcome email is sent).',
            'params'      => $customer_fields + [
                'contact_firstname' => ['type' => 'string'],
                'contact_lastname'  => ['type' => 'string'],
                'contact_email'     => ['type' => 'string'],
                'contact_phone'     => ['type' => 'string'],
            ],
            'required'    => ['company'], 'write' => true,
            'access'      => function () { return $this->can('create', 'customers'); },
            'handler'     => 'tool_create_customer',
        ];
        $r['update_customer'] = [
            'title'       => 'Update customer',
            'description' => 'Updates basic fields of an existing customer. Only the fields passed are changed.',
            'params'      => ['customer_id' => ['type' => 'integer']] + $customer_fields,
            'required'    => ['customer_id'], 'write' => true,
            'access'      => function () { return $this->can('edit', 'customers'); },
            'handler'     => 'tool_update_customer',
        ];
        $r['add_contact'] = [
            'title'       => 'Add contact',
            'description' => 'Adds a contact person to a customer (no welcome email is sent).',
            'params'      => [
                'customer_id' => ['type' => 'integer'],
                'firstname'   => ['type' => 'string'],
                'lastname'    => ['type' => 'string'],
                'email'       => ['type' => 'string'],
                'phonenumber' => ['type' => 'string'],
                'title'       => ['type' => 'string', 'description' => 'Job title / position.'],
            ],
            'required'    => ['customer_id', 'firstname', 'lastname', 'email'], 'write' => true,
            'access'      => function () { return $this->can('edit', 'customers') || $this->can('create', 'customers'); },
            'handler'     => 'tool_add_contact',
        ];
        $r['add_note'] = [
            'title'       => 'Add note',
            'description' => 'Adds an internal note to a customer or a lead.',
            'params'      => [
                'rel_type' => ['type' => 'string', 'enum' => ['customer', 'lead']],
                'rel_id'   => ['type' => 'integer', 'description' => 'Customer ID or lead ID.'],
                'note'     => ['type' => 'string'],
            ],
            'required'    => ['rel_type', 'rel_id', 'note'], 'write' => true,
            'access'      => $always, 'handler' => 'tool_add_note',
        ];

        // Leads ------------------------------------------------------------------------
        $leads_access = function () { return $this->can('view', 'leads') || $this->is_staff_member(); };
        $r['list_leads'] = [
            'title'       => 'List leads',
            'description' => 'Lists leads the user can access. Filter by status ID (see get_reference_data) or assigned staff.',
            'params'      => $limit_params + [
                'status_id'   => ['type' => 'integer'],
                'assigned_to' => ['type' => 'integer', 'description' => 'Staff ID.'],
                'include_lost_junk' => ['type' => 'boolean', 'description' => 'Include lost and junk leads (default false).'],
            ],
            'required'    => [], 'write' => false,
            'access'      => $leads_access, 'handler' => 'tool_list_leads',
        ];
        $r['get_lead'] = [
            'title'       => 'Get lead',
            'description' => 'Full details of one lead including notes and recent activity.',
            'params'      => ['lead_id' => ['type' => 'integer']],
            'required'    => ['lead_id'], 'write' => false,
            'access'      => $leads_access, 'handler' => 'tool_get_lead',
        ];
        $lead_fields = [
            'name'        => ['type' => 'string', 'description' => 'Person name.'],
            'company'     => ['type' => 'string'],
            'title'       => ['type' => 'string', 'description' => 'Job title / position.'],
            'email'       => ['type' => 'string'],
            'phonenumber' => ['type' => 'string'],
            'website'     => ['type' => 'string'],
            'address'     => ['type' => 'string'],
            'city'        => ['type' => 'string'],
            'state'       => ['type' => 'string'],
            'country'     => ['type' => 'string', 'description' => 'Country name.'],
            'description' => ['type' => 'string'],
            'lead_value'  => ['type' => 'number'],
            'status_id'   => ['type' => 'integer', 'description' => 'Lead status ID (default status if omitted).'],
            'source_id'   => ['type' => 'integer', 'description' => 'Lead source ID (default source if omitted).'],
            'assigned_to' => ['type' => 'integer', 'description' => 'Staff ID to assign.'],
        ];
        $r['create_lead'] = [
            'title'       => 'Create lead',
            'description' => 'Creates a new lead.',
            'params'      => $lead_fields,
            'required'    => ['name'], 'write' => true,
            'access'      => function () { return $this->can('create', 'leads') || ($this->is_staff_member() && !$this->feature_has_capability('leads', 'create')); },
            'handler'     => 'tool_create_lead',
        ];
        $r['update_lead'] = [
            'title'       => 'Update lead',
            'description' => 'Updates fields or the status of an existing lead. Only the fields passed are changed.',
            'params'      => ['lead_id' => ['type' => 'integer']] + $lead_fields,
            'required'    => ['lead_id'], 'write' => true,
            'access'      => $leads_access, 'handler' => 'tool_update_lead',
        ];
        $r['delete_lead'] = [
            'title'       => 'Delete lead',
            'description' => 'Permanently deletes a lead. Always confirm with the user first.',
            'params'      => ['lead_id' => ['type' => 'integer']],
            'required'    => ['lead_id'], 'write' => true, 'destructive' => true,
            'access'      => function () { return get_option('ai_mcp_allow_delete') == '1' && ($this->can('delete', 'leads') || $this->is_admin()); },
            'handler'     => 'tool_delete_lead',
        ];

        // Tasks ------------------------------------------------------------------------
        $r['list_tasks'] = [
            'title'       => 'List tasks',
            'description' => 'Lists tasks the user can see. Use mine=true for tasks assigned to the user.',
            'params'      => $limit_params + [
                'mine'       => ['type' => 'boolean', 'description' => 'Only tasks assigned to me.'],
                'status'     => ['type' => 'integer', 'description' => '1 Not Started, 2 Awaiting Feedback, 3 Testing, 4 In Progress, 5 Complete.'],
                'overdue'    => ['type' => 'boolean', 'description' => 'Only overdue, not completed tasks.'],
                'rel_type'   => ['type' => 'string', 'enum' => ['project', 'customer', 'lead', 'invoice', 'estimate', 'contract', 'ticket', 'expense', 'proposal']],
                'rel_id'     => ['type' => 'integer'],
            ],
            'required'    => [], 'write' => false,
            'access'      => $always, 'handler' => 'tool_list_tasks',
        ];
        $r['get_task'] = [
            'title'       => 'Get task',
            'description' => 'Full task details including assignees, checklist and comments.',
            'params'      => ['task_id' => ['type' => 'integer']],
            'required'    => ['task_id'], 'write' => false,
            'access'      => $always, 'handler' => 'tool_get_task',
        ];
        $r['create_task'] = [
            'title'       => 'Create task',
            'description' => 'Creates a task, optionally linked to a project/customer/lead etc. and assigned to staff.',
            'params'      => [
                'name'        => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'startdate'   => ['type' => 'string', 'description' => 'YYYY-MM-DD, default today.'],
                'duedate'     => ['type' => 'string', 'description' => 'YYYY-MM-DD.'],
                'priority'    => ['type' => 'integer', 'description' => '1 Low, 2 Medium, 3 High, 4 Urgent.'],
                'rel_type'    => ['type' => 'string', 'enum' => ['project', 'customer', 'lead']],
                'rel_id'      => ['type' => 'integer'],
                'assignees'   => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Staff IDs.'],
                'is_public'   => ['type' => 'boolean'],
                'billable'    => ['type' => 'boolean'],
            ],
            'required'    => ['name'], 'write' => true,
            'access'      => function () { return $this->can('create', 'tasks'); },
            'handler'     => 'tool_create_task',
        ];
        $r['update_task_status'] = [
            'title'       => 'Update task status',
            'description' => 'Changes a task status (1 Not Started, 2 Awaiting Feedback, 3 Testing, 4 In Progress, 5 Complete).',
            'params'      => ['task_id' => ['type' => 'integer'], 'status' => ['type' => 'integer', 'description' => '1-5, see description.']],
            'required'    => ['task_id', 'status'], 'write' => true,
            'access'      => $always, 'handler' => 'tool_update_task_status',
        ];
        $r['assign_task'] = [
            'title'       => 'Assign task',
            'description' => 'Adds a staff member as assignee of a task.',
            'params'      => ['task_id' => ['type' => 'integer'], 'staff_id' => ['type' => 'integer']],
            'required'    => ['task_id', 'staff_id'], 'write' => true,
            'access'      => function () { return $this->can('edit', 'tasks'); },
            'handler'     => 'tool_assign_task',
        ];
        $r['add_task_comment'] = [
            'title'       => 'Comment on task',
            'description' => 'Adds a comment to a task.',
            'params'      => ['task_id' => ['type' => 'integer'], 'comment' => ['type' => 'string']],
            'required'    => ['task_id', 'comment'], 'write' => true,
            'access'      => $always, 'handler' => 'tool_add_task_comment',
        ];
        $r['delete_task'] = [
            'title'       => 'Delete task',
            'description' => 'Permanently deletes a task. Always confirm with the user first.',
            'params'      => ['task_id' => ['type' => 'integer']],
            'required'    => ['task_id'], 'write' => true, 'destructive' => true,
            'access'      => function () { return get_option('ai_mcp_allow_delete') == '1' && $this->can('delete', 'tasks'); },
            'handler'     => 'tool_delete_task',
        ];

        // Projects ---------------------------------------------------------------------
        $r['list_projects'] = [
            'title'       => 'List projects',
            'description' => 'Lists projects the user can access (all projects with view permission, otherwise projects they are a member of).',
            'params'      => $limit_params + [
                'status'      => ['type' => 'integer', 'description' => '1 Not Started, 2 In Progress, 3 On Hold, 4 Cancelled, 5 Finished.'],
                'customer_id' => ['type' => 'integer'],
            ],
            'required'    => [], 'write' => false,
            'access'      => $always, 'handler' => 'tool_list_projects',
        ];
        $r['get_project'] = [
            'title'       => 'Get project',
            'description' => 'Project details with members, milestones and task counts.',
            'params'      => ['project_id' => ['type' => 'integer']],
            'required'    => ['project_id'], 'write' => false,
            'access'      => $always, 'handler' => 'tool_get_project',
        ];

        // Sales ------------------------------------------------------------------------
        $r['list_invoices'] = [
            'title'       => 'List invoices',
            'description' => 'Lists invoices the user can see.',
            'params'      => $limit_params + [
                'status'      => ['type' => 'integer', 'description' => '1 Unpaid, 2 Paid, 3 Partially Paid, 4 Overdue, 5 Cancelled, 6 Draft.'],
                'customer_id' => ['type' => 'integer'],
                'date_from'   => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'date_to'     => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
            ],
            'required'    => [], 'write' => false,
            'access'      => function () { return $this->sales_access('invoices', 'allow_staff_view_invoices_assigned'); },
            'handler'     => 'tool_list_invoices',
        ];
        $r['get_invoice'] = [
            'title'       => 'Get invoice',
            'description' => 'Invoice details with line items and payments.',
            'params'      => ['invoice_id' => ['type' => 'integer']],
            'required'    => ['invoice_id'], 'write' => false,
            'access'      => function () { return $this->sales_access('invoices', 'allow_staff_view_invoices_assigned'); },
            'handler'     => 'tool_get_invoice',
        ];
        $r['create_invoice'] = [
            'title'       => 'Create draft invoice',
            'description' => 'Creates a DRAFT invoice for a customer with line items. Tax IDs come from get_reference_data. The user reviews and sends it from the CRM.',
            'params'      => [
                'customer_id' => ['type' => 'integer'],
                'date'        => ['type' => 'string', 'description' => 'YYYY-MM-DD, default today.'],
                'duedate'     => ['type' => 'string', 'description' => 'YYYY-MM-DD.'],
                'project_id'  => ['type' => 'integer'],
                'clientnote'  => ['type' => 'string'],
                'adminnote'   => ['type' => 'string'],
                'items'       => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'description'      => ['type' => 'string'],
                            'long_description' => ['type' => 'string'],
                            'qty'              => ['type' => 'number'],
                            'rate'             => ['type' => 'number'],
                            'unit'             => ['type' => 'string'],
                            'tax_ids'          => ['type' => 'array', 'items' => ['type' => 'integer']],
                        ],
                        'required' => ['description', 'qty', 'rate'],
                    ],
                ],
            ],
            'required'    => ['customer_id', 'items'], 'write' => true,
            'access'      => function () { return $this->can('create', 'invoices'); },
            'handler'     => 'tool_create_invoice',
        ];
        $r['record_payment'] = [
            'title'       => 'Record payment',
            'description' => 'Records a payment received against an invoice.',
            'params'      => [
                'invoice_id'      => ['type' => 'integer'],
                'amount'          => ['type' => 'number'],
                'payment_mode_id' => ['type' => 'string', 'description' => 'Payment mode ID from get_reference_data.'],
                'date'            => ['type' => 'string', 'description' => 'YYYY-MM-DD, default today.'],
                'transaction_id'  => ['type' => 'string'],
                'note'            => ['type' => 'string'],
            ],
            'required'    => ['invoice_id', 'amount', 'payment_mode_id'], 'write' => true,
            'access'      => function () { return $this->can('create', 'payments'); },
            'handler'     => 'tool_record_payment',
        ];
        $r['list_estimates'] = [
            'title'       => 'List estimates',
            'description' => 'Lists estimates the user can see.',
            'params'      => $limit_params + [
                'status'      => ['type' => 'integer', 'description' => '1 Draft, 2 Sent, 3 Declined, 4 Accepted, 5 Expired.'],
                'customer_id' => ['type' => 'integer'],
            ],
            'required'    => [], 'write' => false,
            'access'      => function () { return $this->sales_access('estimates', 'allow_staff_view_estimates_assigned'); },
            'handler'     => 'tool_list_estimates',
        ];
        $r['list_proposals'] = [
            'title'       => 'List proposals',
            'description' => 'Lists proposals the user can see.',
            'params'      => $limit_params + ['status' => ['type' => 'integer', 'description' => '1 Open, 2 Declined, 3 Accepted, 4 Sent, 5 Revised, 6 Draft.']],
            'required'    => [], 'write' => false,
            'access'      => function () { return $this->sales_access('proposals', 'allow_staff_view_proposals_assigned'); },
            'handler'     => 'tool_list_proposals',
        ];
        $r['list_contracts'] = [
            'title'       => 'List contracts',
            'description' => 'Lists contracts the user can see, with start/end dates and value.',
            'params'      => $limit_params + [
                'customer_id'   => ['type' => 'integer'],
                'expiring_days' => ['type' => 'integer', 'description' => 'Only contracts ending within this many days.'],
            ],
            'required'    => [], 'write' => false,
            'access'      => function () { return $this->can_view('contracts'); },
            'handler'     => 'tool_list_contracts',
        ];

        // Expenses ---------------------------------------------------------------------
        $r['list_expenses'] = [
            'title'       => 'List expenses',
            'description' => 'Lists expenses the user can see.',
            'params'      => $limit_params + [
                'category_id' => ['type' => 'integer'],
                'customer_id' => ['type' => 'integer'],
                'date_from'   => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'date_to'     => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
            ],
            'required'    => [], 'write' => false,
            'access'      => function () { return $this->can_view('expenses'); },
            'handler'     => 'tool_list_expenses',
        ];
        $r['create_expense'] = [
            'title'       => 'Create expense',
            'description' => 'Records an expense. Category and payment mode IDs come from get_reference_data.',
            'params'      => [
                'category_id'     => ['type' => 'integer'],
                'amount'          => ['type' => 'number'],
                'date'            => ['type' => 'string', 'description' => 'YYYY-MM-DD, default today.'],
                'expense_name'    => ['type' => 'string'],
                'note'            => ['type' => 'string'],
                'customer_id'     => ['type' => 'integer'],
                'project_id'      => ['type' => 'integer'],
                'payment_mode_id' => ['type' => 'string'],
                'reference_no'    => ['type' => 'string'],
                'billable'        => ['type' => 'boolean'],
            ],
            'required'    => ['category_id', 'amount'], 'write' => true,
            'access'      => function () { return $this->can('create', 'expenses'); },
            'handler'     => 'tool_create_expense',
        ];

        // Support ----------------------------------------------------------------------
        $tickets_access = function () { return $this->tickets_access(); };
        $r['list_tickets'] = [
            'title'       => 'List tickets',
            'description' => 'Lists support tickets the user can see.',
            'params'      => $limit_params + [
                'status_id'     => ['type' => 'integer'],
                'department_id' => ['type' => 'integer'],
                'customer_id'   => ['type' => 'integer'],
                'mine'          => ['type' => 'boolean', 'description' => 'Only tickets assigned to me.'],
            ],
            'required'    => [], 'write' => false,
            'access'      => $tickets_access, 'handler' => 'tool_list_tickets',
        ];
        $r['get_ticket'] = [
            'title'       => 'Get ticket',
            'description' => 'Ticket details with the conversation (replies).',
            'params'      => ['ticket_id' => ['type' => 'integer']],
            'required'    => ['ticket_id'], 'write' => false,
            'access'      => $tickets_access, 'handler' => 'tool_get_ticket',
        ];
        $r['create_ticket'] = [
            'title'       => 'Create ticket',
            'description' => 'Opens a support ticket for a customer contact.',
            'params'      => [
                'contact_id'    => ['type' => 'integer'],
                'subject'       => ['type' => 'string'],
                'message'       => ['type' => 'string'],
                'department_id' => ['type' => 'integer'],
                'priority_id'   => ['type' => 'integer'],
                'assigned_to'   => ['type' => 'integer', 'description' => 'Staff ID.'],
                'project_id'    => ['type' => 'integer'],
            ],
            'required'    => ['contact_id', 'subject', 'message', 'department_id'], 'write' => true,
            'access'      => $tickets_access, 'handler' => 'tool_create_ticket',
        ];
        $r['reply_ticket'] = [
            'title'       => 'Reply to ticket',
            'description' => 'Posts a staff reply on a ticket (the customer is emailed as usual) and optionally changes its status.',
            'params'      => [
                'ticket_id' => ['type' => 'integer'],
                'message'   => ['type' => 'string'],
                'status_id' => ['type' => 'integer'],
            ],
            'required'    => ['ticket_id', 'message'], 'write' => true,
            'access'      => $tickets_access, 'handler' => 'tool_reply_ticket',
        ];

        // Projects (write) -------------------------------------------------------------
        $r['create_project'] = [
            'title'       => 'Create project',
            'description' => 'Creates a project for a customer. billing_type: 1 Fixed rate, 2 Project hours, 3 Task hours.',
            'params'      => [
                'name'         => ['type' => 'string'],
                'customer_id'  => ['type' => 'integer'],
                'billing_type' => ['type' => 'integer', 'description' => '1 Fixed rate (default), 2 Project hours, 3 Task hours.'],
                'project_cost' => ['type' => 'number', 'description' => 'For fixed rate.'],
                'rate_per_hour' => ['type' => 'number', 'description' => 'For project hours.'],
                'start_date'   => ['type' => 'string', 'description' => 'YYYY-MM-DD, default today.'],
                'deadline'     => ['type' => 'string', 'description' => 'YYYY-MM-DD.'],
                'description'  => ['type' => 'string'],
                'member_ids'   => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Staff IDs.'],
            ],
            'required'    => ['name', 'customer_id'], 'write' => true,
            'access'      => function () { return $this->can('create', 'projects'); },
            'handler'     => 'tool_create_project',
        ];
        $r['update_project'] = [
            'title'       => 'Update project',
            'description' => 'Updates a project\'s name, status (1 Not Started, 2 In Progress, 3 On Hold, 4 Cancelled, 5 Finished), dates, progress or cost.',
            'params'      => [
                'project_id'   => ['type' => 'integer'],
                'name'         => ['type' => 'string'],
                'status'       => ['type' => 'integer'],
                'start_date'   => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'deadline'     => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'progress'     => ['type' => 'integer', 'description' => '0-100'],
                'project_cost' => ['type' => 'number'],
                'description'  => ['type' => 'string'],
            ],
            'required'    => ['project_id'], 'write' => true,
            'access'      => function () { return $this->can('edit', 'projects'); },
            'handler'     => 'tool_update_project',
        ];
        $r['add_project_member'] = [
            'title'       => 'Add project member',
            'description' => 'Adds a staff member to a project.',
            'params'      => ['project_id' => ['type' => 'integer'], 'staff_id' => ['type' => 'integer']],
            'required'    => ['project_id', 'staff_id'], 'write' => true,
            'access'      => function () { return $this->can('edit', 'projects'); },
            'handler'     => 'tool_add_project_member',
        ];
        $r['add_milestone'] = [
            'title'       => 'Add milestone',
            'description' => 'Adds a milestone to a project.',
            'params'      => [
                'project_id'  => ['type' => 'integer'],
                'name'        => ['type' => 'string'],
                'due_date'    => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'description' => ['type' => 'string'],
            ],
            'required'    => ['project_id', 'name', 'due_date'], 'write' => true,
            'access'      => function () { return $this->can('edit', 'projects'); },
            'handler'     => 'tool_add_milestone',
        ];

        // Tasks (more writes) ------------------------------------------------------------
        $r['update_task'] = [
            'title'       => 'Update task',
            'description' => 'Changes a task\'s name, description, dates or priority. Only the fields passed are changed.',
            'params'      => [
                'task_id'     => ['type' => 'integer'],
                'name'        => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'startdate'   => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'duedate'     => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'priority'    => ['type' => 'integer', 'description' => '1 Low, 2 Medium, 3 High, 4 Urgent.'],
            ],
            'required'    => ['task_id'], 'write' => true,
            'access'      => function () { return $this->can('edit', 'tasks'); },
            'handler'     => 'tool_update_task',
        ];
        $r['add_checklist_item'] = [
            'title'       => 'Add checklist item',
            'description' => 'Adds a checklist item to a task.',
            'params'      => ['task_id' => ['type' => 'integer'], 'text' => ['type' => 'string']],
            'required'    => ['task_id', 'text'], 'write' => true,
            'access'      => $always, 'handler' => 'tool_add_checklist_item',
        ];
        $r['add_task_follower'] = [
            'title'       => 'Add task follower',
            'description' => 'Adds a staff member as follower of a task.',
            'params'      => ['task_id' => ['type' => 'integer'], 'staff_id' => ['type' => 'integer']],
            'required'    => ['task_id', 'staff_id'], 'write' => true,
            'access'      => function () { return $this->can('edit', 'tasks'); },
            'handler'     => 'tool_add_task_follower',
        ];
        $r['log_time'] = [
            'title'       => 'Log time on task',
            'description' => 'Records time spent on a task for the current user (timesheet entry).',
            'params'      => [
                'task_id' => ['type' => 'integer'],
                'hours'   => ['type' => 'number', 'description' => 'Hours spent, e.g. 1.5'],
                'date'    => ['type' => 'string', 'description' => 'YYYY-MM-DD, default today.'],
                'note'    => ['type' => 'string'],
            ],
            'required'    => ['task_id', 'hours'], 'write' => true,
            'access'      => $always, 'handler' => 'tool_log_time',
        ];

        // Leads (more) -------------------------------------------------------------------
        $r['convert_lead_to_customer'] = [
            'title'       => 'Convert lead to customer',
            'description' => 'Creates a customer and primary contact from a lead (using the lead\'s company, name and email) and marks the lead as converted.',
            'params'      => ['lead_id' => ['type' => 'integer']],
            'required'    => ['lead_id'], 'write' => true,
            'access'      => function () { return $this->can('create', 'customers'); },
            'handler'     => 'tool_convert_lead',
        ];
        $r['mark_lead'] = [
            'title'       => 'Mark lead lost / junk',
            'description' => 'Marks a lead as lost or junk, or clears that mark.',
            'params'      => [
                'lead_id' => ['type' => 'integer'],
                'mark'    => ['type' => 'string', 'enum' => ['lost', 'junk', 'active']],
            ],
            'required'    => ['lead_id', 'mark'], 'write' => true,
            'access'      => $leads_access, 'handler' => 'tool_mark_lead',
        ];

        // Sales (more) ---------------------------------------------------------------------
        $item_schema = [
            'type'  => 'array',
            'items' => [
                'type'       => 'object',
                'properties' => [
                    'description'      => ['type' => 'string'],
                    'long_description' => ['type' => 'string'],
                    'qty'              => ['type' => 'number'],
                    'rate'             => ['type' => 'number'],
                    'unit'             => ['type' => 'string'],
                    'tax_ids'          => ['type' => 'array', 'items' => ['type' => 'integer']],
                ],
                'required' => ['description', 'qty', 'rate'],
            ],
        ];
        $r['get_estimate'] = [
            'title'       => 'Get estimate',
            'description' => 'Estimate details with line items.',
            'params'      => ['estimate_id' => ['type' => 'integer']],
            'required'    => ['estimate_id'], 'write' => false,
            'access'      => function () { return $this->sales_access('estimates', 'allow_staff_view_estimates_assigned'); },
            'handler'     => 'tool_get_estimate',
        ];
        $r['create_estimate'] = [
            'title'       => 'Create draft estimate',
            'description' => 'Creates a DRAFT estimate (quotation) for a customer with line items.',
            'params'      => [
                'customer_id' => ['type' => 'integer'],
                'date'        => ['type' => 'string', 'description' => 'YYYY-MM-DD, default today.'],
                'expirydate'  => ['type' => 'string', 'description' => 'YYYY-MM-DD.'],
                'clientnote'  => ['type' => 'string'],
                'items'       => $item_schema,
            ],
            'required'    => ['customer_id', 'items'], 'write' => true,
            'access'      => function () { return $this->can('create', 'estimates'); },
            'handler'     => 'tool_create_estimate',
        ];
        $r['convert_estimate_to_invoice'] = [
            'title'       => 'Convert estimate to invoice',
            'description' => 'Converts an estimate into a DRAFT invoice.',
            'params'      => ['estimate_id' => ['type' => 'integer']],
            'required'    => ['estimate_id'], 'write' => true,
            'access'      => function () { return $this->can('create', 'invoices') && $this->sales_access('estimates', 'allow_staff_view_estimates_assigned'); },
            'handler'     => 'tool_convert_estimate',
        ];
        $r['get_proposal'] = [
            'title'       => 'Get proposal',
            'description' => 'Proposal details with line items and content.',
            'params'      => ['proposal_id' => ['type' => 'integer']],
            'required'    => ['proposal_id'], 'write' => false,
            'access'      => function () { return $this->sales_access('proposals', 'allow_staff_view_proposals_assigned'); },
            'handler'     => 'tool_get_proposal',
        ];
        $r['get_contract'] = [
            'title'       => 'Get contract',
            'description' => 'Contract details including its text.',
            'params'      => ['contract_id' => ['type' => 'integer']],
            'required'    => ['contract_id'], 'write' => false,
            'access'      => function () { return $this->can_view('contracts'); },
            'handler'     => 'tool_get_contract',
        ];
        $r['create_contract'] = [
            'title'       => 'Create contract',
            'description' => 'Creates a contract for a customer.',
            'params'      => [
                'customer_id'      => ['type' => 'integer'],
                'subject'          => ['type' => 'string'],
                'start_date'       => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'end_date'         => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'contract_value'   => ['type' => 'number'],
                'contract_type_id' => ['type' => 'integer'],
                'description'      => ['type' => 'string'],
            ],
            'required'    => ['customer_id', 'subject', 'start_date'], 'write' => true,
            'access'      => function () { return $this->can('create', 'contracts'); },
            'handler'     => 'tool_create_contract',
        ];
        $r['list_payments'] = [
            'title'       => 'List payments',
            'description' => 'Lists payments received against invoices.',
            'params'      => $limit_params + [
                'customer_id' => ['type' => 'integer'],
                'date_from'   => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'date_to'     => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
            ],
            'required'    => [], 'write' => false,
            'access'      => function () { return $this->can_view('payments'); },
            'handler'     => 'tool_list_payments',
        ];
        $r['list_credit_notes'] = [
            'title'       => 'List credit notes',
            'description' => 'Lists credit notes.',
            'params'      => $limit_params + ['customer_id' => ['type' => 'integer']],
            'required'    => [], 'write' => false,
            'access'      => function () { return $this->can_view('credit_notes'); },
            'handler'     => 'tool_list_credit_notes',
        ];
        $r['list_items'] = [
            'title'       => 'List products / services',
            'description' => 'Lists saved items (products and services) with rates, for use on invoices and estimates.',
            'params'      => $limit_params,
            'required'    => [], 'write' => false,
            'access'      => function () { return $this->can('view', 'items') || $this->can('create', 'invoices') || $this->can('create', 'estimates'); },
            'handler'     => 'tool_list_items',
        ];
        $r['create_item'] = [
            'title'       => 'Create product / service',
            'description' => 'Saves a new item (product or service) for reuse on invoices and estimates.',
            'params'      => [
                'description'      => ['type' => 'string'],
                'long_description' => ['type' => 'string'],
                'rate'             => ['type' => 'number'],
                'unit'             => ['type' => 'string'],
                'tax_id'           => ['type' => 'integer'],
            ],
            'required'    => ['description', 'rate'], 'write' => true,
            'access'      => function () { return $this->can('create', 'items'); },
            'handler'     => 'tool_create_item',
        ];

        // Support (more) -----------------------------------------------------------------
        $r['update_ticket'] = [
            'title'       => 'Update ticket',
            'description' => 'Changes a ticket\'s status, priority, department or assigned staff without replying.',
            'params'      => [
                'ticket_id'     => ['type' => 'integer'],
                'status_id'     => ['type' => 'integer'],
                'priority_id'   => ['type' => 'integer'],
                'department_id' => ['type' => 'integer'],
                'assigned_to'   => ['type' => 'integer', 'description' => 'Staff ID, 0 to unassign.'],
            ],
            'required'    => ['ticket_id'], 'write' => true,
            'access'      => $tickets_access, 'handler' => 'tool_update_ticket',
        ];
        $r['search_knowledge_base'] = [
            'title'       => 'Search knowledge base',
            'description' => 'Searches knowledge base articles.',
            'params'      => ['query' => ['type' => 'string']],
            'required'    => ['query'], 'write' => false,
            'access'      => function () { return $this->can('view', 'knowledge_base') || $this->is_staff_member(); },
            'handler'     => 'tool_search_kb',
        ];

        // Reminders ------------------------------------------------------------------------
        $r['add_reminder'] = [
            'title'       => 'Add reminder',
            'description' => 'Sets a reminder on a customer, lead, project, task, invoice, estimate or ticket for a staff member (default: me).',
            'params'      => [
                'rel_type'       => ['type' => 'string', 'enum' => ['customer', 'lead', 'project', 'task', 'invoice', 'estimate', 'ticket']],
                'rel_id'         => ['type' => 'integer'],
                'datetime'       => ['type' => 'string', 'description' => 'YYYY-MM-DD HH:MM'],
                'description'    => ['type' => 'string'],
                'staff_id'       => ['type' => 'integer'],
                'notify_by_email' => ['type' => 'boolean'],
            ],
            'required'    => ['rel_type', 'rel_id', 'datetime', 'description'], 'write' => true,
            'access'      => $always, 'handler' => 'tool_add_reminder',
        ];

        // Tools contributed by other modules (see EXTENDING.md). A module tool may declare
        // 'feature' + 'capability' instead of an access callback, and a callable handler.
        $r = hooks()->apply_filters('ai_mcp_tools', $r, $this);
        foreach ($r as $name => &$tool) {
            $tool += ['title' => $name, 'params' => [], 'required' => [], 'write' => false];
            if (!isset($tool['access'])) {
                $feature = isset($tool['feature']) ? $tool['feature'] : null;
                $capability = isset($tool['capability']) ? $tool['capability'] : ($tool['write'] ? 'create' : 'view');
                $tool['access'] = $feature === null
                    ? function () { return false; } // no declared permission = never exposed
                    : function () use ($feature, $capability) {
                        return $this->can($capability, $feature) || ($capability === 'view' && $this->can('view_own', $feature));
                    };
            }
            if (!empty($tool['module']) && function_exists('module_dir_path')) {
                $CI = $this->CI;
                $module = $tool['module'];
                $inner = $tool['access'];
                $tool['access'] = function () use ($CI, $module, $inner) {
                    return $CI->app_modules->is_active($module) && call_user_func($inner);
                };
            }
        }
        unset($tool);

        return $this->registry = $r;
    }

    private function schema($tool)
    {
        $properties = [];
        foreach ($tool['params'] as $key => $def) {
            $properties[$key] = $def;
        }

        $schema = ['type' => 'object', 'properties' => empty($properties) ? new stdClass() : $properties];
        if (!empty($tool['required'])) {
            $schema['required'] = array_values($tool['required']);
        }

        return $schema;
    }

    // ---------------------------------------------------------------------------------
    // Permission helpers
    // ---------------------------------------------------------------------------------

    private function can($capability, $feature)
    {
        return ai_mcp_staff_can($capability, $feature, $this->staff_id);
    }

    private function can_view($feature)
    {
        return $this->can('view', $feature) || $this->can('view_own', $feature);
    }

    private function is_admin()
    {
        return is_admin($this->staff_id);
    }

    private function is_staff_member()
    {
        $row = $this->CI->db->select('is_not_staff')->where('staffid', $this->staff_id)->get($this->t('staff'))->row();

        return $row && (int) $row->is_not_staff === 0;
    }

    /**
     * Older Perfex builds have no "create" capability for leads (any staff member could add
     * leads). Returns whether the capability is registered so we follow the install's rules.
     */
    private function feature_has_capability($feature, $capability)
    {
        if (!function_exists('get_available_staff_permissions')) {
            return true;
        }
        $all = get_available_staff_permissions();

        return isset($all[$feature]['capabilities'][$capability]);
    }

    private function sales_access($feature, $assigned_option)
    {
        return $this->can_view($feature) || get_option($assigned_option) == '1';
    }

    private function tickets_access()
    {
        if ($this->is_admin()) {
            return true;
        }
        if (!$this->is_staff_member() && get_option('access_tickets_to_none_staff_members') != '1') {
            return false;
        }

        return true;
    }

    private function t($table)
    {
        return db_prefix() . $table;
    }

    /** SQL restricting customers to the ones this staff member may see; null = all. */
    private function customer_scope()
    {
        if ($this->can('view', 'customers')) {
            return null;
        }

        return $this->t('clients') . '.userid IN (SELECT customer_id FROM ' . $this->t('customer_admins') . ' WHERE staff_id=' . $this->staff_id . ')';
    }

    private function lead_scope()
    {
        if ($this->can('view', 'leads')) {
            return null;
        }
        $l = $this->t('leads');

        return '(' . $l . '.assigned=' . $this->staff_id . ' OR ' . $l . '.addedfrom=' . $this->staff_id . ' OR ' . $l . '.is_public=1)';
    }

    private function project_scope()
    {
        if ($this->can('view', 'projects')) {
            return null;
        }

        return $this->t('projects') . '.id IN (SELECT project_id FROM ' . $this->t('project_members') . ' WHERE staff_id=' . $this->staff_id . ')';
    }

    /** Mirrors Perfex's get_tasks_where_string(). */
    private function task_scope()
    {
        if ($this->can('view', 'tasks')) {
            return null;
        }
        $t = $this->t('tasks');
        $s = $this->staff_id;
        $where = '(' . $t . '.id IN (SELECT taskid FROM ' . $this->t('task_assigned') . ' WHERE staffid=' . $s . ')'
            . ' OR ' . $t . '.id IN (SELECT taskid FROM ' . $this->t('task_followers') . ' WHERE staffid=' . $s . ')'
            . ' OR (' . $t . '.addedfrom=' . $s . ' AND ' . $t . '.is_added_from_contact=0)'
            . ' OR ' . $t . '.is_public=1';
        if (get_option('show_all_tasks_for_project_member') == '1') {
            $where .= ' OR (' . $t . '.rel_type="project" AND ' . $t . '.rel_id IN (SELECT project_id FROM ' . $this->t('project_members') . ' WHERE staff_id=' . $s . '))';
        }

        return $where . ')';
    }

    /** Mirrors get_invoices_where_sql_for_staff() & friends; '1=0' = nothing visible. */
    private function sales_scope($feature, $table, $assigned_column, $assigned_option)
    {
        if ($this->can('view', $feature)) {
            return null;
        }
        $parts = [];
        if ($this->can('view_own', $feature)) {
            $parts[] = $this->t($table) . '.addedfrom=' . $this->staff_id;
        }
        if (get_option($assigned_option) == '1') {
            $parts[] = $this->t($table) . '.' . $assigned_column . '=' . $this->staff_id;
        }

        return empty($parts) ? '1=0' : '(' . implode(' OR ', $parts) . ')';
    }

    private function own_scope($feature, $table)
    {
        if ($this->can('view', $feature)) {
            return null;
        }

        return $this->can('view_own', $feature) ? $this->t($table) . '.addedfrom=' . $this->staff_id : '1=0';
    }

    private function ticket_scope()
    {
        if ($this->is_admin() || get_option('staff_access_only_assigned_departments') != '1') {
            return null;
        }

        return $this->t('tickets') . '.department IN (SELECT departmentid FROM ' . $this->t('staff_departments') . ' WHERE staffid=' . $this->staff_id . ')';
    }

    private function apply_scope($scope)
    {
        if ($scope !== null) {
            $this->CI->db->where($scope, null, false);
        }
    }

    private function customer_accessible($id)
    {
        $this->CI->db->where('userid', (int) $id);
        $this->apply_scope($this->customer_scope());

        return $this->CI->db->count_all_results($this->t('clients')) > 0;
    }

    private function lead_accessible($id)
    {
        $this->CI->db->where('id', (int) $id);
        $this->apply_scope($this->lead_scope());

        return $this->CI->db->count_all_results($this->t('leads')) > 0;
    }

    private function project_accessible($id)
    {
        $this->CI->db->where('id', (int) $id);
        $this->apply_scope($this->project_scope());

        return $this->CI->db->count_all_results($this->t('projects')) > 0;
    }

    private function task_accessible($id)
    {
        $this->CI->db->where('id', (int) $id);
        $this->apply_scope($this->task_scope());

        return $this->CI->db->count_all_results($this->t('tasks')) > 0;
    }

    private function ticket_accessible($id)
    {
        if (!$this->tickets_access()) {
            return false;
        }
        $this->CI->db->where('ticketid', (int) $id);
        $this->apply_scope($this->ticket_scope());

        return $this->CI->db->count_all_results($this->t('tickets')) > 0;
    }

    private function deny($what = 'this record')
    {
        throw new Ai_mcp_tool_exception('Not found or you do not have access to ' . $what . '.');
    }

    // ---------------------------------------------------------------------------------
    // Argument helpers
    // ---------------------------------------------------------------------------------

    private function arg($args, $key, $default = null)
    {
        return isset($args[$key]) && $args[$key] !== '' ? $args[$key] : $default;
    }

    private function str($args, $key, $default = null)
    {
        $v = $this->arg($args, $key, $default);

        return $v === null ? null : trim((string) $v);
    }

    private function int($args, $key, $default = null)
    {
        $v = $this->arg($args, $key, $default);

        return $v === null ? null : (int) $v;
    }

    private function bool($args, $key)
    {
        if (!isset($args[$key])) {
            return null;
        }
        $v = $args[$key];

        return $v === true || $v === 1 || $v === '1' || $v === 'true';
    }

    /** Validates a YYYY-MM-DD date argument. */
    private function date($args, $key, $default = null)
    {
        $v = $this->str($args, $key, $default);
        if ($v === null) {
            return null;
        }
        $d = DateTime::createFromFormat('Y-m-d', $v);
        if (!$d || $d->format('Y-m-d') !== $v) {
            throw new Ai_mcp_tool_exception($key . ' must be a date in YYYY-MM-DD format.');
        }

        return $v;
    }

    /** Perfex models parse dates in the install's display format, so convert for them. */
    private function to_app_date($ymd)
    {
        return $ymd ? _d($ymd) : '';
    }

    private function paging($args)
    {
        $limit = max(1, min(100, (int) $this->arg($args, 'limit', 25)));
        $offset = max(0, (int) $this->arg($args, 'offset', 0));

        return [$limit, $offset];
    }

    private function page($query_rows, $limit, $offset)
    {
        $more = count($query_rows) > $limit;

        return [
            'items'       => array_slice($query_rows, 0, $limit),
            'offset'      => $offset,
            'has_more'    => $more,
            'next_offset' => $more ? $offset + $limit : null,
        ];
    }

    private function country_id($name)
    {
        if (!$name) {
            return 0;
        }
        $row = $this->CI->db->select('country_id')
            ->group_start()->where('short_name', $name)->or_where('long_name', $name)->or_where('iso2', $name)->or_where('iso3', $name)->group_end()
            ->get($this->t('countries'))->row();

        return $row ? (int) $row->country_id : 0;
    }

    private function country_name($id)
    {
        if (!$id) {
            return null;
        }
        $row = $this->CI->db->select('short_name')->where('country_id', $id)->get($this->t('countries'))->row();

        return $row ? $row->short_name : null;
    }

    private function staff_exists($id)
    {
        return $this->CI->db->where('staffid', (int) $id)->where('active', 1)->count_all_results($this->t('staff')) > 0;
    }

    private function customer_names($ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) {
            return [];
        }
        $map = [];
        foreach ($this->CI->db->select('userid, company')->where_in('userid', $ids)->get($this->t('clients'))->result_array() as $row) {
            $map[$row['userid']] = $row['company'];
        }

        return $map;
    }

    private function currency_names()
    {
        $map = [];
        foreach ($this->CI->db->select('id, name')->get($this->t('currencies'))->result_array() as $row) {
            $map[$row['id']] = $row['name'];
        }

        return $map;
    }

    private function fmt_number($type, $row)
    {
        $fn = 'format_' . $type . '_number';
        if (function_exists($fn)) {
            return strip_tags($fn($row['id']));
        }

        return (isset($row['prefix']) ? $row['prefix'] : '') . str_pad($row['number'], 6, '0', STR_PAD_LEFT);
    }

    private function log_activity($text)
    {
        if (function_exists('log_activity')) {
            log_activity('[AI] ' . $text, $this->staff_id);
        }
    }

    // ---------------------------------------------------------------------------------
    // General tools
    // ---------------------------------------------------------------------------------

    protected function tool_whoami($args)
    {
        $staff = $this->CI->db->select('staffid, firstname, lastname, email, admin, role, is_not_staff')
            ->where('staffid', $this->staff_id)->get($this->t('staff'))->row_array();
        $role = null;
        if (!empty($staff['role'])) {
            $r = $this->CI->db->select('name')->where('roleid', $staff['role'])->get($this->t('roles'))->row();
            $role = $r ? $r->name : null;
        }

        $features = ['customers', 'leads', 'tasks', 'projects', 'invoices', 'payments', 'estimates', 'proposals', 'contracts', 'expenses', 'credit_notes', 'items', 'staff', 'reports'];
        $permissions = [];
        foreach ($features as $feature) {
            $caps = [];
            foreach (['view', 'view_own', 'create', 'edit', 'delete'] as $cap) {
                if ($this->can($cap, $feature)) {
                    $caps[] = $cap;
                }
            }
            $permissions[$feature] = $caps;
        }

        return [
            'staff_id'           => (int) $staff['staffid'],
            'name'               => trim($staff['firstname'] . ' ' . $staff['lastname']),
            'email'              => $staff['email'],
            'is_admin'           => (bool) $staff['admin'],
            'role'               => $role,
            'is_staff_member'    => (int) $staff['is_not_staff'] === 0,
            'permissions'        => $permissions,
            'available_tools'    => array_keys($this->available()),
            'today'              => date('Y-m-d'),
            'company'            => get_option('companyname'),
            'base_currency'      => function_exists('get_base_currency') && get_base_currency() ? get_base_currency()->name : null,
        ];
    }

    protected function tool_search($args)
    {
        $q = $this->str($args, 'query');
        $db = $this->CI->db;
        $out = [];

        $db->select('userid as id, company')->from($this->t('clients'))->like('company', $q)->limit(5);
        $this->apply_scope($this->customer_scope());
        $out['customers'] = $db->get()->result_array();

        $db->select('c.id, c.userid as customer_id, c.firstname, c.lastname, c.email, ' . $this->t('clients') . '.company')
            ->from($this->t('contacts') . ' c')
            ->join($this->t('clients'), $this->t('clients') . '.userid = c.userid', 'inner')
            ->group_start()->like('c.firstname', $q)->or_like('c.lastname', $q)->or_like('c.email', $q)->group_end()
            ->limit(5);
        $this->apply_scope($this->customer_scope());
        $out['contacts'] = $db->get()->result_array();

        if ($this->can('view', 'leads') || $this->is_staff_member()) {
            $db->select('id, name, company, email, status')->from($this->t('leads'))
                ->group_start()->like('name', $q)->or_like('company', $q)->or_like('email', $q)->or_like('phonenumber', $q)->group_end()->limit(5);
            $this->apply_scope($this->lead_scope());
            $out['leads'] = $db->get()->result_array();
        }

        $db->select('id, name, clientid as customer_id, status')->from($this->t('projects'))->like('name', $q)->limit(5);
        $this->apply_scope($this->project_scope());
        $out['projects'] = $db->get()->result_array();

        $db->select('id, name, status, duedate')->from($this->t('tasks'))->like('name', $q)->limit(5);
        $this->apply_scope($this->task_scope());
        $out['tasks'] = $db->get()->result_array();

        if ($this->sales_access('invoices', 'allow_staff_view_invoices_assigned')) {
            $num = preg_replace('/\D/', '', $q);
            if ($num !== '') {
                $db->select('id, number, prefix, clientid as customer_id, total, status')->from($this->t('invoices'))->where('number', (int) $num)->limit(5);
                $this->apply_scope($this->sales_scope('invoices', 'invoices', 'sale_agent', 'allow_staff_view_invoices_assigned'));
                $rows = $db->get()->result_array();
                foreach ($rows as &$row) {
                    $row['formatted_number'] = $this->fmt_number('invoice', $row);
                }
                $out['invoices'] = $rows;
            }
        }

        if ($this->tickets_access()) {
            $db->select('ticketid as id, subject, status, userid as customer_id')->from($this->t('tickets'))->like('subject', $q)->limit(5);
            $this->apply_scope($this->ticket_scope());
            $out['tickets'] = $db->get()->result_array();
        }

        return $out;
    }

    protected function tool_reference_data($args)
    {
        $db = $this->CI->db;
        $data = [
            'staff' => $db->select('staffid as id, CONCAT(firstname, " ", lastname) as name', false)
                ->where('active', 1)->order_by('firstname')->get($this->t('staff'))->result_array(),
            'task_statuses'   => $this->task_statuses,
            'task_priorities' => $this->task_priorities,
        ];
        if ($this->can('view', 'leads') || $this->is_staff_member()) {
            $data['lead_statuses'] = $db->select('id, name')->order_by('statusorder')->get($this->t('leads_status'))->result_array();
            $data['lead_sources'] = $db->select('id, name')->order_by('name')->get($this->t('leads_sources'))->result_array();
            $data['default_lead_status'] = get_option('leads_default_status');
            $data['default_lead_source'] = get_option('leads_default_source');
        }
        if ($this->tickets_access()) {
            $data['ticket_departments'] = $db->select('departmentid as id, name')->get($this->t('departments'))->result_array();
            $data['ticket_priorities'] = $db->select('priorityid as id, name')->get($this->t('tickets_priorities'))->result_array();
            $data['ticket_statuses'] = $db->select('ticketstatusid as id, name')->order_by('statusorder')->get($this->t('tickets_status'))->result_array();
        }
        $data['taxes'] = $db->select('id, name, taxrate')->get($this->t('taxes'))->result_array();
        $data['payment_modes'] = $db->select('id, name')->where('active', 1)->get($this->t('payment_modes'))->result_array();
        $data['expense_categories'] = $db->select('id, name')->get($this->t('expenses_categories'))->result_array();
        $data['currencies'] = $db->select('id, name, symbol, isdefault')->get($this->t('currencies'))->result_array();

        return $data;
    }

    protected function tool_dashboard($args)
    {
        $db = $this->CI->db;
        $today = date('Y-m-d');
        $assigned = $this->t('tasks') . '.id IN (SELECT taskid FROM ' . $this->t('task_assigned') . ' WHERE staffid=' . $this->staff_id . ')';

        $out = [];
        $out['my_open_tasks'] = $db->where($assigned, null, false)->where('status !=', 5)->count_all_results($this->t('tasks'));
        $out['my_overdue_tasks'] = $db->select('id, name, duedate, priority')->where($assigned, null, false)
            ->where('status !=', 5)->where('duedate <', $today)->where('duedate IS NOT NULL', null, false)
            ->order_by('duedate')->limit(10)->get($this->t('tasks'))->result_array();
        $out['my_tasks_due_today'] = $db->select('id, name, priority')->where($assigned, null, false)
            ->where('status !=', 5)->where('duedate', $today)->get($this->t('tasks'))->result_array();

        if ($this->sales_access('invoices', 'allow_staff_view_invoices_assigned')) {
            $db->select('id, number, prefix, clientid, total, duedate')->where('status', 4)->order_by('duedate')->limit(10);
            $this->apply_scope($this->sales_scope('invoices', 'invoices', 'sale_agent', 'allow_staff_view_invoices_assigned'));
            $rows = $db->get($this->t('invoices'))->result_array();
            $names = $this->customer_names(array_column($rows, 'clientid'));
            foreach ($rows as &$row) {
                $row['formatted_number'] = $this->fmt_number('invoice', $row);
                $row['customer'] = isset($names[$row['clientid']]) ? $names[$row['clientid']] : null;
            }
            $out['overdue_invoices'] = $rows;
        }
        if ($this->can('view', 'leads') || $this->is_staff_member()) {
            $db->where('lost', 0)->where('junk', 0)->where('date_converted IS NULL', null, false)->where('assigned', $this->staff_id);
            $out['my_open_leads'] = $db->count_all_results($this->t('leads'));
        }
        if ($this->tickets_access()) {
            $db->where('status', 1);
            $this->apply_scope($this->ticket_scope());
            $out['open_tickets'] = $db->count_all_results($this->t('tickets'));
        }

        return $out;
    }

    // ---------------------------------------------------------------------------------
    // Customers
    // ---------------------------------------------------------------------------------

    protected function tool_list_customers($args)
    {
        list($limit, $offset) = $this->paging($args);
        $db = $this->CI->db;
        $c = $this->t('clients');
        $db->select($c . '.userid as id, company, vat, phonenumber, city, state, website, active, datecreated,'
            . ' (SELECT email FROM ' . $this->t('contacts') . ' WHERE userid=' . $c . '.userid AND is_primary=1 LIMIT 1) as primary_email', false)
            ->from($c);
        if ($q = $this->str($args, 'search')) {
            $db->group_start()->like('company', $q)->or_like('vat', $q)->or_like('phonenumber', $q)->group_end();
        }
        $active = $this->bool($args, 'active');
        if ($active !== null) {
            $db->where('active', $active ? 1 : 0);
        }
        $this->apply_scope($this->customer_scope());
        $rows = $db->order_by('company')->limit($limit + 1, $offset)->get()->result_array();

        return $this->page($rows, $limit, $offset);
    }

    protected function tool_get_customer($args)
    {
        $id = $this->int($args, 'customer_id');
        if (!$this->customer_accessible($id)) {
            $this->deny('customer #' . $id);
        }
        $db = $this->CI->db;
        $client = $db->where('userid', $id)->get($this->t('clients'))->row_array();
        $client['country_name'] = $this->country_name($client['country']);
        unset($client['registration_confirmed']);

        $client['contacts'] = $db->select('id, firstname, lastname, email, phonenumber, title, is_primary, active')
            ->where('userid', $id)->get($this->t('contacts'))->result_array();
        $client['notes'] = $db->select('id, description, dateadded, addedfrom')
            ->where('rel_type', 'customer')->where('rel_id', $id)->order_by('dateadded', 'desc')->limit(10)
            ->get($this->t('notes'))->result_array();

        $db->select('id, name, status, deadline')->where('clientid', $id);
        $this->apply_scope($this->project_scope());
        $client['projects'] = $db->get($this->t('projects'))->result_array();

        if ($this->sales_access('invoices', 'allow_staff_view_invoices_assigned')) {
            $db->select('id, number, prefix, date, duedate, total, status')->where('clientid', $id)->order_by('date', 'desc')->limit(10);
            $this->apply_scope($this->sales_scope('invoices', 'invoices', 'sale_agent', 'allow_staff_view_invoices_assigned'));
            $invoices = $db->get($this->t('invoices'))->result_array();
            foreach ($invoices as &$inv) {
                $inv['formatted_number'] = $this->fmt_number('invoice', $inv);
                $inv['status_name'] = isset($this->invoice_statuses[$inv['status']]) ? $this->invoice_statuses[$inv['status']] : null;
            }
            $client['recent_invoices'] = $invoices;
        }

        return $client;
    }

    private function customer_payload($args)
    {
        $data = [];
        foreach (['company', 'vat', 'phonenumber', 'website', 'address', 'city', 'state', 'zip'] as $field) {
            if (array_key_exists($field, $args)) {
                $data[$field] = (string) $args[$field];
            }
        }
        if (!empty($args['country'])) {
            $data['country'] = $this->country_id($args['country']);
        }

        return $data;
    }

    protected function tool_create_customer($args)
    {
        $this->CI->load->model('clients_model');
        $data = $this->customer_payload($args);
        $data['billing_street'] = isset($data['address']) ? $data['address'] : '';
        $data['billing_city'] = isset($data['city']) ? $data['city'] : '';
        $data['billing_state'] = isset($data['state']) ? $data['state'] : '';
        $data['billing_zip'] = isset($data['zip']) ? $data['zip'] : '';
        $data['billing_country'] = isset($data['country']) ? $data['country'] : 0;

        $id = $this->CI->clients_model->add($data);
        if (!$id) {
            throw new Ai_mcp_tool_exception('Customer could not be created.');
        }

        // Like the Perfex UI: a creator without global "view" becomes customer admin so they can see it.
        if (!$this->can('view', 'customers')) {
            $this->CI->db->insert($this->t('customer_admins'), [
                'staff_id' => $this->staff_id, 'customer_id' => $id, 'date_assigned' => date('Y-m-d H:i:s'),
            ]);
        }

        $contact_id = null;
        if (!empty($args['contact_email']) && !empty($args['contact_firstname'])) {
            $contact_id = $this->insert_contact($id, [
                'firstname'   => $args['contact_firstname'],
                'lastname'    => $this->str($args, 'contact_lastname', ''),
                'email'       => $args['contact_email'],
                'phonenumber' => $this->str($args, 'contact_phone', ''),
                'is_primary'  => 1,
            ]);
        }

        return ['customer_id' => (int) $id, 'contact_id' => $contact_id, 'url' => admin_url('clients/client/' . $id)];
    }

    protected function tool_update_customer($args)
    {
        $id = $this->int($args, 'customer_id');
        if (!$this->customer_accessible($id)) {
            $this->deny('customer #' . $id);
        }
        $data = $this->customer_payload($args);
        if (empty($data)) {
            throw new Ai_mcp_tool_exception('No fields to update were provided.');
        }
        // Direct, whitelisted update: clients_model->update() expects the full edit form and
        // would reset fields (groups, flags) that are not posted.
        $this->CI->db->where('userid', $id)->update($this->t('clients'), $data);
        hooks()->do_action('client_updated', ['id' => $id, 'data' => $data]);
        $this->log_activity('Customer updated [ID: ' . $id . ']');

        return ['customer_id' => $id, 'updated_fields' => array_keys($data)];
    }

    protected function tool_add_contact($args)
    {
        $customer_id = $this->int($args, 'customer_id');
        if (!$this->customer_accessible($customer_id)) {
            $this->deny('customer #' . $customer_id);
        }
        $has_primary = $this->CI->db->where('userid', $customer_id)->where('is_primary', 1)->count_all_results($this->t('contacts')) > 0;
        $id = $this->insert_contact($customer_id, [
            'firstname'   => $this->str($args, 'firstname'),
            'lastname'    => $this->str($args, 'lastname'),
            'email'       => $this->str($args, 'email'),
            'phonenumber' => $this->str($args, 'phonenumber', ''),
            'title'       => $this->str($args, 'title', ''),
            'is_primary'  => $has_primary ? 0 : 1,
        ]);

        return ['contact_id' => $id, 'customer_id' => $customer_id];
    }

    private function insert_contact($customer_id, $data)
    {
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new Ai_mcp_tool_exception('Invalid email address.');
        }
        if ($this->CI->db->where('email', $data['email'])->count_all_results($this->t('contacts')) > 0) {
            throw new Ai_mcp_tool_exception('A contact with this email already exists.');
        }
        $this->CI->load->model('clients_model');
        $payload = [
            'firstname'             => $data['firstname'],
            'lastname'              => $data['lastname'],
            'email'                 => $data['email'],
            'phonenumber'           => $data['phonenumber'],
            'title'                 => isset($data['title']) ? $data['title'] : '',
            'password'              => bin2hex(random_bytes(12)),
            'donotsendwelcomeemail' => true,
        ];
        if (!empty($data['is_primary'])) {
            $payload['is_primary'] = 'on';
        }
        $id = $this->CI->clients_model->add_contact($payload, $customer_id);
        if (!$id) {
            throw new Ai_mcp_tool_exception('Contact could not be created.');
        }

        return (int) $id;
    }

    protected function tool_add_note($args)
    {
        $type = $this->str($args, 'rel_type');
        $id = $this->int($args, 'rel_id');
        if ($type === 'customer') {
            if (!$this->customer_accessible($id)) {
                $this->deny('customer #' . $id);
            }
        } elseif ($type === 'lead') {
            if (!($this->can('view', 'leads') || $this->is_staff_member()) || !$this->lead_accessible($id)) {
                $this->deny('lead #' . $id);
            }
        } else {
            throw new Ai_mcp_tool_exception('rel_type must be customer or lead.');
        }
        $this->CI->load->model('misc_model');
        $note_id = $this->CI->misc_model->add_note(['description' => $this->str($args, 'note')], $type, $id);
        if (!$note_id) {
            throw new Ai_mcp_tool_exception('Note could not be added.');
        }

        return ['note_id' => (int) $note_id];
    }

    // ---------------------------------------------------------------------------------
    // Leads
    // ---------------------------------------------------------------------------------

    protected function tool_list_leads($args)
    {
        list($limit, $offset) = $this->paging($args);
        $db = $this->CI->db;
        $l = $this->t('leads');
        $db->select($l . '.id, ' . $l . '.name, company, email, phonenumber, ' . $l . '.status as status_id, s.name as status, src.name as source, assigned, dateadded, lastcontact, lead_value, lost, junk', false)
            ->from($l)
            ->join($this->t('leads_status') . ' s', 's.id = ' . $l . '.status', 'left')
            ->join($this->t('leads_sources') . ' src', 'src.id = ' . $l . '.source', 'left');
        if ($q = $this->str($args, 'search')) {
            $db->group_start()->like($l . '.name', $q)->or_like('company', $q)->or_like('email', $q)->or_like('phonenumber', $q)->group_end();
        }
        if ($status = $this->int($args, 'status_id')) {
            $db->where($l . '.status', $status);
        }
        if ($assigned = $this->int($args, 'assigned_to')) {
            $db->where('assigned', $assigned);
        }
        if (!$this->bool($args, 'include_lost_junk')) {
            $db->where('lost', 0)->where('junk', 0);
        }
        $this->apply_scope($this->lead_scope());
        $rows = $db->order_by($l . '.dateadded', 'desc')->limit($limit + 1, $offset)->get()->result_array();

        return $this->page($rows, $limit, $offset);
    }

    protected function tool_get_lead($args)
    {
        $id = $this->int($args, 'lead_id');
        if (!$this->lead_accessible($id)) {
            $this->deny('lead #' . $id);
        }
        $db = $this->CI->db;
        $lead = $db->where('id', $id)->get($this->t('leads'))->row_array();
        unset($lead['hash'], $lead['email_integration_uid']);
        $lead['country_name'] = $this->country_name($lead['country']);
        $status = $db->select('name')->where('id', $lead['status'])->get($this->t('leads_status'))->row();
        $source = $db->select('name')->where('id', $lead['source'])->get($this->t('leads_sources'))->row();
        $lead['status_name'] = $status ? $status->name : null;
        $lead['source_name'] = $source ? $source->name : null;
        $lead['assigned_name'] = $lead['assigned'] ? ai_mcp_staff_name($lead['assigned']) : null;
        $lead['notes'] = $db->select('id, description, dateadded, addedfrom')
            ->where('rel_type', 'lead')->where('rel_id', $id)->order_by('dateadded', 'desc')->limit(15)
            ->get($this->t('notes'))->result_array();
        if ($db->table_exists($this->t('lead_activity_log'))) {
            $lead['activity'] = $db->select('description, additional_data, date, full_name')
                ->where('leadid', $id)->order_by('date', 'desc')->limit(15)
                ->get($this->t('lead_activity_log'))->result_array();
        }

        return $lead;
    }

    private function lead_payload($args)
    {
        $data = [];
        foreach (['name', 'company', 'title', 'email', 'phonenumber', 'website', 'address', 'city', 'state', 'description'] as $field) {
            if (array_key_exists($field, $args)) {
                $data[$field] = (string) $args[$field];
            }
        }
        if (isset($args['lead_value'])) {
            $data['lead_value'] = (float) $args['lead_value'];
        }
        if (!empty($args['country'])) {
            $data['country'] = $this->country_id($args['country']);
        }
        if (isset($args['status_id'])) {
            if ($this->CI->db->where('id', (int) $args['status_id'])->count_all_results($this->t('leads_status')) === 0) {
                throw new Ai_mcp_tool_exception('Unknown lead status_id.');
            }
            $data['status'] = (int) $args['status_id'];
        }
        if (isset($args['source_id'])) {
            if ($this->CI->db->where('id', (int) $args['source_id'])->count_all_results($this->t('leads_sources')) === 0) {
                throw new Ai_mcp_tool_exception('Unknown lead source_id.');
            }
            $data['source'] = (int) $args['source_id'];
        }
        if (isset($args['assigned_to'])) {
            if ((int) $args['assigned_to'] && !$this->staff_exists($args['assigned_to'])) {
                throw new Ai_mcp_tool_exception('assigned_to is not an active staff member.');
            }
            $data['assigned'] = (int) $args['assigned_to'];
        }

        return $data;
    }

    protected function tool_create_lead($args)
    {
        $data = $this->lead_payload($args);
        $data += [
            'status'     => (int) get_option('leads_default_status'),
            'source'     => (int) get_option('leads_default_source'),
            'assigned'   => 0,
        ];
        if (!$data['status'] || !$data['source']) {
            throw new Ai_mcp_tool_exception('Pass status_id and source_id (no default lead status/source is configured).');
        }
        $data['dateadded'] = date('Y-m-d H:i:s');
        $data['addedfrom'] = $this->staff_id;
        $data['hash'] = app_generate_hash();
        $data['lastcontact'] = null;
        if ($data['assigned']) {
            $data['dateassigned'] = date('Y-m-d');
        }

        $this->CI->db->insert($this->t('leads'), $data);
        $id = $this->CI->db->insert_id();
        if (!$id) {
            throw new Ai_mcp_tool_exception('Lead could not be created.');
        }

        $this->CI->load->model('leads_model');
        if (method_exists($this->CI->leads_model, 'log_lead_activity')) {
            $this->CI->leads_model->log_lead_activity($id, 'not_lead_activity_created');
        }
        if ($data['assigned'] && $data['assigned'] != $this->staff_id && method_exists($this->CI->leads_model, 'lead_assigned_member_notification')) {
            $this->CI->leads_model->lead_assigned_member_notification($id, $data['assigned']);
        }
        hooks()->do_action('lead_created', $id);
        $this->log_activity('New Lead Added [ID: ' . $id . ']');

        return ['lead_id' => (int) $id, 'url' => admin_url('leads/index/' . $id)];
    }

    protected function tool_update_lead($args)
    {
        $id = $this->int($args, 'lead_id');
        if (!$this->lead_accessible($id)) {
            $this->deny('lead #' . $id);
        }
        $lead = $this->CI->db->where('id', $id)->get($this->t('leads'))->row_array();
        $mine = (int) $lead['assigned'] === $this->staff_id || (int) $lead['addedfrom'] === $this->staff_id;
        if (!$this->can('edit', 'leads') && !($mine && !$this->feature_has_capability('leads', 'edit'))) {
            throw new Ai_mcp_tool_exception('You do not have permission to edit leads.');
        }

        $data = $this->lead_payload($args);
        if (empty($data)) {
            throw new Ai_mcp_tool_exception('No fields to update were provided.');
        }
        $status_changed = isset($data['status']) && (int) $data['status'] !== (int) $lead['status'];
        if ($status_changed) {
            $data['last_status_change'] = date('Y-m-d H:i:s');
            $data['last_lead_status'] = $lead['status'];
        }
        if (isset($data['assigned']) && (int) $data['assigned'] !== (int) $lead['assigned']) {
            $data['dateassigned'] = date('Y-m-d');
        }

        $this->CI->db->where('id', $id)->update($this->t('leads'), $data);

        $this->CI->load->model('leads_model');
        if ($status_changed && method_exists($this->CI->leads_model, 'log_lead_activity')) {
            $this->CI->leads_model->log_lead_activity($id, 'not_lead_activity_status_updated', false, serialize([get_staff_full_name($this->staff_id), $lead['status'], $data['status']]));
            hooks()->do_action('lead_status_changed', ['lead_id' => $id, 'old_status' => $lead['status'], 'new_status' => $data['status']]);
        }
        if (isset($data['dateassigned']) && $data['assigned'] && $data['assigned'] != $this->staff_id && method_exists($this->CI->leads_model, 'lead_assigned_member_notification')) {
            $this->CI->leads_model->lead_assigned_member_notification($id, $data['assigned']);
        }
        $this->log_activity('Lead Updated [ID: ' . $id . ']');

        return ['lead_id' => $id, 'updated_fields' => array_keys($data)];
    }

    protected function tool_delete_lead($args)
    {
        $id = $this->int($args, 'lead_id');
        if (!$this->lead_accessible($id)) {
            $this->deny('lead #' . $id);
        }
        $this->CI->load->model('leads_model');
        if (!$this->CI->leads_model->delete($id)) {
            throw new Ai_mcp_tool_exception('Lead could not be deleted.');
        }

        return ['deleted' => true, 'lead_id' => $id];
    }

    // ---------------------------------------------------------------------------------
    // Tasks
    // ---------------------------------------------------------------------------------

    private function task_row(&$row)
    {
        $row['status_name'] = isset($this->task_statuses[$row['status']]) ? $this->task_statuses[$row['status']] : null;
        $row['priority_name'] = isset($this->task_priorities[$row['priority']]) ? $this->task_priorities[$row['priority']] : null;
    }

    protected function tool_list_tasks($args)
    {
        list($limit, $offset) = $this->paging($args);
        $db = $this->CI->db;
        $t = $this->t('tasks');
        $db->select($t . '.id, name, status, priority, startdate, duedate, rel_type, rel_id, billable, is_public,'
            . ' (SELECT GROUP_CONCAT(CONCAT(s.firstname, " ", s.lastname) SEPARATOR ", ") FROM ' . $this->t('task_assigned') . ' ta JOIN ' . $this->t('staff') . ' s ON s.staffid = ta.staffid WHERE ta.taskid = ' . $t . '.id) as assignees', false)
            ->from($t);
        if ($q = $this->str($args, 'search')) {
            $db->like('name', $q);
        }
        if ($this->bool($args, 'mine')) {
            $db->where($t . '.id IN (SELECT taskid FROM ' . $this->t('task_assigned') . ' WHERE staffid=' . $this->staff_id . ')', null, false);
        }
        if ($status = $this->int($args, 'status')) {
            $db->where('status', $status);
        }
        if ($this->bool($args, 'overdue')) {
            $db->where('status !=', 5)->where('duedate <', date('Y-m-d'))->where('duedate IS NOT NULL', null, false);
        }
        if ($rel_type = $this->str($args, 'rel_type')) {
            $db->where('rel_type', $rel_type);
            if ($rel_id = $this->int($args, 'rel_id')) {
                $db->where('rel_id', $rel_id);
            }
        }
        $this->apply_scope($this->task_scope());
        $rows = $db->order_by('duedate IS NULL', '', false)->order_by('duedate', 'asc')->limit($limit + 1, $offset)->get()->result_array();
        foreach ($rows as &$row) {
            $this->task_row($row);
        }

        return $this->page($rows, $limit, $offset);
    }

    protected function tool_get_task($args)
    {
        $id = $this->int($args, 'task_id');
        if (!$this->task_accessible($id)) {
            $this->deny('task #' . $id);
        }
        $db = $this->CI->db;
        $task = $db->select('id, name, description, status, priority, dateadded, startdate, duedate, datefinished, addedfrom, rel_type, rel_id, is_public, billable, billed, hourly_rate, milestone')
            ->where('id', $id)->get($this->t('tasks'))->row_array();
        $this->task_row($task);
        $task['description'] = trim(strip_tags((string) $task['description']));
        $task['assignees'] = $db->select('s.staffid as id, CONCAT(s.firstname, " ", s.lastname) as name', false)
            ->from($this->t('task_assigned') . ' ta')->join($this->t('staff') . ' s', 's.staffid = ta.staffid')
            ->where('ta.taskid', $id)->get()->result_array();
        $task['followers'] = $db->select('s.staffid as id, CONCAT(s.firstname, " ", s.lastname) as name', false)
            ->from($this->t('task_followers') . ' tf')->join($this->t('staff') . ' s', 's.staffid = tf.staffid')
            ->where('tf.taskid', $id)->get()->result_array();
        $task['checklist'] = $db->select('description, finished')->where('taskid', $id)->order_by('list_order')
            ->get($this->t('task_checklist_items'))->result_array();
        $comments = $db->select('content, staffid, contact_id, dateadded')->where('taskid', $id)->order_by('dateadded', 'desc')->limit(20)
            ->get($this->t('task_comments'))->result_array();
        foreach ($comments as &$comment) {
            $comment['content'] = trim(strip_tags($comment['content']));
            $comment['author'] = $comment['staffid'] ? ai_mcp_staff_name($comment['staffid']) : 'Contact #' . $comment['contact_id'];
        }
        $task['comments'] = $comments;
        $task['url'] = admin_url('tasks/view/' . $id);

        return $task;
    }

    private function assert_related_access($rel_type, $rel_id)
    {
        if (!$rel_type) {
            return;
        }
        $ok = false;
        if ($rel_type === 'project') {
            $ok = $this->project_accessible($rel_id);
        } elseif ($rel_type === 'customer') {
            $ok = $this->customer_accessible($rel_id);
        } elseif ($rel_type === 'lead') {
            $ok = $this->lead_accessible($rel_id);
        }
        if (!$ok) {
            $this->deny($rel_type . ' #' . $rel_id);
        }
    }

    protected function tool_create_task($args)
    {
        $rel_type = $this->str($args, 'rel_type');
        $rel_id = $this->int($args, 'rel_id');
        if ($rel_type && !$rel_id) {
            throw new Ai_mcp_tool_exception('rel_id is required when rel_type is set.');
        }
        $this->assert_related_access($rel_type, $rel_id);

        $startdate = $this->date($args, 'startdate', date('Y-m-d'));
        $duedate = $this->date($args, 'duedate');
        $priority = $this->int($args, 'priority', (int) get_option('default_task_priority') ?: 2);

        $data = [
            'name'        => $this->str($args, 'name'),
            'description' => nl2br(html_escape((string) $this->str($args, 'description', ''))),
            'startdate'   => $this->to_app_date($startdate),
            'duedate'     => $this->to_app_date($duedate),
            'priority'    => max(1, min(4, $priority)),
        ];
        if ($rel_type) {
            $data['rel_type'] = $rel_type;
            $data['rel_id'] = $rel_id;
        }
        if ($this->bool($args, 'is_public')) {
            $data['is_public'] = 'on';
        }
        if ($this->bool($args, 'billable')) {
            $data['billable'] = 'on';
        }

        $this->CI->load->model('tasks_model');
        $id = $this->CI->tasks_model->add($data);
        if (!$id) {
            throw new Ai_mcp_tool_exception('Task could not be created.');
        }

        $assigned = [];
        foreach ((array) $this->arg($args, 'assignees', []) as $staff_id) {
            $staff_id = (int) $staff_id;
            if (!$staff_id || !$this->staff_exists($staff_id)) {
                continue;
            }
            if ($this->CI->db->where('taskid', $id)->where('staffid', $staff_id)->count_all_results($this->t('task_assigned')) === 0) {
                $this->CI->tasks_model->add_task_assignees(['taskid' => $id, 'assignee' => $staff_id]);
            }
            $assigned[] = $staff_id;
        }

        return ['task_id' => (int) $id, 'assignees' => $assigned, 'url' => admin_url('tasks/view/' . $id)];
    }

    private function is_task_assignee($task_id)
    {
        return $this->CI->db->where('taskid', (int) $task_id)->where('staffid', $this->staff_id)->count_all_results($this->t('task_assigned')) > 0;
    }

    protected function tool_update_task_status($args)
    {
        $id = $this->int($args, 'task_id');
        $status = $this->int($args, 'status');
        if (!isset($this->task_statuses[$status])) {
            throw new Ai_mcp_tool_exception('Invalid status.');
        }
        if (!$this->task_accessible($id)) {
            $this->deny('task #' . $id);
        }
        // Same rule as Perfex: editors and the task's assignees may change status.
        if (!$this->can('edit', 'tasks') && !$this->is_task_assignee($id)) {
            throw new Ai_mcp_tool_exception('Only task editors or assignees can change the status.');
        }
        $this->CI->load->model('tasks_model');
        $this->CI->tasks_model->mark_as($status, $id);

        return ['task_id' => $id, 'status' => $status, 'status_name' => $this->task_statuses[$status]];
    }

    protected function tool_assign_task($args)
    {
        $id = $this->int($args, 'task_id');
        $staff_id = $this->int($args, 'staff_id');
        if (!$this->task_accessible($id)) {
            $this->deny('task #' . $id);
        }
        if (!$this->staff_exists($staff_id)) {
            throw new Ai_mcp_tool_exception('staff_id is not an active staff member.');
        }
        if ($this->CI->db->where('taskid', $id)->where('staffid', $staff_id)->count_all_results($this->t('task_assigned')) > 0) {
            return ['task_id' => $id, 'staff_id' => $staff_id, 'already_assigned' => true];
        }
        $this->CI->load->model('tasks_model');
        $this->CI->tasks_model->add_task_assignees(['taskid' => $id, 'assignee' => $staff_id]);

        return ['task_id' => $id, 'staff_id' => $staff_id, 'assigned' => true];
    }

    protected function tool_add_task_comment($args)
    {
        $id = $this->int($args, 'task_id');
        if (!$this->task_accessible($id)) {
            $this->deny('task #' . $id);
        }
        $this->CI->load->model('tasks_model');
        $comment_id = $this->CI->tasks_model->add_task_comment([
            'taskid'  => $id,
            'content' => nl2br(html_escape($this->str($args, 'comment'))),
        ]);
        if (!$comment_id) {
            throw new Ai_mcp_tool_exception('Comment could not be added.');
        }

        return ['task_id' => $id, 'comment_id' => (int) $comment_id];
    }

    protected function tool_delete_task($args)
    {
        $id = $this->int($args, 'task_id');
        if (!$this->task_accessible($id)) {
            $this->deny('task #' . $id);
        }
        $this->CI->load->model('tasks_model');
        if (!$this->CI->tasks_model->delete_task($id)) {
            throw new Ai_mcp_tool_exception('Task could not be deleted.');
        }

        return ['deleted' => true, 'task_id' => $id];
    }

    // ---------------------------------------------------------------------------------
    // Projects
    // ---------------------------------------------------------------------------------

    protected function tool_list_projects($args)
    {
        list($limit, $offset) = $this->paging($args);
        $db = $this->CI->db;
        $p = $this->t('projects');
        $db->select($p . '.id, name, clientid as customer_id, status, start_date, deadline, progress, billing_type, project_cost')->from($p);
        if ($q = $this->str($args, 'search')) {
            $db->like('name', $q);
        }
        if ($status = $this->int($args, 'status')) {
            $db->where('status', $status);
        }
        if ($customer = $this->int($args, 'customer_id')) {
            $db->where('clientid', $customer);
        }
        $this->apply_scope($this->project_scope());
        $rows = $db->order_by($p . '.id', 'desc')->limit($limit + 1, $offset)->get()->result_array();
        $names = $this->customer_names(array_column($rows, 'customer_id'));
        foreach ($rows as &$row) {
            $row['customer'] = isset($names[$row['customer_id']]) ? $names[$row['customer_id']] : null;
            $row['status_name'] = isset($this->project_statuses[$row['status']]) ? $this->project_statuses[$row['status']] : null;
        }

        return $this->page($rows, $limit, $offset);
    }

    protected function tool_get_project($args)
    {
        $id = $this->int($args, 'project_id');
        if (!$this->project_accessible($id)) {
            $this->deny('project #' . $id);
        }
        $db = $this->CI->db;
        $project = $db->select('id, name, description, status, clientid as customer_id, billing_type, start_date, deadline, project_created, date_finished, progress, project_cost, project_rate_per_hour, estimated_hours')
            ->where('id', $id)->get($this->t('projects'))->row_array();
        $project['description'] = trim(strip_tags((string) $project['description']));
        $project['status_name'] = isset($this->project_statuses[$project['status']]) ? $this->project_statuses[$project['status']] : null;
        $names = $this->customer_names([$project['customer_id']]);
        $project['customer'] = isset($names[$project['customer_id']]) ? $names[$project['customer_id']] : null;
        $project['members'] = $db->select('s.staffid as id, CONCAT(s.firstname, " ", s.lastname) as name', false)
            ->from($this->t('project_members') . ' pm')->join($this->t('staff') . ' s', 's.staffid = pm.staff_id')
            ->where('pm.project_id', $id)->get()->result_array();
        $project['milestones'] = $db->select('id, name, due_date')->where('project_id', $id)->order_by('milestone_order')
            ->get($this->t('milestones'))->result_array();

        $counts = [];
        $db->select('status, COUNT(*) as total')->where('rel_type', 'project')->where('rel_id', $id)->group_by('status');
        $this->apply_scope($this->task_scope());
        foreach ($db->get($this->t('tasks'))->result_array() as $row) {
            $counts[$this->task_statuses[$row['status']] ?? $row['status']] = (int) $row['total'];
        }
        $project['visible_task_counts'] = $counts;
        $project['url'] = admin_url('projects/view/' . $id);

        return $project;
    }

    // ---------------------------------------------------------------------------------
    // Sales
    // ---------------------------------------------------------------------------------

    protected function tool_list_invoices($args)
    {
        list($limit, $offset) = $this->paging($args);
        $db = $this->CI->db;
        $i = $this->t('invoices');
        $db->select($i . '.id, number, prefix, clientid as customer_id, date, duedate, currency, subtotal, total_tax, total, status, sale_agent, project_id')->from($i);
        if ($q = $this->str($args, 'search')) {
            $num = preg_replace('/\D/', '', $q);
            $db->group_start()->where('clientid IN (SELECT userid FROM ' . $this->t('clients') . ' WHERE company LIKE ' . $db->escape('%' . $db->escape_like_str($q) . '%') . ')', null, false);
            if ($num !== '') {
                $db->or_where('number', (int) $num);
            }
            $db->group_end();
        }
        if ($status = $this->int($args, 'status')) {
            $db->where('status', $status);
        }
        if ($customer = $this->int($args, 'customer_id')) {
            $db->where('clientid', $customer);
        }
        if ($from = $this->date($args, 'date_from')) {
            $db->where('date >=', $from);
        }
        if ($to = $this->date($args, 'date_to')) {
            $db->where('date <=', $to);
        }
        $this->apply_scope($this->sales_scope('invoices', 'invoices', 'sale_agent', 'allow_staff_view_invoices_assigned'));
        $rows = $db->order_by('date', 'desc')->order_by($i . '.id', 'desc')->limit($limit + 1, $offset)->get()->result_array();

        $names = $this->customer_names(array_column($rows, 'customer_id'));
        $currencies = $this->currency_names();
        foreach ($rows as &$row) {
            $row['formatted_number'] = $this->fmt_number('invoice', $row);
            $row['customer'] = isset($names[$row['customer_id']]) ? $names[$row['customer_id']] : null;
            $row['currency'] = isset($currencies[$row['currency']]) ? $currencies[$row['currency']] : $row['currency'];
            $row['status_name'] = isset($this->invoice_statuses[$row['status']]) ? $this->invoice_statuses[$row['status']] : null;
        }

        return $this->page($rows, $limit, $offset);
    }

    private function invoice_accessible($id)
    {
        $this->CI->db->where('id', (int) $id);
        $this->apply_scope($this->sales_scope('invoices', 'invoices', 'sale_agent', 'allow_staff_view_invoices_assigned'));

        return $this->CI->db->count_all_results($this->t('invoices')) > 0;
    }

    protected function tool_get_invoice($args)
    {
        $id = $this->int($args, 'invoice_id');
        if (!$this->invoice_accessible($id)) {
            $this->deny('invoice #' . $id);
        }
        $db = $this->CI->db;
        $inv = $db->select('id, number, prefix, clientid as customer_id, date, duedate, currency, subtotal, total_tax, discount_total, adjustment, total, status, sent, sale_agent, project_id, clientnote, adminnote, terms, hash')
            ->where('id', $id)->get($this->t('invoices'))->row_array();
        $inv['formatted_number'] = $this->fmt_number('invoice', $inv);
        $inv['status_name'] = isset($this->invoice_statuses[$inv['status']]) ? $this->invoice_statuses[$inv['status']] : null;
        $currencies = $this->currency_names();
        $inv['currency'] = isset($currencies[$inv['currency']]) ? $currencies[$inv['currency']] : $inv['currency'];
        $names = $this->customer_names([$inv['customer_id']]);
        $inv['customer'] = isset($names[$inv['customer_id']]) ? $names[$inv['customer_id']] : null;
        $inv['items'] = $db->select('description, long_description, qty, unit, rate')
            ->where('rel_type', 'invoice')->where('rel_id', $id)->order_by('item_order')
            ->get($this->t('itemable'))->result_array();
        $inv['payments'] = $db->select('p.id, p.amount, p.date, p.transactionid, p.note, pm.name as payment_mode')
            ->from($this->t('invoicepaymentrecords') . ' p')
            ->join($this->t('payment_modes') . ' pm', 'pm.id = p.paymentmode', 'left')
            ->where('p.invoiceid', $id)->get()->result_array();
        $paid = array_sum(array_column($inv['payments'], 'amount'));
        $inv['amount_paid'] = round($paid, 2);
        $inv['amount_due'] = round($inv['total'] - $paid, 2);
        $inv['public_link'] = site_url('invoice/' . $id . '/' . $inv['hash']);
        $inv['url'] = admin_url('invoices/list_invoices/' . $id);
        unset($inv['hash']);

        return $inv;
    }

    protected function tool_create_invoice($args)
    {
        $customer_id = $this->int($args, 'customer_id');
        if (!$this->customer_accessible($customer_id)) {
            $this->deny('customer #' . $customer_id);
        }
        $project_id = $this->int($args, 'project_id');
        if ($project_id && !$this->project_accessible($project_id)) {
            $this->deny('project #' . $project_id);
        }
        list($newitems, $subtotal, $total_tax) = $this->build_items($this->arg($args, 'items', []));

        $client = $this->CI->db->where('userid', $customer_id)->get($this->t('clients'))->row_array();
        $currency = (int) $client['default_currency'];
        if (!$currency && function_exists('get_base_currency') && get_base_currency()) {
            $currency = (int) get_base_currency()->id;
        }
        $date = $this->date($args, 'date', date('Y-m-d'));
        $duedate = $this->date($args, 'duedate');
        if (!$duedate && (int) get_option('invoice_due_after') > 0) {
            $duedate = date('Y-m-d', strtotime($date . ' +' . (int) get_option('invoice_due_after') . ' days'));
        }
        $modes = array_column($this->CI->db->select('id')->where('active', 1)->where('selected_by_default', 1)->get($this->t('payment_modes'))->result_array(), 'id');

        $data = [
            'clientid'              => $customer_id,
            'project_id'            => $project_id ?: 0,
            'number'                => (int) get_option('next_invoice_number'),
            'date'                  => $this->to_app_date($date),
            'duedate'               => $this->to_app_date($duedate),
            'currency'              => $currency,
            'subtotal'              => round($subtotal, 2),
            'total'                 => round($subtotal + $total_tax, 2),
            'discount_percent'      => 0,
            'discount_total'        => 0,
            'discount_type'         => '',
            'adjustment'            => 0,
            'show_quantity_as'      => 1,
            'sale_agent'            => 0,
            'recurring'             => 0,
            'allowed_payment_modes' => $modes,
            'clientnote'            => $this->str($args, 'clientnote', get_option('predefined_clientnote_invoice')),
            'adminnote'             => $this->str($args, 'adminnote', ''),
            'terms'                 => get_option('predefined_terms_invoice'),
            'newitems'              => $newitems,
            'save_as_draft'         => true,
        ] + $this->billing_fields($client);

        $this->CI->load->model('invoices_model');
        $id = $this->CI->invoices_model->add($data);
        if (!$id) {
            throw new Ai_mcp_tool_exception('Invoice could not be created.');
        }

        return [
            'invoice_id'       => (int) $id,
            'formatted_number' => $this->fmt_number('invoice', ['id' => $id, 'number' => $data['number']]),
            'subtotal'         => $data['subtotal'],
            'tax'              => round($total_tax, 2),
            'total'            => $data['total'],
            'status'           => 'Draft',
            'url'              => admin_url('invoices/list_invoices/' . $id),
        ];
    }

    private function billing_fields($client)
    {
        return [
            'billing_street'  => $client['billing_street'] ?: $client['address'],
            'billing_city'    => $client['billing_city'] ?: $client['city'],
            'billing_state'   => $client['billing_state'] ?: $client['state'],
            'billing_zip'     => $client['billing_zip'] ?: $client['zip'],
            'billing_country' => $client['billing_country'] ?: $client['country'],
        ];
    }

    /**
     * Turns tool line items into Perfex's "newitems" form structure.
     * Returns [newitems, subtotal, total_tax].
     */
    private function build_items($items)
    {
        if (!is_array($items) || empty($items)) {
            throw new Ai_mcp_tool_exception('At least one item is required.');
        }

        $taxes = [];
        foreach ($this->CI->db->select('id, name, taxrate')->get($this->t('taxes'))->result_array() as $tax) {
            $taxes[$tax['id']] = $tax;
        }

        $subtotal = 0;
        $total_tax = 0;
        $newitems = [];
        $order = 1;
        foreach ($items as $item) {
            if (empty($item['description']) || !isset($item['qty'], $item['rate'])) {
                throw new Ai_mcp_tool_exception('Each item needs description, qty and rate.');
            }
            $qty = (float) $item['qty'];
            $rate = (float) $item['rate'];
            $line = $qty * $rate;
            $subtotal += $line;
            $taxnames = [];
            foreach ((array) ($item['tax_ids'] ?? []) as $tax_id) {
                if (!isset($taxes[$tax_id])) {
                    throw new Ai_mcp_tool_exception('Unknown tax id ' . $tax_id . '.');
                }
                $taxnames[] = $taxes[$tax_id]['name'] . '|' . $taxes[$tax_id]['taxrate'];
                $total_tax += $line * $taxes[$tax_id]['taxrate'] / 100;
            }
            $newitems[$order] = [
                'order'            => $order,
                'description'      => (string) $item['description'],
                'long_description' => (string) ($item['long_description'] ?? ''),
                'qty'              => $qty,
                'unit'             => (string) ($item['unit'] ?? ''),
                'rate'             => $rate,
                'taxname'          => $taxnames,
            ];
            $order++;
        }

        return [$newitems, $subtotal, $total_tax];
    }

    protected function tool_record_payment($args)
    {
        $invoice_id = $this->int($args, 'invoice_id');
        if (!$this->invoice_accessible($invoice_id)) {
            $this->deny('invoice #' . $invoice_id);
        }
        $amount = (float) $this->arg($args, 'amount');
        if ($amount <= 0) {
            throw new Ai_mcp_tool_exception('amount must be greater than zero.');
        }
        $mode = $this->str($args, 'payment_mode_id');
        if (ctype_digit($mode) && $this->CI->db->where('id', (int) $mode)->count_all_results($this->t('payment_modes')) === 0) {
            throw new Ai_mcp_tool_exception('Unknown payment_mode_id.');
        }
        $this->CI->load->model('payments_model');
        $id = $this->CI->payments_model->add([
            'invoiceid'     => $invoice_id,
            'amount'        => $amount,
            'paymentmode'   => $mode,
            'date'          => $this->to_app_date($this->date($args, 'date', date('Y-m-d'))),
            'transactionid' => $this->str($args, 'transaction_id', ''),
            'note'          => $this->str($args, 'note', ''),
        ]);
        if (!$id) {
            throw new Ai_mcp_tool_exception('Payment could not be recorded (check the amount does not exceed the amount due).');
        }

        return ['payment_id' => (int) $id, 'invoice_id' => $invoice_id, 'amount' => $amount];
    }

    protected function tool_list_estimates($args)
    {
        list($limit, $offset) = $this->paging($args);
        $db = $this->CI->db;
        $e = $this->t('estimates');
        $db->select($e . '.id, number, prefix, clientid as customer_id, date, expirydate, total, status, sale_agent')->from($e);
        if ($status = $this->int($args, 'status')) {
            $db->where('status', $status);
        }
        if ($customer = $this->int($args, 'customer_id')) {
            $db->where('clientid', $customer);
        }
        if ($q = $this->str($args, 'search')) {
            $db->where('clientid IN (SELECT userid FROM ' . $this->t('clients') . ' WHERE company LIKE ' . $db->escape('%' . $db->escape_like_str($q) . '%') . ')', null, false);
        }
        $this->apply_scope($this->sales_scope('estimates', 'estimates', 'sale_agent', 'allow_staff_view_estimates_assigned'));
        $rows = $db->order_by('date', 'desc')->limit($limit + 1, $offset)->get()->result_array();
        $names = $this->customer_names(array_column($rows, 'customer_id'));
        foreach ($rows as &$row) {
            $row['formatted_number'] = $this->fmt_number('estimate', $row);
            $row['customer'] = isset($names[$row['customer_id']]) ? $names[$row['customer_id']] : null;
            $row['status_name'] = isset($this->estimate_statuses[$row['status']]) ? $this->estimate_statuses[$row['status']] : null;
        }

        return $this->page($rows, $limit, $offset);
    }

    protected function tool_list_proposals($args)
    {
        list($limit, $offset) = $this->paging($args);
        $db = $this->CI->db;
        $p = $this->t('proposals');
        $db->select($p . '.id, subject, proposal_to, rel_type, rel_id, date, open_till, total, status, assigned')->from($p);
        if ($status = $this->int($args, 'status')) {
            $db->where('status', $status);
        }
        if ($q = $this->str($args, 'search')) {
            $db->group_start()->like('subject', $q)->or_like('proposal_to', $q)->group_end();
        }
        $this->apply_scope($this->sales_scope('proposals', 'proposals', 'assigned', 'allow_staff_view_proposals_assigned'));
        $rows = $db->order_by('date', 'desc')->limit($limit + 1, $offset)->get()->result_array();
        foreach ($rows as &$row) {
            $row['status_name'] = isset($this->proposal_statuses[$row['status']]) ? $this->proposal_statuses[$row['status']] : null;
        }

        return $this->page($rows, $limit, $offset);
    }

    protected function tool_list_contracts($args)
    {
        list($limit, $offset) = $this->paging($args);
        $db = $this->CI->db;
        $c = $this->t('contracts');
        $db->select($c . '.id, subject, client as customer_id, datestart, dateend, contract_value, signed, marked_as_signed')
            ->from($c)->where('trash', 0);
        if ($q = $this->str($args, 'search')) {
            $db->like('subject', $q);
        }
        if ($customer = $this->int($args, 'customer_id')) {
            $db->where('client', $customer);
        }
        if ($days = $this->int($args, 'expiring_days')) {
            $db->where('dateend >=', date('Y-m-d'))->where('dateend <=', date('Y-m-d', strtotime('+' . $days . ' days')));
        }
        $this->apply_scope($this->own_scope('contracts', 'contracts'));
        $rows = $db->order_by('dateend', 'asc')->limit($limit + 1, $offset)->get()->result_array();
        $names = $this->customer_names(array_column($rows, 'customer_id'));
        foreach ($rows as &$row) {
            $row['customer'] = isset($names[$row['customer_id']]) ? $names[$row['customer_id']] : null;
        }

        return $this->page($rows, $limit, $offset);
    }

    // ---------------------------------------------------------------------------------
    // Expenses
    // ---------------------------------------------------------------------------------

    protected function tool_list_expenses($args)
    {
        list($limit, $offset) = $this->paging($args);
        $db = $this->CI->db;
        $e = $this->t('expenses');
        $db->select($e . '.id, expense_name, ec.name as category, amount, ' . $e . '.date, clientid as customer_id, project_id, billable, invoiceid, reference_no, ' . $e . '.note', false)
            ->from($e)->join($this->t('expenses_categories') . ' ec', 'ec.id = ' . $e . '.category', 'left');
        if ($q = $this->str($args, 'search')) {
            $db->group_start()->like('expense_name', $q)->or_like($e . '.note', $q)->or_like('reference_no', $q)->group_end();
        }
        if ($cat = $this->int($args, 'category_id')) {
            $db->where('category', $cat);
        }
        if ($customer = $this->int($args, 'customer_id')) {
            $db->where('clientid', $customer);
        }
        if ($from = $this->date($args, 'date_from')) {
            $db->where($e . '.date >=', $from);
        }
        if ($to = $this->date($args, 'date_to')) {
            $db->where($e . '.date <=', $to);
        }
        $this->apply_scope($this->own_scope('expenses', 'expenses'));
        $rows = $db->order_by($e . '.date', 'desc')->limit($limit + 1, $offset)->get()->result_array();

        return $this->page($rows, $limit, $offset);
    }

    protected function tool_create_expense($args)
    {
        $category = $this->int($args, 'category_id');
        if ($this->CI->db->where('id', $category)->count_all_results($this->t('expenses_categories')) === 0) {
            throw new Ai_mcp_tool_exception('Unknown category_id.');
        }
        $customer_id = $this->int($args, 'customer_id');
        if ($customer_id && !$this->customer_accessible($customer_id)) {
            $this->deny('customer #' . $customer_id);
        }
        $project_id = $this->int($args, 'project_id');
        if ($project_id && !$this->project_accessible($project_id)) {
            $this->deny('project #' . $project_id);
        }
        $currency = function_exists('get_base_currency') && get_base_currency() ? get_base_currency()->id : 0;

        $data = [
            'category'     => $category,
            'amount'       => (float) $this->arg($args, 'amount'),
            'date'         => $this->to_app_date($this->date($args, 'date', date('Y-m-d'))),
            'expense_name' => $this->str($args, 'expense_name', ''),
            'note'         => $this->str($args, 'note', ''),
            'clientid'     => $customer_id ?: 0,
            'project_id'   => $project_id ?: 0,
            'paymentmode'  => $this->str($args, 'payment_mode_id', ''),
            'reference_no' => $this->str($args, 'reference_no', ''),
            'currency'     => $currency,
            'tax'          => 0,
            'tax2'         => 0,
        ];
        if ($this->bool($args, 'billable')) {
            $data['billable'] = 'on';
        }

        $this->CI->load->model('expenses_model');
        $id = $this->CI->expenses_model->add($data);
        if (!$id) {
            throw new Ai_mcp_tool_exception('Expense could not be created.');
        }

        return ['expense_id' => (int) $id, 'url' => admin_url('expenses/list_expenses/' . $id)];
    }

    // ---------------------------------------------------------------------------------
    // Tickets
    // ---------------------------------------------------------------------------------

    protected function tool_list_tickets($args)
    {
        list($limit, $offset) = $this->paging($args);
        $db = $this->CI->db;
        $t = $this->t('tickets');
        $db->select($t . '.ticketid as id, subject, ' . $t . '.userid as customer_id, contactid, ' . $t . '.status as status_id, ts.name as status, d.name as department, tp.name as priority, assigned, ' . $t . '.date, lastreply', false)
            ->from($t)
            ->join($this->t('tickets_status') . ' ts', 'ts.ticketstatusid = ' . $t . '.status', 'left')
            ->join($this->t('departments') . ' d', 'd.departmentid = ' . $t . '.department', 'left')
            ->join($this->t('tickets_priorities') . ' tp', 'tp.priorityid = ' . $t . '.priority', 'left')
            ->where('merged_ticket_id IS NULL', null, false);
        if ($q = $this->str($args, 'search')) {
            $db->like('subject', $q);
        }
        if ($status = $this->int($args, 'status_id')) {
            $db->where($t . '.status', $status);
        }
        if ($dept = $this->int($args, 'department_id')) {
            $db->where($t . '.department', $dept);
        }
        if ($customer = $this->int($args, 'customer_id')) {
            $db->where($t . '.userid', $customer);
        }
        if ($this->bool($args, 'mine')) {
            $db->where('assigned', $this->staff_id);
        }
        $this->apply_scope($this->ticket_scope());
        $rows = $db->order_by($t . '.lastreply IS NULL', '', false)->order_by($t . '.date', 'desc')->limit($limit + 1, $offset)->get()->result_array();

        return $this->page($rows, $limit, $offset);
    }

    protected function tool_get_ticket($args)
    {
        $id = $this->int($args, 'ticket_id');
        if (!$this->ticket_accessible($id)) {
            $this->deny('ticket #' . $id);
        }
        $db = $this->CI->db;
        $ticket = $db->select('ticketid as id, subject, message, userid as customer_id, contactid, email, name, department, priority, status, assigned, project_id, date, lastreply')
            ->where('ticketid', $id)->get($this->t('tickets'))->row_array();
        $ticket['message'] = trim(strip_tags((string) $ticket['message']));
        $replies = $db->select('admin, contactid, name, message, date')->where('ticketid', $id)->order_by('date', 'asc')
            ->get($this->t('ticket_replies'))->result_array();
        foreach ($replies as &$reply) {
            $reply['message'] = trim(strip_tags((string) $reply['message']));
            $reply['from'] = $reply['admin'] ? 'staff: ' . ai_mcp_staff_name($reply['admin']) : 'customer: ' . $reply['name'];
            unset($reply['admin'], $reply['name']);
        }
        $ticket['replies'] = $replies;
        $ticket['url'] = admin_url('tickets/ticket/' . $id);

        return $ticket;
    }

    protected function tool_create_ticket($args)
    {
        $contact = $this->CI->db->select('id, userid')->where('id', $this->int($args, 'contact_id'))->get($this->t('contacts'))->row();
        if (!$contact) {
            throw new Ai_mcp_tool_exception('Unknown contact_id.');
        }
        $department = $this->int($args, 'department_id');
        if ($this->CI->db->where('departmentid', $department)->count_all_results($this->t('departments')) === 0) {
            throw new Ai_mcp_tool_exception('Unknown department_id.');
        }
        if (!$this->is_admin() && get_option('staff_access_only_assigned_departments') == '1'
            && $this->CI->db->where('staffid', $this->staff_id)->where('departmentid', $department)->count_all_results($this->t('staff_departments')) === 0) {
            throw new Ai_mcp_tool_exception('You can only open tickets in your own departments.');
        }
        $project_id = $this->int($args, 'project_id');
        if ($project_id && !$this->project_accessible($project_id)) {
            $this->deny('project #' . $project_id);
        }
        $assigned = $this->int($args, 'assigned_to', 0);
        if ($assigned && !$this->staff_exists($assigned)) {
            throw new Ai_mcp_tool_exception('assigned_to is not an active staff member.');
        }

        $this->CI->load->model('tickets_model');
        $id = $this->CI->tickets_model->add([
            'contactid'  => $contact->id,
            'userid'     => $contact->userid,
            'subject'    => $this->str($args, 'subject'),
            'message'    => nl2br(html_escape($this->str($args, 'message'))),
            'department' => $department,
            'priority'   => $this->int($args, 'priority_id', (int) get_option('default_ticket_priority') ?: 2),
            'assigned'   => $assigned,
            'project_id' => $project_id ?: 0,
        ], $this->staff_id);
        if (!$id) {
            throw new Ai_mcp_tool_exception('Ticket could not be created.');
        }

        return ['ticket_id' => (int) $id, 'url' => admin_url('tickets/ticket/' . $id)];
    }

    protected function tool_reply_ticket($args)
    {
        $id = $this->int($args, 'ticket_id');
        if (!$this->ticket_accessible($id)) {
            $this->deny('ticket #' . $id);
        }
        $data = ['message' => nl2br(html_escape($this->str($args, 'message')))];
        if ($status = $this->int($args, 'status_id')) {
            if ($this->CI->db->where('ticketstatusid', $status)->count_all_results($this->t('tickets_status')) === 0) {
                throw new Ai_mcp_tool_exception('Unknown status_id.');
            }
            $data['status'] = $status;
        }
        $this->CI->load->model('tickets_model');
        $reply_id = $this->CI->tickets_model->add_reply($data, $id, $this->staff_id);
        if (!$reply_id) {
            throw new Ai_mcp_tool_exception('Reply could not be posted.');
        }

        return ['ticket_id' => $id, 'reply_id' => (int) $reply_id];
    }

    protected function tool_update_ticket($args)
    {
        $id = $this->int($args, 'ticket_id');
        if (!$this->ticket_accessible($id)) {
            $this->deny('ticket #' . $id);
        }
        $db = $this->CI->db;
        $data = [];
        if (isset($args['priority_id'])) {
            if ($db->where('priorityid', (int) $args['priority_id'])->count_all_results($this->t('tickets_priorities')) === 0) {
                throw new Ai_mcp_tool_exception('Unknown priority_id.');
            }
            $data['priority'] = (int) $args['priority_id'];
        }
        if (isset($args['department_id'])) {
            $dept = (int) $args['department_id'];
            if ($db->where('departmentid', $dept)->count_all_results($this->t('departments')) === 0) {
                throw new Ai_mcp_tool_exception('Unknown department_id.');
            }
            if (!$this->is_admin() && get_option('staff_access_only_assigned_departments') == '1'
                && $db->where('staffid', $this->staff_id)->where('departmentid', $dept)->count_all_results($this->t('staff_departments')) === 0) {
                throw new Ai_mcp_tool_exception('You can only move tickets to your own departments.');
            }
            $data['department'] = $dept;
        }
        if (isset($args['assigned_to'])) {
            $assigned = (int) $args['assigned_to'];
            if ($assigned && !$this->staff_exists($assigned)) {
                throw new Ai_mcp_tool_exception('assigned_to is not an active staff member.');
            }
            $data['assigned'] = $assigned;
        }
        $status = $this->int($args, 'status_id');
        if ($status && $db->where('ticketstatusid', $status)->count_all_results($this->t('tickets_status')) === 0) {
            throw new Ai_mcp_tool_exception('Unknown status_id.');
        }
        if (empty($data) && !$status) {
            throw new Ai_mcp_tool_exception('Nothing to update.');
        }
        if (!empty($data)) {
            $db->where('ticketid', $id)->update($this->t('tickets'), $data);
        }
        if ($status) {
            $this->CI->load->model('tickets_model');
            if (method_exists($this->CI->tickets_model, 'change_ticket_status')) {
                $this->CI->tickets_model->change_ticket_status($id, $status);
            } else {
                $db->where('ticketid', $id)->update($this->t('tickets'), ['status' => $status]);
            }
        }
        $this->log_activity('Ticket updated [ID: ' . $id . ']');

        return ['ticket_id' => $id, 'updated' => array_keys($data + ($status ? ['status' => $status] : []))];
    }

    protected function tool_search_kb($args)
    {
        $q = $this->str($args, 'query');
        $rows = $this->CI->db->select('articleid as id, subject, description')
            ->where('active', 1)
            ->group_start()->like('subject', $q)->or_like('description', $q)->group_end()
            ->limit(10)->get($this->t('knowledge_base'))->result_array();
        foreach ($rows as &$row) {
            $row['excerpt'] = mb_strimwidth(trim(preg_replace('/\s+/', ' ', strip_tags((string) $row['description']))), 0, 600, '…');
            unset($row['description']);
        }

        return ['articles' => $rows];
    }

    // ---------------------------------------------------------------------------------
    // Projects (write)
    // ---------------------------------------------------------------------------------

    private function valid_staff_ids($ids)
    {
        $out = [];
        foreach ((array) $ids as $id) {
            if ((int) $id && $this->staff_exists($id)) {
                $out[] = (int) $id;
            }
        }

        return array_values(array_unique($out));
    }

    protected function tool_create_project($args)
    {
        $customer_id = $this->int($args, 'customer_id');
        if (!$this->customer_accessible($customer_id)) {
            $this->deny('customer #' . $customer_id);
        }
        $billing_type = $this->int($args, 'billing_type', 1);
        if (!in_array($billing_type, [1, 2, 3], true)) {
            throw new Ai_mcp_tool_exception('billing_type must be 1, 2 or 3.');
        }
        $members = $this->valid_staff_ids($this->arg($args, 'member_ids', []));
        // A creator without "view all projects" must be a member, or they could not see it.
        if (!$this->can('view', 'projects') && !in_array($this->staff_id, $members, true)) {
            $members[] = $this->staff_id;
        }

        $data = [
            'name'                  => $this->str($args, 'name'),
            'clientid'              => $customer_id,
            'billing_type'          => $billing_type,
            'status'                => 2,
            'start_date'            => $this->to_app_date($this->date($args, 'start_date', date('Y-m-d'))),
            'project_cost'          => (float) $this->arg($args, 'project_cost', 0),
            'project_rate_per_hour' => (float) $this->arg($args, 'rate_per_hour', 0),
            'description'           => nl2br(html_escape((string) $this->str($args, 'description', ''))),
            'project_members'       => $members,
        ];
        if ($deadline = $this->date($args, 'deadline')) {
            $data['deadline'] = $this->to_app_date($deadline);
        }
        if (function_exists('get_project_tabs_admin')) {
            $data['settings'] = ['available_features' => array_keys(get_project_tabs_admin())];
        }

        $this->CI->load->model('projects_model');
        $id = $this->CI->projects_model->add($data);
        if (!$id) {
            throw new Ai_mcp_tool_exception('Project could not be created.');
        }

        return ['project_id' => (int) $id, 'members' => $members, 'url' => admin_url('projects/view/' . $id)];
    }

    protected function tool_update_project($args)
    {
        $id = $this->int($args, 'project_id');
        if (!$this->project_accessible($id)) {
            $this->deny('project #' . $id);
        }
        $data = [];
        if (isset($args['name'])) {
            $data['name'] = $this->str($args, 'name');
        }
        if (isset($args['description'])) {
            $data['description'] = nl2br(html_escape((string) $args['description']));
        }
        if (isset($args['status'])) {
            $status = (int) $args['status'];
            if (!isset($this->project_statuses[$status])) {
                throw new Ai_mcp_tool_exception('Invalid status.');
            }
            $data['status'] = $status;
            $data['date_finished'] = $status === 5 ? date('Y-m-d H:i:s') : null;
        }
        if ($d = $this->date($args, 'start_date')) {
            $data['start_date'] = $d;
        }
        if ($d = $this->date($args, 'deadline')) {
            $data['deadline'] = $d;
        }
        if (isset($args['progress'])) {
            $data['progress'] = max(0, min(100, (int) $args['progress']));
            $data['progress_from_tasks'] = 0;
        }
        if (isset($args['project_cost'])) {
            $data['project_cost'] = (float) $args['project_cost'];
        }
        if (empty($data)) {
            throw new Ai_mcp_tool_exception('No fields to update were provided.');
        }
        $this->CI->db->where('id', $id)->update($this->t('projects'), $data);
        hooks()->do_action('after_update_project', $id);
        $this->log_activity('Project updated [ID: ' . $id . ']');

        return ['project_id' => $id, 'updated_fields' => array_keys($data)];
    }

    protected function tool_add_project_member($args)
    {
        $id = $this->int($args, 'project_id');
        $staff_id = $this->int($args, 'staff_id');
        if (!$this->project_accessible($id)) {
            $this->deny('project #' . $id);
        }
        if (!$this->staff_exists($staff_id)) {
            throw new Ai_mcp_tool_exception('staff_id is not an active staff member.');
        }
        $db = $this->CI->db;
        if ($db->where('project_id', $id)->where('staff_id', $staff_id)->count_all_results($this->t('project_members')) > 0) {
            return ['project_id' => $id, 'staff_id' => $staff_id, 'already_member' => true];
        }
        $db->insert($this->t('project_members'), ['project_id' => $id, 'staff_id' => $staff_id]);
        $this->log_activity('Project member added [Project ID: ' . $id . ', Staff ID: ' . $staff_id . ']');

        return ['project_id' => $id, 'staff_id' => $staff_id, 'added' => true];
    }

    protected function tool_add_milestone($args)
    {
        $id = $this->int($args, 'project_id');
        if (!$this->project_accessible($id)) {
            $this->deny('project #' . $id);
        }
        $db = $this->CI->db;
        $order = (int) $db->where('project_id', $id)->count_all_results($this->t('milestones')) + 1;
        $db->insert($this->t('milestones'), [
            'name'                            => $this->str($args, 'name'),
            'description'                     => (string) $this->str($args, 'description', ''),
            'description_visible_to_customer' => 0,
            'due_date'                        => $this->date($args, 'due_date'),
            'project_id'                      => $id,
            'milestone_order'                 => $order,
            'datecreated'                     => date('Y-m-d'),
        ]);
        $milestone_id = $db->insert_id();
        if (!$milestone_id) {
            throw new Ai_mcp_tool_exception('Milestone could not be added.');
        }

        return ['milestone_id' => (int) $milestone_id, 'project_id' => $id];
    }

    // ---------------------------------------------------------------------------------
    // Tasks (more)
    // ---------------------------------------------------------------------------------

    private function can_work_on_task($task_id)
    {
        if ($this->can('edit', 'tasks') || $this->is_task_assignee($task_id)) {
            return true;
        }

        return $this->CI->db->where('id', (int) $task_id)->where('addedfrom', $this->staff_id)->where('is_added_from_contact', 0)
            ->count_all_results($this->t('tasks')) > 0;
    }

    protected function tool_update_task($args)
    {
        $id = $this->int($args, 'task_id');
        if (!$this->task_accessible($id)) {
            $this->deny('task #' . $id);
        }
        $data = [];
        if (isset($args['name'])) {
            $data['name'] = $this->str($args, 'name');
        }
        if (isset($args['description'])) {
            $data['description'] = nl2br(html_escape((string) $args['description']));
        }
        if ($d = $this->date($args, 'startdate')) {
            $data['startdate'] = $d;
        }
        if ($d = $this->date($args, 'duedate')) {
            $data['duedate'] = $d;
        }
        if (isset($args['priority'])) {
            $data['priority'] = max(1, min(4, (int) $args['priority']));
        }
        if (empty($data)) {
            throw new Ai_mcp_tool_exception('No fields to update were provided.');
        }
        $this->CI->db->where('id', $id)->update($this->t('tasks'), $data);
        hooks()->do_action('after_update_task', $id);
        $this->log_activity('Task updated [ID: ' . $id . ']');

        return ['task_id' => $id, 'updated_fields' => array_keys($data)];
    }

    protected function tool_add_checklist_item($args)
    {
        $id = $this->int($args, 'task_id');
        if (!$this->task_accessible($id)) {
            $this->deny('task #' . $id);
        }
        if (!$this->can_work_on_task($id)) {
            throw new Ai_mcp_tool_exception('Only task editors, assignees or the creator can edit the checklist.');
        }
        $db = $this->CI->db;
        $order = (int) $db->where('taskid', $id)->count_all_results($this->t('task_checklist_items')) + 1;
        $db->insert($this->t('task_checklist_items'), [
            'taskid'      => $id,
            'description' => $this->str($args, 'text'),
            'finished'    => 0,
            'dateadded'   => date('Y-m-d H:i:s'),
            'addedfrom'   => $this->staff_id,
            'list_order'  => $order,
        ]);

        return ['task_id' => $id, 'checklist_item_id' => (int) $db->insert_id()];
    }

    protected function tool_add_task_follower($args)
    {
        $id = $this->int($args, 'task_id');
        $staff_id = $this->int($args, 'staff_id');
        if (!$this->task_accessible($id)) {
            $this->deny('task #' . $id);
        }
        if (!$this->staff_exists($staff_id)) {
            throw new Ai_mcp_tool_exception('staff_id is not an active staff member.');
        }
        if ($this->CI->db->where('taskid', $id)->where('staffid', $staff_id)->count_all_results($this->t('task_followers')) > 0) {
            return ['task_id' => $id, 'staff_id' => $staff_id, 'already_following' => true];
        }
        $this->CI->load->model('tasks_model');
        $this->CI->tasks_model->add_task_followers(['taskid' => $id, 'follower' => $staff_id]);

        return ['task_id' => $id, 'staff_id' => $staff_id, 'added' => true];
    }

    protected function tool_log_time($args)
    {
        $id = $this->int($args, 'task_id');
        if (!$this->task_accessible($id)) {
            $this->deny('task #' . $id);
        }
        if (!$this->can('edit', 'tasks') && !$this->is_task_assignee($id)) {
            throw new Ai_mcp_tool_exception('Only task assignees or editors can log time on this task.');
        }
        $hours = (float) $this->arg($args, 'hours');
        if ($hours <= 0 || $hours > 24) {
            throw new Ai_mcp_tool_exception('hours must be between 0 and 24.');
        }
        $date = $this->date($args, 'date', date('Y-m-d'));
        $end = $date === date('Y-m-d') ? time() : strtotime($date . ' 18:00:00');
        $start = $end - (int) round($hours * 3600);

        $task = $this->CI->db->select('hourly_rate')->where('id', $id)->get($this->t('tasks'))->row();
        $this->CI->db->insert($this->t('taskstimers'), [
            'task_id'     => $id,
            'start_time'  => $start,
            'end_time'    => $end,
            'staff_id'    => $this->staff_id,
            'hourly_rate' => $task ? $task->hourly_rate : 0,
            'note'        => (string) $this->str($args, 'note', ''),
        ]);

        return ['task_id' => $id, 'timesheet_id' => (int) $this->CI->db->insert_id(), 'hours' => $hours, 'date' => $date];
    }

    // ---------------------------------------------------------------------------------
    // Leads (more)
    // ---------------------------------------------------------------------------------

    protected function tool_convert_lead($args)
    {
        $id = $this->int($args, 'lead_id');
        if (!$this->lead_accessible($id)) {
            $this->deny('lead #' . $id);
        }
        $lead = $this->CI->db->where('id', $id)->get($this->t('leads'))->row_array();
        if (!empty($lead['date_converted']) || (int) $lead['client_id'] > 0) {
            throw new Ai_mcp_tool_exception('This lead is already converted (customer #' . $lead['client_id'] . ').');
        }
        if (empty($lead['email'])) {
            throw new Ai_mcp_tool_exception('The lead has no email address; add one with update_lead first (needed for the contact).');
        }

        $parts = preg_split('/\s+/', trim($lead['name']), 2);
        $created = $this->tool_create_customer([
            'company'           => $lead['company'] ?: $lead['name'],
            'phonenumber'       => (string) $lead['phonenumber'],
            'website'           => (string) $lead['website'],
            'address'           => (string) $lead['address'],
            'city'              => (string) $lead['city'],
            'state'             => (string) $lead['state'],
            'zip'               => (string) $lead['zip'],
            'country'           => $this->country_name($lead['country']),
            'contact_firstname' => $parts[0],
            'contact_lastname'  => isset($parts[1]) ? $parts[1] : '',
            'contact_email'     => $lead['email'],
            'contact_phone'     => (string) $lead['phonenumber'],
        ]);
        $customer_id = $created['customer_id'];

        $this->CI->db->where('userid', $customer_id)->update($this->t('clients'), ['leadid' => $id]);
        $this->CI->db->where('id', $id)->update($this->t('leads'), ['date_converted' => date('Y-m-d H:i:s'), 'client_id' => $customer_id]);
        $this->CI->load->model('leads_model');
        if (method_exists($this->CI->leads_model, 'log_lead_activity')) {
            $this->CI->leads_model->log_lead_activity($id, 'not_lead_activity_converted', false, serialize([get_staff_full_name($this->staff_id)]));
        }
        hooks()->do_action('lead_converted_to_customer', ['lead_id' => $id, 'customer_id' => $customer_id]);

        return ['lead_id' => $id, 'customer_id' => $customer_id, 'contact_id' => $created['contact_id'], 'url' => $created['url']];
    }

    protected function tool_mark_lead($args)
    {
        $id = $this->int($args, 'lead_id');
        if (!$this->lead_accessible($id)) {
            $this->deny('lead #' . $id);
        }
        if (!$this->can('edit', 'leads') && $this->feature_has_capability('leads', 'edit')) {
            throw new Ai_mcp_tool_exception('You do not have permission to edit leads.');
        }
        $mark = $this->str($args, 'mark');
        $map = ['lost' => ['lost' => 1, 'junk' => 0], 'junk' => ['lost' => 0, 'junk' => 1], 'active' => ['lost' => 0, 'junk' => 0]];
        if (!isset($map[$mark])) {
            throw new Ai_mcp_tool_exception('mark must be lost, junk or active.');
        }
        $this->CI->db->where('id', $id)->update($this->t('leads'), $map[$mark]);
        $this->log_activity('Lead marked ' . $mark . ' [ID: ' . $id . ']');

        return ['lead_id' => $id, 'mark' => $mark];
    }

    // ---------------------------------------------------------------------------------
    // Sales (more)
    // ---------------------------------------------------------------------------------

    private function estimate_accessible($id)
    {
        $this->CI->db->where('id', (int) $id);
        $this->apply_scope($this->sales_scope('estimates', 'estimates', 'sale_agent', 'allow_staff_view_estimates_assigned'));

        return $this->CI->db->count_all_results($this->t('estimates')) > 0;
    }

    private function line_items($rel_type, $id)
    {
        return $this->CI->db->select('description, long_description, qty, unit, rate')
            ->where('rel_type', $rel_type)->where('rel_id', $id)->order_by('item_order')
            ->get($this->t('itemable'))->result_array();
    }

    protected function tool_get_estimate($args)
    {
        $id = $this->int($args, 'estimate_id');
        if (!$this->estimate_accessible($id)) {
            $this->deny('estimate #' . $id);
        }
        $est = $this->CI->db->select('id, number, prefix, clientid as customer_id, date, expirydate, currency, subtotal, total_tax, total, status, sale_agent, invoiceid, clientnote, adminnote')
            ->where('id', $id)->get($this->t('estimates'))->row_array();
        $est['formatted_number'] = $this->fmt_number('estimate', $est);
        $est['status_name'] = isset($this->estimate_statuses[$est['status']]) ? $this->estimate_statuses[$est['status']] : null;
        $names = $this->customer_names([$est['customer_id']]);
        $est['customer'] = isset($names[$est['customer_id']]) ? $names[$est['customer_id']] : null;
        $currencies = $this->currency_names();
        $est['currency'] = isset($currencies[$est['currency']]) ? $currencies[$est['currency']] : $est['currency'];
        $est['items'] = $this->line_items('estimate', $id);
        $est['url'] = admin_url('estimates/list_estimates/' . $id);

        return $est;
    }

    protected function tool_create_estimate($args)
    {
        $customer_id = $this->int($args, 'customer_id');
        if (!$this->customer_accessible($customer_id)) {
            $this->deny('customer #' . $customer_id);
        }
        list($newitems, $subtotal, $total_tax) = $this->build_items($this->arg($args, 'items', []));
        $client = $this->CI->db->where('userid', $customer_id)->get($this->t('clients'))->row_array();
        $currency = (int) $client['default_currency'];
        if (!$currency && function_exists('get_base_currency') && get_base_currency()) {
            $currency = (int) get_base_currency()->id;
        }
        $date = $this->date($args, 'date', date('Y-m-d'));
        $expiry = $this->date($args, 'expirydate');
        if (!$expiry && (int) get_option('estimate_due_after') > 0) {
            $expiry = date('Y-m-d', strtotime($date . ' +' . (int) get_option('estimate_due_after') . ' days'));
        }

        $data = [
            'clientid'         => $customer_id,
            'project_id'       => 0,
            'number'           => (int) get_option('next_estimate_number'),
            'date'             => $this->to_app_date($date),
            'expirydate'       => $this->to_app_date($expiry),
            'currency'         => $currency,
            'subtotal'         => round($subtotal, 2),
            'total'            => round($subtotal + $total_tax, 2),
            'discount_percent' => 0,
            'discount_total'   => 0,
            'discount_type'    => '',
            'adjustment'       => 0,
            'show_quantity_as' => 1,
            'sale_agent'       => 0,
            'status'           => 1,
            'clientnote'       => $this->str($args, 'clientnote', get_option('predefined_clientnote_estimate')),
            'terms'            => get_option('predefined_terms_estimate'),
            'newitems'         => $newitems,
        ] + $this->billing_fields($client);

        $this->CI->load->model('estimates_model');
        $id = $this->CI->estimates_model->add($data);
        if (!$id) {
            throw new Ai_mcp_tool_exception('Estimate could not be created.');
        }

        return [
            'estimate_id'      => (int) $id,
            'formatted_number' => $this->fmt_number('estimate', ['id' => $id, 'number' => $data['number']]),
            'total'            => $data['total'],
            'status'           => 'Draft',
            'url'              => admin_url('estimates/list_estimates/' . $id),
        ];
    }

    protected function tool_convert_estimate($args)
    {
        $id = $this->int($args, 'estimate_id');
        if (!$this->estimate_accessible($id)) {
            $this->deny('estimate #' . $id);
        }
        $est = $this->CI->db->select('invoiceid')->where('id', $id)->get($this->t('estimates'))->row();
        if ($est && (int) $est->invoiceid > 0) {
            throw new Ai_mcp_tool_exception('This estimate was already converted to invoice #' . $est->invoiceid . '.');
        }
        $this->CI->load->model('estimates_model');
        $invoice_id = $this->CI->estimates_model->convert_to_invoice($id, false, true);
        if (!$invoice_id) {
            throw new Ai_mcp_tool_exception('Estimate could not be converted.');
        }

        return ['estimate_id' => $id, 'invoice_id' => (int) $invoice_id, 'status' => 'Draft', 'url' => admin_url('invoices/list_invoices/' . $invoice_id)];
    }

    protected function tool_get_proposal($args)
    {
        $id = $this->int($args, 'proposal_id');
        $db = $this->CI->db;
        $db->where('id', $id);
        $this->apply_scope($this->sales_scope('proposals', 'proposals', 'assigned', 'allow_staff_view_proposals_assigned'));
        if ($db->count_all_results($this->t('proposals')) === 0) {
            $this->deny('proposal #' . $id);
        }
        $p = $db->select('id, subject, content, proposal_to, email, phone, rel_type, rel_id, date, open_till, subtotal, total_tax, total, status, assigned')
            ->where('id', $id)->get($this->t('proposals'))->row_array();
        $p['content'] = mb_strimwidth(trim(preg_replace('/\s+/', ' ', strip_tags((string) $p['content']))), 0, 4000, '…');
        $p['status_name'] = isset($this->proposal_statuses[$p['status']]) ? $this->proposal_statuses[$p['status']] : null;
        $p['items'] = $this->line_items('proposal', $id);
        $p['url'] = admin_url('proposals/list_proposals/' . $id);

        return $p;
    }

    protected function tool_get_contract($args)
    {
        $id = $this->int($args, 'contract_id');
        $db = $this->CI->db;
        $db->where('id', $id)->where('trash', 0);
        $this->apply_scope($this->own_scope('contracts', 'contracts'));
        if ($db->count_all_results($this->t('contracts')) === 0) {
            $this->deny('contract #' . $id);
        }
        $c = $db->select('id, subject, description, content, client as customer_id, datestart, dateend, contract_type, contract_value, signed, marked_as_signed, addedfrom, dateadded')
            ->where('id', $id)->get($this->t('contracts'))->row_array();
        $c['content'] = mb_strimwidth(trim(preg_replace('/\s+/', ' ', strip_tags((string) $c['content']))), 0, 4000, '…');
        $names = $this->customer_names([$c['customer_id']]);
        $c['customer'] = isset($names[$c['customer_id']]) ? $names[$c['customer_id']] : null;
        $c['url'] = admin_url('contracts/contract/' . $id);

        return $c;
    }

    protected function tool_create_contract($args)
    {
        $customer_id = $this->int($args, 'customer_id');
        if (!$this->customer_accessible($customer_id)) {
            $this->deny('customer #' . $customer_id);
        }
        $type = $this->int($args, 'contract_type_id');
        if ($type && $this->CI->db->where('id', $type)->count_all_results($this->t('contracts_types')) === 0) {
            throw new Ai_mcp_tool_exception('Unknown contract_type_id.');
        }
        $data = [
            'client'         => $customer_id,
            'subject'        => $this->str($args, 'subject'),
            'datestart'      => $this->to_app_date($this->date($args, 'start_date')),
            'contract_value' => (float) $this->arg($args, 'contract_value', 0),
            'contract_type'  => $type ?: 0,
            'description'    => (string) $this->str($args, 'description', ''),
        ];
        if ($end = $this->date($args, 'end_date')) {
            $data['dateend'] = $this->to_app_date($end);
        }
        $this->CI->load->model('contracts_model');
        $id = $this->CI->contracts_model->add($data);
        if (!$id) {
            throw new Ai_mcp_tool_exception('Contract could not be created.');
        }

        return ['contract_id' => (int) $id, 'url' => admin_url('contracts/contract/' . $id)];
    }

    protected function tool_list_payments($args)
    {
        list($limit, $offset) = $this->paging($args);
        $db = $this->CI->db;
        $i = $this->t('invoices');
        $db->select('p.id, p.invoiceid as invoice_id, p.amount, p.date, p.transactionid, pm.name as payment_mode, ' . $i . '.clientid as customer_id, ' . $i . '.number, ' . $i . '.prefix', false)
            ->from($this->t('invoicepaymentrecords') . ' p')
            ->join($i, $i . '.id = p.invoiceid', 'inner')
            ->join($this->t('payment_modes') . ' pm', 'pm.id = p.paymentmode', 'left');
        if ($customer = $this->int($args, 'customer_id')) {
            $db->where($i . '.clientid', $customer);
        }
        if ($from = $this->date($args, 'date_from')) {
            $db->where('p.date >=', $from);
        }
        if ($to = $this->date($args, 'date_to')) {
            $db->where('p.date <=', $to);
        }
        if (!$this->can('view', 'payments')) {
            // "View own" payments: only payments on invoices this staff member can see.
            $this->apply_scope($this->sales_scope('invoices', 'invoices', 'sale_agent', 'allow_staff_view_invoices_assigned') ?: '1=1');
        }
        $rows = $db->order_by('p.date', 'desc')->limit($limit + 1, $offset)->get()->result_array();
        $names = $this->customer_names(array_column($rows, 'customer_id'));
        foreach ($rows as &$row) {
            $row['invoice_number'] = $this->fmt_number('invoice', ['id' => $row['invoice_id'], 'number' => $row['number'], 'prefix' => $row['prefix']]);
            $row['customer'] = isset($names[$row['customer_id']]) ? $names[$row['customer_id']] : null;
            unset($row['number'], $row['prefix']);
        }

        return $this->page($rows, $limit, $offset);
    }

    protected function tool_list_credit_notes($args)
    {
        list($limit, $offset) = $this->paging($args);
        $db = $this->CI->db;
        $c = $this->t('creditnotes');
        $db->select($c . '.id, number, prefix, clientid as customer_id, date, total, status')->from($c);
        if ($customer = $this->int($args, 'customer_id')) {
            $db->where('clientid', $customer);
        }
        $this->apply_scope($this->own_scope('credit_notes', 'creditnotes'));
        $rows = $db->order_by('date', 'desc')->limit($limit + 1, $offset)->get()->result_array();
        $names = $this->customer_names(array_column($rows, 'customer_id'));
        $statuses = [1 => 'Open', 2 => 'Closed', 3 => 'Void'];
        foreach ($rows as &$row) {
            $row['formatted_number'] = $this->fmt_number('credit_note', $row);
            $row['customer'] = isset($names[$row['customer_id']]) ? $names[$row['customer_id']] : null;
            $row['status_name'] = isset($statuses[$row['status']]) ? $statuses[$row['status']] : null;
        }

        return $this->page($rows, $limit, $offset);
    }

    protected function tool_list_items($args)
    {
        list($limit, $offset) = $this->paging($args);
        $db = $this->CI->db;
        $db->select('id, description, long_description, rate, unit, tax, tax2')->from($this->t('items'));
        if ($q = $this->str($args, 'search')) {
            $db->group_start()->like('description', $q)->or_like('long_description', $q)->group_end();
        }
        $rows = $db->order_by('description')->limit($limit + 1, $offset)->get()->result_array();

        return $this->page($rows, $limit, $offset);
    }

    protected function tool_create_item($args)
    {
        $tax = $this->int($args, 'tax_id');
        if ($tax && $this->CI->db->where('id', $tax)->count_all_results($this->t('taxes')) === 0) {
            throw new Ai_mcp_tool_exception('Unknown tax_id.');
        }
        $this->CI->db->insert($this->t('items'), [
            'description'      => $this->str($args, 'description'),
            'long_description' => (string) $this->str($args, 'long_description', ''),
            'rate'             => (float) $this->arg($args, 'rate'),
            'unit'             => (string) $this->str($args, 'unit', ''),
            'tax'              => $tax ?: null,
            'group_id'         => 0,
        ]);
        $id = $this->CI->db->insert_id();
        if (!$id) {
            throw new Ai_mcp_tool_exception('Item could not be created.');
        }
        $this->log_activity('New Invoice Item Added [ID: ' . $id . ']');

        return ['item_id' => (int) $id];
    }

    // ---------------------------------------------------------------------------------
    // Reminders
    // ---------------------------------------------------------------------------------

    protected function tool_add_reminder($args)
    {
        $type = $this->str($args, 'rel_type');
        $rel_id = $this->int($args, 'rel_id');
        $checks = [
            'customer' => 'customer_accessible',
            'lead'     => 'lead_accessible',
            'project'  => 'project_accessible',
            'task'     => 'task_accessible',
            'invoice'  => 'invoice_accessible',
            'estimate' => 'estimate_accessible',
            'ticket'   => 'ticket_accessible',
        ];
        if (!isset($checks[$type])) {
            throw new Ai_mcp_tool_exception('Unsupported rel_type.');
        }
        if (!call_user_func([$this, $checks[$type]], $rel_id)) {
            $this->deny($type . ' #' . $rel_id);
        }
        $when = DateTime::createFromFormat('Y-m-d H:i', (string) $this->str($args, 'datetime'));
        if (!$when) {
            throw new Ai_mcp_tool_exception('datetime must be YYYY-MM-DD HH:MM.');
        }
        $staff_id = $this->int($args, 'staff_id', $this->staff_id);
        if (!$this->staff_exists($staff_id)) {
            throw new Ai_mcp_tool_exception('staff_id is not an active staff member.');
        }
        $this->CI->db->insert($this->t('reminders'), [
            'description'     => $this->str($args, 'description'),
            'date'            => $when->format('Y-m-d H:i:s'),
            'isnotified'      => 0,
            'rel_id'          => $rel_id,
            'staff'           => $staff_id,
            'rel_type'        => $type,
            'notify_by_email' => $this->bool($args, 'notify_by_email') ? 1 : 0,
            'creator'         => $this->staff_id,
        ]);

        return ['reminder_id' => (int) $this->CI->db->insert_id(), 'when' => $when->format('Y-m-d H:i')];
    }
}
