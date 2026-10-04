<?php

defined('BASEPATH') or exit('No direct script access allowed');

$lang['ai_mcp']                     = 'AI Connector (MCP)';
$lang['ai_mcp_menu']                = 'AI Assistant';
$lang['ai_mcp_permission_use']      = 'Use AI assistant & MCP';

// Settings
$lang['ai_mcp_section_status']      = 'Status';
$lang['ai_mcp_intro']               = 'Let your team work in the CRM from any AI app (Claude, ChatGPT, Gemini, Cursor and others) through the Model Context Protocol, and from the built-in chat assistant.';
$lang['ai_mcp_saas_note']           = 'This feature is included in your plan. Once you enable it below, your staff get access.';
$lang['ai_mcp_enable']              = 'Enable AI connector for this organisation';
$lang['ai_mcp_enable_help']         = 'When enabled, staff can create personal connection tokens and use the chat assistant. Disabling it immediately blocks every connected AI app.';
$lang['ai_mcp_endpoint']            = 'MCP server URL';
$lang['ai_mcp_section_access']      = 'Who can use it';
$lang['ai_mcp_access_all']          = 'All active staff members';
$lang['ai_mcp_access_permission']   = 'Only staff whose role or permissions include "AI Connector (MCP) → Use AI assistant & MCP"';
$lang['ai_mcp_access_help']         = 'Administrators always have access. Manage the permission under Setup → Staff → Roles.';
$lang['ai_mcp_permission_mirror']   = 'The AI always acts as the staff member using it: it can only see and change what that person could in the CRM (view own / view all / create / edit / delete), never more.';
$lang['ai_mcp_section_safety']      = 'Safety';
$lang['ai_mcp_confirm_writes']      = 'In the chat assistant, ask the user to approve every create / update action before it runs';
$lang['ai_mcp_allow_delete']        = 'Allow AI to delete leads and tasks (only for staff who have delete permission)';
$lang['ai_mcp_rate_limit']          = 'Max AI actions per staff member per minute (0 = unlimited)';
$lang['ai_mcp_log_retention']       = 'Keep activity log for (days, 0 = forever)';
$lang['ai_mcp_logs']                = 'AI activity log';
$lang['ai_mcp_logs_help']           = 'Every action an AI performed in the CRM, by whom and through which channel.';
$lang['ai_mcp_section_chat']        = 'Chat assistant';
$lang['ai_mcp_chat_help']           = 'The chat bubble in the corner of the CRM sends staff requests to the AI provider below. The organisation pays for usage with its own API key.';
$lang['ai_mcp_chat_enable']         = 'Show the chat assistant to staff';
$lang['ai_mcp_provider_preset']     = 'Quick setup';
$lang['ai_mcp_preset_custom']       = '— choose a provider —';
$lang['ai_mcp_provider']            = 'API type';
$lang['ai_mcp_provider_openai']     = 'OpenAI-compatible (ChatGPT, Gemini, OpenRouter, Groq, DeepSeek, Ollama…)';
$lang['ai_mcp_model']               = 'Model';
$lang['ai_mcp_base_url']            = 'API base URL';
$lang['ai_mcp_base_url_placeholder'] = 'Leave blank for the provider default';
$lang['ai_mcp_api_key']             = 'API key';
$lang['ai_mcp_api_key_saved']       = 'Saved (encrypted) – leave blank to keep';
$lang['ai_mcp_clear_key']           = 'Remove saved API key';
$lang['ai_mcp_test']                = 'Test connection';
$lang['ai_mcp_test_help']           = 'Save your settings first; the test uses the saved provider, model and key.';
$lang['ai_mcp_test_ok']             = 'Connected. Reply:';
$lang['ai_mcp_system_prompt']       = 'Extra instructions for the assistant (optional)';
$lang['ai_mcp_system_prompt_placeholder'] = 'e.g. Always reply in English. Our financial year starts on 1 April. Amounts are in INR.';
$lang['ai_mcp_active_connections']  = 'Active AI connections (all staff)';
$lang['ai_mcp_no_tokens_org']       = 'No staff member has connected an AI app yet.';
$lang['ai_mcp_revoke']              = 'Revoke';
$lang['ai_mcp_revoke_all']          = 'Revoke all connections';
$lang['ai_mcp_all_tokens_revoked']  = 'All AI connections were revoked.';
$lang['ai_mcp_token_revoked']       = 'Connection revoked.';

