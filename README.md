# TYPO3 Preflight

Deterministic pre-flight integration checks for TYPO3 projects running in DDEV.

`webprofil/typo3-preflight` is meant as a fast smoke gate before a developer or coding agent marks a TYPO3 change as done. It complements static QA tools by checking project wiring, TYPO3 runtime basics, Content Blocks, database state, frontend smoke URLs, and logs.

## Requirements

- PHP 8.2+
- TYPO3 13+
- DDEV project
- Composer-based TYPO3 installation

## Installation

Until the package is published, add the repository to your project and require it as a dev dependency:

```bash
composer config repositories.typo3-preflight vcs git@github.com:butu/typo3-preflight.git
composer require --dev webprofil/typo3-preflight:@dev
```

In DDEV projects, prefer running Composer inside the container:

```bash
ddev composer require --dev webprofil/typo3-preflight:@dev
```

## Basic usage

Run all enabled checks from the TYPO3 project root:

```bash
ddev exec vendor/bin/wp-typo3-preflight check
```

JSON output for agents or CI-style consumers:

```bash
ddev exec vendor/bin/wp-typo3-preflight check --format=json
```

Run only one suite:

```bash
ddev exec vendor/bin/wp-typo3-preflight check --suite content_blocks
```

Stop after the first failure:

```bash
ddev exec vendor/bin/wp-typo3-preflight check --fail-fast
```

## Configuration

Copy the dist config into your project root:

```bash
cp vendor/webprofil/typo3-preflight/wp-typo3-preflight.dist.yml wp-typo3-preflight.yml
```

Minimal example:

```yaml
suites:
  static:
    enabled: true
  extensions:
    enabled: true
  site:
    enabled: true
  content_blocks:
    enabled: true
  wiring:
    enabled: true
  database:
    enabled: true
  runtime:
    enabled: true

urls:
  - /
  - /kontakt
```

`base_url` defaults to `DDEV_PRIMARY_URL` inside DDEV. If no URLs are configured, frontend smoke checks are skipped.

Individual checks can be disabled by their output check name:

```yaml
checks:
  reference-index:
    enabled: false
```

## Suites

| Suite | Purpose |
| --- | --- |
| `static` | Composer validation, PHP linting, simple architecture checks, secret scanning |
| `extensions` | Local TYPO3 package metadata, PSR-4 paths, Services.yaml sanity |
| `site` | TYPO3 site YAML basics, root page IDs, bases, language base duplicates, error handling basics |
| `content_blocks` | Content Blocks lint command, YAML basics, Basic references, labels/templates, duplicate typeNames, ext_tables.sql divergence |
| `wiring` | Extbase Fluid action links against `configurePlugin()` registrations |
| `database` | TYPO3 database schema dry-run and reference index check |
| `runtime` | TYPO3 boot smoke, configured frontend URLs, new runtime log entries |

## Baselines

Known legacy failures can be baselined so new issues remain visible.

Create or refresh a baseline:

```bash
ddev exec vendor/bin/wp-typo3-preflight baseline:create
```

Baselines are stored in `build/preflight` by default and should usually be committed with a short reason for every accepted legacy issue.

## Exit codes

| Code | Meaning |
| --- | --- |
| `0` | All checks passed or were skipped |
| `1` | At least one project check failed |
| `2` | Environment/tooling error, for example not running inside DDEV |

## Development

Install dependencies:

```bash
composer install
```

Run tests:

```bash
vendor/bin/phpunit
```

If host PHP is unavailable, the tests can be run with a PHP container, for example:

```bash
docker run --rm -v "$PWD":/app -w /app ddev/ddev-webserver:v1.25.2 php vendor/bin/phpunit
```

## Non-goals

- No auto-fixing
- No browser automation
- No replacement for PHPStan, Rector, Fractor, or project test suites
- No non-DDEV runtime support
