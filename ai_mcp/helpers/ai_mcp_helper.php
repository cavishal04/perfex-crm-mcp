<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Has the organisation owner (tenant admin) finished setting up the connector?
 */
function ai_mcp_is_enabled()
{
    return get_option('ai_mcp_enabled') == '1';
}

/**
 * Hard gate for SaaS installs. In Perfex SaaS the super admin controls which modules a
 * package includes, so a tenant whose package does not include "ai_mcp" never loads this
 * module at all. This filter lets a custom SaaS integration add any extra package check.
 */
function ai_mcp_tenant_allowed()
{
    return (bool) hooks()->apply_filters('ai_mcp_tenant_allowed', true);
}

/**
 * Perfex 3.x uses staff_can(); older builds use has_permission().
 */
function ai_mcp_staff_can($capability, $feature, $staff_id = '')
{
    if (function_exists('staff_can')) {
        return staff_can($capability, $feature, $staff_id);
    }

    return has_permission($feature, $staff_id, $capability);
}

/**
 * Can this staff member use the AI connector at all (MCP + chat)?
 * What they can actually do through it is decided per-tool by their CRM permissions.
 */
function ai_mcp_staff_has_access($staff_id = null)
{
    $staff_id = $staff_id ?: get_staff_user_id();
    if (!$staff_id || !ai_mcp_is_enabled() || !ai_mcp_tenant_allowed()) {
        return false;
    }

    $CI = &get_instance();
    $staff = $CI->db->select('active')->where('staffid', $staff_id)->get(db_prefix() . 'staff')->row();
    if (!$staff || (int) $staff->active !== 1) {
        return false;
    }

    if (is_admin($staff_id)) {
        return true;
    }

    if (get_option('ai_mcp_access_mode') === 'permission') {
        return ai_mcp_staff_can('use', 'ai_mcp', $staff_id);
    }

    return true;
}

function ai_mcp_chat_available()
{
    return is_staff_logged_in()
        && get_option('ai_mcp_chat_enabled') == '1'
        && get_option('ai_mcp_api_key') != ''
        && ai_mcp_staff_has_access();
}

function ai_mcp_get_api_key()
{
    $value = get_option('ai_mcp_api_key');
    if ($value === '' || $value === null) {
        return '';
    }
    $CI = &get_instance();
    $decrypted = $CI->encryption->decrypt($value);

    return $decrypted === false ? '' : $decrypted;
}

function ai_mcp_endpoint_url()
{
    return site_url('ai_mcp/mcp');
}

function ai_mcp_staff_name($staff_id)
{
    $CI = &get_instance();
    $row = $CI->db->select('firstname, lastname')->where('staffid', $staff_id)->get(db_prefix() . 'staff')->row();

    return $row ? trim($row->firstname . ' ' . $row->lastname) : '';
}
