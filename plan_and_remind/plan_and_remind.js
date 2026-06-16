/**
 * Plan & Remind – client logic
 *
*/

/* global rcmail, rcube_event */

(function () {
    'use strict';

    if (window.rcmail === undefined) {
        return;
    }

    // shared per-page state
    var pnr = {
        pending_schedule: false, // set just before a scheduled send is triggered
        undo_timer:       null
    };

    // Reference to Roundcube's original submit_messageform (set when hooked).
    var nativeSubmitMessageform = null;

    // Global keydown guard: when a UI dialog (.ui-dialog, including our own)
    // is open, prevent numeric keys 0–5 (and any key with a global handler
    // registered by other plugins like Thunderbird Labels) from triggering
    // their command when the keystroke originates inside the dialog.
    if (!window._pnr_dialog_keyguard) {
        window._pnr_dialog_keyguard = true;
        document.addEventListener('keydown', function (e) {
            // Only act when a jQuery UI dialog is currently open.
            if (!document.querySelector('.ui-dialog:visible')) { return; }
            // Allow normal text entry in form fields, just stop propagation
            // and default behaviour so global hotkey handlers never fire.
            e.stopPropagation();
        }, true); // capture phase → runs before any plugin handlers
    }

    /* ----------------------------------------------------------------- */
    /*  Helpers                                                          */
    /* ----------------------------------------------------------------- */

    function L(key) {
        return rcmail.get_label(key, 'plan_and_remind');
    }

    // Minimal HTML escaper (avoids depending on rcmail.quote_html being present).
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function pad(n) {
        return (n < 10 ? '0' : '') + n;
    }

    // Format a Date as the local "YYYY-MM-DDTHH:MM" datetime-local value.
    function fmtLocal(d) {
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
            + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    function nowSec() {
        return Math.floor(Date.now() / 1000);
    }

    // Self-contained HTML escaper (does not depend on rcmail internals).
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // Compute a preset Date and write it into the dialog's datetime input.
    window.pnr_apply_preset = function (name) {
        var d = new Date();
        d.setSeconds(0, 0);

        switch (name) {
            case 'in1h': d.setHours(d.getHours() + 1); break;
            case 'in3h': d.setHours(d.getHours() + 3); break;
            case 'thisevening':
                d.setHours(18, 0, 0, 0);
                if (d.getTime() <= Date.now()) { d.setDate(d.getDate() + 1); }
                break;
            case 'tomorrow':
                d.setDate(d.getDate() + 1); d.setHours(8, 0, 0, 0);
                break;
            case 'monday':
                do { d.setDate(d.getDate() + 1); } while (d.getDay() !== 1);
                d.setHours(8, 0, 0, 0);
                break;
        }

        var input = document.getElementById('pnr-dt');
        if (input) { input.value = fmtLocal(d); }
        return false;
    };

    function presetButtons() {
        var keys = ['in1h', 'in3h', 'thisevening', 'tomorrow', 'monday'];
        var html = '<div class="pnr-presets">';
        keys.forEach(function (k) {
            html += '<a href="#" class="pnr-preset button" onclick="return pnr_apply_preset(\'' + k + '\')">'
                + esc(L('preset_' + k)) + '</a>';
        });
        return html + '</div>';
    }

    // Read the datetime input, returning a future epoch (s) or null on error.
    function readDateTime() {
        var input = document.getElementById('pnr-dt');
        if (!input || !input.value) {
            rcmail.display_message(L('past_time'), 'error');
            return null;
        }
        var ts = Math.floor(new Date(input.value).getTime() / 1000);
        if (!ts || ts <= nowSec()) {
            rcmail.display_message(L('past_time'), 'error');
            return null;
        }
        return ts;
    }

    function ensureHidden(name, value) {
        var form = rcmail.gui_objects.messageform;
        if (!form) { return; }
        var el = form.elements[name];
        if (!el) {
            el = document.createElement('input');
            el.type = 'hidden';
            el.name = name;
            form.appendChild(el);
        }
        el.value = value;
    }

    /* ----------------------------------------------------------------- */
    /*  Compose: scheduled send + undo countdown                         */
    /* ----------------------------------------------------------------- */

    function addComposeButton() {
        var send = null;

        // Find the real Send command button (not "save draft").
        var candidates = document.querySelectorAll('a, button');
        for (var i = 0; i < candidates.length; i++) {
            var oc = candidates[i].getAttribute('onclick') || '';
            if (/command\(\s*['"]send['"]/.test(oc) && !/savedraft/.test(oc)) {
                send = candidates[i];
                break;
            }
        }
        if (!send) { send = document.querySelector('a.send'); }
        if (document.getElementById('pnr-scheduledsend-btn')) { return; }

        // Fallback: no cloneable Send button found → build a plain one.
        if (!send) {
            var toolbar = document.querySelector('#messagetoolbar, .toolbar, .toolbarmenu');
            if (!toolbar) { return; }
            var a = document.createElement('a');
            a.id = 'pnr-scheduledsend-btn';
            a.href = '#';
            a.className = 'button pnr-scheduledsend';
            a.setAttribute('onclick', 'window.pnr_open_schedule(); return false;');
            a.setAttribute('title', L('scheduledsend_desc'));
            var span = document.createElement('span');
            span.className = 'inner';
            span.textContent = L('schedule');
            a.appendChild(span);
            toolbar.appendChild(a);
            return;
        }

        // Clone the Send button so it inherits the skin's styling, then retheme.
        var btn = send.cloneNode(true);
        btn.id = 'pnr-scheduledsend-btn';
        btn.className = (send.className.replace(/\bsend\b/g, '') + ' pnr-scheduledsend').replace(/\s+/g, ' ').trim();
        btn.setAttribute('onclick', 'window.pnr_open_schedule(); return false;');
        btn.setAttribute('title', L('scheduledsend_desc'));
        btn.removeAttribute('data-icon');
        btn.removeAttribute('data-command');

        var inner = btn.querySelector('.inner') || btn.querySelector('.button-inner') || btn;
        if (inner) { inner.textContent = L('schedule'); }

        send.parentNode.insertBefore(btn, send.nextSibling);
    }

    // Does the composer currently have at least one recipient?
    function hasRecipients() {
        var form = rcmail.gui_objects.messageform;
        if (!form) { return true; }
        var f = ['_to', '_cc', '_bcc'];
        for (var i = 0; i < f.length; i++) {
            var el = form.elements[f[i]];
            if (el && String(el.value).trim() !== '') { return true; }
        }
        return false;
    }

    // Remove any stale schedule markers from a previous (aborted) attempt and
    // restore the Sent-copy target so a normal send behaves as expected.
    // Note: _pnr_origin_* fields must NOT be cleared — they identify the
    // scheduled draft being edited and are needed on send/re-schedule.
    function clearScheduleFields() {
        var form = rcmail.gui_objects.messageform;
        if (!form) { return; }
        var at = form.elements['_pnr_schedule_at'];
        if (at) { at.value = ''; }
        var stash = form.elements['_pnr_store_target'];
        var st    = form.elements['_store_target'];
        if (stash && st && st.value === '' && stash.value !== '') {
            st.value = stash.value;
        }
        if (stash) { stash.value = ''; }
    }

    // Inject hidden fields that carry the composed-message origin across
    // Roundcube's internal autosave / submit cycles. The PHP hook reads these
    // to detect and cancel a prior scheduled item being superseded.
    function ensureOriginFields() {
        var form = rcmail.gui_objects.messageform;
        if (!form) { return; }
        if (rcmail.env.pnr_origin_mbox) {
            ensureHidden('_pnr_origin_mbox', rcmail.env.pnr_origin_mbox);
        }
        if (rcmail.env.pnr_origin_uid) {
            ensureHidden('_pnr_origin_uid', rcmail.env.pnr_origin_uid);
        }
    }

    // Wrap submit_messageform so the undo countdown runs *before* Roundcube
    // enters its "sending" state. A scheduled send bypasses the countdown.
    function hookSubmitMessageform() {
        if (rcmail._pnr_sm) { return; }
        rcmail._pnr_sm = true;

        var orig = rcmail.submit_messageform;
        if (typeof orig !== 'function') { return; }
        nativeSubmitMessageform = orig;

        rcmail.submit_messageform = function (draft, saveonly) {
            var scheduling = pnr.pending_schedule === true;
            pnr.pending_schedule = false;

            if (!scheduling) { clearScheduleFields(); }

            // Always carry the origin fields on every submit so the PHP hook
            // can cancel the old scheduled item regardless of whether the
            // new action is send (no countdown) or re-schedule.
            ensureOriginFields();

            // Scheduled send, drafts, autosave or disabled undo → send straight away.
            if (scheduling || draft || saveonly || !rcmail.env.pnr_undo_enabled) {
                return orig.apply(this, arguments);
            }

            // No recipients → let Roundcube show its own validation error now.
            if (!hasRecipients()) {
                return orig.apply(this, arguments);
            }

            // A countdown is already running → ignore the repeat click.
            if (pnr.undo_timer) { return; }

            var self = this, args = arguments;
            startUndoCountdown(function () {
                orig.apply(self, args);
            });
            // Held: the actual send happens only when the countdown completes.
        };
    }

    function startUndoCountdown(sendFn) {
        var remaining = parseInt(rcmail.env.pnr_undo_delay, 10) || 10;
        var done = false;

        var toast = document.createElement('div');
        toast.className = 'pnr-undo-toast';

        var label = document.createElement('span');
        label.className = 'pnr-undo-label';

        var spinner = document.createElement('span');
        spinner.className = 'pnr-undo-ring';

        var btn = document.createElement('a');
        btn.href = '#';
        btn.className = 'pnr-undo-btn';
        btn.textContent = L('undo');

        toast.appendChild(spinner);
        toast.appendChild(label);
        toast.appendChild(btn);
        document.body.appendChild(toast);

        function paint() {
            label.textContent = L('sending_in').replace('$sec', remaining);
        }
        function cleanup() {
            if (pnr.undo_timer) { clearInterval(pnr.undo_timer); pnr.undo_timer = null; }
            if (toast.parentNode) { toast.parentNode.removeChild(toast); }
        }
        function fire() {
            if (done) { return; }
            done = true; cleanup(); sendFn();
        }

        paint();
        pnr.undo_timer = setInterval(function () {
            remaining--;
            if (remaining <= 0) { fire(); } else { paint(); }
        }, 1000);

        btn.onclick = function (e) {
            e.preventDefault();
            if (done) { return; }
            done = true;
            cleanup();
            // Make sure no "sending" lock keeps the toolbar buttons disabled.
            if (rcmail.busy) { rcmail.set_busy(false); }
            rcmail.enable_command('send', true);
            rcmail.display_message(L('cancel'), 'notice');
        };
    }

    function openScheduleDialog() {
        var def = new Date(Date.now() + 3600 * 1000);
        def.setSeconds(0, 0);

        var html = '<div class="pnr-dt-dialog">'
            + '<div class="pnr-field"><label for="pnr-dt">' + esc(L('pick_datetime')) + '</label>'
            + '<input type="datetime-local" id="pnr-dt" value="' + fmtLocal(def) + '" min="' + fmtLocal(new Date()) + '"></div>'
            + presetButtons()
            + '</div>';

        var pressBtn = document.getElementById('pnr-scheduledsend-btn');
        if (pressBtn) { pressBtn.classList.add('pnr-pressed'); }

        rcmail.show_popup_dialog(html, L('scheduledsend'), [
            {
                text: L('schedule'),
                'class': 'mainaction send',
                click: function () {
                    var ts = readDateTime();
                    if (ts === null) { return; }

                    var form = rcmail.gui_objects.messageform;
                    // Inject the schedule time and suppress the immediate Sent copy.
                    ensureHidden('_pnr_schedule_at', ts);
                    if (form) {
                        var st = form.elements['_store_target'];
                        if (st) { ensureHidden('_pnr_store_target', st.value); st.value = ''; }
                    }

                    pnr.pending_schedule = true; // consumed by wrapper to skip undo

                    // MUST inject origin fields here too, because the wrapper
                    // might not run if send goes through the native path.
                    ensureOriginFields();

                    $(this).dialog('close');

                    // Route through our wrapper (rcmail.submit_messageform)
                    // so ensureOriginFields runs and pnr.pending_schedule is
                    // consumed correctly.  Fallback to nativeSubmitMessageform
                    // only if the wrapper wasn't registered.
                    var fn = rcmail.submit_messageform || nativeSubmitMessageform;
                    try {
                        fn.call(rcmail);
                    } catch (e) {
                        rcmail.command('send'); // last-resort fallback
                    }
                }
            },
            {
                text: L('cancel'),
                'class': 'cancel',
                click: function () { $(this).dialog('close'); }
            }
        ], {
            close: function () {
                if (pressBtn) { pressBtn.classList.remove('pnr-pressed'); }
                try { $(this).dialog('destroy').remove(); } catch (e) {}
            }
        });
    }

    // Rewrite the post-send confirmation text when we actually scheduled.
    function hookSentSuccessfully() {
        if (typeof rcmail.sent_successfully !== 'function' || rcmail._pnr_ss) { return; }
        rcmail._pnr_ss = true;
        var orig = rcmail.sent_successfully;

        rcmail.sent_successfully = function (type, msg, folders, save_error, message_id) {
            var form = rcmail.gui_objects.messageform;
            var at   = form && form.elements['_pnr_schedule_at'];

            // If this compose session replaced a queued item, delete the old
            // draft from the Drafts folder on successful send or schedule.
            var replaced = rcmail.env.pnr_replaced;
            if (replaced) {
                cleanReplacedDraft();
            }

            if (at && at.value) {
                var ts = parseInt(at.value, 10);
                at.value = '';
                var dstr = '';
                if (!isNaN(ts)) {
                    try { dstr = new Date(ts * 1000).toLocaleString(); }
                    catch (e) { dstr = rcmail.env.pnr_last_scheduled || ''; }
                }
                type = 'confirmation';
                msg  = L('scheduled_ok').replace('$datetime', dstr);
            }
            return orig.call(rcmail, type, msg, folders, save_error, message_id);
        };
    }

    // Delete the intermediate draft(s) that were created for editing a queued
    // item. Handles two kinds:
    //  - Temporary Drafts-folder draft created by action_edit (settings list).
    //  - Original scheduled-folder draft when edited directly from the folder.
    function cleanReplacedDraft() {
        if (!rcmail.env.pnr_replaced) { return; }

        // 1. Remove the temporary Drafts-folder copy (settings-list edit path).
        var draftUid = rcmail.env.pnr_draft_uid || rcmail.env.draft_id
                     || rcmail.env._draft_id || rcmail.env.uid || '';
        if (!draftUid) {
            var m = window.location.search.match(/[?&]_(?:draft_id|uid)=(\d+)/);
            if (m) { draftUid = m[1]; }
        }
        var draftsMbox = rcmail.env.drafts_mailbox || rcmail.env.drafts_mbox
                       || rcmail.env.mailbox || rcmail.env.cur_folder || '';
        if (draftUid && draftsMbox) {
            try {
                rcmail.http_post('plugin.pnr_delete_draft', {
                    _uid: draftUid,
                    _mbox: draftsMbox
                });
            } catch (e) {}
        }

        // 2. Remove the original scheduled-folder draft (direct-from-folder
        //    edit path), if env was populated by the PHP hook.
        var replacedFolder = rcmail.env.pnr_replaced_folder || '';
        var replacedUid    = rcmail.env.pnr_replaced_folder_uid || '';
        if (replacedFolder && replacedUid) {
            try {
                rcmail.http_post('plugin.pnr_delete_draft', {
                    _uid: replacedUid,
                    _mbox: replacedFolder
                });
            } catch (e) {}
        }
    }

    /* ----------------------------------------------------------------- */
    /*  Reminder (Wiedervorlage)                                         */
    /* ----------------------------------------------------------------- */

    function currentUids() {
        if (rcmail.env.uid) { return [rcmail.env.uid]; }
        if (rcmail.message_list) { return rcmail.message_list.get_selection(); }
        return [];
    }

    function openReminderDialog() {
        var uids = currentUids();
        if (!uids.length) {
            rcmail.display_message(L('no_recipient'), 'warning');
            return;
        }

        var mbox = rcmail.env.mailbox || rcmail.env.cur_folder || '';
        var def  = new Date(Date.now() + 86400 * 1000); // default: tomorrow same time
        def.setSeconds(0, 0);

        var self = rcmail.env.pnr_reminder_default_self ? (rcmail.env.pnr_identity_email || '') : '';

        var html = '<div class="pnr-dt-dialog pnr-reminder-dialog">'
            + '<div class="pnr-field"><label for="pnr-dt">' + esc(L('reminder_when')) + '</label>'
            + '<input type="datetime-local" id="pnr-dt" value="' + fmtLocal(def) + '" min="' + fmtLocal(new Date()) + '"></div>'
            + presetButtons()
            + '<div class="pnr-field"><label for="pnr-rto">' + esc(L('reminder_to')) + '</label>'
            + '<input type="text" id="pnr-rto" value="' + esc(self) + '" autocomplete="off">'
            + '<div class="pnr-hint">' + esc(L('reminder_to_hint')) + '</div></div>'
            + '<div class="pnr-field"><label for="pnr-rnote">' + esc(L('reminder_note')) + '</label>'
            + '<textarea id="pnr-rnote" rows="3"></textarea></div>'
            + '<div class="pnr-field pnr-check"><label><input type="checkbox" id="pnr-rorig"> '
            + esc(L('reminder_include')) + '</label></div>'
            + '</div>';

        rcmail.show_popup_dialog(html, L('reminder'), [
            {
                text: L('create_reminder'),
                'class': 'mainaction',
                click: function () {
                    var ts = readDateTime();
                    if (ts === null) { return; }

                    var data = {
                        _uid:  uids.join(','),
                        _mbox: mbox,
                        _at:   ts,
                        _to:   document.getElementById('pnr-rto').value,
                        _note: document.getElementById('pnr-rnote').value,
                        _orig: document.getElementById('pnr-rorig').checked ? 1 : 0
                    };

                    $(this).dialog('close');
                    rcmail.http_post('plugin.pnr_create_reminder', data, rcmail.set_busy(true, 'loading'));
                }
            },
            {
                text: L('cancel'),
                'class': 'cancel',
                click: function () { $(this).dialog('close'); }
            }
        ]);
    }

    /* ----------------------------------------------------------------- */
    /*  Settings: cancel queued items                                    */
    /* ----------------------------------------------------------------- */

    function cancelItem(id) {
        if (!id) { return false; }
        if (!confirm(L('confirm_cancel'))) { return false; }
        rcmail.http_post('plugin.pnr_cancel', { _id: id }, rcmail.set_busy(true, 'loading'));
        return false;
    }

    /* ----------------------------------------------------------------- */
    /*  Settings: edit a scheduled item                                  */
    /* ----------------------------------------------------------------- */

    function editItem(id) {
        if (!id) { return false; }
        rcmail.http_post('plugin.pnr_edit', { _id: id }, rcmail.set_busy(true, 'loading'));
        return false;
    }

    // Server responds with plugin.pnr_edit_ready → open draft in a new tab.
    // This also handles mail task context on compose page load.
    function handleEditReady(response) {
        if (response && response.url) {
            window.open(response.url, '_blank');
            // Remove the row from the settings list (the old task was cancelled).
            if (response.id) {
                var row = document.querySelector('tr[data-id="' + response.id + '"]');
                if (row && row.parentNode) { row.parentNode.removeChild(row); }
            }
        }
    }

    /* ----------------------------------------------------------------- */
    /*  Scheduled-messages IMAP folder support                           */
    /* ----------------------------------------------------------------- */

    // Name of the optional scheduled-messages folder (from server env, may be empty).
    function scheduledFolder() {
        return rcmail.env.pnr_scheduled_mbox || '';
    }

    // Is the user currently viewing the scheduled-messages folder?
    function inScheduledFolder() {
        if (!scheduledFolder()) { return false; }
        var mbox = rcmail.env.mailbox || rcmail.env.cur_folder || '';
        return mbox === scheduledFolder();
    }

    // Intercept message-list row double-click in the scheduled folder to open
    // the draft in compose mode instead of the read-only preview.
    function hookScheduledFolderOpen() {
        if (!scheduledFolder()) { return; }
        if (rcmail._pnr_sf) { return; }
        rcmail._pnr_sf = true;

        // Register the edit command for the scheduled folder.
        rcmail.register_command('plugin.pnr_edit_scheduled', function (cmd, event) {
            var uid;
            if (rcmail.env.uid && rcmail.env.action === 'show') {
                uid = rcmail.env.uid;
            } else if (rcmail.message_list) {
                var sel = rcmail.message_list.get_selection();
                if (sel && sel.length > 0) { uid = sel[0]; }
            }
            if (uid) { openScheduledDraft(uid); }
            return false;
        }, true);

        if (rcmail.message_list) {
            // Double-click opens compose.
            rcmail.message_list.addEventListener('dblclick', function () {
                if (!inScheduledFolder()) { return; }
                var sel = rcmail.message_list.get_selection();
                if (sel && sel.length > 0) {
                    openScheduledDraft(sel[0]);
                }
            });

            // Track the current folder to enable/disable the edit command.
            if (inScheduledFolder()) {
                rcmail.message_list.addEventListener('select', function (list) {
                    rcmail.enable_command('plugin.pnr_edit_scheduled',
                        list.get_selection().length > 0);
                });
            }
        }

        // If a message in the scheduled folder is shown standalone, enable the
        // edit command and expose it in the toolbar.
        if (inScheduledFolder() && rcmail.env.action === 'show') {
            rcmail.enable_command('plugin.pnr_edit_scheduled', true);
            // Add a toolbar button if not present.
            if (!document.getElementById('pnr-edit-scheduled-btn')) {
                var tb = document.querySelector('#messagetoolbar, .toolbar');
                if (tb) {
                    var btn = document.createElement('a');
                    btn.id = 'pnr-edit-scheduled-btn';
                    btn.href = '#';
                    btn.className = 'button pnr-edit-scheduled';
                    btn.setAttribute('onclick',
                        'return rcmail.command(\'plugin.pnr_edit_scheduled\', this, event)');
                    btn.title = L('edit');
                    var inner = document.createElement('span');
                    inner.className = 'inner';
                    inner.textContent = L('edit');
                    btn.appendChild(inner);
                    tb.appendChild(btn);
                }
            }
        }

        // Add a contextual hint banner when viewing the folder listing or
        // standalone message. Use setTimeout(0) so the DOM has settled after
        // Roundcube's own initial render, and use a short retry loop for the
        // standalone message view (load is async).
        if (inScheduledFolder()) {
            addScheduledToolbarHint();
            setTimeout(addScheduledToolbarHint, 200);
            setTimeout(addScheduledToolbarHint, 800);
        }
    }

    // Open a message from the scheduled folder in compose/draft mode, the same
    // way Roundcube does for the standard Drafts folder.
    function openScheduledDraft(uid) {
        if (!uid) { return; }
        var url = './?_task=mail&_action=compose'
            + '&_mbox=' + encodeURIComponent(scheduledFolder())
            + '&_uid=' + encodeURIComponent(uid);
        window.open(url, '_blank');
    }

    // Remove the hint banner if it exists (called when navigating away
    // from the scheduled-messages folder).
    function removeScheduledToolbarHint() {
        var hint = document.getElementById('pnr-sf-hint');
        if (hint && hint.parentNode) {
            hint.parentNode.removeChild(hint);
        }
    }

    // Display a help banner when viewing the scheduled folder listing or a
    // single scheduled draft.  The banner is inserted directly inside the
    // message content / message preview container so it acts as a heading
    // line above the message view, never overlapping the menu bar.
    function addScheduledToolbarHint() {
        // Guard against duplicate insertion.
        if (document.getElementById('pnr-sf-hint')) { return; }

        var hint = document.createElement('div');
        hint.id = 'pnr-sf-hint';
        hint.className = 'pnr-sf-hint alert information';
        hint.innerHTML = '<span class="pnr-sf-icon">&#128197;</span> '
            + '<span class="pnr-sf-text">' + esc(L('scheduled_mbox_folder')) + '</span>';

        // Try to place the banner inside the main content area.
        // Targets ordered from most specific to most generic.
        var targets = [
            '#mailmessageframe',              // iframe holder for single message view
            '#messagecontent',                // message view content wrapper
            '#mailpreviewframe',              // right-pane preview
            '#messagecontframe',              // older skin message container
            '#mailview-right .content',       // Elastic: right pane content
            '#layout-content .content',       // Elastic: main content area
            '#mailview-right',                // older skins right pane
            '#mainscreen-content'             // legacy: mainscreen content
        ];
        var inserted = false;
        for (var i = 0; i < targets.length; i++) {
            var el = document.querySelector(targets[i]);
            if (el) {
                // For iframe holders, insert before. For containers, prepend.
                if (el.tagName === 'IFRAME' || el.id === 'mailmessageframe') {
                    el.parentNode.insertBefore(hint, el);
                } else {
                    el.insertBefore(hint, el.firstChild);
                }
                inserted = true;
                break;
            }
        }

        if (!inserted) {
            // Elastic skin: insert before the watermark / message list header.
            var content = document.querySelector('#layout-content');
            if (content) {
                content.insertBefore(hint, content.firstChild);
            }
        }
    }

    // Handle the folder list response from the server (used by settings).
    function handleFolders(folders) {
        if (!folders || !Array.isArray(folders)) { return; }
        var sel = document.getElementById('pnr_scheduled_mbox');
        // If there's a datalist element we can populate, do so.
        var list = document.getElementById('pnr_scheduled_folders');
        if (!list) {
            // Create a datalist dynamically.
            list = document.createElement('datalist');
            list.id = 'pnr_scheduled_folders';
            if (sel && sel.parentNode) {
                sel.parentNode.appendChild(list);
                sel.setAttribute('list', 'pnr_scheduled_folders');
            }
        }
        if (list) {
            list.innerHTML = '';
            folders.forEach(function (f) {
                var opt = document.createElement('option');
                opt.value = f;
                list.appendChild(opt);
            });
        }
    }

    /* ----------------------------------------------------------------- */
    /*  Wire-up                                                          */
    /* ----------------------------------------------------------------- */

    rcmail.addEventListener('init', function () {
        // --- Mail task ---
        if (rcmail.env.task === 'mail') {
            rcmail.register_command('plugin.pnr_reminder', openReminderDialog, false);

            // Scheduled-folder behaviours.
            hookScheduledFolderOpen();
            rcmail.addEventListener('plugin.pnr_folders', handleFolders);

            // Re-evaluate the banner when the user navigates between folders.
            rcmail.addEventListener('listupdate', function () {
                removeScheduledToolbarHint();
                if (inScheduledFolder()) {
                    addScheduledToolbarHint();
                    // Trigger server-side reconciliation to sync any deletes
                    // the user made in the IMAP folder back to the DB.
                    rcmail.http_post('plugin.pnr_reconcile', {});
                }
            });

            if (rcmail.env.action === 'compose') {
                window.pnr_open_schedule = openScheduleDialog;
                rcmail.register_command('plugin.pnr_schedule', openScheduleDialog, true);
                hookSubmitMessageform();
                addComposeButton();
                hookSentSuccessfully();
            }
            else {
                // Enable reminder when a message is open or selected.
                if (rcmail.env.uid) {
                    rcmail.enable_command('plugin.pnr_reminder', true);
                }
                if (rcmail.message_list) {
                    rcmail.message_list.addEventListener('select', function (list) {
                        rcmail.enable_command('plugin.pnr_reminder', list.get_selection().length > 0);
                    });
                }
            }
        }

        // --- Settings task ---
        if (rcmail.env.task === 'settings') {
            rcmail.register_command('plugin.pnr_cancel_item', cancelItem, true);
            rcmail.register_command('plugin.pnr_edit_item', editItem, true);
            rcmail.addEventListener('plugin.pnr_cancelled', function (p) {
                var row = document.querySelector('tr[data-id="' + p.id + '"]');
                if (row && row.parentNode) { row.parentNode.removeChild(row); }
            });
            rcmail.addEventListener('plugin.pnr_edit_ready', handleEditReady);
            rcmail.addEventListener('plugin.pnr_folders', handleFolders);

            // Lazy-load the folder list when the user focuses the field.
            var sfInput = document.getElementById('pnr_scheduled_mbox');
            if (sfInput) {
                var loaded = false;
                sfInput.addEventListener('focus', function () {
                    if (!loaded) {
                        loaded = true;
                        rcmail.http_post('plugin.pnr_list_folders', {});
                    }
                });
            }
        }
    });

})();
