# Trackly

Trackly ist eine SSR-first Zeiterfassung (PHP 8.2 + MariaDB) für kleine Vereine: manuelle Zeiten &amp; Timer, regelbasierte Flags (Arbeitsrecht), Audit-Logs, Rückfragen/Koordination, Ankündigungen, sowie CSV/PDF-Export (wkhtmltopdf). Fokus: RBAC, CSRF, PRG, deterministische Tests &amp; idempotente Migrations/Seeds.

## Voraussetzungen

| Komponente | Linux / macOS | Windows |
|---|---|---|
| PHP >= 8.2 | Paketverwaltung / Homebrew | [php.net](https://windows.php.net/download/) oder via XAMPP / Laragon |
| Composer | [getcomposer.org](https://getcomposer.org/download/) | Installer von [getcomposer.org](https://getcomposer.org/download/) |
| MySQL / MariaDB | Paketverwaltung / Homebrew | XAMPP / Laragon oder [MariaDB MSI](https://mariadb.org/download/) |
| [wkhtmltopdf](https://wkhtmltopdf.org/) | Paketverwaltung / Homebrew | [Installer von wkhtmltopdf.org](https://wkhtmltopdf.org/downloads.html) |
| Git | Paketverwaltung / Homebrew | [git-scm.com](https://git-scm.com/download/win) |

> **Tipp für Windows:** [Laragon](https://laragon.org/) bringt PHP, MariaDB und Git in einem Paket mit und ermöglicht einen schnellen Start ohne manuelle Konfiguration.

## Setup (Linux / macOS)

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

## Setup (Windows)

Die folgenden Befehle funktionieren in **PowerShell** oder der **Git Bash**:

```powershell
# 1. Repository klonen
git clone https://github.com/jan-brand/trackly.git
cd trackly

# 2. Abhängigkeiten installieren
composer install

# 3. Umgebungsvariablen konfigurieren (PowerShell)
Copy-Item .env.example .env
# .env mit einem Texteditor öffnen und anpassen:
# DB_HOST, DB_NAME, DB_USER, DB_PASSWORD, ADMIN_EMAIL, ADMIN_PASSWORD, WKHTMLTOPDF_PATH
notepad .env

# 4. Datenbank-Migrationen ausführen
php bin\migrate.php

# 5. Seeds ausführen (Rollen & Admin-Benutzer anlegen)
php bin\seed.php

# 6. Entwicklungsserver starten
php -S localhost:8000 -t public
```

> **Hinweis:** PHP muss in der `PATH`-Umgebungsvariable eingetragen sein. Laragon erledigt das automatisch; bei manueller PHP-Installation muss der Pfad (z. B. `C:\php`) selbst ergänzt werden.

## Tests ausführen

```bash
vendor/bin/phpunit
```

Unter Windows:

```powershell
php vendor\bin\phpunit
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

**Windows:** Den Installer von [wkhtmltopdf.org/downloads.html](https://wkhtmltopdf.org/downloads.html) herunterladen und ausführen. Der Standardinstallationspfad ist `C:\Program Files\wkhtmltopdf\bin\wkhtmltopdf.exe`.

Anschließend den Pfad zur Binary in `.env` eintragen:

```dotenv
# Linux / macOS
WKHTMLTOPDF_PATH=/usr/bin/wkhtmltopdf

# Windows
WKHTMLTOPDF_PATH=C:\Program Files\wkhtmltopdf\bin\wkhtmltopdf.exe
```

Den tatsächlichen Pfad ermitteln:

```bash
# Linux / macOS
which wkhtmltopdf

# Windows (PowerShell)
(Get-Command wkhtmltopdf).Source
```

## Passwort zurücksetzen

```bash
php bin/seed.php --reset-admin-password
```
