# PRD: webprofil/typo3-preflight

## Problem Statement

TYPO3-Agenten und Entwickler melden Änderungen als fertig, obwohl typische Integrationsfehler vorliegen: kaputtes Composer-Setup, TYPO3 bootet nicht, Frontend wirft Exceptions, Logs voller Errors, Content-Blocks-Schema divergiert, Extbase-Wiring ist inkonsistent. Diese Fehler werden erst spät entdeckt — oft nach Merge oder Deploy. Es fehlt ein schnelles, deterministisches Tool das diese Checks gebündelt ausführt, bevor "done" gemeldet wird.

## Solution

Ein eigenständiges Composer-Package `webprofil/typo3-preflight` mit CLI-Binary, das im DDEV-Container läuft und deterministische Smoke-Checks in konfigurierbaren Suites ausführt. Maschinen- und menschenlesbarer Output, Baseline-Support für Altlasten, klare Exit-Codes. Ergänzt `webprofil-qa` (statische Analyse) um Runtime-/Integrations-Verifikation.

## Implementation Status

Stand: 2026-06-20

### Erledigt

- Composer-Package `webprofil/typo3-preflight` mit Symfony-Console-Binary `vendor/bin/wp-typo3-preflight`.
- Commands `check` und `baseline:create`.
- Explizite Check-Registrierung ohne Auto-Discovery.
- Suites `static`, `site`, `content_blocks`, `wiring`, `database` und `runtime` mit `--suite`, Suite-Opt-out und `--fail-fast`.
- Checks: `ComposerCheck`, `PhpLintCheck`, `ArchitectureSqlCheck`, `SecretScannerCheck`, `SiteConfigCheck`, `ContentBlocksLintCheck`, `ContentBlocksYamlCheck`, `ExtbaseWiringCheck`, `DatabaseSchemaCheck`, `ReferenceIndexCheck`, `Typo3BootCheck`, `FrontendSmokeCheck`, `LogCheck`.
- JSON- und Text-Output.
- Exit-Codes `0`/`1`/`2`, inkl. DDEV-Environment-Fehler als Exit `2`.
- Baseline-Loading, Fingerprint-Matching, Stale-Baseline-Hinweise und `reason`-Feld.
- Dist-Config `wp-typo3-preflight.dist.yml`.
- Unit-Tests für Baseline, ManifestLoader, Formatter, CheckResult, ComposerCheck, SiteConfigCheck, PhpLintCheck, ArchitectureSqlCheck, SecretScannerCheck und ExtbaseWiringCheck.
- Git-Repo initialisiert, Initial-Commit erstellt.
- In `pinlog.app` als lokales Path-Package installiert und mit minimaler Config + Baseline getestet.

### Teilweise erledigt

- Test-Suite: Unit-Tests vorhanden und grün; Integration-Tests für den kompletten CLI-Durchlauf fehlen noch.
- ComposerCheck: `composer validate --strict` und `composer install --dry-run` laufen.
- ContentBlocksLintCheck: läuft nur wenn der TYPO3-CLI-Befehl `content-blocks:lint` verfügbar ist, sonst Skip.
- ExtbaseWiringCheck: prüft eine statische, pragmatische Teilmenge (`f:link.action`/`f:uri.action` gegen `configurePlugin`); RouteEnhancer/PageType-Hardcodes bleiben offen.
- FrontendSmokeCheck: prüft konfigurierte URLs; keine Browser-/DOM-Prüfung, bewusst Out of Scope.

### Offen

- RouteEnhancer- und PageType-Prüfungen in `site`/`wiring`.
- Content-Blocks-Prüfung für `ext_tables.sql`-Divergenzen.
- Restliche `static`-Checks: Extension-Metadata, PSR-4-Check, Services-Sanity.
- Integration-Tests und weitere Check-spezifische Unit-Tests für ContentBlocks/Database/Runtime.

## User Stories

