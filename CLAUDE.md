# CLAUDE.md

@AGENTS.md

## Notes for Claude Code sessions

- Start every session by reading `progress.md` and `lesson.md`; they are the memory of this project across sessions.
- The orchestrating session delegates work to sub-agents in parallel waves over disjoint directories and picks the model by difficulty (Opus for transport/outcome/ledger/campaign logic, Sonnet for builders, providers and the Laravel layer, Haiku for catalogs and boilerplate). Paste the "Before you start", "When you finish", "Hard rules" and "Testing style" sections of `AGENTS.md` into every sub-agent brief, or tell the sub-agent to read `AGENTS.md` first.
- After each wave: integrate, run `composer check`, update the status table in `progress.md`, then launch the next wave.
- Composer must run with `COMPOSER_ALLOW_SUPERUSER=1` in the cloud container.
