# API Design Specification

## General
APIs are optional implementation details but should follow consistent REST-like conventions if used.

## Authentication
- POST `/api/auth/login`
- POST `/api/auth/logout`
- POST `/api/auth/change-password`

## Student
- GET `/api/students/me`
- PUT `/api/students/me`
- GET `/api/students/me/enrollment`
- GET `/api/students/me/fees`
- GET `/api/students/me/grades`

## Enrollment
- POST `/api/enrollment/applications`
- GET `/api/enrollment/applications/{id}`
- POST `/api/enrollment/applications/{id}/submit`
- POST `/api/enrollment/applications/{id}/correction`

## Registrar
- GET `/api/registrar/applications`
- POST `/api/registrar/applications/{id}/approve`
- POST `/api/registrar/applications/{id}/reject`
- POST `/api/registrar/applications/{id}/request-correction`

## Finance
- GET `/api/finance/students/{id}/account`
- POST `/api/finance/payments`
- GET `/api/finance/payments/{id}`

## Academic
- GET `/api/teacher/classes`
- POST `/api/teacher/classes/{id}/attendance`
- POST `/api/teacher/classes/{id}/grades`

## API Rules
- Authenticate protected requests.
- Authorize by role and ownership.
- Validate request data.
- Return consistent success/error structures.
- Do not expose internal exceptions or secrets.

## Authentication Model
The API is **session + CSRF authenticated**, not token-based. It runs through the same
session middleware as the browser application and is same-origin — it exists to serve
the application's own pages, not third-party clients.

- `POST /api/auth/login` establishes a **session**; it does not return a token.
- Every state-changing request must carry a valid CSRF token.
- There are no API keys or bearer tokens in v1.0.

## Response Envelope
All responses use a consistent structure:

```json
{ "success": true,  "data": {} }
{ "success": false, "message": "Validation failed", "errors": { "field": ["reason"] } }
```

Error responses never expose exception messages, stack traces, SQL, or file paths.

## Conventions
- Version prefix: `/api/v1/...`.
- List endpoints accept `page`, `per_page`, `search`, and `sort`, and return
  `meta: { page, per_page, total }`.
- Rate limiting applies to authentication endpoints, matching the web login limits.
