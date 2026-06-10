# Stride LMS

**Stride** is a modern LMS platform for training management — a clean rewrite of VAD Vormingen v3. Business logic lives in the `stride-core` mu-plugin (NTDST Core framework: DI container, Bootstrap, Router); presentation lives in the `stridence` theme (Tailwind CSS + Alpine.js + Vite). LearnDash is the content engine; editions/sessions/registrations are Stride's own scheduling layer on top. Built on Bedrock WordPress.

## Documentation

- **[CLAUDE.md](CLAUDE.md)** — the real developer guide: architecture, patterns, data model, workflows
- **[docs/LAUNCH-CHECKLIST.md](docs/LAUNCH-CHECKLIST.md)** — production launch checklist
- `site.yml` — operational config (hosting, deploy, SSH)

## Quickstart (DDEV)

```bash
ddev start
ddev launch                                              # open https://stride.ddev.site

# Seed development data (users, courses, editions, registrations)
ddev exec wp eval-file scripts/seed.php

# Tests
ddev exec vendor/bin/phpunit --testsuite Unit            # unit suite (fast, stubs)
ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist  # integration suite (real WordPress)
ddev exec composer lint:stan                             # static analysis (PHPStan)
```
