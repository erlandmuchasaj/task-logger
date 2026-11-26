# Database

Loads when you work under `database`. The rule that a schema change is a new migration, and that a committed migration is never edited, is in the root `AGENTS.md` under "Schema".

- A migration is forward-only: `down()` must exist but stay empty. `make:migration` names the file, so do not rename it by hand. A data migration uses `chunkById()`, never `get()` and `foreach` on an unbounded query.
- The next two bullets keep a large table from being rebuilt. A required (NOT NULL) column is therefore added nullable, backfilled by a data migration, and enforced by the Action and a CHECK constraint. Making it NOT NULL afterwards is a rebuild: do it on a small table, or ask the owner first.
- Safe, no table rebuild: add a column at the end (nullable, no default), add, drop or rename an index, add or drop a foreign key, rename a column or table, change a default, extend a VARCHAR within its byte threshold, add an ENUM value at the end.
- Forbidden, because it rebuilds the table: change a column type, drop a column, reorder columns, change nullability, drop the primary key. Use several PRs instead: dual-write, backfill, cutover.
- Caution: `->after()` prevents an instant change, and a non-null default may need a table scan.
