# Workflow-Regeln

1. **Branch-Pakete:** Jede Feature-Gruppe wird in einem eigenen Branch entwickelt (`feat/<name>`). Kein Commit geht direkt auf `main`.

2. **Migrationen & Seeds sind idempotent:** Jede Migration und jeder Seed kann mehrfach ausgeführt werden, ohne Fehler zu erzeugen oder Daten zu duplizieren.

3. **Kein Secret im Repo:** Passwörter, API-Keys und andere Geheimnisse werden ausschließlich in `.env` (nicht versioniert) gespeichert.

4. **Tests müssen grün sein:** Vor jedem Merge muss `vendor/bin/phpunit` ohne Fehler durchlaufen. CI prüft dies automatisch.
