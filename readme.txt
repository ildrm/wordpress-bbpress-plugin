=== Forum Intelligence for bbPress ===
Contributors: forumintelligence
Tags: bbpress, forums, solutions, search, community
Requires at least: 6.6
Requires PHP: 8.2
Requires Plugins: bbpress
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Permission-aware accepted solutions, search and optional community knowledge tools.

== Description ==
Extend bbPress without core changes. Accepted solutions, reactions, reports,
search, summaries, discovery, notifications, typed fields, polls and optional
messaging, attachments, push and PWA share current content authorization.

Version 0.1.0 completed local implementation and fourteen review passes.
Consult docs/FINAL_REVIEW.md, docs/REQUIREMENTS.md and docs/VERIFICATION.md for
native test evidence, optional-feature decisions and deployment checks.

AI is optional and disabled until configured. External AI processes redacted
questions and authorized public excerpts only. Browser push uses the browser
vendor's push service with a generic activity payload. No remote telemetry.

== Installation ==
1. Install and activate bbPress.
2. Install the release directory with production Composer dependencies.
3. Activate Forum Intelligence for bbPress.
4. Review Settings and Tools, build the search index and configure cron.
5. Add the documented shortcodes to WordPress pages.

Source checkouts require Composer installation and the reproducible npm build.
Restricted attachments require a confirmed storage directory outside web roots.

== Frequently Asked Questions ==
= Is AI required? =
No. Solutions, local search and community features work without an AI account.

= Are restricted discussions sent to AI? =
No. Private, hidden, password-protected and BuddyPress group forums are excluded
by the bundled external adapter. Additional access filters can further restrict.

= Do public attachments become private after moving a topic? =
No. Existing public Media Library URLs remain public. Use protected storage for
content that may become restricted.

= Are messages end-to-end encrypted? =
No. They are private site database records, subject to site administrator access,
retention and selected-message reporting.

== Changelog ==
= 0.1.0 =
Locally verified implementation with native minimum/current compatibility,
source-authorized intelligence, independent optional providers, member feedback,
human editorial review, privacy controls and an isolated installable package.
