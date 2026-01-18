# Basketball Scoreboard (PHP + SQLite)

Portables Basketball-Scoreboard mit Admin-Login, mehreren Spielen, 12 Spielern pro Team, Foul-Tracking (0–5) und Viertel-History.

## Voraussetzungen

- PHP 8+
- PDO SQLite aktiviert
- Schreibrechte für `/data`

## Setup

1. Dateien auf den Webspace hochladen.
2. Sicherstellen, dass `/data` beschreibbar ist.
3. In `config.php` das Admin-Passwort anpassen (Hash ersetzen).
4. Admin-Login: `/admin/login.php`

**Standard-Login:** Passwort `admin` (bitte ändern!).

## Projektstruktur

```
/scoreboard
├── public/
├── admin/
├── includes/
├── data/
├── config.php
└── README.md
```

## Hinweise

- Die SQLite-Datei wird automatisch unter `/data/scoreboard.sqlite` angelegt.
- `/data/.htaccess` schützt die Datenbank unter Apache (falls aktiv).
- `quarter_history` speichert **kumulative Endstände** pro Viertelende. In der öffentlichen Anzeige werden daraus die Viertelpunkte berechnet.
- Wenn das Webroot nicht `/public` ist, müssen die Links ggf. angepasst werden.

## Konfiguration

In `config.php`:

- `ADMIN_PASSWORD_HASH` (Passwort-Hash via `password_hash`)
- `QUARTER_SECONDS` (Standard-Viertellänge in Sekunden, z. B. 600)
- `MAX_FOULS` (Standard 5)
