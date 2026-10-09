# Security

## Authorization

Access is rechecked for every source read, snippet, citation, relationship endpoint, attachment download, notification, messaging membership and visible reputation event. Forum ancestors, passwords and group membership can restrict descendants. Inaccessible objects return a generic unavailable response. Permission filters only further restrict the bundled decision.

REST cookie authentication requires a WordPress REST nonce. Native forms verify a purpose-specific nonce then invoke the same capability-checked operations. Admin configuration requires manage_options; moderation requires moderate and current object management rights. Conversation membership, ownership and blocking are distinct checks. Moderator status does not permit reading arbitrary private messages; only selected reported evidence is exposed.

Shared page/CDN caches must bypass logged-in and intelligence/forum routes. Derived request caches never bypass current authorization. Vectors include source/metadata/visibility hashes and are excluded after permission or source changes.

## Injection and external requests

HTML uses context-specific escaping; generated/provider content is plain untrusted text. Knowledge article rendering uses WordPress HTML sanitization. Prepared values and identifier allowlists guard SQL. REST/action payloads normally cap at 16 KB; authenticated composer preparation permits 192 KB for bounded Unicode HTML, and administrator configuration import/contextual-template updates permit 1 MB before domain validation. Poll choices, fields, messages and source sets have independent bounds. Report reasons and state commands are allowlisted.

External AI requires server-side constants and HTTPS, forbids credentials in endpoint URLs, uses wp_safe_remote_post, no redirects, 15-second timeouts and a 256 KB response cap. Evidence has a maximum of twelve sources and 3,000 characters each. Questions and evidence undergo best-effort email/credential redaction. No tools or remote actions are available to generated text.

Push endpoints are restricted to known browser vendor HTTPS services, validated against unsafe destinations; Guzzle redirects are disabled. Subscription keys have expected decoded lengths; database credentials are authenticated-encrypted. Payloads contain a generic update, never source titles, excerpts or links.

## Files

Uploads require participation and upload_files capability, genuine HTTP upload origin, an allowed extension matching finfo MIME, a 5 MB cap and at most five files per post. A trusted scanner hook can reject content. The small executable-pattern check supplements MIME checks; it is not a malware scanner.

Protected storage must resolve outside all known served roots and require explicit operator confirmation. Paths use random fixed-format keys and traversal checks. The download controller rechecks current source access and sends attachment/no-store/nosniff headers. Public media remains publicly addressable regardless of controller checks. A move to a restricted forum cannot retroactively protect public bytes.

## Reporting and review

Reports never deduct reputation automatically. Moderation logs intent before native state changes and checks the resulting state before marking cases actioned. AI is never authorized to punish, change permissions or publish.

Run the adversarial tests and current dependency audits before packaging. Locally downloaded official FriendsOfPHP advisories were checked against locked versions; this complements Composer audit, not a guarantee against undisclosed vulnerabilities. Do not publicly attach API keys, message contents or private logs to issue reports.

Successful native configuration additions/updates produce audit metadata through idempotent option hooks. Settings sanitization produces no audit event before persistence. Known changed field names and definition counts are retained; old/new values, unknown keys, credentials, template bodies and field descriptions are excluded. Reviewed imports audit inside their transaction; authorized configuration exports audit before returning. Denied exports and rejected imports create no success event. External server configuration/secret-file changes require the operator's own audit process.

## Encrypted composer copies

