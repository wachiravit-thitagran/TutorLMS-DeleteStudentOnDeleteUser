# Tutor User Cleanup

A WordPress plugin that adds an **opt-in option to the "Delete Users" screen** to also wipe a user's **Tutor LMS learning data** — enrollments, course & lesson progress, completion status, and quiz attempts & answers — while **preserving all financial and instructor records**. The goal: let someone re-register with the same email and start as a brand-new learner.

> Repo: `TutorLMS-DeleteStudentOnDeleteUser` · Plugin slug: `tutor-user-cleanup`

## What it does

- Adds a checkbox on the WordPress delete-user confirmation screen (single **and** bulk delete): *"Also wipe this user's Tutor LMS learning data."*
- When ticked, runs a safe, ordered cleanup **before** the account is removed.
- Provides a separate **Reset Tutor data** action (row + bulk) to wipe learning data **without** deleting the WordPress account.
- **Dry-run preview** shows exactly how many records will be removed before you commit.
- Logs requested, deleted and failed counts for every operation under **Tools → Tutor User Cleanup**.
- On network deletion, processes every site the user belongs to; extensible via the `tutor_user_cleanup_after_user` hook.
- Multisite site-local resets, site-removal cleanup and their previews are blocked: Tutor lesson progress and reading metadata are shared across the network without a site namespace. The confirmation screen and preview explain this limitation. Explicit network account deletion retains whole-account cleanup, subject to the existing version and request guards. Network deletion preview is unavailable; it must not be confused with a site-local reset preview.

## What it removes vs. preserves

| Removed (learner data) | Preserved (never touched) |
|---|---|
| Enrollments (`tutor_enrolled` CPT) | WooCommerce / Tutor orders |
| Course & lesson completion | Instructor earnings & withdrawals |
| Quiz attempts & answers | Course reviews & ratings |
| Gradebook results (Pro, if present) | Course Q&A |
| Learner caches / transients | Instructor-authored courses & quiz definitions |

## How it works (verified against Tutor LMS source)

- **Enrollments** are the `tutor_enrolled` custom post type (`post_author` = student, `post_parent` = course).
- **Course completion** lives in `wp_comments` (`comment_type = 'course_completed'`); **lesson completion** in usermeta `_tutor_completed_lesson_id_{id}`.
- **Quiz attempts/answers** live in `{prefix}tutor_quiz_attempts` + `{prefix}tutor_quiz_attempt_answers`.
- Wherever possible the plugin calls Tutor's own high-level APIs — `tutor_utils()->delete_course_progress()` and `QuizModel::delete_quiz_attempt()` — so it stays compatible across Tutor versions and lets add-ons clean up via `delete_tutor_course_progress`.

See [`docs/REFINED-PLAN.md`](docs/REFINED-PLAN.md) for the full data-model analysis (Thai).

## Version pinning (safety against schema changes)

Tutor LMS occasionally changes how it stores data (for example, the **4.0** release in July 2026 was a full redesign). If the plugin blindly ran its delete queries against an unfamiliar schema, it could remove the wrong rows.

To prevent that, the plugin pins a **supported Tutor LMS version range**:

```php
define( 'TUTOR_USER_CLEANUP_MIN_TUTOR_VERSION',    '2.0.0' );
define( 'TUTOR_USER_CLEANUP_TESTED_TUTOR_VERSION', '4.0.1' ); // bump after re-validating
```

Behaviour when the installed Tutor LMS version is outside this range—or cannot be detected:

- **Live cleanup is blocked** and logged as `blocked` — nothing is deleted.
- **Dry-run / preview still works**, so you can see what *would* be removed.
- A clear warning appears on the delete-user screen, the reset confirmation screen, and under **Tools → Tutor User Cleanup**.

An admin who has verified that a newer Tutor version still uses the same data model can lift the block via the **Version override** setting, or programmatically:

```php
add_filter( 'tutor_user_cleanup_allow_unsupported_version', '__return_true' );
```

After confirming compatibility with a new Tutor release, bump `TUTOR_USER_CLEANUP_TESTED_TUTOR_VERSION` to that version.

## Install

1. Zip this repo (or the plugin files) and upload via **Plugins → Add New → Upload**, or copy into `wp-content/plugins/tutor-user-cleanup/`.
2. Activate.
3. Configure data types, logging and version safety under **Tools → Tutor User Cleanup**.

**Always back up your database and test on staging first.** Start with dry-run.

## Tests

All tests run with plain PHP — no WordPress install, no database, no PHPUnit.

Unit tests (pure logic, stubbed WordPress) drive the cleanup service through a
fake repository:

```bash
php tests/run-tests.php
```

They cover the permission/nonce/checkbox guard (including multi-site iteration and
malformed requests), the `delete_user` entry point — importantly, that a
deletion from REST/WP-CLI/WooCommerce without the checkbox does nothing —
dry-run vs. live behaviour, dedupe, data-type toggles, settings sanitization
edge cases, per-operation failure isolation and actual deletion counts, the extension hooks and their arguments,
the version gate and its override paths, and the financial-data safety
guarantee.

Integration tests exercise the **real** repository end-to-end against a seeded,
in-memory database (a lightweight `$wpdb` stand-in that executes the exact
queries the plugin issues). They seed two learners plus financial/instructor
records, run the wipe, and assert — reading rows directly — that every learner
data type is removed for the target user while orders, earnings, withdrawals,
reviews, Q&A, quiz definitions, instructor courses, and a bystander user's data
all survive.

They also cover query precision (that `esc_like` escaping keeps the SQL `_`
wildcard from over-matching lesson meta, that the `comment_agent` filter spares
another plugin's rows, and that `delete_enrollment()` refuses any post that is
not a `tutor_enrolled` record), a learner with no data, missing optional Pro
tables, enrollment statuses, bulk wipes, dry-run, and the version block.

```bash
php tests/integration-tests.php
```

To lint:

```bash
find . -name '*.php' -not -path './.git/*' -exec php -l {} \;
```

## Continuous integration

`.github/workflows/ci.yml` lints every PHP file and runs both suites on each
push and pull request across PHP 7.2, 7.4, 8.0, 8.1, 8.2, and 8.3.

## Extending

```php
// Clean up your own add-on's learner data.
add_action( 'tutor_user_cleanup_after_user', function ( $user_id, $counts, $dry_run, $context ) {
    if ( $dry_run ) {
        return; // preview only.
    }
    // ... remove your data for $user_id ...
}, 10, 4 );
```

## License

GPL-2.0-or-later.
