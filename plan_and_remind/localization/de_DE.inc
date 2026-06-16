<?php

/**
 * Plan & Remind – Deutsche Lokalisierung
 */

$labels = [];
$messages = [];

// Bereich / Modul
$labels['plan_and_remind']    = 'Plan & Wiedervorlage';
$labels['scheduled_list']     = 'Geplante Nachrichten & Wiedervorlagen';
$labels['no_scheduled']       = 'Sie haben keine geplanten Nachrichten oder Wiedervorlagen.';

// Verfassen: geplantes Senden
$labels['scheduledsend']      = 'Geplantes Senden';
$labels['scheduledsend_desc'] = 'Wählen Sie, wann diese Nachricht gesendet werden soll';
$labels['sendnow']            = 'Jetzt senden';
$labels['pick_datetime']      = 'Senden am';
$labels['schedule']           = 'Planen';
$labels['cancel']             = 'Abbrechen';

// Zeit-Vorgaben
$labels['preset_in1h']        = 'In 1 Stunde';
$labels['preset_in3h']        = 'In 3 Stunden';
$labels['preset_thisevening'] = 'Heute Abend (18:00)';
$labels['preset_tomorrow']    = 'Morgen früh (8:00)';
$labels['preset_monday']      = 'Montag früh (8:00)';

// Senden rückgängig
$labels['undosend']           = 'Senden rückgängig';
$labels['undosend_enable']    = 'Nachrichten vor dem Senden kurz zurückhalten';
$labels['undosend_delay']     = 'Zurückhalten für';
$labels['seconds']            = 'Sekunden';
$labels['sending_in']         = 'Senden in $sec s …';
$labels['undo']               = 'Rückgängig';

// Wiedervorlage
$labels['reminder']             = 'Wiedervorlage';
$labels['reminder_desc']        = 'Eine Wiedervorlage zu dieser Nachricht planen';
$labels['reminder_when']        = 'Erinnern am';
$labels['reminder_to']          = 'Wiedervorlage senden an';
$labels['reminder_to_hint']     = 'Standardmäßig an Sie selbst. Mehrere Adressen mit Komma trennen.';
$labels['reminder_note']        = 'Notiz (optional)';
$labels['reminder_include']     = 'Ursprüngliche Nachricht anhängen';
$labels['create_reminder']      = 'Wiedervorlage erstellen';
$labels['reminder_subject']     = 'Wiedervorlage: $subject';
$labels['reminder_intro']       = 'Dies ist eine geplante Wiedervorlage zu folgender Nachricht:';
$labels['reminder_field_subject'] = 'Betreff:';
$labels['reminder_field_from']    = 'Von:';
$labels['reminder_field_date']    = 'Datum:';
$labels['reminder_field_note']    = 'Notiz:';
$labels['reminder_footer']        = 'Geplant mit Roundcube Plan & Wiedervorlage';
$labels['no_subject']             = '(kein Betreff)';

// Tabellenüberschriften
$labels['send_at']            = 'Sendezeit';
$labels['type']               = 'Typ';
$labels['subject']            = 'Betreff';
$labels['recipients']         = 'Empfänger';
$labels['type_scheduled']     = 'Geplante Mail';
$labels['type_reminder']      = 'Wiedervorlage';
$labels['edit']               = 'Bearbeiten';

// Einstellungen
$labels['reminder_default_self'] = 'Wiedervorlagen mit meiner eigenen Adresse vorbelegen';

// Optionaler IMAP-Ordner für geplante Mails & Wiedervorlagen
$labels['scheduled_mbox']         = 'Ordner für geplante Nachrichten & Wiedervorlagen';
$labels['scheduled_mbox_desc']    = 'Wenn Sie hier einen Ordner angeben, werden geplante Mails und Wiedervorlagen zusätzlich als physische Entwürfe in diesem IMAP-Ordner abgelegt. Sie können diese dann wie normale Entwürfe in Roundcube öffnen oder mit einem externen E-Mail-Programm bearbeiten. Die Synchronisation mit der internen Datenbank erfolgt automatisch.';
$labels['scheduled_mbox_create'] = 'Ordner automatisch anlegen, falls nicht vorhanden';
$labels['scheduled_mbox_none']   = '– Kein (nur Datenbank) –';
$labels['scheduled_mbox_folder'] = 'Geplante Nachrichten';

// Meldungen
$messages['scheduled_ok']     = 'Nachricht geplant für $datetime.';
$messages['reminder_ok']      = 'Wiedervorlage geplant für $datetime.';
$messages['cancelled_ok']     = 'Der geplante Eintrag wurde abgebrochen.';
$messages['schedule_failed']  = 'Die Nachricht konnte nicht geplant werden.';
$messages['reminder_failed']  = 'Die Wiedervorlage konnte nicht geplant werden.';
$messages['cancel_failed']    = 'Der Eintrag konnte nicht abgebrochen werden (er wurde möglicherweise bereits gesendet).';
$messages['past_time']        = 'Bitte wählen Sie einen Zeitpunkt in der Zukunft.';
$messages['no_recipient']     = 'Bitte geben Sie mindestens einen gültigen Empfänger an.';
$messages['confirm_cancel']   = 'Diesen geplanten Eintrag abbrechen?';
$messages['edit_ok']          = 'Die geplante Nachricht wurde zum Bearbeiten im Entwurfsordner geöffnet.';
$messages['edit_failed']      = 'Die geplante Nachricht konnte nicht zum Bearbeiten geöffnet werden.';
$messages['scheduled_copy']   = 'Geplante Nachrichten werden beim Versand als Kopie im Gesendet-Ordner gespeichert.';
