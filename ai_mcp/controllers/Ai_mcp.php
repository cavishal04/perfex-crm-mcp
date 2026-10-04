<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Ai_mcp extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model(AI_MCP_MODULE_NAME . '/ai_mcp_model');
    }

    public function index()
    {
        redirect(admin_url(is_admin() && !ai_mcp_is_enabled() ? 'ai_mcp/settings' : 'ai_mcp/connection'));
    }

    // ---------------------------------------------------------------------------------
    // Organisation setup (tenant admin / owner)
    // ---------------------------------------------------------------------------------

    public function settings()
    {
        if (!is_admin()) {
            access_denied('ai_mcp');
        }

        if ($this->input->post()) {
            $post = $this->input->post();
            $provider = ($post['ai_mcp_provider'] ?? '') === 'openai' ? 'openai' : 'anthropic';

            update_option('ai_mcp_enabled', !empty($post['ai_mcp_enabled']) ? '1' : '0');
            update_option('ai_mcp_access_mode', ($post['ai_mcp_access_mode'] ?? '') === 'permission' ? 'permission' : 'all');
            update_option('ai_mcp_chat_enabled', !empty($post['ai_mcp_chat_enabled']) ? '1' : '0');
            update_option('ai_mcp_confirm_writes', !empty($post['ai_mcp_confirm_writes']) ? '1' : '0');
            update_option('ai_mcp_allow_delete', !empty($post['ai_mcp_allow_delete']) ? '1' : '0');
            update_option('ai_mcp_provider', $provider);
            update_option('ai_mcp_model', trim($post['ai_mcp_model'] ?? ''));
            update_option('ai_mcp_base_url', trim($post['ai_mcp_base_url'] ?? ''));
            update_option('ai_mcp_rate_limit', max(0, (int) ($post['ai_mcp_rate_limit'] ?? 60)));
            update_option('ai_mcp_log_retention_days', max(0, (int) ($post['ai_mcp_log_retention_days'] ?? 90)));
            update_option('ai_mcp_system_prompt', $this->input->post('ai_mcp_system_prompt', false) ?? '');

            // Blank means "keep the saved key"; the key is stored encrypted and never re-displayed.
            $key = trim($this->input->post('ai_mcp_api_key', false) ?? '');
            if (!empty($post['ai_mcp_clear_key'])) {
                update_option('ai_mcp_api_key', '');
            } elseif ($key !== '') {
                update_option('ai_mcp_api_key', $this->encryption->encrypt($key));
            }

            $this->ai_mcp_model->prune_logs(get_option('ai_mcp_log_retention_days'));
            set_alert('success', _l('settings_updated'));
            redirect(admin_url('ai_mcp/settings'));
        }

        $data['title'] = _l('ai_mcp');
        $data['has_key'] = get_option('ai_mcp_api_key') != '';
        $data['tokens'] = $this->ai_mcp_model->get_all_tokens();
        $data['is_saas_tenant'] = function_exists('perfex_saas_is_tenant') && perfex_saas_is_tenant();
        $this->load->view('settings', $data);
    }

    public function test_provider()
    {
        if (!is_admin() || !$this->input->is_ajax_request()) {
            ajax_access_denied();
        }
        $this->load->library(AI_MCP_MODULE_NAME . '/ai_mcp_agent');
        try {
            $reply = $this->ai_mcp_agent->test();
            echo json_encode(['success' => true, 'message' => _l('ai_mcp_test_ok') . ' ' . html_escape(mb_substr($reply, 0, 100))]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => html_escape($e->getMessage())]);
        }
    }

    public function revoke_all_tokens()
    {
        if (!is_admin() || $this->input->method() !== 'post') {
            access_denied('ai_mcp');
        }
        $this->ai_mcp_model->revoke_all_tokens();
        set_alert('success', _l('ai_mcp_all_tokens_revoked'));
        redirect(admin_url('ai_mcp/settings'));
    }

    public function admin_revoke_token($id)
    {
        if (!is_admin() || $this->input->method() !== 'post') {
            access_denied('ai_mcp');
        }
        $this->ai_mcp_model->revoke_token((int) $id);
        set_alert('success', _l('ai_mcp_token_revoked'));
        redirect(admin_url('ai_mcp/settings'));
    }

    public function logs()
    {
        $staff_filter = is_admin() ? null : get_staff_user_id();
        if (!is_admin() && !ai_mcp_staff_has_access()) {
            access_denied('ai_mcp');
        }
        $data['title'] = _l('ai_mcp_logs');
        $data['logs'] = $this->ai_mcp_model->get_logs(300, $staff_filter);
        $this->load->view('logs', $data);
    }

    // ---------------------------------------------------------------------------------
    // Personal connection page (every staff member with access)
    // ---------------------------------------------------------------------------------

    public function connection()
    {
        if (!ai_mcp_staff_has_access()) {
            if (is_admin()) {
                redirect(admin_url('ai_mcp/settings'));
            }
            access_denied('ai_mcp');
        }

        if ($this->input->method() === 'post') {
            $plain = $this->ai_mcp_model->create_token(get_staff_user_id(), $this->input->post('name'));
            // Shown once on the next page load; only the hash is stored.
            $this->session->set_flashdata('ai_mcp_new_token', $plain);
            redirect(admin_url('ai_mcp/connection'));
        }

        $this->load->library(AI_MCP_MODULE_NAME . '/ai_mcp_tools');
        $data['title'] = _l('ai_mcp_menu');
        $data['tokens'] = $this->ai_mcp_model->get_staff_tokens(get_staff_user_id());
        $data['new_token'] = $this->session->flashdata('ai_mcp_new_token');
        $data['endpoint'] = ai_mcp_endpoint_url();
        $data['tools'] = $this->ai_mcp_tools->for_staff(get_staff_user_id())->available();
        $this->load->view('connection', $data);
    }

    public function revoke_token($id)
    {
        if ($this->input->method() !== 'post') {
            access_denied('ai_mcp');
        }
        $this->ai_mcp_model->revoke_token((int) $id, get_staff_user_id());
        set_alert('success', _l('ai_mcp_token_revoked'));
        redirect(admin_url('ai_mcp/connection'));
    }

    // ---------------------------------------------------------------------------------
    // Chat widget endpoints (AJAX, staff session + CSRF)
    // ---------------------------------------------------------------------------------

    public function chat_send()
    {
        $this->chat_guard();
        $text = trim((string) $this->input->post('message', false));
        if ($text === '') {
            $this->json(['error' => _l('ai_mcp_chat_empty')]);

            return;
        }
        $text = mb_substr($text, 0, 8000);
        $this->run_agent(function ($agent) use ($text) {
            return $agent->send(get_staff_user_id(), $text);
        });
    }

    public function chat_confirm()
    {
        $this->chat_guard();
        $approve = $this->input->post('approve') == '1';
        $this->run_agent(function ($agent) use ($approve) {
            return $agent->confirm(get_staff_user_id(), $approve);
        });
    }

    public function chat_history()
    {
        $this->chat_guard();
        $this->load->library(AI_MCP_MODULE_NAME . '/ai_mcp_agent');
        $this->json($this->ai_mcp_agent->history(get_staff_user_id()));
    }

    public function chat_reset()
    {
        $this->chat_guard();
        $this->load->library(AI_MCP_MODULE_NAME . '/ai_mcp_agent');
        $this->ai_mcp_agent->reset(get_staff_user_id());
        $this->json(['success' => true]);
    }

    private function chat_guard()
    {
        if (!$this->input->is_ajax_request() || !ai_mcp_chat_available()) {
            ajax_access_denied();
        }
    }

    private function run_agent($callback)
    {
        @set_time_limit(300);
        ob_start();
        $this->load->library(AI_MCP_MODULE_NAME . '/ai_mcp_agent');
        try {
            $result = $callback($this->ai_mcp_agent);
        } catch (Throwable $e) {
            log_message('error', 'ai_mcp chat: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $result = ['error' => $e->getMessage()];
        }
        $this->json($result);
    }

    private function json($payload)
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
