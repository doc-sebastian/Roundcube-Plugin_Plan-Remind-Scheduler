<?php

/**
 * Plan & Remind – scheduled delivery worker.
 *
 * Run this every minute from the system crontab, e.g.:
 *
 *   * * * * * php /path/to/roundcube/plugins/plan_and_remind/bin/send_scheduled.php
 *
 * It looks up every queued message whose send time has arrived and delivers
 * it over SMTP. The IMAP "Sent" copy is written the next time the owning user
 * logs in (the cron has no IMAP session of its own).
 *
 * IMPORTANT: make sure the PHP CLI timezone matches the web server's, or
 * messages may go out at the wrong moment.
 *
 * NOTE on console output: Roundcube core and its bundled PEAR libraries are not
 * fully free of PHP 8.x deprecation/warning notices. Those originate in core,
 * not in this plugin, and do not affect delivery. We lower error_reporting here
 * so the cron stays quiet; real failures are still written to the log and the
 * summary line. You can also just append " >/dev/null 2>&1" in crontab.
 */

// Keep cron output to genuine errors only (silence library deprecations/notices).
error_reporting(E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR);

define('INSTALL_PATH', realpath(__DIR__ . '/../../../') . '/');

/*
 * Bootstrap Roundcube WITHOUT loading the configured plugins.
 *
 * A full rcmail bootstrap (program/include/clisetup.php → rcmail::get_instance)
 * loads every plugin listed in the 'plugins' config. A single third-party
 * plugin that is not PHP 8 compatible (e.g. carddav) then aborts the whole cron
 * run with a fatal error before any mail is processed. This worker only needs
 * core config, the database and SMTP, so we boot the *base* rcube instance,
 * which does not load the plugin list at all.
 */
require_once INSTALL_PATH . 'program/include/iniset.php';

// iniset may raise the level from config; keep the cron quiet.
error_reporting(E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR);

$rcmail = rcube::get_instance();
@set_time_limit(0);

// Re-assert after the instance bootstrap (config load can change the level).
error_reporting(E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR);

// Load this plugin's config without rcube_config::load_from_file(), which on
// PHP 8 emits "Undefined variable $rcmail_config" / cast deprecations. We just
// include the file (it defines $config) and copy the values in.
foreach (['config.inc.php', 'config.inc.php.dist'] as $cfg) {
    $file = __DIR__ . '/../' . $cfg;
    if (is_file($file)) {
        $config = [];
        include $file;
        if (!empty($config) && is_array($config)) {
            foreach ($config as $ckey => $cval) {
                $rcmail->config->set($ckey, $cval);
            }
        }
        break;
    }
}

require_once __DIR__ . '/../lib/plan_and_remind_storage.php';

$storage      = new plan_and_remind_storage($rcmail->get_dbh());
$max_attempts = (int) $rcmail->config->get('plan_and_remind_max_attempts', 5);
$save_to_sent = (bool) $rcmail->config->get('plan_and_remind_save_to_sent', true);

$due  = $storage->get_due($max_attempts, 200);
$sent = 0;
$fail = 0;

foreach ($due as $item) {
    $error = '';

    try {
        $ok = pnr_deliver($rcmail, $item, $error);
    } catch (Exception $e) {
        $ok    = false;
        $error = $e->getMessage();
    }

    if ($ok) {
        // Keep a sent-copy flag only for real scheduled messages with a target.
        $keep_copy = $save_to_sent && !empty($item['store_target']);

        // Try to save the sent copy directly via IMAP (cron has no web session).
        if ($keep_copy) {
            $sent_saved = pnr_save_sent_copy($rcmail, $item);
            if ($sent_saved) {
                // Copy successfully written. Clear the flag.
                $keep_copy = false;
            }
        }

        $storage->mark_sent($item['id'], $keep_copy);

        // Remove the physical scheduled-folder draft if one exists. The cron
        // worker cannot use the web session's IMAP connection, so we try with
        // the stored credentials and silently fall back to login_after cleanup.
        if (!empty($item['imap_uid']) && !empty($item['imap_folder'])) {
            pnr_remove_scheduled_draft($rcmail, $item);
        }

        $sent++;
    } else {
        $attempts = (int) $item['attempts'] + 1;
        if ($attempts >= $max_attempts) {
            $storage->mark_permanently_failed($item['id'], $error);
        } else {
            $storage->mark_failed($item['id'], $error);
        }
        $fail++;
        rcube::write_log('plan_and_remind', sprintf('delivery failed for #%d: %s', $item['id'], $error));
    }
}

