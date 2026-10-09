# ADR 0001: Native content ownership and a modular monolith

Status: accepted.

bbPress posts and native capabilities remain authoritative. Intelligence interactions use plugin-owned per-blog InnoDB tables and services behind contracts. This makes local features available without SaaS and avoids changing core or creating divergent forum content. Costs are explicit schema migrations and adapter/permission review.

Authorization is centralized and rechecked at every derived output. A vector hit, cached aggregate, notification or source citation never grants access. Trusted filters may restrict access, not widen a failed native check.