The browser uses native AES-GCM-256 with a fresh random 96-bit IV per save, 128-bit authentication tag, a random 128-bit salt and nonextractable keys derived with PBKDF2-HMAC-SHA256 at 600,000 iterations. The fixed derivation cost follows the [OWASP PBKDF2 guidance](https://cheatsheetseries.owasp.org/cheatsheets/Password_Storage_Cheat_Sheet.html#pbkdf2); primitives follow the [W3C Web Cryptography API](https://www.w3.org/TR/webcrypto/). Envelope shape, encoded sizes and exact algorithm/version/iterations are checked before derivation. Origin/blog/owner/type/source and envelope metadata are authenticated as additional data, preventing ciphertext transplantation and expiry/header edits from restoring plaintext. Random bytes come from crypto.getRandomValues. No custom crypto implementation or remote crypto service is used.

Native posting never depends on this option. Enabling and recovering require online current identity/source/posting authorization; decrypted HTML goes through wp_kses_post before editor insertion. Passphrases have no form name, never enter storage/network keys or requests, and are cleared from the field. Imported byte buffers are cleared, and lock/source/submit/hidden/pagehide events release key references; JavaScript strings and garbage collection cannot guarantee immediate memory erasure. Web Locks serialize cooperating tabs' compare-and-write operations. Stale copies fail rather than overwrite a newer record. A storage event locks another open panel. Same-origin hostile scripts, browser extensions, passphrase guessing and a manipulated device clock are outside these guarantees. No private-response cache or authenticated offline navigation is introduced. Recovery expiry is not a secure deletion mechanism; users explicitly remove local ciphertext. Server privacy tools cannot erase it remotely.

Composer preview uses the same live identity/site/source/posting checks and Unicode bounds as recovery, with a separate 20/minute quota. Its formatting allowlist removes all attributes and active links/media/embeds/forms; the client adds an empty sandbox, no-referrer policy and CSP denying default resources, base URLs and forms while allowing only fixed inline styling. The frame's title and language are set, its source is removed on invalidation, and results after edits/source changes are discarded. Server preparation makes no content record/event/provider job. Preview and offline recovery preparation are REST-only; the native redirect adapter rejects them before dispatch so its generic result transient cannot copy returned plaintext. This protects the bundled paths; trusted host scripts/plugins and external request logging remain under operator control.


## Delayed Ask fallback evidence

Both provider failure/abstention responses and owner-scoped native result transients reauthorize each source and rebuild titles, snippets, links, solution state and primary-answer previews from current WordPress content. A fresh solution reader avoids state cached across provider work. Search snapshots carry an HMAC-SHA256 proof bound to site, reader, source/primary IDs and current source fingerprints, keyed with the native nonce salt. Raw restricted-content fingerprints are not exposed. The proof preserves unchanged semantic admission; missing, owner-mismatched or changed proofs require current local exact-source matching, and unmatched candidates disappear. It is not an authorization credential: native read checks still run. Refresh is bounded to 30 unique native topic/reply candidates and records no new search/Ask metric or provider request. The original fallback question comes from the existing composer URL; no second question log is added.

## Contextual question guidance

Administrators configure at most 20 plain-text templates with native forum scopes and bounded labels/text/criteria. Forum 0 deliberately applies to every currently readable forum; administrators must treat that guidance as public. Each catalog read rechecks current native access and excludes other scoped forums. Selection does not modify topic classification, persist a user preference, or invoke a provider. Explicit prefill rereads the active initialized visual/text editor and preserves occupied content. Source changes clear stale catalog/options before loading new guidance, and late responses are discarded using source/owner/blog and request-generation checks. Native no-JavaScript guidance is escaped, and controls submit no template selection or additional required field.

## Ranking diagnostics

The explain flag adds diagnostics only for manage_options readers, with an independent capability check at native HTML rendering. Default/guest/participant/moderator-only results omit diagnostics, including explicit URL/REST requests. Metadata contains bounded numeric inputs/contributions and a fixed retrieval label, never raw query text, matched private fields, credentials or provider internals. Current access/source filters still run before visible results. The hybrid consumer rejects nonnumeric/nonfinite/out-of-range similarities and bounds adapter examination to 50 entries. Votes use a single site-prefixed query for bounded IDs and do not create a cross-source aggregate. Relevance scores are not probabilities and do not replace accepted-primary and coverage grounding gates.

## Answer grounding at the transfer boundary

The ask service grades only current public, readable evidence after shared email/credential redaction and the 3,000-character limit. Public search-only metadata, private local matches, clipped text and redaction markers cannot supply missing coverage. At least one qualifying source and its current accepted primary must fit in the twelve-source transfer; that pair is reserved before remaining evidence. After generation, both qualifying hashes, current access/public eligibility and the current accepted-primary selection are checked even if the provider cites only the topic. Each returned citation must still belong to the sent set with its unchanged hash. Local authorized fallback can retain readable restricted topics without sending their text to an adapter. These gates verify source eligibility and bounded lexical grounding; these gates do not prove the factual correctness of arbitrary provider prose. Operators should evaluate their selected provider against domain-specific examples before enabling external generation.

Generated response grounding is also signed for the original reader/site and bound to the original qualifying hashes, sanitized answer and citation array. Native delayed display validates current public/readable sources and current accepted-primary selection, even when the provider did not cite that reply. A changed source, withdrawn primary, copied receipt, altered answer/link or missing legacy proof suppresses the saved response and shows a generic request to ask again. Read-only validation invokes no provider, analytics or mutation. Five-minute transient expiry remains the storage limit.
