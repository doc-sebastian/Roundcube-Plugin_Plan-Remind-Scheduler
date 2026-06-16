<?php

/**
 * Plan & Remind
 * =============
 *
 * Delay, schedule and "undo" outgoing mail in Roundcube, plus an e-mail
 * reminder ("Wiedervorlage") feature that schedules a follow-up message for
 * an existing e-mail.
 *
 * Features
 *  - "Delayed send" button next to the Send button in the composer
 *    (stopwatch icon) to pick an arbitrary delivery date/time.
 *  - Undo-send safety delay: a configurable countdown is shown before a
 *    normal message actually leaves the composer; it can be cancelled.
 *  - "Wiedervorlage" entry in the message options menu and the message list
 *    right-click menu (stopwatch icon) to schedule a reminder for the
 *    selected message – to yourself by default, optionally to other people,
 *    with an optional note.
 *  - A management list in Settings to review and cancel pending items.
 *
 * Delivery happens through a per-minute cron job (bin/send_scheduled.php),
 * mirroring the well-known design used by comparable plugins. See README.md.
 *
 * NOTE: Roundcube plugin identifiers cannot contain hyphens (they map to a PHP
 * class name), therefore the technical name is `plan_and_remind`. The display
 * name remains "Plan & Remind".
 *
 * @license GPL-3.0+
 */
class plan_and_remind extends rcube_plugin
{
    public $task = 'mail|settings|login';

    /** @var rcmail */
    private $rc;

    /** @var plan_and_remind_storage */
    private $store;

    public function init()
    {
        $this->rc = rcmail::get_instance();

        $this->load_config();
        $this->add_texts('localization/', true);

        require_once __DIR__ . '/lib/plan_and_remind_storage.php';

        // Clean up the queue when a user account is removed.
        $this->add_hook('user_delete', [$this, 'on_user_delete']);

        // Write the IMAP "Sent" copy of messages delivered by cron the next
        // time the owner logs in (cron itself has no IMAP session).
        $this->add_hook('login_after', [$this, 'on_login_after']);

        if ($this->rc->task === 'mail') {
            // Expose the _pnr_replaced flag when an edited queued item compose
            // session starts, so JS can clean up the intermediate draft later.
            $pnrReplaced = rcube_utils::get_input_value('_pnr_replaced', rcube_utils::INPUT_GET);
            if ($pnrReplaced) {
                $this->rc->output->set_env('pnr_replaced', (int) $pnrReplaced);
                // Also expose the draft UID/ID so JS can clean up later.
                $draftUid = rcube_utils::get_input_value('_uid', rcube_utils::INPUT_GET);
                if ($draftUid) {
                    $this->rc->output->set_env('pnr_draft_uid', (int) $draftUid);
                }
                $draftId = rcube_utils::get_input_value('_draft_id', rcube_utils::INPUT_GET);
                if ($draftId) {
                    $this->rc->output->set_env('pnr_draft_uid', (int) $draftId);
                }
            }

            // When the compose page was opened from the scheduled-messages
            // folder, expose the origin so JS can inject hidden fields and
            // clean up the old draft on send/re-schedule.
            if ($this->rc->action === 'compose' && $this->scheduled_folder() !== '') {
                $originMbox = rcube_utils::get_input_value('_mbox', rcube_utils::INPUT_GET)
                           ?: rcube_utils::get_input_value('_mbox', rcube_utils::INPUT_POST);
                $originUid  = rcube_utils::get_input_value('_uid', rcube_utils::INPUT_GET)
                           ?: rcube_utils::get_input_value('_uid', rcube_utils::INPUT_POST);
                if ($originMbox === $this->scheduled_folder() && $originUid) {
                    $this->rc->output->set_env('pnr_origin_mbox', $originMbox);
                    $this->rc->output->set_env('pnr_origin_uid', (int) $originUid);

                    // Store origin in session as a backup (form fields may not
                    // survive all submit cycles in all Roundcube versions).
                    $_SESSION['pnr_compose_origin'] = [
                        'mbox'  => $originMbox,
                        'uid'   => (int) $originUid,
                        'time'  => time(),
                    ];

                    // Resolve the DB row so we know which item to cancel on
                    // send/re-schedule. Expose its id + type to JS.
                    $item = $this->storage()->get_by_imap($originMbox, (int) $originUid);
                    if ($item && $item['status'] === 'pending') {
                        $this->rc->output->set_env('pnr_replaced', (int) $item['id']);
                    }
                }
            }

            $this->init_mail();
        }

        if ($this->rc->task === 'settings') {
            $this->init_settings();
        }
    }

    /**
     * Hook: decorate message-list rows for drafts living in the scheduled-
     * messages folder so they show a visual indicator next to the subject.
     */
    public function on_messages_list($args)
    {
        $folder = $this->scheduled_folder();
        if ($folder === '' || !isset($args['messages']) || !is_array($args['messages'])) {
            return $args;
        }

        $mbox = isset($args['folder']) ? $args['folder'] : '';
        if ($mbox !== $folder) {
            return $args;
        }

        // Add a CSS class to scheduled-folder messages so the JS / skin can
        // style them. The actual "open in compose" happens in JavaScript.
        foreach ($args['messages'] as $msg) {
            if (is_object($msg)) {
                if (!isset($msg->list_flags) || !is_array($msg->list_flags)) {
                    $msg->list_flags = ['flags' => [], 'classname' => ''];
                }
                if (isset($msg->list_flags['classname'])) {
                    $extra = ' pnr-scheduled-draft';
                    if (strpos($msg->list_flags['classname'], $extra) === false) {
                        $msg->list_flags['classname'] .= $extra;
                    }
                }
            }
        }

        // Bi-directional sync reconciliation: compare the DB's pending items
        // against the IMAP folder. Any DB row whose IMAP UID is no longer
        // present in the folder means the user deleted or moved it → cancel.
        $this->reconcile_scheduled_folder($folder, $args['messages']);

        return $args;
    }

    /* --------------------------------------------------------------------- */
    /*  Initialisation                                                       */
    /* --------------------------------------------------------------------- */

    private function init_mail()
    {
        // Intercept actual SMTP delivery so a scheduled message is queued
        // instead of being sent right away.
        $this->add_hook('message_before_send', [$this, 'on_message_before_send']);

        // Decorate message-list rows in the scheduled-messages folder.
        $this->add_hook('messages_list', [$this, 'on_messages_list']);

        $this->include_script('plan_and_remind.js');
        $this->include_stylesheet($this->local_skin_path() . '/plan_and_remind.css');

        // Reminder entry for the message options / context menu.
        $this->add_button([
            'type'       => 'link',
            'command'    => 'plugin.pnr_reminder',
            'class'      => 'pnr-reminder',
            'classact'   => 'pnr-reminder active',
            'innerclass' => 'inner',
            'label'      => 'plan_and_remind.reminder',
            'title'      => 'plan_and_remind.reminder_desc',
        ], 'messagemenu');

        // Expose settings to the client.
        $this->rc->output->set_env('pnr_undo_enabled', (bool) $this->pref('pnr_undo_enabled', $this->rc->config->get('plan_and_remind_undo_enabled', true)));
        $this->rc->output->set_env('pnr_undo_delay', (int) $this->pref('pnr_undo_delay', $this->rc->config->get('plan_and_remind_default_delay', 10)));
        $this->rc->output->set_env('pnr_reminder_default_self', (bool) $this->pref('pnr_reminder_default_self', $this->rc->config->get('plan_and_remind_reminder_default_self', true)));
        $this->rc->output->set_env('pnr_identity_email', $this->self_email());
        // Expose the optional scheduled-messages IMAP folder so JS can detect
        // it on the message list and on the compose page.
        $this->rc->output->set_env('pnr_scheduled_mbox', $this->scheduled_folder());

        $this->register_action('plugin.pnr_create_reminder', [$this, 'action_create_reminder']);
        $this->register_action('plugin.pnr_cancel', [$this, 'action_cancel']);
        $this->register_action('plugin.pnr_edit', [$this, 'action_edit']);
        $this->register_action('plugin.pnr_delete_draft', [$this, 'action_delete_draft']);
        $this->register_action('plugin.pnr_list_folders', [$this, 'action_list_folders']);

        // Bi-directional sync: reconciliation endpoint called by JS when the
        // user views or refreshes the scheduled-messages folder.
        $this->register_action('plugin.pnr_reconcile', [$this, 'action_reconcile']);
    }

