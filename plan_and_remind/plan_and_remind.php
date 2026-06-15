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
 *  - "Geplantes Senden" button next to the Send button in the composer
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
            }

            $this->init_mail();
        }

        if ($this->rc->task === 'settings') {
            $this->init_settings();
        }
    }

    /* --------------------------------------------------------------------- */
    /*  Initialisation                                                       */
    /* --------------------------------------------------------------------- */

    private function init_mail()
    {
        // Intercept actual SMTP delivery so a scheduled message is queued
        // instead of being sent right away.
        $this->add_hook('message_before_send', [$this, 'on_message_before_send']);

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

        $this->register_action('plugin.pnr_create_reminder', [$this, 'action_create_reminder']);
        $this->register_action('plugin.pnr_cancel', [$this, 'action_cancel']);
        $this->register_action('plugin.pnr_edit', [$this, 'action_edit']);
        $this->register_action('plugin.pnr_delete_draft', [$this, 'action_delete_draft']);
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

            $uid = $imap->id2uid($saved, $folder);
            if (!$uid) {
                $uid = $saved;
            }

            // Mark the old task as cancelled (it has been promoted to a draft).
            $this->storage()->cancel($id, $this->rc->user->ID);

            // Tell the browser to open the draft in compose mode.
            $url = './?_task=mail&_action=compose&_draft_id=' . $uid
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

    private function init_settings()
    {
        $this->add_hook('preferences_sections_list', [$this, 'prefs_section']);
        $this->add_hook('preferences_list', [$this, 'prefs_list']);
        $this->add_hook('preferences_save', [$this, 'prefs_save']);

        $this->include_script('plan_and_remind.js');
        $this->include_stylesheet($this->local_skin_path() . '/plan_and_remind.css');

        $this->register_action('plugin.pnr_cancel', [$this, 'action_cancel']);
        $this->register_action('plugin.pnr_edit', [$this, 'action_edit']);
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
        $orig_message_id = isset($headers->messageID) ? trim($headers->messageID) : '';
        if ($orig_message_id === '' && isset($headers['message-id'])) {
            $orig_message_id = trim($headers['message-id']);
        }

        // Build References chain: existing references from the original +
        // the original's own Message-ID, so this reminder threads under it.
        $references = [];
        if (isset($headers->references) && $headers->references) {
            $refs = trim($headers->references);
            if ($refs !== '') {
                foreach (preg_split('/\\s+/', $refs) as $ref) {
                    $ref = trim($ref);
                    if ($ref !== '' && !in_array($ref, $references)) {
                        $references[] = $ref;
                    }
                }
            }
        } elseif (isset($headers['references']) && $headers['references']) {
            foreach (preg_split('/\\s+/', trim($headers['references'])) as $ref) {
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
        $mhead  = $mime->txtHeaders(null, true);
        $source = $mhead . "\r\n" . $mbody;

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
