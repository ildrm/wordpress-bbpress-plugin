# ADR 0003: Bounded asynchronous work and request caches

Status: accepted.

Use a plugin-owned leased queue with stable keys, revisions, exponential transport retry and safe dead-letter codes. Batch source rebuilds/fanout and process push devices individually. UTC daily quota deferrals preserve retries.

Current permissions are too context-sensitive for shared rendered-answer caches. Cache only request-scoped data with blog identity and reauthorize before output. Persist source hashes and provenance; never use stale vector/source evidence.

The portable backend is bounded rather than an exhaustive large-scale ANN implementation. A production-scale vector adapter must supply its own benchmark and preserve permission invariants.
