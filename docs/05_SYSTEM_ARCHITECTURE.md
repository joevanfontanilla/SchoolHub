# System Architecture

## High-Level Architecture

Browser → Web Server/Apache → PHP Application → MySQL/MariaDB

## Suggested Application Layers
- `public/` - public entry point and static assets
- `app/Controllers/` - request handling
- `app/Services/` - business logic
- `app/Models/` - data models
- `app/Middleware/` - authentication/authorization/security
- `app/Repositories/` - database access where useful
- `app/Views/` - UI templates
- `config/` - configuration
- `routes/` - route definitions
- `storage/` - protected uploads, logs, backups
- `database/` - schema/migrations/seed planning
- `tests/` - automated/manual test support

## Design Principles
- Separation of concerns
- Reusability
- Least privilege
- Server-side validation
- Consistent error handling
- Responsive UI
- Secure file handling
