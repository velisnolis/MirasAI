# Router and layout validation — 2026-11-17

The router now requires an explicit destination for writes when more than one
site is configured, including write previews and batches containing writes.
Tools without authoritative read-only metadata are treated conservatively.
Style results retain host evidence and distinguish saved changes from failed
verification. Transport failures after dispatch report an unknown write outcome;
sequential batches retain earlier results and warn against replaying all calls.

Both CMS hosts support bounded flat element reads with subtree, relative depth
and ETag-bound cursors. Render diagnostics report disabled ancestors, missing
context, observed instance counts and properties empty in all rendered instances.
A missing transformed element is not proof of a zero-row source query.

## Validation

- Full `npm test` suite passed, including PHP syntax checks and 106 router tests.
- Local coverage includes 50 read-mode checks, 54 render checks and 139 Joomla
  schema checks.
- WordPress laboratory: WordPress 7.1.4, YOOtheme 5.0.53. Twelve live checks
  passed against a draft fixture, including real dynamic content, a missing
  featured image, disabled bindings and cursor invalidation after a guarded
  layout edit. The MCP REST controller also returned a valid paginated result
  through an authenticated in-process REST request.
- Joomla laboratory: Joomla 6.2.0, YOOtheme 5.0.56, PHP 8.4.27. Twelve live checks
  passed against an unpublished fixture. The real article source produced two
  instances of the same bound element. Pagination, disabled ancestors and cursor
  invalidation after a guarded edit passed; final system diagnosis returned ok.
- The three changed host PHP files were backed up before deployment. Remote
  hashes matched the checkout and account ownership and permissions were checked.

These checks used real CMS and YOOtheme runtimes through account-level CLI
execution. They do not prove external HTTP authentication or browser appearance.
Missing-context classification remains covered by local fixtures. Laboratory
content remains unpublished for inspection; this is not a public release.
