# Development patterns

This project already has shared helpers for common backend flows. Prefer extending these pieces instead of creating parallel implementations.

## JSON API endpoints

Controllers extending `AppController` should use:

- `requireJsonPost()` for POST-only JSON endpoints.
- `jsonResponse($payload, $status)` for successful JSON output.
- `jsonError($message, $status)` for JSON errors.

This keeps method checks, content type headers, status codes, JSON encoding, and `exit()` behavior consistent.

## Character field image uploads

Template-based character field uploads are handled by `CharacterFieldUploadService`.

Use it for:

- main character image upload/selection,
- image fields,
- image gallery fields,
- image cells inside table fields,
- variant image fields,
- variant gallery fields.

The service keeps the existing form field names and JSON value shape in one place, and delegates actual file validation/storage to `ImageUploadService`.

Do not reimplement nested `$_FILES` parsing in controllers unless a new upload flow cannot fit this service.
