<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-8 col-md-offset-2">
                <h4 class="tw-mt-0 tw-font-semibold tw-text-lg tw-text-neutral-700">
                    <i class="fa-solid fa-robot tw-mr-1"></i> <?php echo _l('ai_mcp'); ?>
                </h4>

                <?php echo form_open(admin_url('ai_mcp/settings'), ['id' => 'ai-mcp-settings-form', 'autocomplete' => 'off']); ?>

                <div class="panel_s">
                    <div class="panel-body">
                        <h4 class="tw-font-semibold tw-mt-0"><?php echo _l('ai_mcp_section_status'); ?></h4>
                        <p class="text-muted"><?php echo _l('ai_mcp_intro'); ?></p>
                        <?php if ($is_saas_tenant) { ?>
                            <div class="alert alert-info"><?php echo _l('ai_mcp_saas_note'); ?></div>
                        <?php } ?>
                        <div class="checkbox checkbox-primary">
                            <input type="checkbox" name="ai_mcp_enabled" id="ai_mcp_enabled" value="1" <?php echo get_option('ai_mcp_enabled') == '1' ? 'checked' : ''; ?>>
                            <label for="ai_mcp_enabled"><strong><?php echo _l('ai_mcp_enable'); ?></strong></label>
                        </div>
                        <p class="text-muted tw-ml-6"><?php echo _l('ai_mcp_enable_help'); ?></p>
                        <p class="tw-ml-6"><?php echo _l('ai_mcp_endpoint'); ?>: <code><?php echo ai_mcp_endpoint_url(); ?></code></p>
                    </div>
                </div>

                <div class="panel_s">
                    <div class="panel-body">
                        <h4 class="tw-font-semibold tw-mt-0"><?php echo _l('ai_mcp_section_access'); ?></h4>
                        <div class="radio radio-primary">
                            <input type="radio" name="ai_mcp_access_mode" id="ai_mcp_access_all" value="all" <?php echo get_option('ai_mcp_access_mode') !== 'permission' ? 'checked' : ''; ?>>
                            <label for="ai_mcp_access_all"><?php echo _l('ai_mcp_access_all'); ?></label>
                        </div>
                        <div class="radio radio-primary">
                            <input type="radio" name="ai_mcp_access_mode" id="ai_mcp_access_permission" value="permission" <?php echo get_option('ai_mcp_access_mode') === 'permission' ? 'checked' : ''; ?>>
                            <label for="ai_mcp_access_permission"><?php echo _l('ai_mcp_access_permission'); ?></label>
                        </div>
                        <p class="text-muted"><?php echo _l('ai_mcp_access_help'); ?> <a href="<?php echo admin_url('roles'); ?>"><?php echo _l('roles'); ?></a></p>
                        <div class="alert alert-success tw-mb-0"><i class="fa-solid fa-shield-halved"></i> <?php echo _l('ai_mcp_permission_mirror'); ?></div>
                    </div>
                </div>

                <div class="panel_s">
                    <div class="panel-body">
                        <h4 class="tw-font-semibold tw-mt-0"><?php echo _l('ai_mcp_section_safety'); ?></h4>
                        <div class="checkbox checkbox-primary">
                            <input type="checkbox" name="ai_mcp_confirm_writes" id="ai_mcp_confirm_writes" value="1" <?php echo get_option('ai_mcp_confirm_writes') == '1' ? 'checked' : ''; ?>>
                            <label for="ai_mcp_confirm_writes"><?php echo _l('ai_mcp_confirm_writes'); ?></label>
                        </div>
                        <div class="checkbox checkbox-danger">
                            <input type="checkbox" name="ai_mcp_allow_delete" id="ai_mcp_allow_delete" value="1" <?php echo get_option('ai_mcp_allow_delete') == '1' ? 'checked' : ''; ?>>
                            <label for="ai_mcp_allow_delete"><?php echo _l('ai_mcp_allow_delete'); ?></label>
                        </div>
                        <div class="row tw-mt-3">
                            <div class="col-md-6">
                                <?php echo render_input('ai_mcp_rate_limit', 'ai_mcp_rate_limit', get_option('ai_mcp_rate_limit'), 'number', ['min' => 0]); ?>
                            </div>
                            <div class="col-md-6">
                                <?php echo render_input('ai_mcp_log_retention_days', 'ai_mcp_log_retention', get_option('ai_mcp_log_retention_days'), 'number', ['min' => 0]); ?>
                            </div>
                        </div>
                        <a href="<?php echo admin_url('ai_mcp/logs'); ?>"><i class="fa-solid fa-list"></i> <?php echo _l('ai_mcp_logs'); ?></a>
                    </div>
                </div>

                <div class="panel_s">
                    <div class="panel-body">
                        <h4 class="tw-font-semibold tw-mt-0"><?php echo _l('ai_mcp_section_chat'); ?></h4>
                        <p class="text-muted"><?php echo _l('ai_mcp_chat_help'); ?></p>
                        <div class="checkbox checkbox-primary">
                            <input type="checkbox" name="ai_mcp_chat_enabled" id="ai_mcp_chat_enabled" value="1" <?php echo get_option('ai_mcp_chat_enabled') == '1' ? 'checked' : ''; ?>>
                            <label for="ai_mcp_chat_enabled"><?php echo _l('ai_mcp_chat_enable'); ?></label>
                        </div>

                        <div class="row tw-mt-3">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="ai_mcp_preset"><?php echo _l('ai_mcp_provider_preset'); ?></label>
                                    <select id="ai_mcp_preset" class="form-control">
                                        <option value=""><?php echo _l('ai_mcp_preset_custom'); ?></option>
                                        <option value="anthropic||claude-opus-5-5">Anthropic Claude (Opus 5.5)</option>
                                        <option value="anthropic||claude-sonnet-5-5">Anthropic Claude (Sonnet 5.5 – lower cost)</option>
                                        <option value="openai|https://api.openai.com/v1|gpt-4o">OpenAI (ChatGPT)</option>
                                        <option value="openai|https://generativelanguage.googleapis.com/v1beta/openai|gemini-2.5-flash">Google Gemini</option>
                                        <option value="openai|https://openrouter.ai/api/v1|openrouter/auto">OpenRouter (any model)</option>
                                        <option value="openai|https://api.groq.com/openai/v1|llama-3.3-70b-versatile">Groq</option>
                                        <option value="openai|https://api.deepseek.com/v1|deepseek-chat">DeepSeek</option>
                                        <option value="openai|http://localhost:11434/v1|llama3.1">Ollama (self-hosted)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="ai_mcp_provider"><?php echo _l('ai_mcp_provider'); ?></label>
                                    <select name="ai_mcp_provider" id="ai_mcp_provider" class="form-control">
                                        <option value="anthropic" <?php echo get_option('ai_mcp_provider') !== 'openai' ? 'selected' : ''; ?>>Anthropic (Claude API)</option>
                                        <option value="openai" <?php echo get_option('ai_mcp_provider') === 'openai' ? 'selected' : ''; ?>><?php echo _l('ai_mcp_provider_openai'); ?></option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <?php echo render_input('ai_mcp_model', 'ai_mcp_model', get_option('ai_mcp_model')); ?>
                            </div>
                            <div class="col-md-6">
                                <?php echo render_input('ai_mcp_base_url', 'ai_mcp_base_url', get_option('ai_mcp_base_url'), 'text', ['placeholder' => _l('ai_mcp_base_url_placeholder')]); ?>
                            </div>
                            <div class="col-md-12">
                                <div class="form-group">
                                    <label for="ai_mcp_api_key"><?php echo _l('ai_mcp_api_key'); ?></label>
                                    <input type="password" class="form-control" name="ai_mcp_api_key" id="ai_mcp_api_key" autocomplete="new-password"
                                        placeholder="<?php echo $has_key ? _l('ai_mcp_api_key_saved') : ''; ?>">
                                    <?php if ($has_key) { ?>
                                        <div class="checkbox checkbox-danger tw-mt-2">
                                            <input type="checkbox" name="ai_mcp_clear_key" id="ai_mcp_clear_key" value="1">
                                            <label for="ai_mcp_clear_key"><?php echo _l('ai_mcp_clear_key'); ?></label>
                                        </div>
                                    <?php } ?>
                                </div>
                                <button type="button" class="btn btn-default" id="ai-mcp-test" <?php echo $has_key ? '' : 'disabled'; ?>>
                                    <i class="fa-solid fa-plug"></i> <?php echo _l('ai_mcp_test'); ?>
                                </button>
                                <span id="ai-mcp-test-result" class="tw-ml-2"></span>
                                <p class="text-muted tw-mt-2"><small><?php echo _l('ai_mcp_test_help'); ?></small></p>
                            </div>
                            <div class="col-md-12 tw-mt-3">
                                <?php echo render_textarea('ai_mcp_system_prompt', 'ai_mcp_system_prompt', get_option('ai_mcp_system_prompt'), ['rows' => 4, 'placeholder' => _l('ai_mcp_system_prompt_placeholder')]); ?>
                            </div>
                        </div>
                    </div>
                    <div class="panel-footer text-right">
                        <button type="submit" class="btn btn-primary"><?php echo _l('submit'); ?></button>
                    </div>
                </div>
                <?php echo form_close(); ?>

                <div class="panel_s">
                    <div class="panel-body">
                        <h4 class="tw-font-semibold tw-mt-0"><?php echo _l('ai_mcp_active_connections'); ?></h4>
                        <?php if (empty($tokens)) { ?>
                            <p class="text-muted"><?php echo _l('ai_mcp_no_tokens_org'); ?></p>
                        <?php } else { ?>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead><tr>
                                        <th><?php echo _l('staff_member'); ?></th>
                                        <th><?php echo _l('ai_mcp_token_name'); ?></th>
                                        <th><?php echo _l('ai_mcp_token_created'); ?></th>
                                        <th><?php echo _l('ai_mcp_token_last_used'); ?></th>
                                        <th></th>
                                    </tr></thead>
                                    <tbody>
                                    <?php foreach ($tokens as $token) { ?>
                                        <tr>
                                            <td><?php echo html_escape($token['staff_name']); ?></td>
                                            <td><?php echo html_escape($token['name']); ?> <code><?php echo html_escape($token['token_prefix']); ?>…</code></td>
                                            <td><?php echo _dt($token['created_at']); ?></td>
                                            <td><?php echo $token['last_used_at'] ? _dt($token['last_used_at']) . ' <small class="text-muted">' . html_escape($token['last_used_ip']) . '</small>' : '–'; ?></td>
                                            <td class="text-right">
                                                <?php echo form_open(admin_url('ai_mcp/admin_revoke_token/' . $token['id']), ['class' => 'inline-block']); ?>
                                                <button class="btn btn-danger btn-xs _delete"><?php echo _l('ai_mcp_revoke'); ?></button>
                                                <?php echo form_close(); ?>
                                            </td>
                                        </tr>
                                    <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php echo form_open(admin_url('ai_mcp/revoke_all_tokens')); ?>
                            <button class="btn btn-danger _delete"><i class="fa-solid fa-ban"></i> <?php echo _l('ai_mcp_revoke_all'); ?></button>
                            <?php echo form_close(); ?>
                        <?php } ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php init_tail(); ?>
<script>
$(function () {
    $('#ai_mcp_preset').on('change', function () {
        var v = $(this).val();
        if (!v) { return; }
        var parts = v.split('|');
        $('#ai_mcp_provider').val(parts[0]);
        $('#ai_mcp_base_url').val(parts[1]);
        $('#ai_mcp_model').val(parts[2]);
    });
    $('#ai-mcp-test').on('click', function () {
        var $r = $('#ai-mcp-test-result').html('<i class="fa fa-spinner fa-spin"></i>');
        $.post(admin_url + 'ai_mcp/test_provider').done(function (res) {
            res = typeof res === 'string' ? JSON.parse(res) : res;
            $r.html('<span class="text-' + (res.success ? 'success' : 'danger') + '">' + res.message + '</span>');
        }).fail(function () {
            $r.html('<span class="text-danger">Request failed</span>');
        });
    });
});
</script>
</body>
</html>
