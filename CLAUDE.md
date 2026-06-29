## graphify

This project has a knowledge graph at graphify-out/ with god nodes, community structure, and cross-file relationships.

Rules:
- For codebase questions, first run `graphify query "<question>"` when graphify-out/graph.json exists. Use `graphify path "<A>" "<B>"` for relationships and `graphify explain "<concept>"` for focused concepts. These return a scoped subgraph, usually much smaller than GRAPH_REPORT.md or raw grep output.
- If graphify-out/wiki/index.md exists, use it for broad navigation instead of raw source browsing.
- Read graphify-out/GRAPH_REPORT.md only for broad architecture review or when query/path/explain do not surface enough context.
- After modifying code, run `graphify update .` to keep the graph current (AST-only, no API cost).

## OMS Rules

- If unclear, STOP and ASK. Do not guess.
- Use Graphify when available before broad source browsing.
- Do not read .env or sensitive storage files.
- Do not edit financial logic without an audit first.
- Every financial operation must remain balanced double-entry accounting.
- Use DB::transaction() for financial create/edit/delete.
- Do not mix currencies in reports.
- Respect SoftDeletes.
- After code changes, summarize changed files and verification steps.
