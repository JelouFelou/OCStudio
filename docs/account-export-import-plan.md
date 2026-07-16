# Account export/import plan

## Export

User export is a ZIP archive with:

- `manifest.json` with format version, timestamp and source user id.
- `data.sql` with rows owned by the user and directly dependent rows.
- `uploads/` with image files registered in `image_assets` for that user.

The archive is private and may contain hidden or adult content.

## Import modes

Planned restore modes:

- Replace current state: remove current user-owned content, then import archive content into the current account.
- Fill missing data: import only objects that do not already exist by stable ids/public ids/names where safe.
- Copy beside current data: import everything as duplicated content with new ids/public ids, useful for testing or merging accounts.

## Image deduplication

Images must be deduplicated before writing files:

- Use `sha256` from `image_assets` as the primary match.
- If a matching hash exists for the target account, reuse that file and remap references.
- If the hash is new, copy the file once and create a new `image_assets` row.
- Never create duplicate upload files for the same user and same hash.

## Id mapping

Import cannot directly trust ids from `data.sql` when restoring into another account.
It needs a mapping layer for:

- worlds and story folders
- templates and template fields
- characters and variants
- stories and story fields
- relations and relation boards
- image assets

All imported rows must be rewritten to the current user id.
