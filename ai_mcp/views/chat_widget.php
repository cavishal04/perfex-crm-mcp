<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<div id="ai-mcp-chat" class="ai-mcp-chat">
    <button type="button" class="ai-mcp-fab" id="ai-mcp-fab" aria-label="<?php echo _l('ai_mcp_chat_title'); ?>" title="<?php echo _l('ai_mcp_chat_title'); ?>">
        <i class="fa-solid fa-robot"></i>
    </button>
    <section class="ai-mcp-panel" id="ai-mcp-panel" aria-label="<?php echo _l('ai_mcp_chat_title'); ?>" hidden>
        <header class="ai-mcp-header">
            <div>
                <strong><?php echo _l('ai_mcp_chat_title'); ?></strong>
                <small><?php echo _l('ai_mcp_chat_subtitle'); ?></small>
            </div>
            <div class="ai-mcp-header-actions">
                <button type="button" id="ai-mcp-reset" title="<?php echo _l('ai_mcp_chat_reset'); ?>"><i class="fa-solid fa-rotate-left"></i></button>
                <button type="button" id="ai-mcp-close" title="<?php echo _l('close'); ?>"><i class="fa-solid fa-xmark"></i></button>
            </div>
        </header>
        <div class="ai-mcp-messages" id="ai-mcp-messages" aria-live="polite"></div>
        <div class="ai-mcp-confirm" id="ai-mcp-confirm" hidden></div>
        <form class="ai-mcp-input" id="ai-mcp-form">
            <textarea id="ai-mcp-text" rows="1" maxlength="8000" placeholder="<?php echo _l('ai_mcp_chat_placeholder'); ?>"></textarea>
            <button type="submit" id="ai-mcp-send" title="<?php echo _l('ai_mcp_chat_send'); ?>"><i class="fa-solid fa-paper-plane"></i></button>
        </form>
    </section>
</div>
<script>
window.AI_MCP_CHAT = <?php echo json_encode([
    'urls' => [
        'send'    => admin_url('ai_mcp/chat_send'),
        'confirm' => admin_url('ai_mcp/chat_confirm'),
        'history' => admin_url('ai_mcp/chat_history'),
        'reset'   => admin_url('ai_mcp/chat_reset'),
    ],
    'csrf' => [
        'name' => get_instance()->security->get_csrf_token_name(),
        'hash' => get_instance()->security->get_csrf_hash(),
    ],
    'lang' => [
        'welcome'      => _l('ai_mcp_chat_welcome'),
        'thinking'     => _l('ai_mcp_chat_thinking'),
        'confirmTitle' => _l('ai_mcp_chat_confirm_title'),
        'approve'      => _l('ai_mcp_chat_approve'),
        'cancel'       => _l('ai_mcp_chat_cancel'),
        'resetConfirm' => _l('ai_mcp_chat_reset_confirm'),
        'error'        => _l('ai_mcp_chat_request_failed'),
        'done'         => _l('ai_mcp_chat_action_done'),
        'failed'       => _l('ai_mcp_chat_action_failed'),
    ],
], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
</script>
<script src="<?php echo module_dir_url(AI_MCP_MODULE_NAME, 'assets/js/ai_mcp_chat.js') . '?v=' . AI_MCP_VERSION; ?>"></script>
