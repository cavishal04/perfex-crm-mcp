<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: AI MCP Connector
Description: Connect any AI (Claude, ChatGPT, Gemini, Cursor, ...) to Perfex CRM through the Model Context Protocol, plus an in-app AI chat assistant. Every AI action runs with exactly the permissions of the staff member using it.
Version: 1.1.0
Requires at least: 3.0.*
Author: Vishal Sharma
Author URI: https://github.com/cavishal04/perfex-crm-mcp
*/

define('AI_MCP_MODULE_NAME', 'ai_mcp');
define('AI_MCP_VERSION', '1.1.0');

$CI = &get_instance();

register_activation_hook(AI_MCP_MODULE_NAME, 'ai_mcp_module_activation_hook');
register_uninstall_hook(AI_MCP_MODULE_NAME, 'ai_mcp_module_uninstall_hook');
register_language_files(AI_MCP_MODULE_NAME, [AI_MCP_MODULE_NAME]);

$CI->load->helper(AI_MCP_MODULE_NAME . '/ai_mcp');

hooks()->add_action('admin_init', 'ai_mcp_module_init_menu_items');
hooks()->add_action('admin_init', 'ai_mcp_module_register_permissions');
hooks()->add_action('app_admin_head', 'ai_mcp_module_admin_head');
hooks()->add_action('app_admin_footer', 'ai_mcp_module_chat_widget');
hooks()->add_filter('csrf_exclude_uris', 'ai_mcp_module_csrf_exclude');

function ai_mcp_module_activation_hook()
{
    $CI = &get_instance();
    require_once __DIR__ . '/install.php';
}

function ai_mcp_module_uninstall_hook()
{
    $CI = &get_instance();
    require_once __DIR__ . '/uninstall.php';
}

/**
 * The MCP endpoint is called by external AI clients with a bearer token, not a browser
 * session, so it must be excluded from Perfex's CSRF protection.
 */
function ai_mcp_module_csrf_exclude($exclude)
{
    $exclude[] = 'ai_mcp/mcp.*';

    return $exclude;
}

/**
 * Adds an "AI Assistant: Use" capability to Setup -> Staff -> Roles / staff permissions.
 * Only used when the organisation owner sets access mode to "permission".
 */
function ai_mcp_module_register_permissions()
{
    register_staff_capabilities('ai_mcp', [
        'capabilities' => [
            'use' => _l('ai_mcp_permission_use'),
        ],
    ], _l('ai_mcp'));
}

function ai_mcp_module_init_menu_items()
{
    $CI = &get_instance();

    if (is_admin()) {
        $CI->app_menu->add_setup_menu_item('ai-mcp-settings', [
            'name'     => _l('ai_mcp'),
            'href'     => admin_url('ai_mcp/settings'),
            'position' => 65,
        ]);
    }

    if (ai_mcp_staff_has_access()) {
        $CI->app_menu->add_sidebar_menu_item('ai-mcp', [
            'name'     => _l('ai_mcp_menu'),
            'href'     => admin_url('ai_mcp/connection'),
            'icon'     => 'fa-solid fa-robot',
            'position' => 60,
        ]);
    }
}

function ai_mcp_module_admin_head()
{
    if (!ai_mcp_chat_available()) {
        return;
    }
    echo '<link href="' . module_dir_url(AI_MCP_MODULE_NAME, 'assets/css/ai_mcp_chat.css') . '?v=' . AI_MCP_VERSION . '" rel="stylesheet" type="text/css" />';
}

function ai_mcp_module_chat_widget()
{
    if (!ai_mcp_chat_available()) {
        return;
    }
    $CI = &get_instance();
    $CI->load->view(AI_MCP_MODULE_NAME . '/chat_widget');
}
