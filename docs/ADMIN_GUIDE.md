# Administrator guide

## Initial setup

Install patched WordPress/PHP and active bbPress. Back up the database and files. Activate the plugin, then review Site Health and Forum Intelligence → Tools. Check schema, queue and cron status. Build the index; local search works before indexing through a bounded WordPress fallback.

Create search, ask, hub, discovery, knowledge and optional messages pages using README shortcodes. Validate public/private/hidden/password and ancestor restrictions with real site roles. Exclude personal/forum/intelligence routes from shared caches. Keep advanced modules disabled until their operational dependencies are ready.

Settings controls author acceptance, multiple solutions, self/downvotes, modules, external AI, semantic search, model identifiers, daily request budget, grounding and duplicate thresholds, reputation weights/caps, excluded forums, retention and uninstall opt-in. Core solutions and local search are always available. Feature flags hide operations/queries when disabled; retained data is not deleted merely by disabling a feature.

## AI

Define FI_AI_API_KEY in wp-config.php or another protected bootstrap. FI_AI_ENDPOINT optionally overrides the HTTPS OpenAI-compatible base URL; the default is https://api.openai.com/v1. The bundled adapter uses chat/completions and embeddings. Review outbound public data and provider terms before enabling AI. Restrict key permissions/budgets at the provider. Do not place secrets in configuration-transfer JSON.

AI abstention is expected when evidence is weak, unsolved, stale, inaccessible or provider unavailable. Moderator regeneration is queued. Human edits lock summaries until explicitly unlocked. Mark inaccurate/disable before drafting; draft articles never auto-publish. Publish reviewed articles through WordPress and expose them with the knowledge shortcode, which rechecks all provenance sources.

## Communication

Users opt into email and choose immediate/hourly/daily/weekly/important-only/off. Category and source mutes are rechecked before delivery. Private-message notifications use message membership and blocking checks; titles, email and push never include message text. Configure and test WordPress mail separately. Browser push requires Composer dependencies, OpenSSL, Sodium, HTTPS and VAPID constants: FI_VAPID_PUBLIC_KEY, FI_VAPID_PRIVATE_KEY and FI_VAPID_SUBJECT. Generate keys using the maintained Minishlink VAPID helper in a protected CLI session; keep the private key server-side. Changing salts invalidates stored subscriptions.

PWA uses site icons when available and bundled fallback icons. An existing worker at the same scope is preserved. Integrating with another PWA requires a trusted transport/site adaptation, not replacing that application's worker.

## Content

Tools accepts up to twenty typed field definitions as JSON. Each has key/type/label, public/member/moderator visibility, explicit searchable/AI flags, required status, optional forum IDs and select options. Only public fields may enter search/AI. Example:

```json
[{"key":"product_version","type":"version","label":"Version","visibility":"public","required":true,"searchable":true,"ai":false,"forums":[]}]
```

Templates map forum IDs to plain-text starter guidance with an explicit prefill button that keeps existing text. Polls support two–ten choices, start/end UTC times, maximum selections, change policy and public/voter/closed result visibility. Voter identities are not exposed.

Attachments are off by default. Configure FI_PROTECTED_STORAGE_DIR and FI_PROTECTED_STORAGE_CONFIRMED only after verifying the directory is outside every server alias/document root. Never use a folder in uploads as protected storage. Public storage is a fallback only for public discussions and has explicit persistent-public-URL disclosure.

## Moderation and maintenance

The queue supports triage/assignment, warnings, pending/spam/trash, restore, close/open, dismiss and escalation through native APIs. Reports do not change reputation. Review selected private-message reports separately. Administrative reputation corrections require an explanation. Administrators can queue source-award reconciliation after author or weight changes. Optional AI case triage is requested explicitly, excludes restricted sources, records scores/provenance and never executes a moderation action.

Configuration export contains nonsecret settings, fields and templates. Review JSON and confirm the import form; invalid imports roll back database writes and invalidate option caches. Rebuild indexes after changing opt-in field definitions/models/exclusions. Run retention through WordPress scheduled tasks or bounded CLI batches.

