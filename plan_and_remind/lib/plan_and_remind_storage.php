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

        // Ensure the connection can store 4-byte UTF-8 (emoji etc.).
        // Even with a utf8mb4 table, a legacy "utf8" (3-byte) connection
        // charset will reject those characters.
        $dbtype = method_exists($db, 'db_provider') ? $db->db_provider() : '';
        if ($dbtype === 'mysql' || $dbtype === 'mysqli') {
            try {
                @$db->query("SET NAMES utf8mb4");
            } catch (Throwable $e) {
                // Non-critical; if the server doesn't support utf8mb4 the
                // fallback sanitisation in add() will handle it.
            }
        }
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
            'delivery', 'imap_folder', 'imap_uid',
            'mime_message', 'sent_copy_pending', 'attempts',
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
            // Check if the error is an "incorrect string value" / charset issue.
            // This happens with 4-byte UTF-8 (emoji etc.) when the connection
            // charset is the legacy 3-byte "utf8" and couldn't be upgraded.
            $errStr = is_string($err) ? $err : 'database error';
            if (stripos($errStr, 'Incorrect string value') !== false
                || stripos($errStr, 'invalid byte sequence') !== false
                || stripos($errStr, 'charset') !== false
            ) {
                // Retry: sanitize all text fields to strip 4-byte characters.
                $values_sanitized = $this->sanitize_values($values);

                // Only retry if something actually changed.
                if ($values_sanitized !== $values) {
                    $this->db->query($sql, $values_sanitized);
                    $err = $this->db->is_error();
                }
            }

            if ($err) {
                $this->last_error = (is_string($err) ? $err : 'database error') . ' [table: ' . $this->table . ']';
                rcube::write_log('plan_and_remind', 'insert failed: ' . $this->last_error);
                return false;
            }
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

    /**
     * Look up a pending/sent item by its IMAP folder + UID link.
     *
     * @param string $folder
     * @param int    $uid
     * @param int    $user_id  optional, restrict to a single user
     * @return array|null
     */
    public function get_by_imap($folder, $uid, $user_id = null)
    {
        if ($folder === null || $uid === null) {
            return null;
        }
        $sql    = 'SELECT * FROM ' . $this->table
                . ' WHERE imap_folder = ? AND imap_uid = ?';
        $params = [(string) $folder, (int) $uid];
        if ($user_id !== null) {
            $sql     .= ' AND user_id = ?';
            $params[] = (int) $user_id;
        }
        $sql .= ' ORDER BY id DESC';
        $result = $this->db->query($sql, $params);
        return $this->db->fetch_assoc($result) ?: null;
    }

    /**
     * Get all pending items that have an IMAP folder/uid link.
     * Used for bi-directional sync reconciliation.
     *
     * @param int         $user_id
     * @param string|null $folder  restrict to a specific folder
     * @return array
     */
    public function get_pending_with_imap($user_id, $folder = null)
    {
        $sql    = 'SELECT id, imap_folder, imap_uid FROM ' . $this->table
                . ' WHERE user_id = ? AND status = ? AND imap_uid IS NOT NULL AND imap_folder IS NOT NULL';
        $params = [(int) $user_id, 'pending'];
        if ($folder !== null) {
            $sql     .= ' AND imap_folder = ?';
            $params[] = (string) $folder;
        }
        $result = $this->db->query($sql, $params);
        $items  = [];
        while ($row = $this->db->fetch_assoc($result)) {
            $items[] = $row;
        }
        return $items;
    }

    /**
     * Store or update the IMAP folder/UID pointer for an item so that the DB
     * row and the physical draft can be correlated.
     *
     * @param int    $id
     * @param string $folder
     * @param int    $uid
     */
    public function set_imap_link($id, $folder, $uid)
    {
        $this->db->query(
            'UPDATE ' . $this->table
            . ' SET imap_folder = ?, imap_uid = ? WHERE id = ?',
            $folder, (int) $uid, (int) $id
        );
    }

    /**
     * Clear the IMAP link so orphaned references don't interfere with
     * subsequent syncs.
     *
     * @param int $id
     */
    public function clear_imap_link($id)
    {
        $this->db->query(
            'UPDATE ' . $this->table
            . ' SET imap_folder = NULL, imap_uid = NULL WHERE id = ?',
            (int) $id
        );
    }

    /**
     * Sent items whose IMAP scheduled-folder draft still needs to be removed.
     * Used by the login_after hook when the cron couldn't delete the draft.
     * This is independent of the sent_copy_pending state.
     *
     * @param int $user_id
     * @param int $limit
     * @return array
     */
    public function get_sent_imap_pending($user_id, $limit = 50)
    {
        $result = $this->db->limitquery(
            'SELECT * FROM ' . $this->table
            . ' WHERE user_id = ? AND status = ? AND imap_uid IS NOT NULL'
            . ' AND imap_folder IS NOT NULL'
            . ' ORDER BY sent_at DESC',
            0, $limit,
            $user_id, 'sent'
        );

        $items = [];
        while ($row = $this->db->fetch_assoc($result)) {
            $items[] = $row;
        }

        return $items;
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

    /**
     * Strip 4-byte UTF-8 characters (emoji, supplementary planes) from all
     * string values in a data array. Used as a fallback when the DB connection
     * doesn't support utf8mb4.
     *
     * @param array $values indexed array of values (matching $cols order)
     * @return array sanitized values
     */
    private function sanitize_values(array $values)
    {
        foreach ($values as $i => $val) {
            if (is_string($val)) {
                // Remove any character outside the BMP (code points U+10000
                // and above), i.e. 4-byte UTF-8 sequences.
                $values[$i] = preg_replace('/[\x{10000}-\x{10FFFF}]/u', "\xEF\xBF\xBD", $val);
            }
        }
        return $values;
    }
}
