<?php

/**
 * Plan & Remind – English localization
 */

$labels = [];
$messages = [];

// Section / module
$labels['plan_and_remind']    = 'Plan & Remind';
$labels['scheduled_list']     = 'Scheduled messages & reminders';
$labels['no_scheduled']       = 'You have no scheduled messages or reminders.';

// Composer: scheduled send
$labels['scheduledsend']      = 'Schedule send';
$labels['scheduledsend_desc'] = 'Choose when this message should be sent';
$labels['sendnow']            = 'Send now';
$labels['pick_datetime']      = 'Send at';
$labels['schedule']           = 'Schedule';
$labels['cancel']             = 'Cancel';

// Date presets
$labels['preset_in1h']        = 'In 1 hour';
$labels['preset_in3h']        = 'In 3 hours';
$labels['preset_thisevening'] = 'This evening (18:00)';
$labels['preset_tomorrow']    = 'Tomorrow morning (8:00)';
$labels['preset_monday']      = 'Monday morning (8:00)';

// Undo send
$labels['undosend']           = 'Undo send';
$labels['undosend_enable']    = 'Hold messages before sending';
$labels['undosend_delay']     = 'Hold for';
$labels['seconds']            = 'seconds';
$labels['sending_in']         = 'Sending in $sec s…';
$labels['undo']               = 'Undo';

// Reminder (Wiedervorlage)
$labels['reminder']             = 'Reminder';
$labels['reminder_desc']        = 'Schedule a follow-up reminder for this message';
$labels['reminder_when']        = 'Remind at';
$labels['reminder_to']          = 'Send reminder to';
$labels['reminder_to_hint']     = 'Defaults to yourself. Separate multiple addresses with commas.';
$labels['reminder_note']        = 'Note (optional)';
$labels['reminder_include']     = 'Attach the original message';
$labels['create_reminder']      = 'Create reminder';
$labels['reminder_subject']     = 'Reminder: $subject';
$labels['reminder_intro']       = 'This is a scheduled reminder about the following message:';
$labels['reminder_field_subject'] = 'Subject:';
$labels['reminder_field_from']    = 'From:';
$labels['reminder_field_date']    = 'Date:';
$labels['reminder_field_note']    = 'Note:';
$labels['reminder_footer']        = 'Scheduled with Roundcube Plan & Remind';
$labels['no_subject']             = '(no subject)';

// Queue table headers
$labels['send_at']            = 'Send time';
$labels['type']               = 'Type';
$labels['subject']            = 'Subject';
$labels['recipients']         = 'Recipients';
$labels['type_scheduled']     = 'Scheduled mail';
$labels['type_reminder']      = 'Reminder';
$labels['edit']               = 'Edit';

// Settings
$labels['reminder_default_self'] = 'Pre-fill reminders with my own address';

// Messages
$messages['scheduled_ok']     = 'Message scheduled for $datetime.';
$messages['reminder_ok']      = 'Reminder scheduled for $datetime.';
$messages['cancelled_ok']     = 'The scheduled item was cancelled.';
$messages['schedule_failed']  = 'The message could not be scheduled.';
$messages['reminder_failed']  = 'The reminder could not be scheduled.';
$messages['cancel_failed']    = 'The item could not be cancelled (it may already have been sent).';
$messages['past_time']        = 'Please choose a time in the future.';
$messages['no_recipient']     = 'Please provide at least one valid recipient.';
$messages['confirm_cancel']   = 'Cancel this scheduled item?';
$messages['edit_ok']          = 'The scheduled message has been opened for editing in the Drafts folder.';
$messages['edit_failed']      = 'The scheduled message could not be opened for editing.';
$messages['scheduled_copy']   = 'Scheduled messages are saved as a copy in the Sent folder on delivery.';
