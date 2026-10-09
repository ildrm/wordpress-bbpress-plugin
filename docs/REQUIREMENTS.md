# Requirements traceability

The [original 124-section prompt](ORIGINAL_PROMPT.md) remains authoritative. This final local release review distinguishes required behavior from the original optional/conditional examples. Every section has a concrete delivered decision below. Evidence, all thirty done criteria and all fourteen review passes are in [FINAL_REVIEW.md](FINAL_REVIEW.md); historical RED/green and repeated runs remain in [VERIFICATION.md](VERIFICATION.md).

The implementation is locally verified as a 0.1.0 release candidate. External account delivery, live hosting/CI, manual assistive-technology testing and site-specific capacity require the operator's environment; they are not represented as completed live checks.

| Section | Original requirement | Delivered decision / review evidence |
| --- | --- | --- |
| 1 | PRIMARY MISSION | Native bbPress intelligence with local defaults, modular optional services and the original six user flows. |
| 2 | TEAM OF EXPERT ROLES | All 52 perspectives have specific review evidence in FINAL_REVIEW; they are perspectives applied by the implementation agent. |
| 3 | EXECUTION DISCIPLINE | Original/optimized prompts, assumptions, ADRs, meaningful RED/green regressions and preserved repeat-run evidence. |
| 4 | COMPATIBILITY BASELINE | PHP 8.2+, WP 6.6+, bbPress 2.6+; native minimum/current matrices, BuddyPress 14.5.2 and both theme families. |
| 5 | NON-NEGOTIABLE ARCHITECTURAL INVARIANTS | Native posts/capabilities, renewed source authorization, modular services, no-AI local operation and safe degradation. |
| 6 | PROPOSED PROJECT STRUCTURE | Composer-autoloaded src modules, scoped production dependencies, assets, translations, tests, tools and operational docs. |
| 7 | DOMAIN MODULES | Domain modules wired through Services/Operations, shared access/store/queue and explicit contracts. |
| 8 | FEATURE: ACCEPTED SOLUTIONS AND SOLVED TOPICS | Full solved/accepted/multiple/primary/reopen/history/confirmation flow; versions, locking, native bindings and reversible credits. |
| 9 | FEATURE: DUPLICATE-TOPIC PREVENTION | Debounced composer suggestions, current accepted previews, optional dismissal, retained draft/new-tab links and signed aggregate feedback. |
| 10 | FEATURE: ADVANCED SEARCH | Local full-text/fallback and hybrid search; filters/sorts/paging, safe highlights, debug contributions and explicit bounded spelling/reranking. |
| 11 | SEMANTIC INDEXING | Bounded asynchronous native indexing and hashes; metadata/status/privacy/move invalidation. Opt-in pending preparation is restricted, local-only and withheld from retrieval until publication. |
| 12 | VECTOR BACKEND ABSTRACTION | VectorStore contract and schema-8 compiled local cache, fifty-row scan, thousand-candidate bound, model/hash/current-permission validation; indexed adapters remain operator choices. |
| 13 | ASK THE COMMUNITY | Native Ask interface with current accepted support, citations, abstention, prefilling and five verified-response feedback choices. |
| 14 | RAG SAFETY | Public-only bounded/redacted evidence, untrusted-data instructions, sent-source allowlists, renewed hashes/primary and owner/site display proofs. |
| 15 | AI PROVIDER ARCHITECTURE | Independent text/embedding/moderation/reranking contracts, null backend and explicit bridges; feature models, limits, timeout/retry and aggregate logging modes. |
| 16 | AI PRIVACY POLICY | Typed external consent, opted-in public metadata, restricted/password/group exclusions, no secrets/raw content in browser or logs. |
| 17 | THREAD SUMMARIES | Native manual/generated summaries, timestamps/hashes, corrections/locks/disabled/inaccurate/stale states and opt-in asynchronous threshold/debounce. |
| 18 | KNOWLEDGE-BASE GENERATION | Reviewed native/custom-target drafts, configurable criteria, recommendations, human publication, provenance, verification, revision comparison and editor alerts. |
| 19 | KNOWLEDGE GRAPH | Ten typed audited human relationships, symmetric handling and current endpoints. Inference, graph visualization and merge shortcuts remain optional. |
| 20 | VOTING | Unique reversible votes, current source/actor/policy checks, bounded rates, transactional audit/credits and self/downvote settings. |
| 21 | REACTIONS | Typed human-labelled reaction catalogs, explicit settings/import/export and retained owner withdrawal after disabling. |
| 22 | REPUTATION | Inspectable capped credit ledger, reversals, source/author reconciliation and bounded rebuild/profile views. |
| 23 | DOMAIN-SPECIFIC EXPERTISE | Reproducible tag-specific evidence, distinct-source minimum, anti-gaming/current authorship and reconciliation; no AI-only expertise. |
| 24 | EXPERT DISCOVERY | Questions-you-can-help-with feed and public tag experts, current evidence/mutes and minimum samples; unsolicited expert blasts are avoided. |
| 25 | REPORTING / FLAGGING | Native current-readable reports, reasons/notes, uniqueness, participation/rate limits and private moderation queue. |
| 26 | MODERATION CASE MANAGEMENT | Assignment/priority/status/current content and human triage/warn/pending/spam/trash/restore/close/open/dismiss/escalate with intent/action history. |
| 27 | AI-ASSISTED MODERATION | Optional public-only queued moderator-requested advisory scores/provenance; human native actions remain explicit. |
| 28 | AUDIT LOG | Restricted sensitive-event view, configuration/export auditing, known metadata without secrets and retention. |
| 29 | FOLLOWING | Topic/forum/tag/user follows and owned local saved-search alerts with current-source/preference/mute renewal. |
| 30 | MUTING | Source/user/tag/category muting for appropriate feeds/recommendations/delivery; native moderation queue remains independently available. |
| 31 | NOTIFICATION ENGINE | Per-site preference-aware bounded leased fanout and inbox/email/digest/push delivery; actual 201-follower workload and retry/privacy exclusions. |
| 32 | IN-APP NOTIFICATION CENTER | Owned read/category/date groups, native controls, pagination and no-store authenticated bounded unread indicator. |
| 33 | WEB PUSH | Optional explicit browser/user opt-in, encrypted secure metadata, approved endpoints, unsubscribe/expiry cleanup and real encryption/mock delivery. |
| 34 | PERSONALIZED DISCOVERY | Explainable bounded followed/recent/solved/help/trending feeds, chronological modes and module opt-out. |
| 35 | TRENDING | Current-readable/unmuted native replies/participants and known positive signals with decay; restricted/spam/blocked evidence excluded. |
| 36 | COMPOSER | Native progressive composer, formatting/inert preview, templates/fields, duplicates, owned drafts, encrypted copies and attachments. |
| 37 | MENTIONS | Bounded current-member lookup, code-aware publication/edit alerts, deduplication/quotas and BuddyPress parser/delivery ownership. |
| 38 | TOPIC TEMPLATES | Forum defaults and context variants with explicit prefill, deterministic priorities and preserved visual/text editor content. |
| 39 | STRUCTURED TOPIC FIELDS | Typed configurable required/forum/visibility/index/AI fields, sanitized native saves and opted-in metadata projection. |
| 40 | ATTACHMENTS | Multipart MIME/size/nonces/capabilities, public-media honesty, outside-web-root protected storage and authorized byte download/erasure. |
| 41 | POLLS | Optional dated single/multiple polls, change policy, protected result modes, unique votes, aggregate repair and moderator closure. |
| 42 | USER BLOCKING | Symmetric messaging blocks and source/user muting with current participation checks. |
| 43 | PRIVATE REPLIES | Conditional original feature: private replies are not implemented. Native topic/forum inheritance is retained; no CSS privacy substitute. |
| 44 | DRAFTS AND SCHEDULING | Owned versioned server topic/reply drafts, native scheduling and explicit encrypted offline copies with online recovery/24-hour expiry. |
| 45 | BADGES AND ACHIEVEMENTS | Optional deterministic public-evidence achievements, reviewed mentor/expert awards, revocation history/preferences/erasure and notifications. |
| 46 | PRIVATE MESSAGING | Optional account/participant/block checks, private conversations/read/leave/selected-message reports, cursor APIs and no-JS pagination. |
| 47 | REAL-TIME CHAT | RealtimeTransport and visible-open bounded polling; websockets remain a deployment-specific optional transport. |
| 48 | PWA | Optional manifest/icons/shell, generic offline screen, no discussion/message cache and foreign-worker preservation; install prompt is browser-controlled. |
| 49 | COMMUNITY ANALYTICS | Aggregate search/duplicate/abstention/moderation/provider activity and current unsolved-topic documentation opportunities; numeric usage is not vendor billing. |
| 50 | SEARCH-GAP ANALYSIS | Private current-source/category/stale feedback review plus aggregate unmet-demand counters/current unsolved topics; raw-query clustering is deliberately not collected. |
| 51 | ADMIN EXPERIENCE | Eleven native administration sections, labelled progressive controls, audited settings and review tools; packaged keyboard/axe/RTL checks. |
| 52 | ONBOARDING | Ordered environment/defaults/module/privacy/index/health onboarding with explicit indexing review, native authorization and preserved settings. |
| 53 | DATABASE ARCHITECTURE | Site-prefixed indexed InnoDB tables, native content authority, bounded JSON payloads, unique identities and transaction/savepoint discipline. |
| 54 | DATABASE MIGRATIONS | Versioned schema-8 dbDelta migration, safe failure admission, compiled-vector legacy upgrade and populated native-content preservation. |
| 55 | ASYNC JOB SYSTEM | Unique/revised job keys, row-locked leased claims, fenced completion, bounds, retry/backoff/dead jobs, current policy checks and CLI retries. |
| 56 | WP-CLI | Actual registered operational health/reindex/queue/retention/reputation/configuration commands with bounded native jobs and useful output. |
| 57 | CACHE ARCHITECTURE | Site-scoped request reuse and priming, source/model hashes, current authorization at display, no public cache of private content. |
| 58 | REST API | Registered validation/ownership/current-access operations and query routes, WordPress authentication/nonces and private no-store responses. |
| 59 | FRONTEND ARCHITECTURE | Progressive native forms, scoped CSS/modules, bounded enhancements and normal native posting when JavaScript fails. |
| 60 | BLOCK AND CLASSIC THEME SUPPORT | Native classic Twenty Twenty-One and block Twenty Twenty-Five coverage, including the installed final answer/feedback flows. |
| 61 | UI DESIGN SYSTEM | Shared panels/controls/notices/tokens and native WordPress administration; responsive labels and contextual state. |
| 62 | ACCESSIBILITY | Semantic labels/headings/landmarks, keyboard native forms/focus, progressive fallback and scoped WCAG 2.2 AA axe checks; manual behavior reviewed. |
| 63 | RESPONSIVE UX | Mobile viewport and RTL checks across native composer, search, notifications, admin, knowledge, reactions/votes and feedback. |
| 64 | INTERNATIONALIZATION | Escaped translatable strings, current POT, logical/scoped styling, UTC timestamps and native localized formatting. |
| 65 | SECURITY BASELINE | Layered current authorization, quotas, locks, safe storage/transport, controlled adversarial cases and documented external service boundaries. |
| 66 | WORDPRESS SECURITY RULES | Native capabilities, nonces, sanitization/escaping, prepared SQL, restricted admin/REST and no core modification. |
| 67 | XSS AND AI OUTPUT | Plain escaped generated prose, inert preview/sanitized native content and no executable arbitrary AI HTML. |
| 68 | SSRF | Verified HTTPS WordPress safe HTTP, no provider redirects, bounded responses, no user-selected source fetches and approved push endpoints. |
| 69 | PRIVACY | WordPress exporter/eraser, source-bound privacy, approved identity anonymization, opt-in transport and documented stored/transmitted fields. |
| 70 | DATA RETENTION | Configurable bounded retention of operational records and protected byte cleanup; durable explainable reputation/human native content retained. |
| 71 | TELEMETRY | No remote telemetry or raw-query logging; numeric local aggregates and opt-in external services only. |
| 72 | PERFORMANCE BUDGETS | Indexed and bounded retrieval/jobs/paging, measured native screens, query priming and no synchronous embeddings/digests on publication. |
| 73 | LARGE-FORUM BEHAVIOR | Bounded candidate/keyset work, asynchronous reindex/leases and contracts for scalable backends; capacity must be measured on the deployment corpus. |
| 74 | BUDDYPRESS COMPATIBILITY | Actual BuddyPress hidden/group restrictions and member URL/parser/delivery ownership integration; no independent duplicate native mention alerts. |
| 75 | MULTISITE | Native network activation/provisioning/deactivation/uninstall and retained-service site isolation, including feedback and privacy creator records. |
| 76 | THIRD-PARTY COMPATIBILITY | Native hooks/adapters, scoped vendor namespaces, real ZIP crypto with preloaded conflicting legacy classes and optional module defaults. |
| 77 | EXTENSION API | Documented access/ranking/provider/search/vector/knowledge/file/realtime filters and event actions with strict adapter contracts. |
| 78 | FEATURE FLAGS / MODULE SYSTEM | Independent module flags, canonical booleans, native settings/reviewed configuration and graceful null/local fallbacks. |
| 79 | ASSET LOADING | Conditional native assets, reproducible build, browser-only enhancement boot and scoped styles. |
| 80 | ANALYTICS EVENTS | Known local numeric outcome events, deduplicated bounded duplicate feedback and no hidden personal query transcript. |
| 81 | DATA CONSISTENCY | Native versions/unique keys/row locks, audited atomic writes, reversible counters/credits and current-source/cache renewal. |
| 82 | EVENT MODEL | Documented post-commit domain actions and durable events with retryable idempotent internal consumers. |
| 83 | MODERATION VS REPUTATION SEPARATION | Reports/moderation do not assign reputation authority; credit changes require independent policy/authorized audit. |
| 84 | ANTI-GAMING | Unique actor/source identities, self/downvote policy, caps, minimum samples, rates and current blocked/inactive/source checks. |
| 85 | EMAIL | WordPress plain generic activity/digest mail, preference links, header-safe recipients and explicit per-site opt-in; real delivery is operator-owned. |
| 86 | SEO | Native topic URLs and source-authorized human articles; original optional SEO considerations do not advertise private/generated structured answers. |
| 87 | UNINSTALLATION | Default preserve, explicit removal, malformed/blocked safe refusal, protected bytes, native/human/public-media retention and actual restoration. |
| 88 | ERROR HANDLING | Typed domain failures, generic safe frontend errors, local fallback/abstention and preserved native posting/data on outages. |
| 89 | OBSERVABILITY | Site Health, queue/error codes, diagnostics and configurable numeric provider logging without secrets/raw text. |
| 90 | DEVELOPMENT QUALITY | Modular typed source, maintained scoped dependencies, meaningful regressions, repeated real native acceptance and reproducible release tooling. |
| 91 | CODING STANDARDS | Clean configured WordPressCS/PHPCS and ESLint checks; native escaping/sanitization/translation conventions. |
| 92 | PHP QUALITY | Strict PHP, contracts/domain failures, prepared SQL, bounded data and configured PHPStan level 5. |
| 93 | FRONTEND QUALITY | Scoped vanilla progressive frontend, native accessible controls, bounded debounce/polling and deterministic asset build. |
| 94 | TEST STRATEGY | Layered ranking/vector units, actual WordPress/bbPress integration/REST/security and browser/axe/native fixtures. |
| 95 | CRITICAL AUTHORIZATION TEST MATRIX | Native role/public/private/hidden/password/ancestor/group/source-edit/move/deletion and current actor/cache regression coverage. |
| 96 | AI SECURITY TESTS | Controlled malicious/malformed/missing/fabricated citations, redaction/clipping, current source/actor/case changes, provider outage and no-transfer cases. |
| 97 | PERFORMANCE TESTS | Representative search/vector/fanout plus all eight screen workloads, long replies/large graph/notification/vote fixtures and declared local regression budgets. |
| 98 | DATABASE QUERY REVIEW | Native EXPLAIN/FULLTEXT/index review, bounded scans/paging, cache priming and measured query counts; workload rows are labelled synthetic. |
| 99 | ADMIN SECURITY | Native moderator/settings capabilities, nonce-checked mutations, safe export/import and restricted audit/private message-report views. |
| 100 | UX PRINCIPLES | Clear state, retained drafts/human edits, useful fallback, explicit irreversible data-removal choice and nonblocking native posting. |
| 101 | CORE USER FLOWS | Flows A–F covered through native search/solution/duplicate/ask/knowledge/report/moderation and preference/editorial browser workflows. |
| 102 | ADMIN INDEX MANAGEMENT | Reviewed reindex controls, queue progress/dead-job diagnostics, health and native CLI operations. |
| 103 | PROVIDER FAILURE | Bundled circuit pause and bounded retries, safe local retrieval, answer abstention and preserved human/native source state. |
| 104 | COST CONTROL | Site daily requests, per-feature switches/models, input/completion bounds, unchanged hash suppression and aggregate usage; no currency invoice claims. |
| 105 | CONTENT HASHING / INVALIDATION | Native source/metadata/model/visibility hashes, asynchronous invalidation and current presentation/citation/accepted-primary proofs. |
| 106 | KNOWLEDGE PROVENANCE | Strict native IDs/hashes/topic/target/revision provenance, human verification/source comparison and retained editorial content on restriction. |
| 107 | SEARCH RANKING | Reproducible local/hybrid/public signal contributions, administrator-only numeric explanations and optional validated bounded reranking. |
| 108 | ABSTENTION | Accepted-source/evidence thresholds, safe malformed/outage/changed-source refusal and native search/create-topic alternatives. |
| 109 | USER FEEDBACK ON AI ANSWERS | Five verified-response feedback choices, owner/site/expiry receipts, private current-source queue, conflicts, audits, privacy and retention. |
| 110 | MODERATOR CORRECTION OF AI | Explicit manual regeneration/edit/lock/inaccurate/disabled states, relationship changes, draft review and human feedback review status. |
| 111 | ACCESSIBLE AI UX | Native ask/status/citations/feedback controls, semantic labels and keyboard/mobile/RTL/scoped axe coverage without forced animation. |
| 112 | SETTINGS DEFAULTS | AI/semantic/reranking/automatic generation/pending preparation off by default, with separate optional service consent and typed native flags. |
| 113 | COMMERCIAL-QUALITY PRODUCT DESIGN | Clean standalone GPL plugin, documented adapters/hooks, controlled resource use, isolated dependencies and preserved ordinary bbPress workflows. |
| 114 | DOCUMENTATION | README/readme, architecture/data/security/privacy/API/extensions/admin/development/operations/assumptions/ADRs and final role/release review. |
| 115 | CI QUALITY GATES | Defined hosted minimum/current quality matrix; equivalent relevant gates executed locally. Hosted execution and full dev-registry refresh are honestly separate. |
| 116 | RELEASE CHECKLIST | Native lifecycle/migration/preserve/removal, populated content, role/privacy/theme/no-AI/configured-intercept/outage/cron/REST/export/erase/RTL/keyboard/mobile checks. |
| 117 | SELF-REVIEW PASSES | Fourteen dedicated review passes and 52 perspective-specific conclusions documented in FINAL_REVIEW with actual evidence and limits. |
| 118 | IMPLEMENTATION ORDER | Incremental foundation/domain/search/AI/community/communication/composer/optional module/hardening phases reflected by preserved checkpoints. |
| 119 | NO BIG-BANG IMPLEMENTATION | Small tested vertical slices with RED/green/refinement rather than a single unreviewed implementation dump. |
| 120 | HANDLING AMBIGUITY | Conservative documented decisions for privacy, provider credentials, bounds, optional transports/private replies and editorial publication. |
| 121 | AVOID OVERENGINEERING | Modular monolith, native primitives and explicit adapters; no hidden remote infrastructure requirement. |
| 122 | FAILURE CONDITIONS | Current source permission, raw-content transport, native ownership, human edits, core posting and graceful failure reviewed against prohibited failure conditions. |
| 123 | DEFINITION OF DONE | Thirty done criteria mapped to code/native evidence in FINAL_REVIEW; live account/hosting checks remain deployment-specific. |
| 124 | FINAL MODEL BEHAVIOR | Actual defects corrected, tests retained, safe source-first generation and native behavior preserved; local artifact and review delivered. |

