<?php

/**
 * Plan & Remind – persistence layer.
 *
 * Thin wrapper around Roundcube's database handle that manages the
 * `plan_and_remind` queue table. All timestamps are stored as UTC
 * "Y-m-d H:i:s" strings so that the (timezone agnostic) cron job can
 * compare them with gmdate() reliably.
 *
 * @author  Plan & Remind
 * @license GPL-3.0+
 */
class plan_and_remind_storage
{
    /** @var rcube_db */
    private $db;

    /** @var string fully prefixed table name */
    private $table;

    /** @var string last database error message */
    private $last_error = '';

    public function __construct($db, $table = 'plan_and_remind')
    {
        $this->db    = $db;
        $this->table = $db->table_name($table);
    }

    public function get_last_error()
    {
        return $this->last_error;
    }

    public function table_name_full()
    {
        return $this->table;
    }

    /**
     * Verify a row is actually readable (catches uncommitted transactions or a
     * read/write DSN split where the INSERT "succeeds" but nothing persists).
     */
    public function exists($id)
    {
        if (!is_numeric($id)) {
            return true; // can't verify without a numeric id; assume ok
        }
        $result = $this->db->query('SELECT 1 FROM ' . $this->table . ' WHERE id = ?', (int) $id);
        return (bool) $this->db->fetch_array($result);
    }

    /**
     * Insert a new queued message (scheduled send or reminder).
     *
     * @param array $data associative record data
     * @return int|false  new row id or false on error
     */
    public function add(array $data)
    {
        $this->last_error = '';

        $cols = [
            'user_id', 'type', 'status', 'send_at', 'created_at',
            'mail_from', 'recipients', 'subject', 'store_target',
            'delivery', 'mime_message', 'sent_copy_pending', 'attempts',
        ];

        $quoted       = [];
        $values       = [];
        $placeholders = [];
        foreach ($cols as $c) {
            $quoted[]       = $this->db->quote_identifier($c);
            $placeholders[] = '?';
            $values[]       = array_key_exists($c, $data) ? $data[$c] : null;
        }

        $sql = 'INSERT INTO ' . $this->table
            . ' (' . implode(', ', $quoted) . ')'
            . ' VALUES (' . implode(', ', $placeholders) . ')';

        $this->db->query($sql, $values);

        // is_error() with no argument reflects the last query reliably across
        // driver versions (query() may return a handle even on failure).
        $err = $this->db->is_error();
        if ($err) {
            $this->last_error = (is_string($err) ? $err : 'database error') . ' [table: ' . $this->table . ']';
            rcube::write_log('plan_and_remind', 'insert failed: ' . $this->last_error);
            return false;
        }

        // Catch the silent case where the statement "ran" but no row was added.
        $affected = $this->db->affected_rows();
        if ($affected < 1) {
            $this->last_error = 'insert affected 0 rows – row not stored [table: ' . $this->table . ']';
            rcube::write_log('plan_and_remind', $this->last_error);
            return false;
        }

        // Row written. Return the id when the driver provides one, else just
        // success (some drivers/configs don't return lastInsertId reliably).
        $id = $this->db->insert_id('plan_and_remind');
        return $id ?: true;
    }

    /**
     * Fetch all items that are due for delivery right now.
     *
     * @param int $max_attempts skip rows that exhausted their retries
     * @param int $limit        max number of rows to return
     * @return array
     */
    public function get_due($max_attempts = 5, $limit = 100)
    {
        $now = gmdate('Y-m-d H:i:s');

        $result = $this->db->limitquery(
            'SELECT * FROM ' . $this->table
            . ' WHERE status = ? AND send_at <= ? AND attempts < ?'
            . ' ORDER BY send_at ASC',
            0, $limit,
            'pending', $now, $max_attempts
        );

        $items = [];
        while ($row = $this->db->fetch_assoc($result)) {
            $items[] = $row;
        }

        return $items;
    }