1. [x] Als Entwickler will ich mit einem Kommando alle relevanten Preflight-Checks ausführen, damit ich sicher bin dass meine Änderung keine offensichtlichen Integrationsfehler hat. *(für Slice 1: static/runtime)*
2. [x] Als Agent will ich JSON-Output mit Check-Status, damit ich Ergebnisse maschinell auswerten und darauf reagieren kann.
3. [x] Als Entwickler will ich einzelne Suites gezielt ausführen können, damit ich bei bekannten Problemstellen schnell iterieren kann.
4. [x] Als Team-Lead will ich Baselines für bekannte Altfehler pflegen, damit neue Fehler klar von historischen unterscheidbar sind.
5. [x] Als Entwickler will ich klare Fehlermeldungen mit Kontext, damit ich direkt weiß was zu fixen ist.
6. [x] Als Agent will ich einen Exit-Code der zwischen Projektfehler (1) und Umgebungsproblem (2) unterscheidet, damit ich weiß ob ich Code oder Environment fixen muss.
7. [x] Als Entwickler will ich eine Dist-Config-Datei als Referenz, damit ich schnell sehe welche Optionen verfügbar sind.
8. [x] Als Entwickler will ich Smoke-URLs im Manifest deklarieren, damit Checks deterministische statt geratene Seiten prüfen.
9. [x] Als Entwickler will ich Suites in der Config deaktivieren können, damit Checks die für mein Projekt irrelevant sind nicht laufen.
10. [x] Als Agent will ich `--fail-fast` nutzen können, damit ich bei iterativem Fixen schnell Feedback bekomme.
11. [x] Als Entwickler will ich stale Baseline-Einträge gemeldet bekommen, damit ich weiß wann ein alter Fehler behoben wurde.
12. [x] Als Entwickler will ich dass Preflight mir sagt wenn DDEV nicht läuft, damit ich es selbst starten kann.
13. [x] Als Entwickler will ich dass Content-Blocks-Lint-Fehler baseline-fähig sind, damit historische Warnungen neue Checks nicht blockieren.
14. [x] Als Entwickler will ich dass Extbase-Wiring-Checks Fluid-Templates und ext_localconf gegen das Manifest prüfen, damit vergessene Action-Registrierungen auffallen. *(Teilumfang: Fluid `f:link.action`/`f:uri.action` gegen `configurePlugin`; Manifest-/RouteEnhancer-Prüfung offen)*
15. [x] Als Entwickler will ich dass Secret-Scanner konfigurierbare Allowlists haben, damit lokale Test-Credentials nicht als Fehler gemeldet werden.
16. [x] Als Entwickler will ich dass der Log-Check nur Einträge seit Preflight-Start meldet, damit alte Log-Einträge nicht verwirren.
17. [x] Als Entwickler will ich dass Architektur-Checks SQL in Models/Controllern finden, damit Schichtverletzungen früh auffallen.
18. [x] Als Agent will ich dass fehlende Config-URLs zum Skip statt zum Fail führen, damit ich auch ohne vollständige Config sinnvolle Checks bekomme.
19. [x] Als Entwickler will ich eine `baseline:create`-Funktion die automatisch Fingerprints generiert, damit ich nicht manuell hashen muss.
20. [x] Als Entwickler will ich optional einen `reason`-Kommentar in Baseline-Einträgen pflegen, damit das Team versteht warum ein Fehler ignoriert wird.

## Implementation Decisions

### Package-Setup