Deactivate to stop owned schedules. Default uninstall preserves data. The explicit deletion option removes plugin tables/options/meta, while bbPress content and WordPress editorial/public media remain. Back up protected bytes before opting into deletion. Explicit uninstall removes only referenced owned protected files before dropping their references; unavailable storage or malformed references stop removal for operator repair. Public media and unrelated files remain.

## Optional achievements

Enable Achievements in Settings. Accepted answer contributor requires a currently accepted public reply. Repeated helpful contributor requires three distinct public contributions with a net vote count of at least three each. There are no activity-volume or streak awards. The queue reconciles awards after solutions, votes or authorship change. Current source checks immediately suppress badges when supporting content becomes restricted or is removed.

On the Reputation administration tab, moderators can award or revoke Verified expert and Community mentor after recording a 10–1000 character explanation. Badges grant no capabilities. The community hub shows the current member's award/revocation history, capped at twenty transitions per badge. Members can disable and erase their badge records; privacy erasure disables regeneration until the member explicitly enables achievements again. Existing communities need source reconciliation; automatic evidence examines up to 1,000 recent authored posts.

## Expert discovery

Add `[forum_intelligence_experts]` to a public page. Visitors enter an existing topic tag name. A contributor needs at least three distinct, currently public contributions with positive net reputation in that tag. Private evidence is excluded even when the contributor views their own listing. Signed-in visitors' source/tag/member mutes and blocks also apply. The directory evaluates up to 1,000 recent matching source/member pairs and returns ten contributors; these bounded results are not an exhaustive community ranking.

## Topic and reply drafts

With Composer enabled, signed-in members can save, recover and discard a private topic draft per forum and a private reply draft per topic. Native draft buttons work without JavaScript and never publish content. Recovery is explicit and replaces unsaved native composer text; the form advises members to copy that text first. JavaScript adds debounced server autosave and recovery that preserves occupied editors. Server draft content is not put into browser response caches or sent to AI. The encrypted-copy option below uses separate browser storage.

Versions reject saves/discards from stale tabs, including after a draft is deleted and recreated. On conflict, copy unsaved text, clear the editor and recover the newer draft. Limits are 200 title characters and 12,000 content characters; oversized drafts are rejected without silent truncation. Current source access is required for recovery, and saving requires native posting permission in an open discussion. Saved drafts remain until explicit discard, retention or privacy erasure; posting does not automatically erase a possibly newer draft from another tab. Encrypted browser copies are available separately as described below.

## Saved-search alerts

Members can save five local keyword searches in the community hub, then explicitly enable matching alerts for each one. Alerts default off. They cover first publication of public topics and replies, including scheduled topics, and respect saved filters, current account/source access, blocks and mutes. Native controls work without JavaScript. Several matching queries produce a single notification for the same member and source.

The queue scans twenty saved records per batch using a dedicated kind/ID index. Queries remain in owned local records; jobs contain source IDs, timestamps and batch boundaries. Internal matching does not inflate aggregate search activity. Enable email or browser push separately through their existing preferences. Disabling/removing a search stops future delivery and pending matches; existing inbox history follows normal deletion/retention. Opt-in does not send historical matches. Existing draft, private or hidden sources do not become public alert content.

## Search publication dates

Advanced search accepts inclusive UTC start and end publication dates. Same-day ranges include the entire day. Invalid dates or a reversed range keep the search form usable and show an inline correction message. The date controls work without JavaScript. Saved-query filters and their alerts use the same boundaries. Search with advanced filters stays local until a semantic adapter supports those filters; this avoids unnecessary external processing and unfiltered vector results.

## Notification center and unread indicator

The community hub includes the notification center. For a dedicated page, use `[forum_intelligence_notifications]`. Members can filter by category and All/Read/Unread, browse UTC date groups, change read state, mark all read or delete individual items through native nonce-protected forms. These controls work without JavaScript. Empty pages can occur when previously delivered sources become inaccessible; no inaccessible aggregate totals are shown.

