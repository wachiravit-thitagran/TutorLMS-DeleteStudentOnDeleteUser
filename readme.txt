=== Tutor User Cleanup ===
Contributors: wachiravit
Tags: tutor lms, lms, users, gdpr, enrollment, quiz, cleanup
Requires at least: 5.6
Tested up to: 6.6
Requires PHP: 7.2
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Optionally wipe a user's Tutor LMS learning data when you delete their WordPress account, so the same email can re-register as a fresh learner. Financial data is preserved.

== Description ==

When you delete a WordPress user, Tutor LMS leaves behind enrollments, progress,
completion status and quiz attempts. That means a person who re-registers with the
same email is not treated as a brand-new learner.

**Tutor User Cleanup** adds an opt-in checkbox to the WordPress "Delete Users"
screen. When ticked, it removes the selected user's Tutor LMS *learning* data
before the account is deleted:

* Course enrollments (`tutor_enrolled` posts)
* Course &amp; lesson progress and completion
* Quiz attempts and their answers
* Gradebook results (Tutor Pro, if present)
* Related learner caches/transients

It **never** removes financial or instructor records:

* WooCommerce orders / Tutor native orders
* Instructor earnings and withdrawals
* Course reviews and Q&amp;A
* Courses authored by an instructor
* Quiz question definitions

Additional features:

* **Reset learner** — wipe learning data *without* deleting the WordPress account
  (per-row action and bulk action on the Users screen).
* **Preview / dry-run** — see exactly how many records will be removed before you
  commit.
* **Bulk delete** support — the checkbox applies to every selected user; each user
  is processed independently so one failure never aborts the batch.
* **Logging** — every operation records requested, deleted and failed counts.
* **Multisite** aware — network deletion cleans every site the user belongs to.
* **Extensible** — fires `tutor_user_cleanup_after_user` so add-ons can clean up
  their own data.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` (or install the zip via
   Plugins → Add New → Upload).
2. Activate the plugin.
3. Configure which data types to wipe under **Tools → Tutor User Cleanup**.

**Always back up your database and test on staging before using in production.**

== Frequently Asked Questions ==

= Does this delete WooCommerce orders? =
No. Orders, payments, earnings and payouts are always preserved.

= Can I remove learning data without deleting the account? =
Yes — use the "Reset Tutor data" row action or the "Reset Tutor learner data" bulk
action on the Users screen.

= What if I run an older/newer Tutor LMS version? =
The plugin pins a supported Tutor LMS version range (minimum and tested-up-to). If
the installed version is outside that range, or its version cannot be detected, live cleanup
is blocked and logged, and nothing is deleted. Dry-run preview still works. An admin
who has verified compatibility can enable the "Version override" setting (or the
`tutor_user_cleanup_allow_unsupported_version` filter) to proceed. It also checks
each table/column before querying and prefers Tutor's own high-level APIs
(`delete_course_progress()`, `QuizModel::delete_quiz_attempt()`).

= Where do I bump the tested version? =
In `tutor-user-cleanup.php`, set `TUTOR_USER_CLEANUP_TESTED_TUTOR_VERSION` to the
newest Tutor LMS version you have validated the data model against.

== Changelog ==

= 1.1.0 =
* Fail closed when the Tutor LMS version cannot be detected.
* Clean every user site during multisite network deletion.
* Keep delete-user cleanup live-only; dry-run remains available through preview.
* Include every enrollment status in course/add-on cleanup.
* Report requested, deleted and failed counts per operation and continue independent steps after failures.
* Use UTC consistently for log retention and uninstall all sites on large networks.

= 1.0.0 =
* Initial release: opt-in cleanup on user deletion, reset-learner action,
  dry-run preview, logging, bulk + multisite support.