// Connection page
$lang['ai_mcp_how_it_works']        = 'Connect your AI app';
$lang['ai_mcp_how_it_works_text']   = 'Create a personal token, then add the CRM to your AI app using one of the setups below. The AI will work with your own CRM permissions.';
$lang['ai_mcp_token_name_placeholder'] = 'Name, e.g. "Claude on my laptop"';
$lang['ai_mcp_create_token']        = 'Create token';
$lang['ai_mcp_token_created_once']  = 'Your new token is shown below only once. Copy it now – the setups further down already include it.';
$lang['ai_mcp_connect_clients']     = 'Setup instructions';
$lang['ai_mcp_replace_token']       = 'Create a token above and the instructions will include it automatically, or replace YOUR_TOKEN yourself.';
$lang['ai_mcp_client_claude_web']   = 'Claude.ai';
$lang['ai_mcp_client_claude_web_help'] = 'In Claude go to Settings → Connectors → Add custom connector, and paste this URL.';
$lang['ai_mcp_client_chatgpt']      = 'ChatGPT';
$lang['ai_mcp_client_chatgpt_help'] = 'In ChatGPT go to Settings → Connectors (developer mode) → Create, choose "No authentication" and paste this URL.';
$lang['ai_mcp_client_claude_desktop'] = 'Claude Desktop';
$lang['ai_mcp_client_claude_desktop_help'] = 'Open Claude Desktop → Settings → Developer → Edit Config, add this to claude_desktop_config.json and restart Claude. Requires Node.js.';
$lang['ai_mcp_client_cli_help']     = 'Run this command in a terminal.';
$lang['ai_mcp_client_cursor']       = 'Cursor / VS Code / others';
$lang['ai_mcp_client_cursor_help']  = 'Add this to your MCP configuration (Cursor: ~/.cursor/mcp.json). Any MCP client that supports "Streamable HTTP" with headers works the same way.';
$lang['ai_mcp_token_secret_warning'] = 'Treat the token (and the URL containing it) like a password: anyone who has it can act in the CRM as you. Revoke it below if it leaks.';
$lang['ai_mcp_my_tokens']           = 'My connections';
$lang['ai_mcp_no_tokens']           = 'You have not created any token yet.';
$lang['ai_mcp_token_name']          = 'Name';
$lang['ai_mcp_token_created']       = 'Created';
$lang['ai_mcp_token_last_used']     = 'Last used';
$lang['ai_mcp_my_tools']            = 'What your AI can do';
$lang['ai_mcp_my_tools_help']       = 'Based on your current CRM permissions. If your permissions change, this list changes too.';
$lang['ai_mcp_tool_read']           = 'Read';
$lang['ai_mcp_tool_write']          = 'Write';
$lang['ai_mcp_tool_delete']         = 'Delete';
$lang['ai_mcp_copied']              = 'Copied to clipboard';

// Logs
$lang['ai_mcp_log_date']            = 'Date';
$lang['ai_mcp_log_channel']         = 'Channel';
$lang['ai_mcp_log_tool']            = 'Action';
$lang['ai_mcp_log_arguments']       = 'Details';
$lang['ai_mcp_log_result']          = 'Result';
$lang['ai_mcp_channel_chat']        = 'Chat';

// Chat widget
$lang['ai_mcp_chat_title']          = 'AI Assistant';
$lang['ai_mcp_chat_subtitle']       = 'Works with your CRM permissions';
$lang['ai_mcp_chat_placeholder']    = 'Ask or tell me what to do…';
$lang['ai_mcp_chat_send']           = 'Send';
$lang['ai_mcp_chat_reset']          = 'New conversation';
$lang['ai_mcp_chat_reset_confirm']  = 'Start a new conversation? The current one will be cleared.';
$lang['ai_mcp_chat_welcome']        = "Hi! I can work in the CRM for you. Try:\n- What tasks are overdue for me?\n- Create a lead for Ravi Kumar from Acme, phone 98xxxxxx\n- Show unpaid invoices for Sharma Traders";
$lang['ai_mcp_chat_thinking']       = 'Working on it…';
$lang['ai_mcp_chat_confirm_title']  = 'Approve these changes?';
$lang['ai_mcp_chat_approve']        = 'Approve';
$lang['ai_mcp_chat_cancel']         = 'Cancel';
$lang['ai_mcp_chat_approved']       = 'Approved';
$lang['ai_mcp_chat_declined']       = 'Cancelled';
$lang['ai_mcp_chat_action_done']    = 'Done';
$lang['ai_mcp_chat_action_failed']  = 'Failed';
$lang['ai_mcp_chat_request_failed'] = 'Something went wrong. Please try again.';
$lang['ai_mcp_chat_empty']          = 'Please type a message.';
$lang['ai_mcp_chat_nothing_pending'] = 'There is no action waiting for approval.';
$lang['ai_mcp_chat_new_conversation_notice'] = 'The previous conversation got long, so a new one was started.';
$lang['ai_mcp_chat_refused']        = 'The AI declined to help with this request.';
$lang['ai_mcp_chat_truncated']      = '(The reply was cut short. Ask me to continue.)';
$lang['ai_mcp_chat_too_many_steps'] = '(I stopped after many steps. Tell me how to continue.)';
$lang['ai_mcp_chat_no_key']         = 'The AI provider is not configured yet. Ask your administrator to add an API key.';
$lang['ai_mcp_chat_bad_response']   = 'The AI provider returned an unexpected response.';
$lang['ai_mcp_chat_network_error']  = 'Could not reach the AI provider.';
$lang['ai_mcp_chat_auth_error']     = 'The AI provider rejected the API key';
$lang['ai_mcp_chat_rate_limited']   = 'The AI provider is rate limiting requests; try again in a moment';
