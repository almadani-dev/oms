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

## Project Memory Rules

After every approved change:
1. Update docs/AI_PROJECT_MEMORY.md with what changed, why it changed, and any important context.
2. Update docs/TASKS_LOG.md with the task result, changed files, verification steps, and commit hash if available.
3. Update docs/DECISIONS_LOG.md if a decision was made.
4. Update docs/PROMPTS_LOG.md if an important prompt was used.
5. Update docs/NEXT_STEPS.md with the next recommended step.
6. Do not document guessed information.
7. Do not document sensitive data.
8. Do not read or summarize .env, storage/app/public, uploaded files, payment proofs, receipt proofs, or images.
9. Show the documentation diff before commit.

Workflow:
Plan -> approval -> edit -> verification -> update memory docs -> show diff -> commit.
