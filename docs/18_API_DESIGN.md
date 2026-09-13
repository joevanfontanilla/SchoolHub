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
