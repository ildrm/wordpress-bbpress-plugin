# REST API

Namespace: /wp-json/forum-intelligence/v1 (or the equivalent rest_route URL on plain permalinks). Successful responses use private, no-store headers. WordPress applies authentication before permission callbacks; every domain call additionally authorizes ownership/capability/current source. Cookie mutations need X-WP-Nonce. Application passwords use native WordPress HTTPS authentication.

## Read routes

| Route | Main parameters | Access |
| --- | --- | --- |
| GET /solutions/{id} | native topic ID | Current topic access |
| GET /search | q, forum, type, tag, author, after, before, solved, unanswered, fields, min_votes, sort, page, per_page, explain (boolean) | Permission-filtered results; explanations require manage_options |
| POST /duplicates | title, body, forum, tags, fields | Local permission-filtered suggestions; max 16 KB |
| GET /solution_history | object, before | Current topic and each referenced answer access |
| GET /draft | object, type: topic/reply | Own draft plus current forum/topic access |
| GET /template | object (forum ID), topic_type, product, category (strings ≤80 characters) | Current forum access; authorized catalog only |
| GET /saved_searches, /saved_search_feed | none | Own local queries and current source permissions |
| GET /moderation_advisory | case | Moderator plus current source access |
| GET /signals | object | Current source access |
| GET /summary, /relationships, /fields, /attachments | object | Current source plus endpoint policy |
| GET /badges | member, history | Public current achievements; history restricted to owner/moderator |
| GET /reputation | member | Current-source visibility; public-only for others |
| GET /notifications | page, unread (boolean), state: all/read/unread, kind (known category) | Own currently authorized, unmuted inbox; 50 candidates per page |
| GET /mentions | q (2–30 ASCII username characters), object (native forum/topic/reply ID) | Active participant, current source access; fallback recipients with current read permission |
| GET /notification_summary | none | Own current unread count/lower bound; no raw totals |
| GET /preferences, /feed | page, mode | Own data |
| GET /poll | object | Current topic; result visibility policy |
| GET /conversations | page | Own memberships |
| GET /messages, /chat | conversation, after | Current participation and membership |
| GET /discovery | mode, page | Current-access filtered candidates |
| GET /experts | tag | Public authorized contribution evidence |

Search page is 1–25 and per_page 1–30. Search items carry an opaque source_proof scoped to the current site and reader. Clients should treat it as an internal snapshot marker, not an access credential. Delayed Ask fallback results reconstruct display fields from current native content and solution visibility. Unchanged semantic candidates may be retained; changed or legacy candidates must still match through local exact-source retrieval, without a new provider request or search metric. Current results do not expose unauthorized totals. Modules conditionally register their routes.

## Mutations

POST /actions/OPERATION with JSON {"data":{...}}. Data is capped at 16 KB; draft, offline_restore and composer_preview permit up to 192 KB of encoded data for authenticated users to support the 12,000-character Unicode limit, and config_import/template_variants permit up to 1 MB for manage_options users and rejects unsupported nested/type shapes before dispatch. Native HTML forms invoke the same operations. Upload uses multipart native form with a genuine PHP-uploaded file rather than JSON paths.

| Operation | Data | Policy |
| --- | --- | --- |
| solution | topic, reply, command: accept/remove/primary/solve/reopen, optional version | Topic author when configured or moderator |
| signal | object, kind: vote/confirm/reaction_*, value | Participant; self/downvote settings |
| report | object, reason, notes | Participant/rate limit |
| moderate | case, command, notes, assignee | Current native moderation rights |
| ask, ai_feedback | question / object,value,token | Participation, grounded evidence and issued owner receipt |
| ai_feedback_review | id,state,version | Current moderator/source access and version conflict check |
| knowledge | topic, command, text | Moderator; draft also editorial rights |
| relationship | source,target,type,status | Authorized endpoints; moderator approval |
| follow | target,type,mode,enabled | Own preference/current target |
| preferences, notification | email_mode,muted_categories / id,command | Own data |
| badge_review | member, badge, active, reason | Moderator; review-based badges only |
| badge_preferences | enabled | Own opt-in/erasure; never changes another member |
| config_export | none | manage_options; whitelisted settings only, no secrets |
| config_import | json, reviewed: true/1 | manage_options; explicit reviewed flag, transactional import |
| reputation_rebuild | none | manage_options; queued source reconciliation |
| moderation_advisory | case | Explicit human request; public current source only |
| saved_search, saved_search_remove | query,filters / id | Own bounded local search data |
| saved_search_alerts | id, enabled: 0/1 | Own saved-search opt-in; future public publications only |
| reputation | member,points,reason | Moderator with bounded correction |
| poll_create, poll_vote, poll_close | topic,choices,maximum,starts,ends,visibility,change / topic,choices / topic | Author/participant/moderator as appropriate |
| fields, field_config, templates, template_variants | topic,fields / json / json / json | Native edit or manage_options |
| draft | object (forum for topic/topic for reply), type, title, body, optional version | Own draft, current access and native posting capabilities |
| composer_preview | object, type, title, body, owner, blog | Signed-in current identity/site/source and native posting rights; 20/minute/account; bounded inert formatting HTML, no persistence/provider |
| offline_restore | object, type, title, body, owner, blog | Current signed-in identity/site, source access and native posting rights; 20/minute/account; sanitizes without persistence |
| draft_discard | object, type, optional version | Own draft and current source access |
| schedule | topic,date | Native topic edit/publication capabilities |
| message_start, message_send, conversation | recipient,text / conversation,text / conversation,command,message | Membership, block policy, selected-report scope |
| push_subscribe, push_unsubscribe | subscription / optional endpoint | Own device; approved endpoint |
| reindex, retry_job, retain, config_import | bounded command parameters | manage_options |
| message_report_resolve | id | Moderator-selected report review |

