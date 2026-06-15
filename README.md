# Plan & Remind – Roundcube Plugin

A Roundcube plugin that lets users **delay, schedule and "undo"** outgoing mail,
plus a **reminder ("Wiedervorlage")** feature that schedules a follow-up message
for an existing e-mail.

---

## Features

- **Schedule send** – A button with a stopwatch icon next to the Send button in
  the compose window lets you pick an arbitrary delivery date/time. The message
  is queued and delivered by a cron job.
- **Undo send** – A configurable countdown is shown before a normal message
  actually leaves the compose window, so you can cancel it before it goes out.
  The delay (in seconds) is set in *Settings → Plan & Remind* (default 10 s).
- **Reminder / Follow-up** – From the message view options menu **and** the
  message-list right-click menu (both with a stopwatch icon) you can schedule a
  short reminder e-mail about the selected message. It goes to yourself by
  default; other recipients and an optional note can be added, and the original
  message can optionally be attached.
- **Manage queue** – *Settings → Plan & Remind* lists every pending scheduled
  message and reminder; any entry can be cancelled before it is sent.

Icons are wired up for both the **Elastic** and **Larry** skins (toolbar button,
right-click/option menu entry and the Settings module icon).

## Requirements

| | |
|---|---|
| Roundcube | 1.5 / 1.6 / 1.7 (Elastic or Larry) |
| PHP | 7.4 or higher |
| Database | MySQL / MariaDB or PostgreSQL (SQLite only for single-DB installations) |
| System | A cron job running **every minute** |

## Installation

1. Copy the `plan_and_remind` folder into the plugin directory of your
   Roundcube installation:

   ```bash
   cp -r plan_and_remind /var/www/roundcube/plugins/
   ```

2. Create the database table:

   ```bash
   # MySQL / MariaDB
   mysql -u <user> -p <roundcube_db> < plugins/plan_and_remind/SQL/mysql.initial.sql
   # PostgreSQL
   psql -U <user> -d <roundcube_db> -f plugins/plan_and_remind/SQL/postgres.initial.sql
   ```

   If you use a `db_prefix`, prepend it to the table names inside the SQL file
   (both `plan_and_remind` and the referenced `users` table).

3. Copy the config and edit it:

   ```bash
   cd /var/www/roundcube/plugins/plan_and_remind
   cp config.inc.php.dist config.inc.php
   nano config.inc.php
   ```

4. Enable the plugin in `config/config.inc.php`:

   ```php
   $config['plugins'] = ['plan_and_remind', /* … */];
   ```

5. Add the cron job (**must run every minute**):

   ```cron
   * * * * * php /path/to/roundcube/plugins/plan_and_remind/bin/send_scheduled.php >/dev/null 2>&1
   ```

   Make sure the **PHP CLI timezone matches the web server's**, otherwise
   messages may go out at the wrong moment.

## Configuration

### SMTP authentication for the cron worker

When cron runs there is no logged-in user, so the user's normal SMTP password is
not available. Choose one of two strategies in `config.inc.php`:

- **Store credentials (default).** `plan_and_remind_store_credentials = true`
  stores each user's SMTP credentials encrypted (with Roundcube's `des_key`)
  next to the queued message and deletes them as soon as it is delivered.
- **Common relay.** Set the option to `false` and either configure a dedicated
  relay account (`plan_and_remind_smtp_host/_user/_pass`) or point
  Roundcube's `smtp_host` at a no-auth localhost relay/MTA.

### Delivery and the "Sent" copy

- Scheduling intercepts Roundcube's own message build, stores the finished MIME
  message in the `plan_and_remind` table and aborts the live SMTP send.
- The cron worker delivers every item whose time has arrived and refreshes the
  `Date:` header to the real send time.
- Because cron has no IMAP session, the **Sent copy of a scheduled message is
  written the next time its owner logs in** (controlled by
  `plan_and_remind_save_to_sent`). Reminders are not copied to Sent.

## Usage

1. **Compose** a new message
2. For **scheduling**: Click the **stopwatch button** in the toolbar and choose
   a delivery date/time
3. For **undo send**: After clicking "Send", use the countdown button to cancel
   the send within the configured delay
4. For a **reminder / follow-up**: In the message view or via right-click in the
   message list, click the **stopwatch icon**, choose recipients, date/time and
   an optional note
5. Review and optionally cancel all scheduled items under
   **Settings → Plan & Remind**

## Troubleshooting / Notes

- Scheduled items live in the database, so they are not visible from
  third-party IMAP clients (Outlook, Thunderbird, …).
- The undo-send countdown is client-side: if you close the browser/tab during
  the countdown the message is not sent.
- Very large attachments are subject to PHP's `memory_limit` (Roundcube needs
  roughly `attachment_size × 1.33 × 12` of memory to encode them).
- The list right-click entry is native in **Elastic**; under **Larry** it
  appears if the separate `contextmenu` plugin is installed. The option-menu
  entry works in both skins.

## Author

**Sebastian Fischer** post@scriptometer.de

## License

MIT License with Non-Commercial restriction – see [LICENSE](LICENSE).