    /**
     * Replace (or insert) the Date header so it reflects the actual send/save
     * time. Also defined as a standalone function in bin/send_scheduled.php.
     */
    public function refresh_date_header($source)
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
     * AJAX action: prepare a queued item for editing in the composer.
     *
     * Saves the queued message source to the user's Drafts folder via IMAP
     * and returns the folder/uid so the JS opens a "compose from draft" URL
     * with a flag to cancel the original queued item on first save/send.
     */
    public function action_edit()
    {
        $id = (int) rcube_utils::get_input_value('_id', rcube_utils::INPUT_POST);
        if ($id <= 0) {
            $this->rc->output->command('display_message', $this->gettext('edit_failed'), 'error');
            $this->rc->output->send();
            return;
        }

        $item = $this->storage()->get($id, $this->rc->user->ID);
        if (!$item || $item['status'] !== 'pending') {
            $this->rc->output->command('display_message', $this->gettext('edit_failed'), 'error');
            $this->rc->output->send();
            return;
        }

        $er = error_reporting();
        error_reporting($er & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING & ~E_STRICT);
        $ob_level = ob_get_level();
        ob_start();

        try {
            $this->rc->storage_init();
            $imap   = $this->rc->get_storage();
            $folder = $this->rc->config->get('drafts_mbox') ?: 'Drafts';

            // Refresh Date header so the draft looks current.
            $source = $this->refresh_date_header($item['mime_message']);

            // Ensure \\Draft flag.
            if ($imap->folder_exists($folder)) {
                $saved = $imap->save_message($folder, $source, '', false, ['DRAFT', 'SEEN']);
            } else {
                // Create drafts folder if it doesn't exist.
                if ($imap->create_folder($folder, true)) {
                    $saved = $imap->save_message($folder, $source, '', false, ['DRAFT', 'SEEN']);
                } else {
                    $saved = false;
                }
            }

            if ($saved === false) {
                throw new Exception('IMAP save_message returned false for folder: ' . $folder);
            }

            // rcube_imap::save_message() returns the UID of the saved message.
            // In some edge cases or older PHP versions it may return 0 —
            // resolve via id2uid using a SEARCH for the highest UID.
            $uid = (int) $saved;
            if ($uid <= 0) {
                // Fallback: search for the last message in the folder.
                $imap->set_folder($folder);
                $all = $imap->search_once($folder, 'ALL', true);
                $uid = (int) $imap->id2uid($all->count(), $folder);
            }

            rcube::write_log('plan_and_remind', sprintf(
                'edit: saved queued item #%d to Drafts as UID %d in folder "%s" (save returned %s)',
                $id, $uid, $folder, var_export($saved, true)
            ));

            // Mark the old task as cancelled (it has been promoted to a draft).
            $this->storage()->cancel($id, $this->rc->user->ID);

            // Remove the old physical scheduled-draft copy if one exists.
            $this->imap_remove_scheduled_draft($id);

            // Tell the browser to open the draft in compose mode.
            // Roundcube uses _uid (not _draft_id) with _mbox to open a
            // message for editing. This is the same mechanism Roundcube
            // uses when you click on a draft in the Drafts folder.
            $url = './?_task=mail&_action=compose'
                 . '&_uid=' . $uid
                 . '&_mbox=' . urlencode($folder)
                 . '&_pnr_replaced=' . $id;

            $this->rc->output->command('plugin.pnr_edit_ready', [
                'url' => $url,
                'id'  => $id,
            ]);
            $this->rc->output->command('display_message', $this->gettext('edit_ok'), 'confirmation');
        } catch (Throwable $e) {
            rcube::write_log('plan_and_remind', 'edit action failed: ' . $e->getMessage());
            $this->rc->output->command('display_message',
                $this->gettext('edit_failed') . ' [' . $e->getMessage() . ']', 'error');
        } finally {
            while (ob_get_level() > $ob_level) {
                ob_end_clean();
            }
            error_reporting($er);
        }

        $this->rc->output->send();
    }

    /**
     * AJAX action: delete an intermediate draft created for editing a queued
     * item (called after the edited message is re-sent or re-scheduled).
     */
    public function action_delete_draft()
    {
        $uid  = rcube_utils::get_input_value('_uid', rcube_utils::INPUT_POST);
        $mbox = rcube_utils::get_input_value('_mbox', rcube_utils::INPUT_POST);

        if (!$uid || !$mbox) {
            $this->rc->output->send();
            return;
        }

        try {
            $this->rc->storage_init();
            $imap    = $this->rc->get_storage();
            $deleted = $imap->delete_message($uid, $mbox);
            $this->rc->output->command('plugin.pnr_draft_deleted', ['uid' => $uid, 'mbox' => $mbox]);
        } catch (Throwable $e) {
            rcube::write_log('plan_and_remind', 'delete draft failed: ' . $e->getMessage());
        }

        $this->rc->output->send();
    }

    /**
     * AJAX action: return the user's IMAP folder list as JSON so the settings
     * page can populate a dropdown for choosing the scheduled-messages folder.
     */
    public function action_list_folders()
    {
        $folders = [];
        try {
            $this->rc->storage_init();
            $imap     = $this->rc->get_storage();
            $all      = $imap->list_folders_subscribed('', '*');
            if (is_array($all)) {
                foreach ($all as $f) {
                    $folders[] = rcube::Q($f);
                }
            }
        } catch (Throwable $e) {
            // fall through with empty list
        }

        $this->rc->output->command('plugin.pnr_folders', $folders);
        $this->rc->output->send();
    }

    private function init_settings()
    {
        $this->add_hook('preferences_sections_list', [$this, 'prefs_section']);
        $this->add_hook('preferences_list', [$this, 'prefs_list']);
        $this->add_hook('preferences_save', [$this, 'prefs_save']);

        $this->include_script('plan_and_remind.js');
        $this->include_stylesheet($this->local_skin_path() . '/plan_and_remind.css');

        $this->register_action('plugin.pnr_cancel', [$this, 'action_cancel']);
        $this->register_action('plugin.pnr_edit', [$this, 'action_edit']);
        $this->register_action('plugin.pnr_list_folders', [$this, 'action_list_folders']);
    }

    /* --------------------------------------------------------------------- */
    /*  Hook: queue a scheduled message instead of sending it                */
    /* --------------------------------------------------------------------- */

