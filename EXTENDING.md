# Giving the AI access to another module

Modules such as bookkeeping, Outlook and email have their own tables and their own rules about who
can see what. The AI does **not** get raw database access to them, because raw access would
ignore permissions. Instead, each module adds a small set of AI actions. Every action:

- is only shown to staff who have that module's permission,
- disappears automatically when the module is deactivated,
- runs as the staff member, and is logged and rate limited like the built-in actions,
- in the chat, asks for approval before it writes anything (when the owner has that turned on).

## Example

Add this to the other module's main file (for example `modules/bookkeeping/bookkeeping.php`),
or to a small separate "glue" module so updates to the other module don't overwrite it:

```php
hooks()->add_filter('ai_mcp_tools', function ($tools, $ai) {

    // Read action: listed only for staff with "Bookkeeping: view" (or "view own").
    $tools['bookkeeping_list_journals'] = [
        'title'       => 'List journal entries',
        'description' => 'Lists journal entries between two dates. Dates are YYYY-MM-DD.',
        'module'      => 'bookkeeping',          // hidden when this module is inactive
        'feature'     => 'bookkeeping',          // permission feature name of the module
        'capability'  => 'view',
        'params'      => [
            'date_from' => ['type' => 'string'],
            'date_to'   => ['type' => 'string'],
        ],
        'required'    => ['date_from', 'date_to'],
        'handler'     => function ($args, $ai) {
            $CI = &get_instance();
            $CI->db->where('date >=', $args['date_from'])->where('date <=', $args['date_to']);
            if (!$ai->staff_can('view', 'bookkeeping')) {
                // only "view own": restrict to the acting staff member's records
                $CI->db->where('addedfrom', $ai->staff_id());
            }
            return $CI->db->get(db_prefix() . 'acc_journal_entries')->result_array();
        },
    ];

    // Write action: listed only for staff with "Bookkeeping: create".
    $tools['bookkeeping_add_journal'] = [
        'title'       => 'Add journal entry',
        'description' => 'Adds a journal entry.',
        'module'      => 'bookkeeping',
        'feature'     => 'bookkeeping',
        'capability'  => 'create',
        'write'       => true,
        'params'      => ['date' => ['type' => 'string'], 'amount' => ['type' => 'number'], 'description' => ['type' => 'string']],
        'required'    => ['date', 'amount'],
        'handler'     => function ($args, $ai) {
            if ($args['amount'] <= 0) {
                $ai->fail('Amount must be positive.');  // the message is shown to the AI
            }
            $CI = &get_instance();
            $CI->load->model('bookkeeping/bookkeeping_model');   // prefer the module's own model
            $id = $CI->bookkeeping_model->add_journal_entry($args);
            return ['journal_id' => $id];
        },
    ];

    return $tools;
}, 10, 2);
```

Table, model and permission names above are placeholders. Replace them with the real ones from the module.

## Rules

| Key | Meaning |
|---|---|
| `feature` + `capability` | Required. The action is listed only when the staff member has this permission. `view` also accepts `view_own`, so the handler must then restrict rows itself. An action with no `feature` and no `access` callback is **never** shown. |
| `module` | Optional. The module's folder name. The action is hidden while that module is inactive. |
| `write` | Set `true` for anything that changes data. This enables the approval card in the chat. |
| `access` | Optional callback for permission rules that `feature` + `capability` can't express. It overrides `feature` + `capability`. |
| `handler($args, $ai)` | Does the work. `$ai` provides `staff_id()`, `staff_can($cap, $feature)`, `can_access_customer($id)` and `fail($message)`. |

Prefer the module's own models over direct queries, so the module's activity logs, hooks and
notifications still run. Re-check the specific record in every write handler (for example, can
this person see this customer?).

You can send me the module when you install it, and I'll write its AI actions.
