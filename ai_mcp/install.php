<?php

defined('BASEPATH') or exit('No direct script access allowed');

if (!$CI->db->table_exists(db_prefix() . 'ai_mcp_tokens')) {
    $CI->db->query('CREATE TABLE `' . db_prefix() . "ai_mcp_tokens` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `staff_id` int(11) NOT NULL,
        `name` varchar(191) NOT NULL,
        `token_hash` char(64) NOT NULL,
        `token_prefix` varchar(16) NOT NULL,
        `created_at` datetime NOT NULL,
        `last_used_at` datetime NULL DEFAULT NULL,
        `last_used_ip` varchar(64) NULL DEFAULT NULL,
        `revoked` tinyint(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        UNIQUE KEY `token_hash` (`token_hash`),
        KEY `staff_id` (`staff_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=" . $CI->db->char_set . ';');
}

if (!$CI->db->table_exists(db_prefix() . 'ai_mcp_logs')) {
    $CI->db->query('CREATE TABLE `' . db_prefix() . "ai_mcp_logs` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `staff_id` int(11) NOT NULL,
        `token_id` int(11) NULL DEFAULT NULL,
        `channel` varchar(16) NOT NULL,
        `tool` varchar(100) NOT NULL,
        `arguments` mediumtext NULL,
        `success` tinyint(1) NOT NULL DEFAULT 1,
        `message` text NULL,
        `created_at` datetime NOT NULL,
        PRIMARY KEY (`id`),
        KEY `staff_id` (`staff_id`),
        KEY `created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=" . $CI->db->char_set . ';');
}

if (!$CI->db->table_exists(db_prefix() . 'ai_mcp_conversations')) {
    $CI->db->query('CREATE TABLE `' . db_prefix() . "ai_mcp_conversations` (
        `staff_id` int(11) NOT NULL,
        `provider` varchar(32) NOT NULL,
        `state` longtext NOT NULL,
        `updated_at` datetime NOT NULL,
        PRIMARY KEY (`staff_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=" . $CI->db->char_set . ';');
}

// add_option() never overwrites an existing value, so re-activation keeps settings.
add_option('ai_mcp_enabled', '0');
add_option('ai_mcp_access_mode', 'all');          // all | permission
add_option('ai_mcp_chat_enabled', '1');
add_option('ai_mcp_provider', 'anthropic');       // anthropic | openai
add_option('ai_mcp_api_key', '');
add_option('ai_mcp_base_url', '');
add_option('ai_mcp_model', 'claude-opus-5-5');
add_option('ai_mcp_confirm_writes', '1');
add_option('ai_mcp_allow_delete', '0');
add_option('ai_mcp_rate_limit', '60');            // tool calls per staff per minute
add_option('ai_mcp_system_prompt', '');
add_option('ai_mcp_log_retention_days', '90');