    /**
     * Fires inside rcube::deliver_message() right before SMTP delivery.
     * (Saving drafts does not pass through this hook.)
     */
    public function on_message_before_send($args)
    {
        // Detect & resolve a prior scheduled item / draft being re-sent or
        // re-scheduled from the compose window so it is cleaned up (DB row
        // cancelled, physical draft deleted) rather than duplicated.
        // This runs for BOTH normal send and scheduled send.
        $this->handle_compose_supersede();

        $raw = rcube_utils::get_input_value('_pnr_schedule_at', rcube_utils::INPUT_POST);

        if ($raw === null || $raw === '') {
            return $args; // normal, immediate send
        }

        $ts = (int) $raw; // unix epoch seconds, UTC, computed by the client
        if ($ts <= time()) {
            return $args; // in the past → just send now
        }

        $message = $args['message']; // Mail_mime instance
        $from    = is_array($args['from']) ? reset($args['from']) : $args['from'];

        // Suppress library deprecation/notice output during our extra message
        // processing. With display_errors on (common on PHP 8), such output would
        // be injected into the send response and leave the composer stuck on
        // "sending…". Real failures are still logged and surfaced below.
        $er = error_reporting();
        error_reporting($er & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING & ~E_STRICT);

        // Capture any stray library output (PHP notices/deprecations printed with
        // display_errors on) so it cannot corrupt the send response and leave the
        // composer stuck on "sending…".
        $ob_level = ob_get_level();
        ob_start();

        try {
            try {
                list($source, $recipients, $subject) = $this->serialize_message($message);
            } catch (Throwable $e) {
                rcube::write_log('plan_and_remind', 'schedule serialize failed: ' . $e->getMessage());
                $args['abort']  = true;
                $args['result'] = false;
                $args['error']  = $this->gettext('schedule_failed');
                if ($this->rc->output) {
                    $this->rc->output->command('display_message',
                        $this->gettext('schedule_failed') . ' [' . $e->getMessage() . ']', 'error');
                }
                return $args;
            }

            // The composer stashes the original "save to Sent" target here before
            // blanking the live field (so no premature Sent copy is created). An
            // empty value means the user chose not to keep a copy.
            $store_target = rcube_utils::get_input_value('_pnr_store_target', rcube_utils::INPUT_POST);
            if ($store_target === null) {
                $store_target = $this->rc->config->get('sent_mbox');
            }
            $keep_copy = $store_target ? 1 : 0;

            $id = $this->storage()->add([
                'user_id'           => $this->rc->user->ID,
                'type'              => 'scheduled',
                'status'            => 'pending',
                'send_at'           => gmdate('Y-m-d H:i:s', $ts),
                'created_at'        => gmdate('Y-m-d H:i:s'),
                'mail_from'         => $from,
                'recipients'        => json_encode(array_values($recipients)),
                'subject'           => $subject,
                'store_target'      => $store_target ?: null,
                'delivery'          => $this->delivery_blob($from),
                'mime_message'      => $source,
                'sent_copy_pending' => $keep_copy,
                'attempts'          => 0,
            ]);

            // Abort the live SMTP delivery in any case.
            $args['abort'] = true;

            if (!$id) {
                // result=false makes rcube::deliver_message() report a failure so
                // the composer stays open and shows the error.
                $dberr = $this->storage()->get_last_error();
                rcube::write_log('plan_and_remind', 'schedule insert failed: ' . $dberr);
                $args['result'] = false;
                $args['error']  = $this->gettext('schedule_failed');
                if ($this->rc->output) {
                    $this->rc->output->command('display_message',
                        $this->gettext('schedule_failed') . ' [' . $dberr . ']', 'error');
                }
            } elseif (is_numeric($id) && !$this->storage()->exists((int) $id)) {
                $why = 'row not readable after insert – likely an uncommitted transaction '
                    . 'or a read/write DB split (db_dsnr vs db_dsnw) [table: '
                    . $this->storage()->table_name_full() . ']';
                rcube::write_log('plan_and_remind', 'schedule: ' . $why);
                $args['result'] = false;
                $args['error']  = $this->gettext('schedule_failed');
                if ($this->rc->output) {
                    $this->rc->output->command('display_message',
                        $this->gettext('schedule_failed') . ' [' . $why . ']', 'error');
                }
            } else {
                // Success. Set result=true explicitly: older Roundcube returns
                // $plugin['result'] verbatim from deliver_message(), so leaving it
                // unset would be read as a send failure even though we aborted on
                // purpose. Newer versions default to true – this is safe for both.
                $args['result'] = true;
                $args['error']  = null;
                $this->rc->output->set_env('pnr_last_scheduled',
                    $this->rc->format_date($ts, $this->rc->config->get('date_long')));

                // Sync the physical IMAP draft copy when the feature is active.
                if (is_numeric($id) && $id > 0 && $this->scheduled_folder() !== '') {
                    $this->imap_save_scheduled_draft(
                        (int) $id, $source, gmdate('Y-m-d H:i:s', $ts), 'scheduled'
                    );
                }
            }
        } catch (Throwable $e) {
            // Any unexpected error must not leave the composer stuck on
            // "sending…". Abort cleanly and report the reason.
            rcube::write_log('plan_and_remind', 'schedule hook error: ' . $e->getMessage());
            $args['abort']  = true;
            $args['result'] = false;
            $args['error']  = $this->gettext('schedule_failed');
            if ($this->rc->output) {
                $this->rc->output->command('display_message',
                    $this->gettext('schedule_failed') . ' [' . $e->getMessage() . ']', 'error');
            }
        } finally {
            while (ob_get_level() > $ob_level) {
                ob_end_clean();
            }
            error_reporting($er);
        }

        return $args;
    }

    /* --------------------------------------------------------------------- */
    /*  AJAX action: create a reminder for an existing message               */
    /* --------------------------------------------------------------------- */

    public function action_create_reminder()
    {
        $uids = rcube_utils::get_input_value('_uid', rcube_utils::INPUT_POST);
        $mbox = rcube_utils::get_input_value('_mbox', rcube_utils::INPUT_POST);
        $at   = (int) rcube_utils::get_input_value('_at', rcube_utils::INPUT_POST);
        $to   = rcube_utils::get_input_value('_to', rcube_utils::INPUT_POST, true);
        $note = rcube_utils::get_input_value('_note', rcube_utils::INPUT_POST, true);
        $incl = (bool) rcube_utils::get_input_value('_orig', rcube_utils::INPUT_POST);

        if ($at <= time()) {
            $this->rc->output->command('display_message', $this->gettext('past_time'), 'error');
            $this->rc->output->send();
            return;
        }

        // Resolve recipients; default to the user themselves.
        $recipients = $this->parse_recipients($to);
        if (empty($recipients)) {
            $recipients = array_filter([$this->self_email()]);
        }
        if (empty($recipients)) {
            $this->rc->output->command('display_message', $this->gettext('no_recipient'), 'error');
            $this->rc->output->send();
            return;
        }

        $count    = 0;
        $last_err = '';

        $er = error_reporting();
        error_reporting($er & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING & ~E_STRICT);
        $ob_level = ob_get_level();
        ob_start();

        try {
            $this->rc->storage_init();
            $imap = $this->rc->get_storage();
            $imap->set_folder($mbox);

            $from      = $this->self_email();
            $from_name = $this->self_name();

            foreach (array_filter(explode(',', (string) $uids), 'strlen') as $uid) {
                $headers = $imap->get_message_headers($uid);
                if (!$headers) {
                    $last_err = 'message headers not found for uid ' . $uid;
                    continue;
                }

                try {
                    list($source, $subject) = $this->build_reminder_message(
                        $headers, $from, $from_name, $recipients, $note, $incl, $imap, $uid, $at
                    );
                } catch (Throwable $e) {
                    $last_err = $e->getMessage();
                    rcube::write_log('plan_and_remind', 'build reminder failed: ' . $e->getMessage());
                    continue;
                }

                $id = $this->storage()->add([
                    'user_id'           => $this->rc->user->ID,
                    'type'              => 'reminder',
                    'status'            => 'pending',
                    'send_at'           => gmdate('Y-m-d H:i:s', $at),
                    'created_at'        => gmdate('Y-m-d H:i:s'),
                    'mail_from'         => $from,
                    'recipients'        => json_encode(array_values($recipients)),
                    'subject'           => $subject,
                    'store_target'      => null, // reminders are not copied to Sent by default
                    'delivery'          => $this->delivery_blob($from),
                    'mime_message'      => $source,
                    'sent_copy_pending' => 0,
                    'attempts'          => 0,
                ]);

                if ($id) {
                    if (is_numeric($id) && !$this->storage()->exists((int) $id)) {
                        $last_err = 'row not readable after insert – likely an uncommitted '
                            . 'transaction or a read/write DB split (db_dsnr vs db_dsnw) '
                            . '[table: ' . $this->storage()->table_name_full() . ']';
                    } else {
                        $count++;
                        // Sync the physical IMAP draft copy when the feature is active.
                        if (is_numeric($id) && $id > 0 && $this->scheduled_folder() !== '') {
                            $this->imap_save_scheduled_draft(
                                (int) $id, $source, gmdate('Y-m-d H:i:s', $at), 'reminder'
                            );
                        }
                    }
                } else {
                    $last_err = 'DB insert failed: ' . $this->storage()->get_last_error();
                }
            }
        } catch (Throwable $e) {
            $last_err = $e->getMessage();
            rcube::write_log('plan_and_remind', 'reminder action error: ' . $e->getMessage());
        } finally {
            while (ob_get_level() > $ob_level) {
                ob_end_clean();
            }
            error_reporting($er);
        }

        if ($count > 0) {
            $msg = $this->gettext([
                'name' => 'reminder_ok',
                'vars' => ['datetime' => $this->rc->format_date($at, $this->rc->config->get('date_long'))],
            ]);
            $this->rc->output->command('display_message', $msg, 'confirmation');
        } else {
            if ($last_err !== '') {
                rcube::write_log('plan_and_remind', 'reminder failed: ' . $last_err);
            }
            // Surface the underlying reason so it can be diagnosed without log access.
            $text = $this->gettext('reminder_failed');
            if ($last_err !== '') {
                $text .= ' [' . $last_err . ']';
            }
            $this->rc->output->command('display_message', $text, 'error');
        }

        $this->rc->output->send();
    }