To add an unread indicator to a page or a shortcode-capable navigation/widget area, use `[forum_intelligence_unread url="/community-hub/"]`, replacing the URL with your actual hub or notification-center page. The URL is validated through WordPress's redirect allowlist. Without a URL the indicator displays text. It renders a generic label on the server and fetches the current member's count through authenticated private/no-store REST; it never embeds a private count in cacheable HTML. Without JavaScript it remains a normal center link. Refresh happens initially and on focus/visibility, at most once a minute; a failed request or hidden tab clears the displayed count. No browser storage holds the count.

Counts examine at most 500 owned unread candidates and stop at 100 authorized items. Larger or partially scanned inboxes display an explicit lower bound rather than an exact global total. Source privacy, current category/source mutes, native Blocked roles and optional-message/badge access are rechecked for every request. A removed category mute restores currently authorized retained items. Schema 7 adds chronological/category indexes without new tables.

## Mentions
The notifications module includes a bounded @username fallback when enabled BuddyPress activity mentions or another supported integration does not own mentions. Topic/reply composers offer a labelled member picker; selecting a member inserts their handle while preserving selected text. The native composer remains usable when lookup fails. Current site membership, recipient discussion visibility, blocked roles and source/category mutes filter suggestions and delivery. Fallback lookup and publication quotas are described in REST_API.md. Native plain-text and TinyMCE editors are supported; the feature stores no copied member directory.

When BuddyPress activity mentions are enabled, its autocomplete and notifications retain ownership. Forum Intelligence loads and connects that existing editor integration rather than also delivering a fallback alert. Configure and verify BuddyPress's own activity/notification/email components on staging; the fallback's current-source delivery policy does not replace the native owner's policy. WordPress user_login is the handle; changing a display name does not rewrite historic content or resend mentions.

Configuration audit records describe successful settings, template and topic-field updates, reviewed imports and authorized exports. They retain changed known setting names or definition counts, actor and time, with no copied settings values or editorial text. Validating/sanitizing unchanged settings does not create a successful-change event. Audit access remains restricted to administrators and follows configured operational-event retention. If recording fails after a native option has already been saved, the plugin emits a fixed diagnostic and `fi_audit_write_failed`; investigate database health before relying on the trail.

## Guided setup

Dashboard links to the ordered Getting started flow in Setup and tools. It verifies WordPress/PHP/extensions, active bbPress and the schema, detects BuddyPress activity/groups/notifications/messages, and offers an explicit Save missing defaults action. Existing configuration choices are preserved; missing AI/semantic settings default off and uninstall retention defaults on. No provider connection is required or attempted by viewing setup or saving defaults.

Review local modules and their separate storage/consent requirements, then review AI/privacy settings. The initial-index form requires an explicit review both in the browser and on the server. Current environment checks gate setup mutations; repeated defaults/index requests are limited to five per five minutes per administrator. Indexing queues bounded background batches; the final step shows the current schedule/last run, flags dead/retrying jobs and links to Jobs and WordPress Site Health. This flow does not claim that an unexercised provider account is ready.

## Encrypted browser copies

Composer adds a collapsed, opt-in browser-copy panel for signed-in native topic/reply forms on HTTPS or localhost with WebCrypto and Web Locks. Enter a unique 16–128-character passphrase and enable/unlock explicitly. The passphrase field has no form name or native validation constraint; it is never submitted to WordPress. Click Save encrypted copy whenever needed. Saving continues if an already-open, authorized composer loses its connection; navigating offline still shows the generic PWA reconnect screen. Copies are scoped to the origin, blog, account, composer type and source. Ten encrypted copies per account/site are allowed, each with 200 title and 12,000 body characters.

