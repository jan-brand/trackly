# Trackly

Trackly ist eine SSR-first Zeiterfassung (PHP 8.2 + MariaDB) für kleine Vereine: manuelle Zeiten &amp; Timer, regelbasierte Flags (Arbeitsrecht), Audit-Logs, Rückfragen/Koordination, Ankündigungen, sowie CSV/PDF-Export (wkhtmltopdf). Fokus: RBAC, CSRF, PRG, deterministische Tests &amp; idempotente Migrations/Seeds.

## Voraussetzungen

- PHP >= 8.2
- Composer
- MySQL / MariaDB
- [wkhtmltopdf](https://wkhtmltopdf.org/) (für den PDF-Export)

## Setup

```bash
# 1. Repository klonen
git clone https://github.com/jan-brand/trackly.git
cd trackly

# 2. Abhängigkeiten installieren
composer install

# 3. Umgebungsvariablen konfigurieren
cp .env.example .env
# .env anpassen (DB_HOST, DB_NAME, DB_USER, DB_PASSWORD, ADMIN_EMAIL, ADMIN_PASSWORD, WKHTMLTOPDF_PATH)

# 4. Datenbank-Migrationen ausführen
php bin/migrate.php

# 5. Seeds ausführen (Rollen & Admin-Benutzer anlegen)
php bin/seed.php

# 6. Entwicklungsserver starten
php -S localhost:8000 -t public
```

## Tests ausführen

```bash
vendor/bin/phpunit
```

## PDF-Export (wkhtmltopdf)

Der PDF-Export benötigt das Kommandozeilenwerkzeug [wkhtmltopdf](https://wkhtmltopdf.org/).

**Installation:**

```bash
# Debian / Ubuntu
sudo apt-get install wkhtmltopdf

# macOS (Homebrew)
brew install wkhtmltopdf
```

Anschließend den Pfad zur Binary in `.env` eintragen:

```dotenv
WKHTMLTOPDF_PATH=/usr/bin/wkhtmltopdf
```

Den tatsächlichen Pfad erhält man mit `which wkhtmltopdf`.

## Passwort zurücksetzen

```bash
php bin/seed.php --reset-admin-password
```