    /* --------------------------------------------------------------------- */
    /*  AJAX action: cancel a pending item                                   */
    /* --------------------------------------------------------------------- */

    public function action_cancel()
    {
        $id = (int) rcube_utils::get_input_value('_id', rcube_utils::INPUT_POST);
        $ok = $id && $this->storage()->cancel($id, $this->rc->user->ID);

        if ($ok) {
            // Remove the physical scheduled-draft copy from IMAP as well.
            $this->imap_remove_scheduled_draft($id);
            $this->rc->output->command('plugin.pnr_cancelled', ['id' => $id]);
            $this->rc->output->command('display_message', $this->gettext('cancelled_ok'), 'confirmation');
        } else {
            $this->rc->output->command('display_message', $this->gettext('cancel_failed'), 'error');
        }

        $this->rc->output->send();
    }

    /* --------------------------------------------------------------------- */
    /*  Hook: write the IMAP "Sent" copy after a cron delivery               */
    /* --------------------------------------------------------------------- */

    public function on_login_after($args)
    {
        try {
            $pending = $this->storage()->get_sent_copy_pending($this->rc->user->ID);
            if (empty($pending)) {
                return $args;
            }

            $imap = $this->rc->get_storage();

            foreach ($pending as $row) {
                $folder = $row['store_target'] ?: $this->rc->config->get('sent_mbox');
                if (!$folder) {
                    $this->storage()->clear_sent_copy_flag($row['id']);
                    continue;
                }

                $source = $row['mime_message'];
                $saved  = $imap->save_message($folder, $source, '', false, ['SEEN']);

                if ($saved !== false) {
                    $this->storage()->clear_sent_copy_flag($row['id']);
                }
            }
        } catch (Exception $e) {
            rcube::raise_error([
                'code' => 600, 'type' => 'php',
                'message' => 'plan_and_remind: sent-copy on login failed: ' . $e->getMessage(),
            ], true, false);
        }

        // Clean up physical scheduled-draft copies that could not be removed by
        // the cron worker (which has no web IMAP session). This removes the
        // draft from the scheduled-messages folder after the message was
        // delivered.
        try {
            $cleanup = $this->storage()->get_sent_imap_pending($this->rc->user->ID);
            if (!empty($cleanup)) {
                $imap = $this->rc->get_storage();
                foreach ($cleanup as $row) {
                    if (!empty($row['imap_uid']) && !empty($row['imap_folder'])) {
                        $imap->delete_message($row['imap_uid'], $row['imap_folder']);
                    }
                    $this->storage()->clear_imap_link($row['id']);
                }
            }
        } catch (Exception $e) {
            rcube::raise_error([
                'code' => 600, 'type' => 'php',
                'message' => 'plan_and_remind: scheduled-draft cleanup on login failed: ' . $e->getMessage(),
            ], true, false);
        }

        // Bi-directional sync: cancel any pending DB rows whose physical draft
        // was deleted or moved out of the scheduled folder (e.g. via another
        // email client or direct IMAP manipulation) since the last login.
        $folder = $this->scheduled_folder();
        if ($folder !== '') {
            $this->reconcile_scheduled_folder($folder);
        }

        return $args;
    }

    public function on_user_delete($args)
    {
        if (!empty($args['user']) && $args['user']->ID) {
            $this->storage()->delete_for_user($args['user']->ID);
        }
        return $args;
    }

    /* --------------------------------------------------------------------- */
    /*  Preferences                                                          */
    /* --------------------------------------------------------------------- */

    public function prefs_section($args)
    {
        $args['list']['plan_and_remind'] = [
            'id'      => 'plan_and_remind',
            'section' => $this->gettext('plan_and_remind'),
        ];
        return $args;
    }

    public function prefs_list($args)
    {
        if ($args['section'] !== 'plan_and_remind') {
            return $args;
        }

        $this->add_texts('localization/');

        // --- Undo send -----------------------------------------------------
        $undo_enabled = (bool) $this->pref('pnr_undo_enabled', $this->rc->config->get('plan_and_remind_undo_enabled', true));
        $undo_delay   = (int) $this->pref('pnr_undo_delay', $this->rc->config->get('plan_and_remind_default_delay', 10));

        $chk = new html_checkbox(['name' => '_pnr_undo_enabled', 'id' => 'pnr_undo_enabled', 'value' => 1]);
        $num = new html_inputfield(['name' => '_pnr_undo_delay', 'id' => 'pnr_undo_delay', 'type' => 'number', 'min' => 1, 'max' => 120, 'size' => 4]);

        $args['blocks']['pnr_undo'] = [
            'name'    => $this->gettext('undosend'),
            'options' => [
                'enabled' => [
                    'title'   => html::label('pnr_undo_enabled', rcube::Q($this->gettext('undosend_enable'))),
                    'content' => $chk->show($undo_enabled ? 1 : 0),
                ],
                'delay' => [
                    'title'   => html::label('pnr_undo_delay', rcube::Q($this->gettext('undosend_delay'))),
                    'content' => $num->show($undo_delay) . ' ' . rcube::Q($this->gettext('seconds')),
                ],
            ],
        ];

        // --- Reminder defaults --------------------------------------------
        $self = (bool) $this->pref('pnr_reminder_default_self', $this->rc->config->get('plan_and_remind_reminder_default_self', true));
        $chk2 = new html_checkbox(['name' => '_pnr_reminder_default_self', 'id' => 'pnr_reminder_default_self', 'value' => 1]);

        $args['blocks']['pnr_reminder'] = [
            'name'    => $this->gettext('reminder'),
            'options' => [
                'self' => [
                    'title'   => html::label('pnr_reminder_default_self', rcube::Q($this->gettext('reminder_default_self'))),
                    'content' => $chk2->show($self ? 1 : 0),
                ],
            ],
        ];

        // --- Optional IMAP folder for scheduled messages & reminders -------
        $schedFolder   = $this->scheduled_folder();
        $autocreate    = (bool) $this->pref('pnr_scheduled_mbox_create', true);
        $folderInput   = new html_inputfield([
            'name'        => '_pnr_scheduled_mbox',
            'id'          => 'pnr_scheduled_mbox',
            'type'        => 'text',
            'size'        => 30,
            'placeholder' => $this->gettext('scheduled_mbox_none'),
        ]);
        $autoChk = new html_checkbox([
            'name' => '_pnr_scheduled_mbox_create', 'id' => 'pnr_scheduled_mbox_create', 'value' => 1,
        ]);

        $args['blocks']['pnr_folder'] = [
            'name' => $this->gettext('scheduled_mbox'),
            'options' => [
                'desc' => [
                    'title'   => '&nbsp;',
                    'content' => html::div(['class' => 'pnr-hint'],
                        rcube::Q($this->gettext('scheduled_mbox_desc'))),
                ],
                'folder' => [
                    'title'   => html::label('pnr_scheduled_mbox', rcube::Q($this->gettext('scheduled_mbox'))),
                    'content' => $folderInput->show($schedFolder),
                ],
                'autocreate' => [
                    'title'   => html::label('pnr_scheduled_mbox_create',
                        rcube::Q($this->gettext('scheduled_mbox_create'))),
                    'content' => $autoChk->show($autocreate ? 1 : 0),
                ],
            ],
        ];

        // --- Pending / scheduled items list -------------------------------
        $args['blocks']['pnr_queue'] = [
            'name'    => $this->gettext('scheduled_list'),
            'options' => [
                'list' => ['content' => $this->render_queue_table()],
            ],
        ];

        return $args;
    }

