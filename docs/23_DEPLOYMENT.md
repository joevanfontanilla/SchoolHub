# Deployment Plan

## Local Development
Recommended:
- Windows
- XAMPP
- Apache
- MySQL/MariaDB
- Browser
- Git
- Optional WSL2 Ubuntu environment

## Production Requirements
- PHP-supported web server
- MySQL/MariaDB
- HTTPS
- Secure environment/configuration
- Protected storage directories
- Scheduled backups
- Monitoring/logging

## Deployment Checklist
1. Configure environment.
2. Create database.
3. Apply database schema.
4. Configure application secrets.
5. Configure file storage.
6. Configure HTTPS.
7. Create initial Super Admin securely.
8. Test authentication.
9. Test role permissions.
10. Test enrollment workflow.
11. Test finance workflow.
12. Test backups.
13. Review logs.
14. Perform acceptance testing.

## Security Rule
Never deploy with development passwords, exposed database credentials, debug output, or publicly accessible backups.