// Light housekeeping.
$storage->purge_old((int) $rcmail->config->get('plan_and_remind_purge_days', 30));

if (php_sapi_name() === 'cli') {
    fwrite(STDOUT, sprintf("plan_and_remind: %d sent, %d failed, %d due\n", $sent, $fail, count($due)));
}

/**
 * Deliver a single queued item over SMTP.
 *
 * @param rcmail $rcmail
 * @param array  $item
 * @param string $error  filled with the SMTP error on failure
 * @return bool
 */
function pnr_deliver($rcmail, $item, &$error)
{
    $source     = pnr_refresh_date_header($item['mime_message']);
    $from       = $item['mail_from'];
    $recipients = json_decode($item['recipients'], true);

    if (empty($recipients)) {
        $error = 'no recipients';
        return false;
    }

    // Resolve SMTP credentials: per-message blob first, then the common relay.
    $delivery = $item['delivery'] ? json_decode($item['delivery'], true) : null;

    // Resolve the SMTP target from Roundcube's own config so the configured
    // smtp_port (e.g. 25) is honoured. Supports both the modern 'smtp_host'
    // (may embed scheme/port) and the older 'smtp_server' + 'smtp_port'.
    $host = $rcmail->config->get('smtp_host');
    if (!$host) {
        $host = $rcmail->config->get('smtp_server');
    }
    if (!$host) {
        $host = 'localhost';
    }
    $port = null;
    if (!preg_match('#[:/]#', (string) $host)) {
        $port = (int) $rcmail->config->get('smtp_port', 25);
    }

    if (!empty($delivery)) {
        $user = isset($delivery['smtp_user']) ? $delivery['smtp_user'] : null;
        $pass = isset($delivery['smtp_pass']) ? $rcmail->decrypt($delivery['smtp_pass']) : null;
        $helo = !empty($delivery['helo_host']) ? $delivery['helo_host'] : null;
    } else {
        $relay = $rcmail->config->get('plan_and_remind_smtp_host');
        if ($relay) {
            $host = str_replace(['%h', '%n', '%d', '%z'], '', (string) $relay);
            $port = preg_match('#[:/]#', $host) ? null : (int) $rcmail->config->get('smtp_port', 25);
        }
        $user = $rcmail->config->get('plan_and_remind_smtp_user');
        $pass = $rcmail->config->get('plan_and_remind_smtp_pass');
        $helo = null;
    }

    // Split the stored source into header block and body.
    $pos = strpos($source, "\r\n\r\n");
    if ($pos === false) {
        $pos    = strpos($source, "\n\n");
        $sep    = 2;
        $head   = substr($source, 0, $pos);
        $body   = substr($source, $pos + $sep);
    } else {
        $head = substr($source, 0, $pos);
        $body = substr($source, $pos + 4);
    }

    // Apply HELO host via config so we don't depend on the version-specific
    // position of the options argument in send_mail().
    if ($helo) {
        $rcmail->config->set('smtp_helo_host', $helo);
    }

    $smtp = new rcube_smtp();
    $conn = $smtp->connect($host, $port, $user, $pass);

    // Many setups deliver through a trusted localhost relay (e.g. localhost:25)
    // that neither requires nor advertises SMTP AUTH. If we sent credentials and
    // the server rejected authentication, retry once without any credentials.
    if (!$conn && ($user !== null && $user !== '')) {
        $first_err = pnr_smtp_error($smtp, '');
        if (stripos($first_err, 'authentic') !== false || stripos($first_err, 'auth') !== false) {
            $smtp = new rcube_smtp();
            $conn = $smtp->connect($host, $port, null, null);
        }
    }

    if (!$conn) {
        $error = pnr_smtp_error($smtp, 'SMTP connect failed');
        return false;
    }

    // Only the first four arguments are consistent across all Roundcube
    // versions; the trailing (error/response/options) parameters differ in
    // order, so we read errors via get_error()/get_response() instead.
    $ok = $smtp->send_mail($from, $recipients, $head, $body);

    if (!$ok) {
        $error = pnr_smtp_error($smtp, 'SMTP send failed');
    }

    $smtp->disconnect();

    return (bool) $ok;
}