    public function prefs_save($args)
    {
        if ($args['section'] !== 'plan_and_remind') {
            return $args;
        }

        $delay = (int) rcube_utils::get_input_value('_pnr_undo_delay', rcube_utils::INPUT_POST);
        $delay = max(1, min(120, $delay ?: 10));

        $args['prefs']['pnr_undo_enabled']         = (bool) rcube_utils::get_input_value('_pnr_undo_enabled', rcube_utils::INPUT_POST);
        $args['prefs']['pnr_undo_delay']           = $delay;
        $args['prefs']['pnr_reminder_default_self'] = (bool) rcube_utils::get_input_value('_pnr_reminder_default_self', rcube_utils::INPUT_POST);

        // Scheduled-messages IMAP folder (optional).
        $schedMbox = trim((string) rcube_utils::get_input_value('_pnr_scheduled_mbox', rcube_utils::INPUT_POST));
        $args['prefs']['pnr_scheduled_mbox']        = $schedMbox;
        $args['prefs']['pnr_scheduled_mbox_create'] = (bool) rcube_utils::get_input_value('_pnr_scheduled_mbox_create', rcube_utils::INPUT_POST);

        return $args;
    }

    /**
     * Build the HTML table of the current user's pending items with
     * cancel links (wired up in plan_and_remind.js).
     */
    private function render_queue_table()
    {
        $items = $this->storage()->list_for_user($this->rc->user->ID, ['pending']);

        if (empty($items)) {
            return html::div(['class' => 'pnr-empty'], rcube::Q($this->gettext('no_scheduled')));
        }

        $rows = '';
        foreach ($items as $it) {
            $recips = implode(', ', (array) json_decode($it['recipients'], true));
            $when   = $this->format_stored_date($it['send_at']);
            $type = $this->gettext($it['type'] === 'reminder' ? 'type_reminder' : 'type_scheduled');

            $cancel = html::a([
                'href'    => '#',
                'class'   => 'pnr-cancel button',
                'rel'     => (int) $it['id'],
                'onclick' => sprintf('return rcmail.command(\'plugin.pnr_cancel_item\', %d, this, event)', (int) $it['id']),
            ], rcube::Q($this->gettext('cancel')));

            // Only scheduled messages (not reminders) can be edited before sending.
            $edit = '';
            if ($it['type'] === 'scheduled') {
                $edit = html::a([
                    'href'    => '#',
                    'class'   => 'pnr-edit button',
                    'rel'     => (int) $it['id'],
                    'onclick' => sprintf('return rcmail.command(\'plugin.pnr_edit_item\', %d, this, event)', (int) $it['id']),
                ], rcube::Q($this->gettext('edit')));
            }

            $actions = trim($edit . ' ' . $cancel);
            $rows .= html::tag('tr', ['data-id' => (int) $it['id']],
                html::tag('td', null, rcube::Q($when)) .
                html::tag('td', null, rcube::Q($type)) .
                html::tag('td', null, rcube::Q($it['subject'] ?: '–')) .
                html::tag('td', null, rcube::Q($recips)) .
                html::tag('td', ['class' => 'pnr-actions'], $actions)
            );
        }

        $head = html::tag('tr', null,
            html::tag('th', null, rcube::Q($this->gettext('send_at'))) .
            html::tag('th', null, rcube::Q($this->gettext('type'))) .
            html::tag('th', null, rcube::Q($this->gettext('subject'))) .
            html::tag('th', null, rcube::Q($this->gettext('recipients'))) .
            html::tag('th', null, '')
        );

        return html::tag('table', ['class' => 'pnr-queue records-table'],
            html::tag('thead', null, $head) . html::tag('tbody', null, $rows)
        );
    }

    /* --------------------------------------------------------------------- */
    /*  Message building helpers                                             */
    /* --------------------------------------------------------------------- */

    /**
     * Turn a fully built Mail_mime object into a transmittable source string.
     * The envelope recipients (To + Cc + Bcc) are extracted and the Bcc header
     * is stripped from the stored copy.
     *
     * @return array [string $source, array $recipients, string $subject]
     */
    private function serialize_message($message)
    {
        // Parameters mirror Roundcube's own delivery defaults.
        $params = [
            'head_encoding' => 'quoted-printable',
            'head_charset'  => 'UTF-8',
            'html_charset'  => 'UTF-8',
            'text_charset'  => 'UTF-8',
        ];

        $body    = $message->get($params);   // builds the MIME body
        $headers = $message->headers();      // now includes structural headers

        $recipients = [];
        foreach (['To', 'Cc', 'Bcc'] as $h) {
            if (!empty($headers[$h])) {
                foreach (rcube_mime::decode_address_list($headers[$h], null, true, null, false) as $a) {
                    if (!empty($a['mailto'])) {
                        $recipients[] = $a['mailto'];
                    }
                }
            }
        }
        $recipients = array_values(array_unique($recipients));
        $subject    = isset($headers['Subject']) ? $headers['Subject'] : '';

        // Re-emit headers without Bcc so recipients never see the blind list.
        unset($headers['Bcc']);
        $head   = $message->txtHeaders($headers, true);
        $source = $head . "\r\n" . $body;

        return [$source, $recipients, $this->decode_header_text($subject)];
    }