Common errors are 400 invalid input, 401 unauthenticated, 403 missing capability, 404 unavailable/inaccessible, 409 version/state conflict, 413 oversized payload, 429 rate limit and 503 safe service failure. Unexpected exception text is never returned. Clients should preserve typed text on errors and present abstention as a normal answer state.

Draft versions are opaque strings containing the record identity and revision. Return the last received version on save/discard; `0` means no draft existed. A stale value returns 409 `draft_conflict` and preserves the stored draft. Including record identity prevents an old tab overwriting a discarded-and-recreated draft. Legacy topic clients may use `forum` instead of `object` and omit type/version, but integrations should use the version check. Recovery returns title/body/type/version/saved_at; a missing record can produce an empty successful WordPress response. Draft content remains local and is subject to configured retention/privacy erasure.

Saved-search listing includes `alerts` (default false). Explicitly enable each owned query through `saved_search_alerts`. New public topic/reply publications, including scheduled publication, are matched locally in keyset batches of 20. Multiple matching queries deliver one `saved_search` notification per member/source. Disabling or removing a query before its batch runs prevents delivery; enabling it after an event does not backfill that event. Existing inbox history remains until deletion or retention. No saved keywords are placed in job payloads, email/push messages, aggregate search metrics or external AI requests.

Publication date filters `after` and `before` are inclusive UTC calendar days. Invalid dates and reversed ranges return 400; native forms preserve the filters and display an escaped inline error. Both FULLTEXT candidate selection and the WordPress fallback apply the date range. Saved searches preserve these filters. Advanced filters currently use local matching, so their queries do not incur an embedding request or admit unfiltered semantic candidates.

`notification_summary` returns `unread`, `complete`, and a localized `label`. A complete result is exact for the current request. It examines at most 500 owned unread candidates and stops at 100 authorized items. An incomplete positive result is explicitly labelled as a lower bound; an incomplete zero uses the generic Notifications label. Hidden/deleted sources, native Blocked accounts, current mutes, disabled optional modules and lost conversation membership are excluded. Category mutes apply to existing inbox items as well as new delivery. The endpoint is private/no-store and limited to 20 requests per member per minute; client-supplied owner IDs never change the member being queried.

## Mention fallback
When enabled BuddyPress activity mentions or a supported integration owns mentions, fallback lookup returns 409 and the native integration owns suggestions/delivery. The `fi_mentions_managed` filter supports another explicitly integrated owner. With no owner, lookup examines at most 20 current-site username-prefix matches and returns at most ten eligible members, excluding self, blocked/inactive members, inaccessible discussion recipients and current source/category mutes. No email address, role or raw total is returned. It is authenticated, private/no-store and limited to 20 lookups per member per minute.

Native topic/reply publication and edit hooks scan at most 60,000 content characters and five distinct ASCII handles (WordPress username letters/digits/underscore/period/hyphen). HTML attributes, comments, code/pre/script/style bodies, Markdown code and email-address occurrences are excluded. Up to 20 mention-bearing operations per author per five minutes are processed; quota exhaustion leaves native posting successful. An owner/source lifetime event key prevents duplicate alerts on retries or repeated edits. Delivery and later inbox/email/push retrieval recheck current visibility/preferences. Unknown handles never expose lookup details in posted content.