/**
 * Extract a human-readable error string from rcube_smtp across versions.
 */
function pnr_smtp_error($smtp, $fallback)
{
    if (method_exists($smtp, 'get_error')) {
        $e = $smtp->get_error();
        if (is_array($e)) {
            $title = isset($e['title']) ? $e['title'] : '';
            $msg   = isset($e['msg']) ? $e['msg'] : '';
            $text  = trim($title . ' ' . $msg);
            if ($text !== '') {
                return $text;
            }
        } elseif (is_string($e) && $e !== '') {
            return $e;
        }
    }

    if (method_exists($smtp, 'get_response')) {
        $r = $smtp->get_response();
        if (is_array($r) && !empty($r)) {
            return implode('; ', $r);
        }
    }

    return $fallback;
}

/**
 * Replace (or insert) the Date header so it reflects the actual send time.
 */
function pnr_refresh_date_header($source)
{
    $eol = strpos($source, "\r\n") !== false ? "\r\n" : "\n";
    $sep = $eol . $eol;
    $pos = strpos($source, $sep);

    if ($pos === false) {
        return 'Date: ' . date('r') . $eol . $source;
    }

    $head = substr($source, 0, $pos);
    $body = substr($source, $pos);

    // Drop any existing Date header (case-insensitive, full folded line).
    $head = preg_replace('/^Date:.*(\r?\n[ \t].*)*\r?\n?/mi', '', $head);
    $head = rtrim($head, "\r\n");
    $head = 'Date: ' . date('r') . $eol . $head;

    return $head . $body;
}

/**
 * Build and open a standalone IMAP connection from the delivery credentials
 * stored alongside a queued item.  Used by the cron worker which has no web
 * session of its own.  Returns an open rcube_imap instance or null.
 *
 * @param rcmail $rcmail
 * @param array  $item
 * @return rcube_imap|null
 */
function pnr_imap_connect($rcmail, $item)
{
    $delivery = $item['delivery'] ? json_decode($item['delivery'], true) : null;
    if (empty($delivery) || empty($delivery['imap_host']) || empty($delivery['imap_user'])) {
        return null;
    }

    $imapHost = $delivery['imap_host'];
    $imapPort = isset($delivery['imap_port']) ? $delivery['imap_port'] : 143;
    $imapSsl  = isset($delivery['imap_ssl']) ? $delivery['imap_ssl'] : null;
    $imapUser = $delivery['imap_user'];
    $imapPass = isset($delivery['imap_pass']) ? $rcmail->decrypt($delivery['imap_pass']) : '';

    if ($imapPass === '') {
        return null;
    }

    // Build the IMAP connection URI including the scheme (ssl/tls) so that
    // rcube_imap parses it correctly.
    $scheme = '';
    if ($imapSsl === 'ssl' || $imapSsl === 'tls') {
        $scheme = $imapSsl . '://';
    } elseif ($imapSsl && $imapPort == 993) {
        $scheme = 'ssl://';
    }

    $connectUri = $scheme . $imapHost . ':' . $imapPort;

    require_once INSTALL_PATH . 'program/lib/Roundcube/rcube_imap.php';

    // Configure the storage layer so rcube_imap's constructor picks up the
    // correct host/port/ssl settings. The modern signature accepts a URI in
    // imap_host containing scheme://host:port; older versions use separate
    // imap_host + default_port + imap_conn_type keys.
    $rcmail->config->set('imap_host', $connectUri);
    $rcmail->config->set('default_port', $imapPort);
    $rcmail->config->set('imap_conn_type', $imapSsl ?: null);
    $rcmail->config->set('imap_user', $imapUser);
    $rcmail->config->set('imap_pass', $imapPass);

    $imap = new rcube_imap();

    // The connect() signature differs across Roundcube versions. We try the
    // main path first (host, port, ssl, user, pass) and fall back to a
    // config-driven approach if that fails.
    try {
        $connected = $imap->connect($imapHost, (int) $imapPort, $imapSsl ?: null, $imapUser, $imapPass);
    } catch (Throwable $e) {
        $connected = false;
    }

    if (!$connected) {
        // Fallback: construct URI as first arg (e.g. ssl://host:993).
        try {
            $connected = $imap->connect($connectUri, null, null, $imapUser, $imapPass);
        } catch (Throwable $e) {
            $connected = false;
        }
    }

    if (!$connected) {
        return null;
    }

    return $imap;
}

