# GlassyPic — AI Image Optimization for WordPress

> **GitHub account: `onepunchtechnology`.** company (One Punch Technology) repo. Run `gh auth switch --user onepunchtechnology` once at the start of any session and **stay on it** for all `gh` / `git push` work — do not switch back to a personal account mid-session.

[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-blue)](https://php.net)
[![WordPress 6.0+](https://img.shields.io/badge/WordPress-6.0%2B-blue)](https://wordpress.org)
[![License: GPL-2.0](https://img.shields.io/badge/License-GPL--2.0-green)](https://www.gnu.org/licenses/gpl-2.0.html)

WordPress plugin for [GlassyPic](https://glassypic.com) — a four-stage AI image optimization pipeline: upscale (Real-ESRGAN), resize, perceptual compression, and auto-generate SEO alt text. Runs in the background via ActionScheduler.

> For installation instructions, feature descriptions, and FAQ see the WordPress.org plugin listing (coming soon) or [`readme.txt`](readme.txt).

---

## Development

**Requirements:** PHP 8.1+, Composer

```bash
composer install   # install dependencies
composer test      # run PHPUnit with --testdox
```

```bash
# Lint
./vendor/bin/phpcs --standard=phpcs.xml.dist src/ uninstall.php

# Auto-fix style
./vendor/bin/phpcbf --standard=phpcs.xml.dist src/ uninstall.php

# Run a single test class
./vendor/bin/phpunit --filter ProcessorTest

# Run a single test method
./vendor/bin/phpunit --filter 'ProcessorTest::test_run_saves_results_on_success'
```

Tests use PHPUnit 10 + Brain\Monkey (Mockery-backed WordPress function stubs) — no live WordPress install required.

---

## Architecture

`glassypic.php` bootstraps on `plugins_loaded` and calls `Plugin::init()`, the single wiring point for all WordPress hooks. No hooks are registered outside of `Plugin`.

### Processing pipeline

```
Upload event
  └─ Scheduler::queueOnUpload()            wp_generate_attachment_metadata filter
       └─ ActionScheduler job
            └─ Processor::run()
                 ├─ ApiClient::upload()             → temp_file_id  (3 retries)
                 ├─ ApiClient::process()            → job_id
                 ├─ ApiClient::pollJob()            → polls every 3s, max 5 min
                 ├─ ApiClient::downloadProcessedFile()
                 ├─ Replacer::swap()                → atomic copy+rename, .glassypic-orig backup
                 ├─ MetaManager::saveResults()      → _glassypic_* meta, status=completed
                 └─ Processor::optimizeThumbnails() → opt-in, non-fatal
```

### Status lifecycle

```
(none) → pending → processing → completed
                              → failed
                              → paused   (credits exhausted; auto-resumes)
```

All status is stored in `_glassypic_*` attachment post meta, managed exclusively by `MetaManager`.

### Key gotchas

- **Re-entrant guard:** `Replacer::swap()` calls `wp_generate_attachment_metadata()` to regenerate thumbnails, which re-fires the upload filter. `Scheduler::queueOnUpload()` returns early if status is already `pending`, `processing`, or `completed`.
- **Poll resume:** a `PollTimeoutException` leaves `job_id` intact so the next `Processor::run()` call skips upload+process and resumes from polling.
- **API key encryption:** AES-256-CBC using `AUTH_KEY . SECURE_AUTH_KEY` as key material; never output in plaintext.

---

## Debugging

Check the ActionScheduler queue:

```
/wp-admin/tools.php?page=action-scheduler&s=glassypic
```