Discovery returns `candidate_window: 100` and `evidence_window: 2000`. Trending scores use current authorized/unmuted native reply evidence and known positive signal evidence, with one distinct participant contribution per topic. Blocked/inactive voters and inaccessible/spam sources are excluded before scoring. Evidence is bounded, so scores are comparative samples within the selected topic page rather than complete lifetime counts. No raw private reply-count metadata or unauthorized source activity contributes to a visible topic's score.

Administrator-only `setup_defaults` fills only missing server-owned settings and preserves already persisted choices, ignoring client-supplied configuration fields. `setup_index` requires `reviewed` equal to true, 1 or "1", a current environment/schema and administrator permissions, then queues the existing bounded rebuild. Both mutations use the standard authenticated nonce-protected operation transport and a five-per-300-second quota. Read-only guided setup status contains no credentials and is restricted to administrators.

The offline_restore operation never receives a passphrase or ciphertext. It validates decrypted text (200 title/12,000 body characters), checks the captured owner/blog against the current session and returns sanitized title/body/type/object/owner/blog. Clients must verify that identity/source still match before inserting into an unoccupied editor. This operation creates no draft record, content event or provider job. REST nonce/cookie authentication still applies; configuration disabling Composer removes the operation. Browser copies are optional; native drafts/posting work without the module.

composer_preview returns sanitized title/body/html/type/object/owner/blog. Its html field permits basic formatting tags with no attributes, links, media, embeds or forms; clients render it in an inert sandbox with restrictive CSP and verify unchanged identity/source/editor content before display. Preview is not an exact reproduction of third-party render filters or theme styling. Both composer_preview and offline_restore use REST only: the native admin-post redirect adapter rejects them before dispatch to prevent returned text entering its five-minute result transients. Native draft save/recover/discard and ordinary posting remain supported.


Contextual template configuration is a JSON list of at most 20 rules: id (unique lowercase identifier, 2–48 characters), label (≤100), forum (integer 0 or an existing readable native forum), topic_type/product/category (optional plain strings ≤80 each), text (nonempty plain guidance ≤5,000). Forum 0 applies globally to readable forums. Every nonempty criterion must equal the requested choice; precedence is number of criteria, then forum-specific scope, then alphabetical id. Without a matching contextual rule, the legacy per-forum template is returned. The read response includes object/owner/blog, selected id/label/text, sorted authorized options/selection and the applicable plain catalog. It makes no provider request, metric or content record. Read access is rechecked even for public callers. Version 1 reviewed configuration transfer includes optional template_variants; invalid entries roll back all imported options/audit writes.

Opt-in search explanations: explain=true adds a ranking object only for manage_options readers. It contains retrieval (fulltext/native/semantic), normalized coverage/similarity, current solved status/net votes, four numeric additive contributions and their total score. Default, anonymous, participant and moderator-only responses omit it, even if requested. Scores are relevance weights rather than probabilities. Solved status includes manual resolution; an accepted primary and strong coverage are still independently required for grounded-answer sufficiency. Explanations add no records/jobs/audit/query text or extra provider call, and each ordinary search contributes only its existing aggregate metric.

Successful ask results also include grounding: {source, primary, proof}. Both IDs identify public transferred support; proof is an opaque reader/site-bound signature covering the support hashes, answer and citations. It is display-validation metadata, not another citation or a credential. Native saved-result rendering rechecks current public/readable sources, accepted-primary selection and this signature before showing a generated answer. Legacy saved generated responses without this metadata are invalidated; transient retention remains five minutes. Answer/source changes or copied/tampered responses cannot bypass that check.

### Verified response feedback

A generated `ask` result may include `feedback_token`, an owner/site-bound receipt valid for fifteen minutes. POST `actions/ai_feedback` with `data: { object: TOPIC_ID, value: CATEGORY, token: TOKEN }`. Categories are helpful, not_helpful, inaccurate, outdated and missing_context. The operation requires authenticated current participation, issued receipt, unchanged public supporting sources and current accepted primary. Source-only calls without a receipt are refused. The response is `{ saved: true }`; replaying the same category is idempotent.

POST `actions/ai_feedback_review` with `data: { id: REPORT_ID, state: STATE, version: VERSION }`. States are open, resolved and dismissed. Current moderators must retain source access. Version zero is valid; an absent or outdated version is rejected. Conflict returns 409. The review writes an audit event and changes no native discussion or generated content. The private paginated review queue is available in administration; it is not exposed as a public report feed. Both actions retain native nonce authentication and private/no-store response headers.

Zero-result search may include `suggested_query` for a bounded local spelling aid. This is an explicit alternative, not substituted result evidence. The original query and ordinary result metadata remain unchanged.
