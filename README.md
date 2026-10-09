# Forum Intelligence for bbPress

Permission-aware solutions, search, knowledge and community tools for bbPress. Version 0.1.0 is an installable release candidate with a completed local implementation and fourteen review passes. See the [final review](docs/FINAL_REVIEW.md), [verification](docs/VERIFICATION.md) and [124-section requirements](docs/REQUIREMENTS.md) for exact evidence and deployment-specific checks.

The optimized execution prompt is [docs/OPTIMIZED_PROMPT.md](docs/OPTIMIZED_PROMPT.md). The complete supplied catalog is preserved in [docs/ORIGINAL_PROMPT.md](docs/ORIGINAL_PROMPT.md). All 52 roles inform the review gates; this does not represent 52 independent reviewers.

## What runs

- Accepted solutions: optimistic versions, primary/multiple answers, history, confirmations, current-access checks and reversible reputation.
- Local full-text/fallback search, filters, safe snippets/highlights, explicit spelling suggestions, optional bounded reranking and nonblocking duplicate composer suggestions.
- Optional semantic retrieval and grounded AI answers with abstention, current-source citation validation, redaction, budgets and verified member/moderator feedback.
- Moderator-reviewed summaries, manual locks, source invalidation and draft knowledge articles.
- Votes, reactions, expertise, discovery, follows, mutes, reports and audited native moderation.
- Current-permission mention lookup and code-aware publication/edit alerts when no supported integration owns mentions; native BuddyPress autocomplete retains ownership.
- In-app notifications, opt-in email/digests and encrypted browser push via maintained Web Push libraries.
- Structured topic fields, contextual question templates, server drafts, opt-in encrypted browser copies, native formatting and inert preview, scheduling, polls and optional attachments.
- Optional isolated private messaging with participation checks, blocking, selected-message reporting and polling.
- Optional PWA shell, generic offline response and no private-content cache.
- Admin tools, nonsecret configuration import/export, privacy export/erasure, retention, health and CLI.

## Install

Requires PHP 8.2+, WordPress 6.6+, bbPress 2.6+ and InnoDB. Use a currently patched PHP build. Local native verification includes WordPress 6.6/PHP 8.2.34 and WordPress 7.1/PHP 8.4.26, plus Windows PHP 8.2.12, with bbPress 2.6.19, BuddyPress 14.5.2 and MariaDB. Final minimum/current matrices each passed 291 tests / 4,150 assertions plus 17 native checks. Hosted CI and live account delivery are separate deployment checks.

Install the ZIP and verify its adjacent SHA-256 sidecar. The archive contains locked, isolated production dependencies and required documentation.

From a source checkout, run `composer install --no-dev --prefer-dist --optimize-autoloader` and `npm ci && npm run build`. Place the directory in `wp-content/plugins/forum-intelligence-for-bbpress`, activate bbPress, then activate this plugin. Keep build tools and development dependencies outside the deployed web root. A release package must include production Composer dependencies.

Open **Forum Intelligence → Settings/Tools**. Start with local search and solutions. Build the index and arrange reliable background processing. External AI, push, PWA, messaging and attachments require explicit settings/configuration. See [admin guide](docs/ADMIN_GUIDE.md).

Add these shortcodes to WordPress pages:

| Shortcode | Purpose |
| --- | --- |
| `[forum_intelligence_search]` | Search with advanced filters |
| `[forum_intelligence_ask]` | Grounded answers or useful abstention |
| `[forum_intelligence_hub]` | Personal notifications, follows and profile |
| `[forum_intelligence_notifications]` | Owned notification center with filters, date groups and native controls |
| `[forum_intelligence_unread url="/community-hub/"]` | Optional authenticated unread indicator; use your actual center URL |
| `[forum_intelligence_discovery]` | Trending, recent, solved and help views |
| `[forum_intelligence_experts]` | Tag-based contributors with public evidence and a minimum sample |
| `[forum_intelligence_knowledge]` | Human-published, source-authorized articles |
| `[forum_intelligence_messages]` | Private conversations when enabled |

Keep authenticated, forum and intelligence pages out of shared full-page/CDN caches. Never treat an attachment download controller as protection for public Media Library URLs.

## Verification

`composer validate --strict --no-check-publish`, `composer lint`, `vendor/bin/phpcs`, `composer analyse`, `composer test`, `npm run lint`, `npm test`, `npm run build` and `npm run e2e` are the relevant commands. Integration tests require an **isolated disposable WordPress database**: fixtures reset plugin tables. Follow [development instructions](docs/DEVELOPMENT.md); never point tests at a production installation.

Latest recorded evidence includes actual WordPress integration, REST authorization, mocked provider transport, real Web Push encryption with mocked delivery, BuddyPress hidden groups, multisite isolation, classic/block themes, keyboard, mobile/RTL and scoped axe checks. External AI credentials, real push subscriptions and mail infrastructure still need deployment validation.

## Documentation

[Architecture](docs/ARCHITECTURE.md) · [Data model](docs/DATA_MODEL.md) · [Security](docs/SECURITY.md) · [Privacy](docs/PRIVACY.md) · [Extension API](docs/EXTENDING.md) · [REST API](docs/REST_API.md) · [Operations](docs/OPERATIONS.md) · [Assumptions](docs/ASSUMPTIONS.md) · [Final review](docs/FINAL_REVIEW.md) · [Release verification](docs/VERIFICATION.md)

Licensed GPL-2.0-or-later. Dependency licenses remain those of their upstream packages; see [third-party notices](THIRD_PARTY.md).