/**
 * Save a copy of the delivered message to the user's Sent folder via IMAP.
 *
 * The cron worker has no web session, so we build a standalone IMAP connection
 * using the credentials stored alongside the queued message.
 *
 * @param rcmail $rcmail
 * @param array  $item
 * @return bool  true if the copy was saved, false on any failure
 */
function pnr_save_sent_copy($rcmail, $item)
{
    try {
        $imap = pnr_imap_connect($rcmail, $item);
        if (!$imap) {
            rcube::write_log('plan_and_remind',
                sprintf('sent-copy IMAP connect failed for #%d', $item['id']));
            return false;
        }

        $folder = $item['store_target'];
        if (!$folder) {
            $folder = $rcmail->config->get('sent_mbox', 'Sent');
        }

        // Make sure the Sent folder exists.
        if (!$imap->folder_exists($folder)) {
            $imap->create_folder($folder, true);
        }

        $source = pnr_refresh_date_header($item['mime_message']);
        $saved  = $imap->save_message($folder, $source, '', false, ['SEEN']);
        $imap->close();

        if ($saved === false) {
            rcube::write_log('plan_and_remind',
                sprintf('sent-copy save_message returned false for #%d', $item['id']));
            return false;
        }

        return true;
    } catch (Exception $e) {
        rcube::write_log('plan_and_remind',
            sprintf('sent-copy exception for #%d: %s', $item['id'], $e->getMessage()));
        return false;
    }
}

/**
 * Remove the physical draft that was stored alongside a queued item in the
 * user's scheduled-messages IMAP folder.  The cron worker has no web session,
 * so we connect using the credentials stored alongside the queue row (the same
 * delivery blob used by pnr_save_sent_copy).  When connection is not possible
 * the draft is left in place and cleaned up by the login_after hook.
 *
 * @param rcmail $rcmail
 * @param array  $item
 */
function pnr_remove_scheduled_draft($rcmail, $item)
{
    $folder = isset($item['imap_folder']) ? $item['imap_folder'] : '';
    $uid    = isset($item['imap_uid']) ? (int) $item['imap_uid'] : 0;
    if (!$folder || !$uid) {
        return;
    }

    try {
        $imap = pnr_imap_connect($rcmail, $item);
        if (!$imap) {
            // Clean-up will be retried by the login_after hook.
            return;
        }

        // Select the folder first so delete_message operates correctly.
        $imap->set_folder($folder);
        $imap->delete_message($uid, $folder);
        $imap->close();
    } catch (Exception $e) {
        rcube::write_log('plan_and_remind',
            sprintf('scheduled-draft cleanup exception for #%d: %s', $item['id'], $e->getMessage()));
    }
}
