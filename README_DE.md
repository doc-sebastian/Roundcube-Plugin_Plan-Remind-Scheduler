# Plan & Remind – Roundcube-Plugin

Ein Roundcube-Plugin, das das **geplante Senden**, den **Senden-Rückgängig**-Countdown
und eine **Wiedervorlage**-Funktion (Erinnerung an eine bestehende E-Mail) bietet.

---

## Funktionen

- **Geplantes Senden (Schedule send)** – Ein Button mit Stoppuhr-Icon neben dem
  Senden-Button im Verfassen-Fenster erlaubt die Wahl eines beliebigen
  Sendezeitpunkts. Die Nachricht wird zwischengespeichert und durch einen
  Cron-Job versendet.
- **Senden-Rückgängig (Undo send)** – Ein konfigurierbarer Countdown wird vor dem
  tatsächlichen Versenden einer normalen Nachricht angezeigt, sodass sie
  abgebrochen werden kann. Die Verzögerung (in Sekunden) wird unter
  *Einstellungen → Plan & Remind* festgelegt (Standard 10 s).
- **Wiedervorlage / Erinnerung** – Im Optionsmenü der Nachrichtenansicht **und**
  im Rechtsklick-Menü der Nachrichtenliste (jeweils mit Stoppuhr-Icon) kann eine
  kurze Erinnerungs-E-Mail zur ausgewählten Nachricht geplant werden. Geht
  standardmäßig an sich selbst, weitere Empfänger und ein optionaler Hinweis
  sind möglich; die Originalnachricht kann optional angehängt werden.
- **Warteschlange verwalten** – *Einstellungen → Plan & Remind* listet alle
  anstehenden geplanten Nachrichten und Erinnerungen; jeder Eintrag kann vor dem
  Versand abgebrochen werden.

Icons sind für die **Elastic**- und die **Larry**-Skins eingebunden (Toolbar-Button,
Rechtsklick-/Optionsmenü-Eintrag und Settings-Modul-Icon).

## Voraussetzungen

| | |
|---|---|
| Roundcube | 1.5 / 1.6 / 1.7 (Elastic oder Larry) |
| PHP | 7.4 oder höher |
| Datenbank | MySQL / MariaDB oder PostgreSQL (SQLite nur für Single-DB-Installationen) |
| System | Ein **jede Minute** laufender Cron-Job |

## Installation

1. Den Ordner `plan_and_remind` in das Plugin-Verzeichnis der
   Roundcube-Installation kopieren:

   ```bash
   cp -r plan_and_remind /var/www/roundcube/plugins/
   ```

2. Datenbank-Tabelle anlegen:

   ```bash
   # MySQL / MariaDB
   mysql -u <user> -p <roundcube_db> < plugins/plan_and_remind/SQL/mysql.initial.sql
   # PostgreSQL
   psql -U <user> -d <roundcube_db> -f plugins/plan_and_remind/SQL/postgres.initial.sql
   ```

   Bei Verwendung eines `db_prefix` muss dieser den Tabellennamen in der
   SQL-Datei vorangestellt werden (sowohl bei `plan_and_remind` als auch bei der
   referenzierten `users`-Tabelle).

3. Konfigurationsdatei anlegen und anpassen:

   ```bash
   cd /var/www/roundcube/plugins/plan_and_remind
   cp config.inc.php.dist config.inc.php
   nano config.inc.php
   ```

4. Plugin in `config/config.inc.php` aktivieren:

   ```php
   $config['plugins'] = ['plan_and_remind', /* … */];
   ```

5. Cron-Job einrichten (**muss jede Minute laufen**):

   ```cron
   * * * * * php /path/to/roundcube/plugins/plan_and_remind/bin/send_scheduled.php >/dev/null 2>&1
   ```

   Sicherstellen, dass die **Zeitzone des PHP-CLI mit der des Webservers
   übereinstimmt**, sonst werden Nachrichten zum falschen Zeitpunkt versendet.

## Konfiguration

### SMTP-Authentifizierung für den Cron-Worker

Wenn Cron läuft, ist kein Benutzer angemeldet und das SMTP-Passwort des Benutzers
ist nicht verfügbar. Eine der beiden Strategien in `config.inc.php` wählen:

- **Zugangsdaten speichern (Standard).** `plan_and_remind_store_credentials = true`
  speichert die SMTP-Zugangsdaten jedes Benutzers verschlüsselt (mit Roundcubes
  `des_key`) neben der Nachricht und löscht sie nach dem Versand.
- **Gemeinsames Relay.** Option auf `false` setzen und entweder ein dediziertes
  Relay-Konto konfigurieren (`plan_and_remind_smtp_host/_user/_pass`) oder
  Roundcubes `smtp_host` auf ein lokales Relay/MTA ohne Authentifizierung zeigen
  lassen.

### Versand und „Gesendet"-Kopie

- Der Sendeplan fängt den Nachrichtenaufbau von Roundcube ab, speichert die
  fertige MIME-Nachricht in der `plan_and_remind`-Tabelle und bricht den
  direkten SMTP-Versand ab.
- Der Cron-Worker versendet jede fällige Nachricht und aktualisiert den
  `Date:`-Header auf die reale Sendezeit.
- Da Cron keine IMAP-Sitzung hat, wird die **„Gesendet"-Kopie einer geplanten
  Nachricht bei der nächsten Anmeldung ihres Eigentümers erstellt** (gesteuert
  durch `plan_and_remind_save_to_sent`). Erinnerungen werden nicht kopiert.

## Benutzung

1. **Neue Nachricht** verfassen
2. Für **geplantes Senden**: Auf den **Stoppuhr-Button** in der Toolbar klicken
   und Sendezeitpunkt wählen
3. Für **Undo send**: Nach dem Klick auf „Senden" den Countdown-Button nutzen,
   um den Versand innerhalb der eingestellten Frist abzubrechen
4. Für **Wiedervorlage / Erinnerung**: In der Nachrichtenansicht oder per
   Rechtsklick in der Nachrichtenliste auf das **Stoppuhr-Icon** klicken,
   Empfänger, Datum/Uhrzeit und optionalen Hinweis wählen
5. Alle geplanten Elemente unter **Einstellungen → Plan & Remind** einsehen und
   ggf. abbrechen

## Fehlersuche / Hinweise

- Geplante Elemente liegen in der Datenbank und sind daher von Drittanbieter-
  IMAP-Clients (Outlook, Thunderbird, …) nicht sichtbar.
- Der Undo-Send-Countdown ist clientseitig: Wird der Browser/Tab während des
  Countdowns geschlossen, wird die Nachricht nicht gesendet.
- Sehr große Anhänge unterliegen PHPs `memory_limit` (Roundcube benötigt grob
  `Anhangsgröße × 1,33 × 12` an Arbeitsspeicher zur Codierung).
- Der Rechtsklick-Eintrag in der Nachrichtenliste ist in **Elastic** nativ;
  unter **Larry** erscheint er, wenn das separate Plugin `contextmenu`
  installiert ist. Der Optionsmenü-Eintrag funktioniert in beiden Skins.

## Autor

**Sebastian Fischer** post@scriptometer.de

## Lizenz

MIT-Lizenz mit Zusatz zur nicht-kommerziellen Nutzung – siehe [LICENSE_DE](LICENSE_DE).
