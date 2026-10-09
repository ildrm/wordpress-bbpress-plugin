# Operations

Use a real scheduler on busy sites. Set DISABLE_WP_CRON and invoke due WordPress cron events every minute per active blog, or run `wp forum-intelligence queue run --limit=20`. Keep WordPress native scheduled publication events running as well. Multisite schedulers must enumerate each site with its own --url context.

| Command | Effect |
| --- | --- |
| `wp forum-intelligence health` | JSON schema/dependencies/jobs/cron status |
| `wp forum-intelligence reindex` | Queue a bounded keyset rebuild |
| `wp forum-intelligence queue run --limit=20` | Process a bounded leased batch |
| `wp forum-intelligence queue retry JOB_ID --user=ADMIN_LOGIN` | Retry a failed job after fixing its cause |
| `wp forum-intelligence config export --user=ADMIN_LOGIN` | JSON of known settings, fields and templates; secrets omitted |
| `wp forum-intelligence config import FILE --reviewed --user=ADMIN_LOGIN` | Import reviewed version 1 configuration, max 1 MB |
| `wp forum-intelligence privacy retain` | Process one retention batch |
| `wp forum-intelligence reputation view MEMBER_ID` | Authorized CLI-context contribution view |
| `wp forum-intelligence reputation rebuild --user=ADMIN_LOGIN` | Queue current source-award reconciliation; manage_options required |

A rebuild processes sources in batches of fifty. Source jobs coalesce and use content/metadata/visibility/model hashes. The local FULLTEXT path retrieves at most 500 candidates; query fallback is bounded. Search exposes authorized page results and has_more, not an inaccessible result total. Portable vector retrieval scans at most 1,000 candidates in fifty-row keyset pages. Schema 8 keeps canonical JSON and adds a disposable compiled binary cache; current native/model/access checks still run. Existing installations convert eligible cached JSON in background vector_encode jobs without provider calls or re-embedding. Missing/damaged frames remain readable through validated JSON, with no retrieval writes. Check queue health/retry controls if upgrade jobs fail. This bounded adapter is not an approximate-nearest-neighbor engine for millions of posts.

Watch queue age, dead/retrying counts, provider failures, token aggregates and schema status. Raw request bodies, API keys and private questions are not logged by the plugin. Token counts are approximate provider usage, not billing invoices. Dead job error codes are safe classifications, not exception text. Retry after fixing credentials, quota, storage or provider compatibility.

Reputation profiles aggregate all historical ledger points per source, then examine up to 1,000 source posts with current visibility. This avoids truncating reversals from long histories but remains a bounded view for members with more than 1,000 source posts. Rebuild reconciles current weights, authorship and accepted-solution/confirmation awards; daily positive-award caps still apply. Trending/feed candidate windows are bounded and cannot represent exhaustive whole-forum personalized ranking. Benchmark representative authorized and restricted data before promising site-specific capacity.

Run `composer audit --locked` and `npm audit` in normal networked CI. The development environment's Composer registry access required verified immutable GitHub distribution URLs; TLS checks were never disabled. The lockfile uses official sources and normal production Composer configuration.

Back up custom and native tables, server constants, salts and attachment directories. To recover: restore native content/permissions first, restore plugin state, restore protected storage, check schema/health, reindex and then run due jobs. Do not serve cached derived data during permission migration. A salt change intentionally requires new push opt-in.

Inspect PHP/web logs and Site Health after upgrades. Validate a staging copy with both supported theme families and role matrix. No live remote AI, real-browser push delivery or production mail account has been verified in this workspace.


Native indexing reacts to metadata addition/update/removal and real forum content/access/hierarchy changes. Known forum aggregate counters and last-activity pointers do not initiate a full rebuild. Unknown extension metadata under the bbPress/FI prefixes retains invalidation. If a queue is delayed, inspect kind/status/available_at/error_code and active leases before retrying; repeated rebuild requests can add workload. Existing deployments retain queued work on upgrade. The disposable release fixture's transactional scan coalescing is test recovery evidence, not an automatic production queue reset. Use the normal native worker and reviewed index rebuild controls for production operation.


The actual 1,002-topic/4,096-component checkpoint84 workload reduced cold queries from 1,999 to 63 and process peak from 226 MiB to 60 MiB, but repeated latency remained approximately 12–14 seconds. The same-corpus schema 8 cache comparison measured JSON fallback at 4.13–4.25 seconds / 60 MiB versus compiled frames at 2.59–2.79 seconds / 64 MiB, with the same 63 cold queries. A compiled-only repeat also passed; an overlapping conversion run was excluded. Query preparation is shared in this comparison, isolating the disposable frame cache. Cross-checkpoint 84/85 corpora use different native IDs, so those older timings are not a matched comparison. These maximum-dimension fixtures use short source bodies; production capacity depends on native content, hierarchy, extensions, PHP and hardware.