Unlock and recovery require an online current-account/source/posting check. Recovery first decrypts locally, then WordPress sanitizes the text without persisting it; only that sanitized result enters the textarea/TinyMCE editor. Existing text is preserved, including text entered during the request. Normal server autosave may subsequently save the recovered text. Copies expire for recovery after 24 hours according to the browser clock; stored ciphertext remains until explicitly removed or browser storage is cleared. Remove encrypted copy works without the passphrase. Lock, source changes, form submission, page hiding and navigation release the page's key reference. Unlock again after returning to a hidden tab. A newer tab's copy is never silently overwritten; lock and unlock to review it. Quota failures do not block native posting.

Losing the passphrase prevents decryption. Keep a separate safe copy if necessary. Browser storage is separate from WordPress export/erasure and may survive site-account deletion. Remove copies on shared devices. This feature does not provide end-to-end encryption against the site: scripts in the origin can see an unlocked editor, and recovered text goes to WordPress. It does not store a passphrase or key, contact AI, or add private content to a service-worker cache.

## Composer formatting and preview

Signed-in new-topic/reply forms include labelled Bold, Italic, Code and Quote actions. Plain-text selection preserves surrounding content; Code escapes selected markup to show it literally. An empty selection inserts editable placeholder text. Native visual editors use their existing TinyMCE formatting commands and undo behavior; their pressed state is exposed to assistive technology. Arrow keys move within the formatting toolbar (reversed for RTL); Home/End select its first/last action and Tab leaves the toolbar. Existing native editor controls remain available.

Preview is explicit and never submits the posting form. It requires current account/site identity, source access and native posting rights, and supports the same 200 title/12,000 body character bounds as drafts. The preview shows basic formatting with inactive links and omitted active media/forms/embeds; it uses a sandboxed frame and makes no external media/provider request. Editing, native editor replacement, draft recovery, source changes, submission and page hiding invalidate an older preview. A response arriving after edits is discarded. Close returns focus to Preview. Failed checks keep the editor text; copy it before reloading if the account or source becomes unavailable. No-JavaScript native composing/posting remains available. Source metadata, body/title and preview HTML are not persisted by the preview operation. Preview/recovery preparation require REST and are rejected by the native redirect adapter, which otherwise stores result metadata temporarily.


## Question guidance

Tools keeps the existing per-forum template map and adds Contextual question templates. Configure up to 20 JSON rules using the displayed example. Each needs a unique stable id, label, integer forum and plain-text guidance; optional question type, product and category labels refine selection. Use forum 0 only for guidance that may be shown in every readable forum. A specific forum limits the catalog, labels and choices to that forum's current readers. Templates allow 5,000 characters, labels 100 and each context value 80. Invalid configuration is rejected before saving any rules.

When several rules match, the most criteria win; ties prefer a rule scoped to that forum, then the first identifier alphabetically. Unmatched choices fall back to existing forum guidance. Participants can browse and copy the native catalog without JavaScript, or use optional selectors and explicit prefill. Existing editor text is preserved. Context choices guide the template; native topic status/type/tags and structured fields remain controlled through their usual inputs. Disabling composer hides guidance controls while retaining configuration. Reviewed version 1 export/import includes contextual rules, with atomic rollback if any imported rule is invalid. Option audit stores definition counts only.

## Ranking explanations

Administrators can open Advanced search and select Show ranking explanations. Each current-readable result exposes the lexical, semantic, solved-topic and vote contributions plus the retrieval path; the optional control works without JavaScript. Ordinary visitors and moderator-only accounts cannot enable it through a URL parameter. The score combines lexical coverage (65%), semantic similarity (30%), solved-topic status (up to 2.5%) and positive net votes (up to 2.5%; capped at ten). Sorting by recency still uses publication time. Solved-topic status includes a manual resolution without an accepted reply; it does not certify an answer or replace the separate grounding checks. Semantic results now retain current net votes, including candidates absent from the lexical page. Invalid/nonfinite/out-of-range similarity values are ignored. The bundled client examines at most 50 candidates, even if an adapter ignores that requested limit.