## All 52 review perspectives

These are explicit review perspectives applied to the implementation, not a claim of 52 independent reviewers.

| Role | Applied review evidence |
| --- | --- |
| 1. Principal Product Manager | Six original flows, conservative defaults and complete native member/moderator journeys. |
| 2. Product Researcher | Unanswered/search/feedback observations guide explicit fallback and documentation opportunities. |
| 3. Community Platform Strategist | Useful solved knowledge, explainable expertise and opt-in communication preserve community participation. |
| 4. Forum/Discussion Systems Specialist | Native forums/topics/replies, accepted answers, duplicate composer and current bbPress status/ancestry. |
| 5. Knowledge Management Architect | Human summaries/articles, strict provenance, stale review, recommendations and editorial adapters. |
| 6. Information Architect | Search filters/context, typed relationships, source links and article revision comparison. |
| 7. Product Analytics Specialist | Numeric aggregate outcomes, duplicate deduplication and private feedback review without raw queries. |
| 8. Commercial Product Strategist | Standalone GPL package, optional modules, documented operational limits and extension contracts. |
| 9. Principal Software Architect | Modular monolith, Services/Operations boundaries and ADR decisions. |
| 10. Senior WordPress Plugin Architect | Native hooks, late boot, lifecycle/schema/health/REST, scoped dependencies and no core modification. |
| 11. bbPress Core/Integration Specialist | Native topic/reply bindings, source moves/statuses/ancestors and retained native posting. |
| 12. WordPress Multisite Specialist | Per-blog storage/flags/cron, retained-service vectors/articles/receipts and native network uninstall. |
| 13. WordPress Database Engineer | Schema-8 InnoDB indexes, unique identities, row locks/savepoints and populated migration. |
| 14. API Architect | Owned and current-authorized REST queries/actions with validation/nonces/private cache headers. |
| 15. Integration Engineer | Independent providers/vector/storage/knowledge/realtime adapters and BuddyPress ownership. |
| 16. Principal PHP Engineer | Strict typed source, valid contracts, bounded inputs and clean PHPStan/WordPressCS. |
| 17. Senior WordPress Backend Engineer | Native nonce/capability/forms/options/cron APIs and after-callback permission renewal. |
| 18. Database Performance Engineer | FULLTEXT/EXPLAIN, batched priming and measured query/resource budgets. |
| 19. Async Processing / Queue Engineer | Leased fenced jobs, revised unique keys, bounded retries, delay/coalescing and admin retry. |
| 20. Caching and Performance Engineer | Current-source hashes, site-scoped caches and schema-8 compiled vector fallback. |
| 21. AI/LLM Architect | Optional independent feature models/flags, null adapters and bounded/loggable transport. |
| 22. RAG Engineer | Accepted support reservation, actual transfer coverage, sent citations and renewed provenance. |
| 23. Information Retrieval/Search Engineer | Local/hybrid filters, ranking diagnostics, explicit spelling and bounded safe reranking. |
| 24. NLP Engineer | Normalized/redacted/clipped evidence, ambiguous spelling refusal and current cited-source checks. |
| 25. Recommendation Systems Engineer | Explainable followed/help/trending feeds with current permission and mute renewal. |
| 26. Reputation/Ranking Systems Engineer | Capped reversible credits, current authorship, distinct-source expertise and public evidence. |
| 27. AI Safety Engineer | No AI native punishment/publication, abstention, untrusted source instructions and strict outputs. |
| 28. Application Security Engineer | Native IDOR/CSRF/XSS/SSRF/current source tests, atomic writes and protected attachment bytes. |
| 29. WordPress Security Specialist | Native capabilities/nonces/prepared SQL/escaping, restricted admin views and shared authorization. |
| 30. Trust & Safety Specialist | Human case actions, advisory context, reasons/history, account/source restrictions. |
| 31. Moderation Systems Designer | Assignment/escalation/intent/action audits and explicit generated-answer feedback review. |
| 32. Privacy/Data Protection Engineer | Export/erasure/retention, site-only creator anonymization and no raw-query/model-training feedback. |
| 33. Abuse/Fraud Prevention Engineer | Unique actors/response receipts, caps/rates/replay suppression and blocked/inactive guards. |
| 34. Principal Product Designer | Shared panels/controls, clear states and preserved human drafts/content. |
| 35. UX Researcher | Keyboard journeys and explicit correction/prefill keep user decisions visible. |
| 36. Interaction Designer | Native forms, feedback/version conflicts, accessible disclosure and optional composer suggestions. |
| 37. Senior Frontend Engineer | Scoped progressive vanilla JS, reproducible assets, offline ciphertext and graceful no-JS. |
| 38. WordPress Admin UI Engineer | Eleven installed native admin sections, native settings/transfer and guided setup. |
| 39. Accessibility Specialist | Scoped WCAG 2.2 AA axe, native labels/headings/landmarks/focus and keyboard review. |
| 40. Responsive Design Specialist | Actual mobile viewport controls/screenshots and bounded readable panels. |
| 41. RTL/Internationalization Specialist | Current POT/translatable strings, logical styles, RTL viewport checks and UTC source times. |
| 42. QA Architect | Original catalog/done matrix plus repeated native/static/frontend/browser/artifact gates. |
| 43. PHP Test Automation Engineer | Meaningful native RED/green regressions and minimum/current full matrices. |
| 44. Frontend/E2E Test Engineer | Installed classic/block browser, native forms, current source revocation and scoped axe. |
| 45. Performance Test Engineer | Eight representative screen budgets plus 1,002-source lexical/vector and 201-follower fanout. |
| 46. Compatibility Engineer | WP 6.6/PHP 8.2.34 and WP 7.1/PHP 8.4.26, actual bbPress/BuddyPress and both themes. |
| 47. Reliability/Observability Engineer | Health/leases/retries/error codes, local fallback and content-preserving outage recovery. |
| 48. Build/Release Engineer | Locked 32 scoped runtime packages, licenses, source hashes, clean ZIP install and restoration. |
| 49. Technical Writer | Code-matched operator/developer/privacy/API/architecture guides and concrete final evidence. |
| 50. Senior Code Reviewer/Refactoring Specialist | Independent contract validation, atomic audit/edge/credit writes and existing behavioral regression coverage. |
| 51. Prompt Engineer | Original prompt preserved, optimized execution order/invariants/evidence and explicit optional decisions. |
| 52. Implementation Coordinator | Serialized shared fixtures, preserved distinct proof runs, byte-matched release and honest external checks. |