    /**
     * Build a reminder e-mail referencing an existing message.
     *
     * @return array [string $source, string $subject]
     */
    private function build_reminder_message($headers, $from, $from_name, $recipients, $note, $include_original, $imap, $uid, $send_ts)
    {
        $orig_subject    = $this->decode_header_text($headers->subject);
        $orig_from       = $this->decode_header_text($headers->from);
        $orig_date       = $headers->date ? $this->rc->format_date($headers->date) : '';

        // Resolve Message-ID from the rcube_message_header object (NOT array access).
        $orig_message_id = '';
        if (isset($headers->messageID)) {
            $orig_message_id = trim($headers->messageID);
        }
        if ($orig_message_id === '' && method_exists($headers, 'get')) {
            $mid = $headers->get('message-id');
            if ($mid) {
                $orig_message_id = trim($mid);
            }
        }

        // Resolve References from the rcube_message_header object.
        $ref_raw = '';
        if (isset($headers->references)) {
            $ref_raw = trim($headers->references);
        }
        if ($ref_raw === '' && method_exists($headers, 'get')) {
            $rv = $headers->get('references');
            if ($rv) {
                $ref_raw = trim($rv);
            }
        }

        // Build References chain: existing references from the original +
        // the original's own Message-ID, so this reminder threads under it.
        $references = [];
        if ($ref_raw !== '') {
            foreach (preg_split('/\\s+/', $ref_raw) as $ref) {
                $ref = trim($ref);
                if ($ref !== '' && !in_array($ref, $references)) {
                    $references[] = $ref;
                }
            }
        }
        if ($orig_message_id !== '' && !in_array($orig_message_id, $references)) {
            $references[] = $orig_message_id;
        }
        // Limit to the most recent ~10 references to stay RFC-friendly.
        if (count($references) > 10) {
            $references = array_slice($references, -10);
        }

        $subject = $this->gettext([
            'name' => 'reminder_subject',
            'vars' => ['subject' => $orig_subject !== '' ? $orig_subject : $this->gettext('no_subject')],
        ]);

        // Plain-text reminder body.
        $lines   = [];
        $lines[] = $this->gettext('reminder_intro');
        $lines[] = '';
        $lines[] = $this->gettext('reminder_field_subject') . ' ' . ($orig_subject ?: '–');
        $lines[] = $this->gettext('reminder_field_from') . ' ' . ($orig_from ?: '–');
        if ($orig_date) {
            $lines[] = $this->gettext('reminder_field_date') . ' ' . $orig_date;
        }
        if ($note !== null && trim($note) !== '') {
            $lines[] = '';
            $lines[] = $this->gettext('reminder_field_note');
            $lines[] = trim($note);
        }
        $lines[] = '';
        $lines[] = '-- ';
        $lines[] = $this->gettext('reminder_footer');

        $body = implode("\r\n", $lines);

        if (!class_exists('Mail_mime')) {
            @include_once 'Mail/mime.php';
        }

        $mime = new Mail_mime("\r\n");

        $mail_headers = [
            'From'                  => $from_name ? sprintf('%s <%s>', $from_name, $from) : $from,
            'To'                    => implode(', ', $recipients),
            'Subject'               => $subject,
            'Date'                  => date('r', $send_ts),
            'Message-ID'            => $this->gen_message_id($from),
            'X-Plan-And-Remind'     => 'reminder',
            'Auto-Submitted'        => 'auto-generated',
        ];

        // Thread the reminder under the original message so mail clients
        // show it in the same conversation.
        if ($orig_message_id !== '') {
            $mail_headers['In-Reply-To'] = $orig_message_id;
        }
        if (!empty($references)) {
            $mail_headers['References'] = implode(' ', $references);
        }

        $mime->headers($mail_headers);
        $mime->setTXTBody($body);

        // Optionally attach the original message as .eml.
        if ($include_original) {
            $raw = $imap->get_raw_body($uid);
            if ($raw) {
                $fname = ($orig_subject ?: 'message') . '.eml';
                $mime->addAttachment($raw, 'message/rfc822', $fname, false);
            }
        }

        $params = [
            'head_encoding' => 'quoted-printable',
            'head_charset'  => 'UTF-8',
            'text_charset'  => 'UTF-8',
        ];

        $mbody  = $mime->get($params);

        // IMPORTANT: pass the explicit headers array (not null) to txtHeaders,
        // because some PEAR Mail_mime versions do not include custom headers
        // like In-Reply-To/References when called with null.
        $hdrs   = $mime->headers();
        $mhead  = $mime->txtHeaders($hdrs, true);
        $source = $mhead . "\r\n" . $mbody;

        // Debug: verify that threading headers are present in the final source.
        $logIrt = '';
        $logRef = '';
        if (preg_match('/^In-Reply-To:\\s*(.+)$/mi', $source, $m)) { $logIrt = trim($m[1]); }
        if (preg_match('/^References:\\s*(.+)$/mi', $source, $m)) { $logRef = trim($m[1]); }
        rcube::write_log('plan_and_remind', sprintf(
            'reminder built: orig_msgid=[%s] in_reply_to=[%s] references=[%s]',
            $orig_message_id, $logIrt, $logRef
        ));

        return [$source, $subject];
    }

    /* --------------------------------------------------------------------- */
    /*  Delivery credentials                                                 */
    /* --------------------------------------------------------------------- */

    /**
     * Build the JSON delivery blob stored with a queued message.
     * The SMTP password is encrypted with Roundcube's des_key, which the
     * cron job can decrypt as well. When credential storage is disabled the
     * cron falls back to the common relay account from config.
     */
    private function delivery_blob($from)
    {
        if (!$this->rc->config->get('plan_and_remind_store_credentials', true)) {
            return null;
        }

        $smtp_host_cfg = $this->rc->config->get('smtp_host');
        if ($smtp_host_cfg === null || $smtp_host_cfg === '') {
            // Older Roundcube used smtp_server; fall back gracefully.
            $smtp_host_cfg = $this->rc->config->get('smtp_server', 'localhost:587');
        }

        if (method_exists('rcube_utils', 'parse_host')) {
            $smtp_host = rcube_utils::parse_host(
                $smtp_host_cfg,
                isset($_SESSION['storage_host']) ? $_SESSION['storage_host'] : ''
            );
        } else {
            $smtp_host = $smtp_host_cfg;
        }

        $user = isset($_SESSION['username']) ? $_SESSION['username'] : '';
        $pass = isset($_SESSION['password']) ? $this->rc->decrypt($_SESSION['password']) : '';

        $blob = [
            'smtp_host' => $smtp_host,
            'smtp_user' => $user,
            'smtp_pass' => $this->rc->encrypt((string) $pass),
            'helo_host' => $this->helo_host(),
            'from'      => $from,
        ];

        // Store IMAP connection info so the cron worker can save a copy to the
        // user's Sent folder directly, without waiting for them to log in.
        $imap_host = isset($_SESSION['storage_host']) ? $_SESSION['storage_host'] : '';
        $imap_port = isset($_SESSION['storage_port']) ? $_SESSION['storage_port'] : '';
        $imap_ssl  = isset($_SESSION['storage_ssl']) ? $_SESSION['storage_ssl'] : '';

        if ($imap_host) {
            $blob['imap_host'] = $imap_host;
            $blob['imap_port'] = $imap_port ?: 143;
            $blob['imap_ssl']  = $imap_ssl;
            $blob['imap_user'] = $user;
            $blob['imap_pass'] = $this->rc->encrypt((string) $pass);
        }

        return json_encode($blob);
    }

    /**
     * Determine a HELO hostname in a way that works on every Roundcube version
     * (rcube_utils::server_name() does not exist in older releases).
     */
    private function helo_host()
    {
        $helo = $this->rc->config->get('smtp_helo_host');
        if ($helo) {
            return $helo;
        }

        if (method_exists('rcube_utils', 'server_name')) {
            $name = rcube_utils::server_name('SERVER_NAME');
            if ($name) {
                return $name;
            }
        }

        if (!empty($_SERVER['SERVER_NAME'])) {
            return $_SERVER['SERVER_NAME'];
        }

        $host = function_exists('gethostname') ? gethostname() : '';
        return $host ?: 'localhost';
    }

    /* --------------------------------------------------------------------- */
    /*  Small utilities                                                      */
    /* --------------------------------------------------------------------- */

    private function storage()
    {
        if (!$this->store) {
            $this->store = new plan_and_remind_storage($this->rc->get_dbh());
        }
        return $this->store;
    }

    private function pref($key, $default = null)
    {
        $val = $this->rc->config->get($key);
        return $val === null ? $default : $val;
    }

    /**
     * Resolve the optional IMAP folder for scheduled messages & reminders.
     *
     * The user preference overrides the global config default. Returns the
     * mailbox name or an empty string when the feature is disabled.
     *
     * @return string
     */
    private function scheduled_folder()
    {
        $folder = $this->pref('pnr_scheduled_mbox',
            $this->rc->config->get('plan_and_remind_scheduled_mbox', ''));
        $folder = trim((string) $folder);
        return $folder;
    }