Knowledge articles are created by converting a reviewed summary to a draft. Native Add New is unavailable. In the article editor, use Source review to open the current discussion and its native editor/revisions. When the discussion changes, the article and public knowledge list show that review is needed. Review the discussion and correct the article as needed, then check “I reviewed the source discussion and verified this article” and save. A successful review records the current source provenance and UTC verification time while preserving your article text and summary lock. An ordinary save leaves verification unchanged. Verification is refused if current editorial/source access is unavailable; restoring source access retains authored content. The summary remains independently stale until separately corrected or regenerated after unlocking.

Automatic long-discussion summaries are optional and disabled by default. In Settings and modules, enable external AI processing, select a responsible moderator by their current user ID, then enable automatic summaries. Set the minimum published replies (default 20), wait after discussion changes (default ten minutes), and minimum time between generated summaries (default one hour). The quiet period combines recent changes into one background job; the minimum interval limits repeated expensive work. These settings apply to future native discussion/solution changes and pending jobs, rather than scanning all existing discussions on enablement.

Current external-AI consent, knowledge module state, moderator permissions, forum/source restrictions, excluded forums and human summary locks are rechecked before work. Changes to policy can cancel queued work or reject a result returned during revocation. Corrected, disabled or inaccurate summaries retain human control. Queue failure/retry controls apply to real provider outages. Automatic summaries do not create or publish knowledge articles; use the reviewed conversion/editor workflow separately.

To alert responsible editors after a source changes, enable “Notify responsible article editors when sources change” in Settings and modules. Community knowledge and notifications must both be enabled. This local alert setting does not require AI. The current native article author is the responsible editor; assign the author through WordPress when editorial responsibility changes.

After a brief quiet period, background work sends an Article source reviews notification with generic text and a native editor link. The recipient must still own the article, have native editing rights and be able to read every recorded source. Category preferences and source mutes apply. Verified/current articles, trashed/deleted articles and content no longer accessible to the editor disappear from current review counts and views; changing ownership withholds previous-owner review links. Review the current discussion and use the explicit native verification checkbox to renew article provenance. Email follows the editor's ordinary preferences and contains only generic links; important-only mode excludes this category. Article text and summary locks remain intact.


### Automatic knowledge drafts

This option is off by default. Choose a responsible moderator who also has native article editing rights, a published-reply minimum, an accepted-answer confirmation minimum and a quiet wait. The discussion must be public and solved with a currently accepted answer and a current accurate enabled summary. Summary generation is a separate action: conversion never calls a provider. Human summaries can convert with external AI off; AI summaries require current consent. Source changes that make a summary stale require a fresh summary before conversion can proceed.

Conversion runs in a coalesced background job and always requests an unpublished native draft owned by that moderator. Existing source-bound articles, including trash, suppress automatic copies. Native source/solution/confirmation and summary-ready events evaluate the current criteria. Current actor/source/module/settings/summary and native draft owner/status are renewed during execution. Rejected insertions roll back the new copy and clear affected native caches. Existing human article text and summary locks remain intact. Publish only after reviewing the native draft and its source review. Defaults are three published replies, zero additional confirmations and a 300-second quiet wait; bounds appear on the native settings form and survive reviewed export/import.


### Reviewing exact source changes

Open a source-bound knowledge article in the native WordPress editor and expand **Source comparison**. Changed source IDs link to current discussions; when both exact text revisions survive and you can edit that source, **Compare recorded and current text** opens the native WordPress revision screen. The review lists up to twenty changes and attempts up to ten exact comparisons from each source’s latest twenty non-autosave revisions. Missing history, disabled revisions and older articles without exact digests show a current-source fallback. Changes outside the bounded source window require reviewing the full discussion. Accepted solution changes are called out separately. After editing the human article, explicitly confirm review and save to renew provenance; the plugin preserves authored text and summary locks.

Automatic draft creation requires verified InnoDB storage for the current site’s participating native and plugin tables. Site Health reports a required storage change if the automatic-draft flag/module is enabled on unsupported storage. The plugin refuses the copy and records a generic retryable job code; it does not convert existing table engines. Have your database operator handle engine changes through their normal maintenance process.


