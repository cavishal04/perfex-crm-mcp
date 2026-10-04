<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <h4 class="tw-mt-0 tw-font-semibold tw-text-lg tw-text-neutral-700">
                    <i class="fa-solid fa-list tw-mr-1"></i> <?php echo _l('ai_mcp_logs'); ?>
                </h4>
                <div class="panel_s">
                    <div class="panel-body">
                        <p class="text-muted"><?php echo _l('ai_mcp_logs_help'); ?></p>
                        <div class="table-responsive">
                            <table class="table table-striped dt-table" data-order-col="0" data-order-type="desc">
                                <thead><tr>
                                    <th><?php echo _l('ai_mcp_log_date'); ?></th>
                                    <th><?php echo _l('staff_member'); ?></th>
                                    <th><?php echo _l('ai_mcp_log_channel'); ?></th>
                                    <th><?php echo _l('ai_mcp_log_tool'); ?></th>
                                    <th><?php echo _l('ai_mcp_log_arguments'); ?></th>
                                    <th><?php echo _l('ai_mcp_log_result'); ?></th>
                                </tr></thead>
                                <tbody>
                                <?php foreach ($logs as $log) { ?>
                                    <tr>
                                        <td data-order="<?php echo $log['created_at']; ?>"><?php echo _dt($log['created_at']); ?></td>
                                        <td><?php echo html_escape($log['staff_name']); ?></td>
                                        <td><span class="label label-<?php echo $log['channel'] === 'chat' ? 'info' : 'primary'; ?>"><?php echo $log['channel'] === 'chat' ? _l('ai_mcp_channel_chat') : 'MCP'; ?></span></td>
                                        <td><code><?php echo html_escape($log['tool']); ?></code></td>
                                        <td><small style="word-break:break-all;"><?php echo html_escape(mb_strimwidth((string) $log['arguments'], 0, 300, '…')); ?></small></td>
                                        <td>
                                            <?php if ($log['success']) { ?>
                                                <span class="text-success"><i class="fa-solid fa-check"></i></span>
                                            <?php } else { ?>
                                                <span class="text-danger"><i class="fa-solid fa-xmark"></i> <?php echo html_escape($log['message']); ?></span>
                                            <?php } ?>
                                        </td>
                                    </tr>
                                <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php init_tail(); ?>
</body>
</html>