    /**
     * Ensure the scheduled-messages IMAP folder exists. Creates it when the
     * "auto-create" preference is active (default). Returns true on success
     * or when the folder is already present.
     *
     * @param rcube_imap $imap
     * @return bool
     */
    private function ensure_scheduled_folder($imap)
    {
        $folder = $this->scheduled_folder();
        if ($folder === '') {
            return false;
        }
        if ($imap->folder_exists($folder)) {
            return true;
        }
        $autocreate = (bool) $this->pref('pnr_scheduled_mbox_create',
            $this->rc->config->get('plan_and_remind_scheduled_mbox_create', true));
        if (!$autocreate) {
            return false;
        }
        return (bool) $imap->create_folder($folder, true);
    }

    /**
     * Write (or replace) the physical draft copy of a queued item in the
     * scheduled-messages IMAP folder. The message carries custom headers so
     * the DB row and the draft can be correlated.
     *
     * @param int    $id       queue row id
     * @param string $source   full MIME source (without PNR headers yet)
     * @param string $send_at  UTC "Y-m-d H:i:s"
     * @param string $type     "scheduled" or "reminder"
     * @return bool
     */
    private function imap_save_scheduled_draft($id, $source, $send_at, $type)
    {
        $folder = $this->scheduled_folder();
        if ($folder === '') {
            return false;
        }

        try {
            $this->rc->storage_init();
            $imap = $this->rc->get_storage();

            if (!$this->ensure_scheduled_folder($imap)) {
                return false;
            }
        } catch (Throwable $e) {
            rcube::write_log('plan_and_remind',
                sprintf('imap_save_scheduled_draft: folder setup failed for #%d: %s', $id, $e->getMessage()));
            return false;
        }

        // Inject / replace the custom identification headers so we can match
        // the draft back to the DB row later.
        $source = $this->inject_pnr_headers($source, $id, $send_at, $type);

        // Mark as draft so Roundcube offers the "edit" action.
        try {
            $saved = $imap->save_message($folder, $source, '', false, ['DRAFT', 'SEEN']);
        } catch (Throwable $e) {
            rcube::write_log('plan_and_remind',
                sprintf('imap_save_scheduled_draft: save_message failed for #%d: %s', $id, $e->getMessage()));
            return false;
        }

        if ($saved === false) {
            return false;
        }

        $uid = (int) $saved;
        if ($uid <= 0) {
            // Resolve via search fallback (older PHP / driver versions).
            try {
                $all  = $imap->search_once($folder, 'ALL', true);
                $uid  = (int) $imap->id2uid($all->count(), $folder);
            } catch (Throwable $e) {
                $uid = 0;
            }
        }

        if ($uid > 0) {
            $this->storage()->set_imap_link($id, $folder, $uid);
            return true;
        }

        return false;
    }

    /**
     * Remove an existing scheduled-messages draft from IMAP when the DB
     * row has been promoted to the standard Drafts folder or otherwise
     * superseded. Silently ignores errors.
     *
     * @param int $id  queue row id
     */
    private function imap_remove_scheduled_draft($id)
    {
        $item = $this->storage()->get($id, $this->rc->user->ID);
        if (!$item || empty($item['imap_uid']) || empty($item['imap_folder'])) {
            return;
        }

        try {
            $this->rc->storage_init();
            $imap = $this->rc->get_storage();
            $imap->delete_message($item['imap_uid'], $item['imap_folder']);
        } catch (Throwable $e) {
            rcube::write_log('plan_and_remind',
                sprintf('imap_remove_scheduled_draft: failed for #%d: %s', $id, $e->getMessage()));
        }

        $this->storage()->clear_imap_link($id);
    }

    /**
     * Inject X-Plan-And-Remind-Id / -Send-At / -Type headers into a MIME
     * source string so the physical draft can be correlated with the DB row.
     * The Date header is also set to the scheduled send time so the folder
     * listing sorts chronologically.
     *
     * @param string $source
     * @param int    $id
     * @param string $send_at  UTC "Y-m-d H:i:s"
     * @param string $type
     * @return string
     */
    private function inject_pnr_headers($source, $id, $send_at, $type)
    {
        $eol = strpos($source, "\r\n") !== false ? "\r\n" : "\n";
        $sep = $eol . $eol;
        $pos = strpos($source, $sep);

        if ($pos === false) {
            $head = '';
            $body = $source;
        } else {
            $head = substr($source, 0, $pos);
            $body = substr($source, $pos);
        }

        // Remove any existing PNR custom headers (e.g. from a prior version).
        $head = preg_replace('/^X-Plan-And-Remind-(Id|Send-At|Type):.*(\r?\n[ \t].*)*\r?\n?/mi', '', $head);
        $head = rtrim($head, "\r\n");

        // Remove the existing Date header and set it to the scheduled time so
        // the message sorts correctly in the folder.
        $ts = strtotime($send_at . ' UTC');
        $dateStr = $ts ? date('r', $ts) : date('r');
        $head = preg_replace('/^Date:.*(\r?\n[ \t].*)*\r?\n?/mi', '', $head);
        $head = rtrim($head, "\r\n");

        $injected = 'Date: ' . $dateStr . $eol
            . 'X-Plan-And-Remind-Id: ' . (int) $id . $eol
            . 'X-Plan-And-Remind-Send-At: ' . $send_at . $eol
            . 'X-Plan-And-Remind-Type: ' . $type . $eol;

        return $injected . $head . $body;
    }

    /**
     * Given an existing DB item, refresh the corresponding physical draft.
     * Removes the old draft (if any) and saves a new one.
     *
     * @param array $item
     */
    private function imap_refresh_scheduled_draft(array $item)
    {
        $folder = $this->scheduled_folder();
        if ($folder === '') {
            return;
        }

        // Remove old draft if linked.
        if (!empty($item['imap_uid']) && !empty($item['imap_folder'])) {
            try {
                $this->rc->storage_init();
                $imap = $this->rc->get_storage();
                $imap->delete_message($item['imap_uid'], $item['imap_folder']);
            } catch (Throwable $e) {
                // Non-fatal; we'll create a fresh one below.
            }
            $this->storage()->clear_imap_link($item['id']);
        }

        $this->imap_save_scheduled_draft(
            $item['id'],
            $item['mime_message'],
            $item['send_at'],
            $item['type']
        );
    }

    /**
     * Bi-directional sync: compare pending DB rows that have IMAP links with
     * the actual messages present in the scheduled-messages folder. Any DB row
     * whose IMAP UID is not found in the folder is cancelled (the user deleted
     * or moved the physical draft).
     *
     * @param string       $folder   scheduled-folder name
     * @param array|null   $messages pre-fetched message list (from messages_list
     *                               hook), or null to fetch fresh
     */
    private function reconcile_scheduled_folder($folder, $messages = null)
    {
        if ($folder === '' || $folder === null) {
            return;
        }

        $rows = $this->storage()->get_pending_with_imap($this->rc->user->ID, $folder);
        if (empty($rows)) {
            return;
        }

        // Build a set of UIDs that currently exist in the folder.
        $existingUids = [];
        if ($messages && is_array($messages)) {
            foreach ($messages as $msg) {
                if (is_object($msg) && isset($msg->uid)) {
                    $existingUids[(int) $msg->uid] = true;
                }
            }
        }

        // If we don't have a pre-fetched list (e.g. from the reconcile action),
        // query the IMAP folder to get the current UIDs.
        if (empty($existingUids)) {
            try {
                $this->rc->storage_init();
                $imap   = $this->rc->get_storage();
                $index  = $imap->search_once($folder, 'ALL', true);
                $msgs   = $index->get();
                if (is_array($msgs)) {
                    foreach ($msgs as $uid) {
                        $existingUids[(int) $uid] = true;
                    }
                }
            } catch (Throwable $e) {
                rcube::write_log('plan_and_remind',
                    'reconcile: IMAP search failed for folder "' . $folder . '": ' . $e->getMessage());
                return;
            }
        }

        // Cancel DB rows whose IMAP UID is no longer in the folder.
        foreach ($rows as $row) {
            $uid = (int) $row['imap_uid'];
            if ($uid > 0 && !isset($existingUids[$uid])) {
                $this->storage()->cancel((int) $row['id'], $this->rc->user->ID);
                $this->storage()->clear_imap_link((int) $row['id']);
                rcube::write_log('plan_and_remind', sprintf(
                    'reconcile: cancelled #%d (imap uid %d no longer in "%s")',
                    $row['id'], $uid, $folder
                ));
            }
        }
    }