### Moderator recommendations

In Community knowledge, use the native topic action to recommend a solved discussion with at least one readable accepted answer. Enter an optional private note of up to 2,000 characters in the shared summary/note field. The recommendation does not generate or change a summary, call AI, create an article or publish content. Notes are available only to current moderators who can read every recorded source.

The moderator queue shows up to 25 candidate records per page, with an Older recommendations link. Inaccessible candidates are withheld. A changed discussion or accepted selection marks its note for fresh review. Create editorial draft is available when the separate reviewed summary is current and accurate and you have native article editing rights. Conversion creates or reuses the source-bound unpublished editorial workflow, preserves existing human article text and archives the recommendation. Withdraw removes a note from the active queue. A later recommendation renews the note and source baseline; unreadable previous source bindings require restoring access first. Notes remain attributed to their author for privacy erasure, including after another moderator withdraws or converts them.


### Selecting an editorial target

In Settings and modules, choose Editorial target for new knowledge drafts. The built-in Community Knowledge type remains the default. A trusted extension can register another compatible private native editorial type; the settings list only eligible integrations. Native create/edit capabilities still apply, including to the automatic draft moderator. A missing or incompatible selected integration stops conversion instead of redirecting it elsewhere. Registered targets can be transferred through reviewed configuration; importing an unavailable target is refused.

Previously authored articles retain their native body, source review, revisions and source restrictions when the selected target changes. Removing an integration withholds its bound article views and editing until it is restored. Existing source-bound articles, including trash and prior targets, suppress automatic duplicates. Human publication stays explicit; compatible native private editorial types appear through guarded community knowledge presentation rather than public REST or ordinary public archives.

## Repairing a damaged stored summary

A malformed stored summary is withheld from the public discussion and cannot create a knowledge draft or authorize regeneration. Native kind/topic collisions and invalid source bindings are preserved for investigation. Use Community Intelligence → Knowledge to enter the source Topic ID, choose Edit and save explicitly reviewed replacement text. A known scalar payload can be repaired this way; the replacement receives current bounded source provenance and a human lock. The moderator must still have current rights and read every interpretable prior/current source. A protected or moved prior binding is not silently discarded; restore legitimate access or investigate the record before correction.

Unlocking a human summary permits future requested regeneration. It does not authorize an older in-flight generation to replace a newer human edit. Concurrent summary changes produce a safe refusal and require reopening the review. Manual/generated saves and their audit event commit together; if storage fails, restore storage and retry after reviewing the current record. Existing article bodies remain authored content and are not replaced by summary repair.

## Reaction choices

In Settings and modules, the Enabled reactions group controls Like, Helpful reaction, Insightful and Thanks independently. Uncheck every choice to disable new reactions while keeping helpful votes available. Helpful reaction expresses appreciation; helpful votes and accepted-answer confirmations use their separate reputation rules. Reactions never award reputation points. Existing history is retained when a choice is disabled, and its current owner can remove it if they still have access and participation rights. Self-reactions follow Allow self-votes; previous self-reactions remain removable after that setting is turned off.

The knowledge review form has separately labeled topic/action fields and a full-width, resizable text area. Save reviewed summary remains an explicit human correction.

## Current vote rules and safe retries

Helpful votes and enabled downvotes keep one reversible signal per member and source. Turning downvotes off prevents new negative votes while keeping a member's existing negative vote removable. Positive accepted-answer confirmations require a currently accepted native reply. If source authorship or discussion binding changes while a vote is submitted, reopen the discussion before retrying. Current module, source access and self-vote settings apply again at write time.

Signal history, reputation credits and their audit commit together. A storage failure refuses the change; restore storage, review current state and retry. Repeating the same value does not duplicate the signal audit or award points again. Reaction checkboxes now place each choice's text beside its checkbox while preserving explicit accessible labels.

## Optional duplicate-prevention feedback

