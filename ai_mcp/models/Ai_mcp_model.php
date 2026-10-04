<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Ai_mcp_model extends App_Model
{
    /**
     * Creates a personal access token. The plain token is returned once and only its
     * SHA-256 hash is stored.
     */
    public function create_token($staff_id, $name)
    {
        $plain = 'pmcp_' . bin2hex(random_bytes(24));

        $this->db->insert(db_prefix() . 'ai_mcp_tokens', [
            'staff_id'     => $staff_id,
            'name'         => mb_substr(trim($name) ?: 'AI client', 0, 191),
            'token_hash'   => hash('sha256', $plain),
            'token_prefix' => substr($plain, 0, 12),
            'created_at'   => date('Y-m-d H:i:s'),
        ]);

        return $plain;
    }

    public function get_staff_tokens($staff_id)
    {
        return $this->db->where('staff_id', $staff_id)
            ->where('revoked', 0)
            ->order_by('id', 'desc')
            ->get(db_prefix() . 'ai_mcp_tokens')->result_array();
    }

    public function revoke_token($id, $staff_id = null)
    {
        $this->db->where('id', $id);
        if ($staff_id !== null) {
            $this->db->where('staff_id', $staff_id);
        }
        $this->db->update(db_prefix() . 'ai_mcp_tokens', ['revoked' => 1]);

        return $this->db->affected_rows() > 0;
    }

    public function revoke_all_tokens()
    {
        $this->db->update(db_prefix() . 'ai_mcp_tokens', ['revoked' => 1]);
    }

    /**
     * Resolves a plain token to its row, or null. Checks revocation, not staff access
     * (the caller re-checks access on every request so permission changes apply instantly).
     */
    public function find_token($plain)
    {
        if (!is_string($plain) || strpos($plain, 'pmcp_') !== 0) {
            return null;
        }
        $row = $this->db->where('token_hash', hash('sha256', $plain))
            ->where('revoked', 0)
            ->get(db_prefix() . 'ai_mcp_tokens')->row_array();

        return $row ?: null;
    }

    public function touch_token($id, $ip)
    {
        $this->db->where('id', $id)->update(db_prefix() . 'ai_mcp_tokens', [
            'last_used_at' => date('Y-m-d H:i:s'),
            'last_used_ip' => mb_substr((string) $ip, 0, 64),
        ]);
    }

    public function log($staff_id, $channel, $tool, $arguments, $success, $message = null, $token_id = null)
    {
        $this->db->insert(db_prefix() . 'ai_mcp_logs', [
            'staff_id'   => $staff_id,
            'token_id'   => $token_id,
            'channel'    => $channel,
            'tool'       => mb_substr($tool, 0, 100),
            'arguments'  => mb_substr(json_encode($arguments, JSON_UNESCAPED_UNICODE), 0, 60000),
            'success'    => $success ? 1 : 0,
            'message'    => $message !== null ? mb_substr($message, 0, 2000) : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function calls_in_last_minute($staff_id)
    {
        return $this->db->where('staff_id', $staff_id)
            ->where('created_at >=', date('Y-m-d H:i:s', time() - 60))
            ->count_all_results(db_prefix() . 'ai_mcp_logs');
    }

    public function get_logs($limit = 200, $staff_id = null)
    {
        $this->db->select('l.*, CONCAT(s.firstname, " ", s.lastname) as staff_name', false)
            ->from(db_prefix() . 'ai_mcp_logs l')
            ->join(db_prefix() . 'staff s', 's.staffid = l.staff_id', 'left')
            ->order_by('l.id', 'desc')
            ->limit($limit);
        if ($staff_id) {
            $this->db->where('l.staff_id', $staff_id);
        }

        return $this->db->get()->result_array();
    }

    public function prune_logs($days)
    {
        $days = (int) $days;
        if ($days > 0) {
            $this->db->where('created_at <', date('Y-m-d H:i:s', strtotime('-' . $days . ' days')))
                ->delete(db_prefix() . 'ai_mcp_logs');
        }
    }

    /**
     * Chat state lives in its own table: Perfex's session table is a 64KB BLOB and a chat
     * transcript with tool results would overflow it.
     */
    public function get_conversation($staff_id, $provider)
    {
        $row = $this->db->where('staff_id', $staff_id)->get(db_prefix() . 'ai_mcp_conversations')->row();
        $state = $row && $row->provider === $provider ? json_decode($row->state, true) : null;

        return is_array($state) ? $state : ['messages' => [], 'display' => [], 'pending' => null];
    }

    public function save_conversation($staff_id, $provider, $state)
    {
        $this->db->replace(db_prefix() . 'ai_mcp_conversations', [
            'staff_id'   => $staff_id,
            'provider'   => $provider,
            'state'      => json_encode($state, JSON_UNESCAPED_UNICODE),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function delete_conversation($staff_id)
    {
        $this->db->where('staff_id', $staff_id)->delete(db_prefix() . 'ai_mcp_conversations');
    }

    public function get_all_tokens()
    {
        return $this->db->select('t.*, CONCAT(s.firstname, " ", s.lastname) as staff_name', false)
            ->from(db_prefix() . 'ai_mcp_tokens t')
            ->join(db_prefix() . 'staff s', 's.staffid = t.staff_id', 'left')
            ->where('t.revoked', 0)
            ->order_by('t.id', 'desc')
            ->get()->result_array();
    }
}