    /**
     * AJAX action: trigger bi-directional sync reconciliation for the
     * scheduled-messages folder. Called by JS after the folder listing
     * refreshes or after a delete operation.
     */
    public function action_reconcile()
    {
        $folder = $this->scheduled_folder();
        if ($folder !== '') {
            $this->reconcile_scheduled_folder($folder, null);
        }
        $this->rc->output->command('plugin.pnr_reconciled', []);
        $this->rc->output->send();
    }

    /**
     * Detect when the current compose session originated from a draft inside
     * the scheduled-messages IMAP folder and the user is re-scheduling it.
     * Returns [$dbId, $uid, $folder] or false.
     *
     * @return array|false
     */
    private function detect_scheduled_folder_reschedule()
    {
        $folder = $this->scheduled_folder();
        if ($folder === '') {
            return false;
        }

        // JS injects hidden form fields so the origin survives autosaves /
        // re-POSTs where _mbox/_uid are stripped by Roundcube.
        $mbox = rcube_utils::get_input_value('_pnr_origin_mbox', rcube_utils::INPUT_POST)
             ?: rcube_utils::get_input_value('_mbox', rcube_utils::INPUT_GET);
        $uid  = rcube_utils::get_input_value('_pnr_origin_uid', rcube_utils::INPUT_POST)
             ?: rcube_utils::get_input_value('_uid', rcube_utils::INPUT_GET);

        if (!$mbox || !$uid) {
            return false;
        }

        if ($mbox !== $folder) {
            return false;
        }

        // Find matching DB row.
        $item = $this->storage()->get_by_imap($folder, (int) $uid);
        if (!$item) {
            return false;
        }

        return [(int) $item['id'], (int) $uid, $folder];
    }

    /**
     * Detect and resolve a prior scheduled item that is being superseded
     * from the compose window — i.e. the user opened an existing scheduled
     * draft (either via the Settings list "Edit" button or directly from the
     * scheduled-messages IMAP folder) and is now sending or re-scheduling it.
     *
     * This is called from on_message_before_send (POST) so it runs both for
     * normal send and scheduled send. It reads hidden JS-injected fields
     * (_pnr_origin_mbox / _pnr_origin_uid) that persist the origin across
     * autosave/sending cycles, finds the matching DB row, cancels it, and
     * queues the physical draft deletion for JS cleanReplacedDraft.
     *
     * @return void
     */
    private function handle_compose_supersede()
    {
        // Only relevant on actual send/schedule POST.
        if ($this->rc->task !== 'mail') {
            return;
        }

        $originMbox = rcube_utils::get_input_value('_pnr_origin_mbox', rcube_utils::INPUT_POST);
        $originUid  = (int) rcube_utils::get_input_value('_pnr_origin_uid', rcube_utils::INPUT_POST);
        $replacedId = (int) rcube_utils::get_input_value('_pnr_replaced', rcube_utils::INPUT_POST);

        // Session fallback: if the hidden form fields weren't submitted
        // (some Roundcube versions strip unknown fields, or the compose
        // originated from a different tab), check the session-stored origin.
        if ((!$originMbox || !$originUid) && isset($_SESSION['pnr_compose_origin'])) {
            $sess = $_SESSION['pnr_compose_origin'];
            // Only use session origin if it's recent (within 24h).
            if (isset($sess['time']) && (time() - $sess['time']) < 86400) {
                if (!$originMbox && !empty($sess['mbox'])) {
                    $originMbox = $sess['mbox'];
                }
                if (!$originUid && !empty($sess['uid'])) {
                    $originUid = (int) $sess['uid'];
                }
            }
        }

        $schedFolder = $this->scheduled_folder();

        // Scenario A: direct-from-scheduled-folder edit.
        if ($originMbox && $originUid && $schedFolder !== '' && $originMbox === $schedFolder) {
            $item = $this->storage()->get_by_imap($originMbox, $originUid, $this->rc->user->ID);
            if ($item && $item['status'] === 'pending') {
                $this->storage()->cancel($item['id'], $this->rc->user->ID);
                $this->storage()->clear_imap_link($item['id']);

                // Delete the old physical draft directly via PHP — don't rely
                // solely on JS cleanReplacedDraft which may fail silently.
                try {
                    $this->rc->storage_init();
                    $imap = $this->rc->get_storage();
                    $imap->delete_message($originUid, $originMbox);
                } catch (Throwable $e) {
                    rcube::write_log('plan_and_remind',
                        sprintf('handle_compose_supersede: old draft delete failed for uid %d: %s',
                            $originUid, $e->getMessage()));
                }

                // Also tell JS to clean up (belt + suspenders).
                $this->rc->output->set_env('pnr_replaced_folder', $originMbox);
                $this->rc->output->set_env('pnr_replaced_folder_uid', $originUid);
                $this->rc->output->set_env('pnr_replaced', (int) $item['id']);
            }

            // Clear the session origin — it's been consumed.
            unset($_SESSION['pnr_compose_origin']);
        } elseif ($replacedId > 0) {
            // Scenario B: Settings-list "Edit". action_edit already cancelled
            // the old DB row; the old physical scheduled-folder draft was
            // already removed there. Nothing more to do here.
        }
    }

    private function self_email()
    {
        $ident = $this->rc->user->get_identity();
        if (!empty($ident['email'])) {
            return $ident['email'];
        }
        return $this->rc->get_user_email();
    }

    private function self_name()
    {
        $ident = $this->rc->user->get_identity();
        return !empty($ident['name']) ? $ident['name'] : '';
    }

    private function parse_recipients($str)
    {
        $out = [];
        if ($str === null || trim($str) === '') {
            return $out;
        }
        foreach (rcube_mime::decode_address_list($str, null, true, null, false) as $a) {
            if (!empty($a['mailto']) && filter_var($a['mailto'], FILTER_VALIDATE_EMAIL)) {
                $out[] = $a['mailto'];
            }
        }
        return array_values(array_unique($out));
    }

    private function decode_header_text($value)
    {
        if ($value === null || $value === '') {
            return '';
        }
        // Header may already be decoded (rcube_message_header) or raw.
        if (strpos($value, '=?') !== false) {
            $value = rcube_mime::decode_mime_string($value, $this->rc->config->get('default_charset', 'UTF-8'));
        }
        return trim($value);
    }

    private function gen_message_id($from)
    {
        if (method_exists($this->rc, 'gen_message_id')) {
            return $this->rc->gen_message_id($from);
        }

        $domain = 'localhost';
        if (strpos($from, '@') !== false) {
            $domain = substr(strrchr($from, '@'), 1);
        }

        return sprintf('<%s.%s@%s>', time(), bin2hex(random_bytes(8)), $domain);
    }

    private function format_stored_date($utc_string)
    {
        try {
            $dt = new DateTime($utc_string, new DateTimeZone('UTC'));
            return $this->rc->format_date($dt->format('U'), $this->rc->config->get('date_long'));
        } catch (Exception $e) {
            return $utc_string;
        }
    }
}