Related discussions use local retrieval while a member composes a topic. Results show a bounded relevance percentage, solved state and an accepted-answer preview when permitted. Related links open in a new tab with an explicit notice, preserving the unsaved composer. The optional None of these discussions answer my question button clears the current suggestions, keeps title/body text and returns keyboard focus to the title. Native posting remains available with JavaScript disabled or if lookup/feedback fails. Question, forum, tags, fields and visual-editor changes clear old suggestions before a debounced refresh.

Authenticated participants may acknowledge displayed, opened and dismissed suggestion sets. Analytics adds duplicate_feedback_viewed, duplicate_feedback_opened and duplicate_feedback_dismissed counters; the existing duplicate_suggestions_shown counts nonempty server responses and duplicate_no_match counts empty retrieval. Each new outcome counts once per issued offer, rather than once per repeated request or individual link. These counts describe acknowledged interactions, not unique members or proven prevented topics. A set may be opened and later dismissed. Guest suggestions remain available without attributable feedback offers. Revoked access, expiry, closed/category forums, source moves, disabled composer and storage/quota failures can withhold feedback, so these counters intentionally undercount those interactions. No draft text is retained or sent to an external provider.

## Answer feedback and provider controls

Each current generated Ask the Community answer offers Helpful, Not helpful, Inaccurate, Outdated and Missing context. Use **AI answer feedback** in Forum Intelligence administration to review source discussions, mark reports resolved or dismissed, or reopen a report. Reviews use the displayed record version; refresh after a conflict. Changed evidence is labelled stale. Restricted, deleted or malformed sources are withheld even from a saved report. Reports do not edit discussions, regenerate answers, publish articles or train a model.

Feedback receipts expire after fifteen minutes and belong to the original account/site. Submission requires current participation and unchanged public support, including the accepted primary. A repeated identical choice does not create another report or audit event. Choosing a different category updates that response's report and reopens it. Native forms and REST share these rules. The queue examines at most twenty-five stored reports per cursor page, so a page may be empty while a next page exists.

Pending topics/replies may be prepared in the local index with an explicit **Prepare pending content** opt-in (default off). Pending text is classified restricted, receives no embeddings, and remains excluded from every search reader until native publication. Turning the option off makes subsequent workers purge its prepared rows. Use a reviewed rebuild to include already pending sources; publication uses normal native reindexing.

External processing requires a stored boolean `true`. Native checkbox `1` is converted to a boolean; reviewed JSON strings such as `"false"`, `"true"` or `"off"` do not enable it. Semantic indexing/search follows the same typed flag rule. Consent is checked again after request-budget accounting, before the bundled HTTP request. Setup and settings show the effective boolean state.

Generated answers and human-requested moderation advice can be enabled separately after general AI consent. Reranking is disabled by default and requires its own boolean opt-in. It processes at most twelve current public search excerpts and a redacted query, preserves explicit recency sorting/pagination, and falls back to current authorized results on invalid output, changed evidence, consent revocation or provider failure. It never adds a source. Private result sets skip external reranking.

Summary, moderation and reranking models may be selected separately; a blank model uses the text model. Configure daily request limits, 1–30 second timeouts, 128–4096 completion tokens and a 10–3600 second provider-failure pause. Queued work also has bounded retries and administrator retry controls. Choose **AI logging**: Off, Aggregate failures only, or Aggregate usage and failures (default). These modes control the bundled adapter's numeric request/failure/token metrics. Malformed logging values disable those metrics. No mode records raw prompts, evidence, answers or credentials. Request quotas and security/configuration audits remain active, and reporting is not a billing invoice.

Zero-result search can offer an explicit local spelling link for one to four English words. It examines at most fifty indexed, currently public/readable sources and twenty title words per source, uses a matching three-letter prefix and bounded edit distance, rejects ambiguous substitutions, and requires current matching results under the original filters. The typed query stays unchanged until the member chooses the suggestion. Other languages, absent indexes and uncertain spellings retain ordinary zero-result guidance.
