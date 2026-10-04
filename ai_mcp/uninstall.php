<?php

defined('BASEPATH') or exit('No direct script access allowed');

$CI->db->query('DROP TABLE IF EXISTS `' . db_prefix() . 'ai_mcp_tokens`');
$CI->db->query('DROP TABLE IF EXISTS `' . db_prefix() . 'ai_mcp_logs`');
$CI->db->query('DROP TABLE IF EXISTS `' . db_prefix() . 'ai_mcp_conversations`');

foreach ([
    'ai_mcp_enabled', 'ai_mcp_access_mode', 'ai_mcp_chat_enabled', 'ai_mcp_provider',
    'ai_mcp_api_key', 'ai_mcp_base_url', 'ai_mcp_model', 'ai_mcp_confirm_writes',
    'ai_mcp_allow_delete', 'ai_mcp_rate_limit', 'ai_mcp_system_prompt', 'ai_mcp_log_retention_days',
] as $option) {
    delete_option($option);
}
