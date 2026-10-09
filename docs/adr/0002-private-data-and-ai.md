# ADR 0002: External AI and derived evidence

Status: accepted.

External AI is optional and opt-in. The bundled provider receives bounded redacted questions and public evidence, plus explicitly AI-enabled public fields. Restricted/password/BuddyPress group sources never enter external indexing or summaries.

Grounded output requires cited IDs among actually sent sources, current source hashes and current authorization. Provider text is untrusted plain text. Weak evidence, outage or changed sources produces abstention with search links. Human-edited summaries lock against automatic overwrite; knowledge generation creates drafts only.

This policy trades recall for privacy and gives the site a usable local-search baseline.
