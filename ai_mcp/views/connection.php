<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<?php
$token_value = $new_token ?: 'YOUR_TOKEN';
$url_with_token = rtrim($endpoint, '/') . '/t/' . $token_value;
$snippets = [
    'claude_web' => [
        'title' => _l('ai_mcp_client_claude_web'),
        'help'  => _l('ai_mcp_client_claude_web_help'),
        'code'  => $url_with_token,
    ],
    'chatgpt' => [
        'title' => _l('ai_mcp_client_chatgpt'),
        'help'  => _l('ai_mcp_client_chatgpt_help'),
        'code'  => $url_with_token,
    ],
    'claude_desktop' => [
        'title' => _l('ai_mcp_client_claude_desktop'),
        'help'  => _l('ai_mcp_client_claude_desktop_help'),
        'code'  => json_encode(['mcpServers' => ['perfex-crm' => [
            'command' => 'npx',
            'args'    => ['-y', 'mcp-remote', $endpoint, '--header', 'Authorization:${AUTH_HEADER}'],
            'env'     => ['AUTH_HEADER' => 'Bearer ' . $token_value],
        ]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    ],
    'claude_code' => [
        'title' => 'Claude Code',
        'help'  => _l('ai_mcp_client_cli_help'),
        'code'  => 'claude mcp add --transport http perfex-crm ' . $endpoint . ' --header "Authorization: Bearer ' . $token_value . '"',
    ],
    'cursor' => [
        'title' => _l('ai_mcp_client_cursor'),
        'help'  => _l('ai_mcp_client_cursor_help'),
        'code'  => json_encode(['mcpServers' => ['perfex-crm' => [
            'url'     => $endpoint,
            'headers' => ['Authorization' => 'Bearer ' . $token_value],
        ]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    ],
];
?>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-10 col-md-offset-1">
                <h4 class="tw-mt-0 tw-font-semibold tw-text-lg tw-text-neutral-700">
                    <i class="fa-solid fa-robot tw-mr-1"></i> <?php echo _l('ai_mcp_menu'); ?>
                </h4>

                <?php if ($new_token) { ?>
                    <div class="alert alert-warning">
                        <p><strong><?php echo _l('ai_mcp_token_created_once'); ?></strong></p>
                        <div class="input-group tw-mt-2">
                            <input type="text" class="form-control" readonly value="<?php echo html_escape($new_token); ?>" id="ai-mcp-new-token">
                            <span class="input-group-btn">
                                <button class="btn btn-default ai-mcp-copy" data-target="#ai-mcp-new-token" type="button"><i class="fa-regular fa-copy"></i></button>
                            </span>
                        </div>
                    </div>
                <?php } ?>

                <div class="panel_s">
                    <div class="panel-body">
                        <h4 class="tw-font-semibold tw-mt-0"><?php echo _l('ai_mcp_how_it_works'); ?></h4>
                        <p class="text-muted"><?php echo _l('ai_mcp_how_it_works_text'); ?></p>
                        <p><?php echo _l('ai_mcp_endpoint'); ?>: <code><?php echo html_escape($endpoint); ?></code></p>

                        <?php echo form_open(admin_url('ai_mcp/connection'), ['class' => 'form-inline tw-mt-4']); ?>
                        <div class="form-group">
                            <input type="text" name="name" class="form-control" required maxlength="100" placeholder="<?php echo _l('ai_mcp_token_name_placeholder'); ?>">
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-key"></i> <?php echo _l('ai_mcp_create_token'); ?></button>
                        <?php echo form_close(); ?>
                    </div>
                </div>

                <div class="panel_s">
                    <div class="panel-body">
                        <h4 class="tw-font-semibold tw-mt-0"><?php echo _l('ai_mcp_connect_clients'); ?></h4>
                        <?php if (!$new_token) { ?>
                            <p class="text-muted"><?php echo _l('ai_mcp_replace_token'); ?></p>
                        <?php } ?>
                        <ul class="nav nav-tabs" role="tablist">
                            <?php $first = true; foreach ($snippets as $key => $snippet) { ?>
                                <li role="presentation" class="<?php echo $first ? 'active' : ''; ?>">
                                    <a href="#ai-mcp-<?php echo $key; ?>" role="tab" data-toggle="tab"><?php echo $snippet['title']; ?></a>
                                </li>
                            <?php $first = false; } ?>
                        </ul>
                        <div class="tab-content tw-mt-3">
                            <?php $first = true; foreach ($snippets as $key => $snippet) { ?>
                                <div role="tabpanel" class="tab-pane <?php echo $first ? 'active' : ''; ?>" id="ai-mcp-<?php echo $key; ?>">
                                    <p><?php echo $snippet['help']; ?></p>
                                    <div class="tw-relative">
                                        <pre id="ai-mcp-code-<?php echo $key; ?>" style="white-space:pre-wrap;word-break:break-all;"><?php echo html_escape($snippet['code']); ?></pre>
                                        <button class="btn btn-default btn-xs ai-mcp-copy" data-target="#ai-mcp-code-<?php echo $key; ?>" type="button" style="position:absolute;top:6px;right:6px;">
                                            <i class="fa-regular fa-copy"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php $first = false; } ?>
                        </div>
                        <p class="text-warning tw-mt-2"><small><i class="fa-solid fa-triangle-exclamation"></i> <?php echo _l('ai_mcp_token_secret_warning'); ?></small></p>
                    </div>
                </div>

                <div class="panel_s">
                    <div class="panel-body">
                        <h4 class="tw-font-semibold tw-mt-0"><?php echo _l('ai_mcp_my_tokens'); ?></h4>
                        <?php if (empty($tokens)) { ?>
                            <p class="text-muted"><?php echo _l('ai_mcp_no_tokens'); ?></p>
                        <?php } else { ?>
                            <table class="table">
                                <thead><tr>
                                    <th><?php echo _l('ai_mcp_token_name'); ?></th>
                                    <th><?php echo _l('ai_mcp_token_created'); ?></th>
                                    <th><?php echo _l('ai_mcp_token_last_used'); ?></th>
                                    <th></th>
                                </tr></thead>
                                <tbody>
                                <?php foreach ($tokens as $token) { ?>
                                    <tr>
                                        <td><?php echo html_escape($token['name']); ?> <code><?php echo html_escape($token['token_prefix']); ?>…</code></td>
                                        <td><?php echo _dt($token['created_at']); ?></td>
                                        <td><?php echo $token['last_used_at'] ? _dt($token['last_used_at']) : '–'; ?></td>
                                        <td class="text-right">
                                            <?php echo form_open(admin_url('ai_mcp/revoke_token/' . $token['id']), ['class' => 'inline-block']); ?>
                                            <button class="btn btn-danger btn-xs _delete"><?php echo _l('ai_mcp_revoke'); ?></button>
                                            <?php echo form_close(); ?>
                                        </td>
                                    </tr>
                                <?php } ?>
                                </tbody>
                            </table>
                        <?php } ?>
                        <a href="<?php echo admin_url('ai_mcp/logs'); ?>"><i class="fa-solid fa-list"></i> <?php echo _l('ai_mcp_logs'); ?></a>
                    </div>
                </div>

                <div class="panel_s">
                    <div class="panel-body">
                        <h4 class="tw-font-semibold tw-mt-0"><?php echo _l('ai_mcp_my_tools'); ?></h4>
                        <p class="text-muted"><?php echo _l('ai_mcp_my_tools_help'); ?></p>
                        <div class="row">
                            <?php foreach ($tools as $name => $tool) { ?>
                                <div class="col-md-6 tw-mb-2">
                                    <span class="label label-<?php echo !empty($tool['destructive']) ? 'danger' : ($tool['write'] ? 'warning' : 'default'); ?>">
                                        <?php echo !empty($tool['destructive']) ? _l('ai_mcp_tool_delete') : ($tool['write'] ? _l('ai_mcp_tool_write') : _l('ai_mcp_tool_read')); ?>
                                    </span>
                                    <strong class="tw-ml-1"><?php echo html_escape($tool['title']); ?></strong>
                                    <code class="tw-ml-1"><?php echo $name; ?></code>
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php init_tail(); ?>
<script>
$(function () {
    $('.ai-mcp-copy').on('click', function () {
        var $t = $($(this).data('target'));
        var text = $t.is('input') ? $t.val() : $t.text();
        navigator.clipboard.writeText(text).then(function () { alert_float('success', '<?php echo _l('ai_mcp_copied'); ?>'); });
    });
});
</script>
</body>
</html>