- Composer-Package: `webprofil/typo3-preflight`
- Lizenz: GPL-2.0-or-later
- PHP 8.2+, TYPO3 13+
- Namespace: `WEBprofil\Typo3Preflight\`, PSR-4 auf `src/`
- CLI-Binary: `vendor/bin/wp-typo3-preflight`
- CLI-Framework: Symfony Console (Version-Constraint kompatibel mit TYPO3 13)
- Kein DDEV Custom Command mitgeliefert, nur Doku/Beispiel

### Laufzeitumgebung

- DDEV-only: Preflight läuft komplett im DDEV-Container
- Aufruf: `ddev exec vendor/bin/wp-typo3-preflight check`
- Wenn DDEV nicht läuft: Exit 2, Meldung, nie selbst starten
- HTTP-Smoke nutzt `DDEV_PRIMARY_URL` (im Container verfügbar), Config als Override
- Guzzle als HTTP-Client, `verify: false` für Self-Signed

### Konfiguration

- Datei: `wp-typo3-preflight.yml` im Projektroot
- Dist-Referenz: `wp-typo3-preflight.dist.yml` im Package
- Ohne Config: sinnvolle Defaults (static + runtime Boot laufen, URL-Checks werden geskippt)
- Suites opt-out in Config (`enabled: false`)

### Architektur / Module

- **Command**: `CheckCommand`, `BaselineCreateCommand` — dünne Symfony-Console-Layer
- **Project**: `ProjectContext` (immutables Value Object), `ManifestLoader` (parsed YAML)
- **Runner**: `ProcessRunner` Interface + Implementierung. Führt Shell-Commands aus, liefert stdout/stderr/exitcode
- **Check**: `CheckInterface` mit `name()`, `suite()`, `run(ProjectContext): CheckResult`. Explizite Registrierung, keine Auto-Discovery
- **Baseline**: `BaselineLoader` (liest JSON), `BaselineComparator` (matcht Fingerprints, erkennt Stale)
- **Output**: `ResultFormatter` Interface, `TextFormatter`, `JsonFormatter`

### Check-Status und Exit-Codes

- Vier Status-Werte: `pass`, `fail`, `skip`, `error`
- Exit 0: alle pass/skip
- Exit 1: mindestens ein fail
- Exit 2: mindestens ein error (Environment-Problem)
- fail dominiert error bei gemischtem Ergebnis

### Suites und Reihenfolge

Ohne `--suite` laufen alle aktiven Suites in dieser Reihenfolge:

1. **static**: Composer validate/dry-run, PHP-Lint, Extension-Metadata, PSR-4-Check, Services-Sanity, Architektur-Regeln (SQL in Models/Controllern), Secret-Scanner
2. **site**: Site-Config YAML Validierung — RouteEnhancers gegen existierende Controller/Actions, ErrorHandling-Seiten existieren, Languages base-Pfade unique
3. **content_blocks**: content-blocks:lint, YAML-Validierung (identifier, useExistingField, typeName etc.), ext_tables.sql-Prüfung für CB-Felder
4. **wiring**: Fluid/JS Action-Referenzen gegen ext_localconf + Manifest prüfen, pageType-Hardcodes gegen Manifest
5. **database**: `database:updateschema --dry-run` (global), `referenceindex:update --check`
6. **runtime**: TYPO3 Boot-Smoke (`typo3 list` / `cache:warmup`), Frontend-URL-Smoke, Log-Check

`--fail-fast` bricht bei erstem Fail ab.

### Baselines

- Ort: `build/preflight/*.baseline.json` (committed)
- Fingerprint-basiert: Hash aus stabilen Teilen (Check-Name + Error-Code + betroffene Datei/Identifier, ohne Zeilennummer/Timestamp)
- Optionales `reason`-Feld pro Eintrag
- Stale Entries werden als Info gemeldet, kein Fail
- `baseline:create` generiert automatisch aus aktuellem Run

### Output

- `--format=text` (Default): kompakt, menschenlesbar
- `--format=json`: strukturiert, ein Objekt pro Check mit suite/check/status/message/details

## Testing Decisions

### Prinzip

Tests prüfen externes Verhalten, nicht Implementierungsdetails. Ein guter Test beschreibt: "Gegeben diesen Input/Zustand, erwarte ich dieses Ergebnis" — nicht wie intern gearbeitet wird.

### Getestete Module

Status aktuell:

- [x] **Baseline**: Fingerprint-Berechnung, Matching-Logik, Stale-Detection.
- [x] **ManifestLoader**: Defaults, Projekt-Config-Override, fehlende Config.
- [x] **ResultFormatter**: Text- und JSON-Output gegen erwartete Strings/Strukturen.
- [x] **CheckResult**: Immutability-Helfer.
- [x] **ComposerCheck**: externe Composer-Warnings werden als einzelne Failure-Fingerprints modelliert.
- [x] **SiteConfigCheck**: valide/kaputte Site-YAMLs, Pflichtfelder und Language-Base-Duplikate.
- [x] **PhpLintCheck**: valide/kaputte PHP-Dateien, Fixture-Skip.
- [x] **ArchitectureSqlCheck**: SQL-/QueryBuilder-Indizien in Controller/Model-Pfaden.
- [x] **SecretScannerCheck**: Treffer, Maskierung und `secrets.allowlist`.
- [x] **ExtbaseWiringCheck**: einfache Fluid-Action-Referenzen gegen `configurePlugin`.
- [ ] **Check-Logik alle Checks**: Fixture-Verzeichnisse mit validen/kaputten Projekten; ProcessRunner gemockt für CLI-Checks, HTTP-Client gemockt für Smoke.
- [ ] **ProcessRunner**: Korrekte Command-Konstruktion, Exit-Code-Handling, Timeout.
- [ ] **Command (Integration)**: Full CLI-Durchlauf mit Fixture-Projekt, prüft Exit-Code und Output-Format.

### Struktur

```
tests/
  Unit/
    Check/
    Baseline/
    Project/
    Runner/
    Output/
  Integration/
    FullRunTest.php
  Fixtures/
    valid-project/
    broken-composer/
    broken-frontend/
    broken-wiring/
```

### Tooling

- PHPUnit
- Kein DDEV in Package-CI — ProcessRunner wird gemockt
- Fixtures simulieren Projektstrukturen minimal

## Out of Scope

- Nicht-DDEV-Umgebungen (CI, Staging, Host-PHP)
- Browser-basiertes Testing (Playwright/Chrome)
- Vollständige statische Analyse (das bleibt bei `webprofil-qa` / PHPStan)
- Compat-/Upgrade-Scans (Hooks, TSFE, Annotations, Fluid-v14-Syntax) — gehören als PHPStan-Rules/Rector-Rules in `webprofil-qa`
- PHPUnit-Tests des Zielprojekts ausführen
- Auto-Fix von gefundenen Problemen
- DDEV automatisch starten
- Composer-Plugin-Mechanismus zum Installieren von DDEV Custom Commands
- Performance-Monitoring / Metriken
- Multi-Site-Support (erstmal Single-Site)
- Interaktive Diagnose / explorative Ausgaben (das bleibt bei `wp-debug`)

## Further Notes

### Erster Slice (Tracer Bullet) — erledigt

Geliefert: Skelett (Command, CheckInterface, ProcessRunner, ManifestLoader, JSON/Text-Output, Baseline-Vergleich) + `static` Suite (ComposerCheck) + `runtime` Suite (Typo3BootCheck, FrontendSmokeCheck, LogCheck). Damit ist das Package sofort produktiv einsetzbar für die zwei häufigsten Agenten-Fehler.

Verifiziert in `pinlog.app`:

- Package als lokales Path-Package installiert.
- `ddev exec vendor/bin/wp-typo3-preflight check --format=json` läuft.
- Composer-Warnings der lokalen `@dev`-Packages sind baselinefähig und in pinlog baselined.
- TYPO3-Boot, Frontend-Smoke `/` und LogCheck laufen erfolgreich.

### Weitere Suites — aktueller Stand

- [x] Slice 2: `site` Suite (Site-Config YAML, `rootPageId`, `base`, ErrorHandling-Basics; RouteEnhancer-Prüfung offen)
- [x] Slice 3: `content_blocks` Suite (`content-blocks:lint` wenn verfügbar, Content-Blocks-YAML-Basics; `ext_tables.sql`-Divergenzen offen)
- [x] Slice 4: `wiring` Suite (Fluid `f:link.action`/`f:uri.action` gegen `configurePlugin`; Manifest-/PageType-Prüfung offen)
- [x] Slice 5: `database` Suite (Schema-Dry-Run + ReferenceIndex-Check)
- [x] Slice 6: Teilmenge restlicher `static`-Checks (PHP-Lint, Architektur-SQL, Secret-Scanner)
- [ ] Rest Slice 6: Extension-Metadata, PSR-4-Check, Services-Sanity

### Beziehung zu anderen Tools

Preflight ist eines von drei komplementären Tools mit klarer Abgrenzung:

| Tool | Zweck | Wann | Braucht TYPO3-Boot? |
|---|---|---|---|
| **webprofil-qa** | Code-Qualität: CS-Fixer, PHPStan, Rector, Fractor | Vor Commit/Merge — "ist der Code formal korrekt?" | Nein |
| **wp-typo3-preflight** | System-Verifikation: Boot, DB, HTTP, Wiring, Integration | Nach Änderung, vor "done" — "funktioniert das Gesamtsystem?" | Ja |
| **wp-debug** | Laufzeit-Diagnose: TypoScript, TCA, Routing, Logs anzeigen | Während Debugging — "warum geht X nicht?" | Ja |

**Abgrenzungsregel:** Wenn ein Check kein laufendes TYPO3 braucht (kein Boot, kein HTTP, keine DB), gehört er in `webprofil-qa`. Wenn er laufendes TYPO3 braucht, gehört er in Preflight. `wp-debug` ist kein Gate (kein pass/fail), sondern explorativ.

**Konsequenz für Compat-/Upgrade-Checks:** Scans nach deprecated Hooks (`$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']`), entferntem TSFE-Zugriff, Extbase-Annotations statt Attributes, Fluid-v14-Inkompatibilitäten etc. sind rein statisch analysierbar → gehören als PHPStan-Rules oder Rector-Rules in `webprofil-qa`, nicht in Preflight.

**Agent-Workflow-Reihenfolge:**
1. `ddev exec vendor/bin/webprofil-qa check` (statisch, schnell)
2. `ddev exec vendor/bin/wp-typo3-preflight check` (Integration, braucht Boot)
3. Erst dann "done" melden

### Bekannte Risiken

- `content-blocks:lint` kann durch Altlasten rot sein → Baseline essenziell.
- Private VCS-Repos können Composer-Commands blockieren → klar als `error` melden.
- `DDEV_PRIMARY_URL` zeigt auf HTTPS mit Self-Signed → `verify: false` Pflicht.
- Log-Dateien können sehr groß werden → nur Tail seit Startzeit lesen (Byte-Offset).