    /**
     * Items belonging to a user (for the management list in Settings).
     *
     * @param int   $user_id
     * @param array $statuses
     * @return array
     */
    public function list_for_user($user_id, array $statuses = ['pending'])
    {
        $in = $this->db->array2list($statuses, 'text');

        $result = $this->db->query(
            'SELECT id, type, status, send_at, created_at, sent_at, mail_from,'
            . ' recipients, subject, error_message, attempts'
            . ' FROM ' . $this->table
            . ' WHERE user_id = ? AND status IN (' . $in . ')'
            . ' ORDER BY send_at ASC',
            $user_id
        );

        $items = [];
        while ($row = $this->db->fetch_assoc($result)) {
            $items[] = $row;
        }

        return $items;
    }

    /**
     * Sent items whose IMAP "Sent" copy still has to be written.
     * Used by the login_after hook (cron has no IMAP session).
     */
    public function get_sent_copy_pending($user_id, $limit = 50)
    {
        $result = $this->db->limitquery(
            'SELECT * FROM ' . $this->table
            . ' WHERE user_id = ? AND status = ? AND sent_copy_pending = 1'
            . ' ORDER BY sent_at ASC',
            0, $limit,
            $user_id, 'sent'
        );

        $items = [];
        while ($row = $this->db->fetch_assoc($result)) {
            $items[] = $row;
        }

        return $items;
    }

    public function get($id, $user_id = null)
    {
        $sql    = 'SELECT * FROM ' . $this->table . ' WHERE id = ?';
        $params = [$id];

        if ($user_id !== null) {
            $sql     .= ' AND user_id = ?';
            $params[] = $user_id;
        }

        $result = $this->db->query($sql, $params);
        return $this->db->fetch_assoc($result) ?: null;
    }

    public function mark_sent($id, $keep_sent_copy = true)
    {
        // Clear stored credentials immediately once the message left the server.
        $this->db->query(
            'UPDATE ' . $this->table
            . ' SET status = ?, sent_at = ?, sent_copy_pending = ?, delivery = NULL, error_message = NULL'
            . ' WHERE id = ?',
            'sent', gmdate('Y-m-d H:i:s'), $keep_sent_copy ? 1 : 0, $id
        );
    }

    public function mark_failed($id, $error)
    {
        $this->db->query(
            'UPDATE ' . $this->table
            . ' SET attempts = attempts + 1, error_message = ?'
            . ' WHERE id = ?',
            (string) $error, $id
        );
    }

    public function mark_permanently_failed($id, $error)
    {
        $this->db->query(
            'UPDATE ' . $this->table
            . ' SET status = ?, attempts = attempts + 1, error_message = ?, delivery = NULL'
            . ' WHERE id = ?',
            'failed', (string) $error, $id
        );
    }

    public function clear_sent_copy_flag($id)
    {
        // Once the Sent copy is written we no longer need the raw message body.
        $this->db->query(
            'UPDATE ' . $this->table
            . ' SET sent_copy_pending = 0 WHERE id = ?',
            $id
        );
    }

    /**
     * Cancel a still-pending item. Ownership is enforced via user_id.
     *
     * @return bool true if a row was actually cancelled
     */
    public function cancel($id, $user_id)
    {
        $result = $this->db->query(
            'UPDATE ' . $this->table
            . ' SET status = ?, delivery = NULL'
            . ' WHERE id = ? AND user_id = ? AND status = ?',
            'cancelled', $id, $user_id, 'pending'
        );

        return $this->db->affected_rows($result) > 0;
    }

    public function delete_for_user($user_id)
    {
        $this->db->query(
            'DELETE FROM ' . $this->table . ' WHERE user_id = ?',
            $user_id
        );
    }

    /**
     * Housekeeping: drop old sent/cancelled rows.
     *
     * @param int $days rows older than this many days are removed
     */
    public function purge_old($days = 30)
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);

        $this->db->query(
            'DELETE FROM ' . $this->table
            . ' WHERE status IN (?, ?) AND sent_copy_pending = 0 AND created_at < ?',
            'sent', 'cancelled', $cutoff
        );
    }
}
