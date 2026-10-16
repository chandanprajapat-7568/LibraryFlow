# LibraryFlow

LibraryFlow is a PHP and MariaDB library operations application for managing a book catalogue, members, loans, returns and inventory reports. Staff sign in with an administrator or librarian account.

The complete project report is available as [Word](docs/LibraryFlow_ERP_Project_Report.docx) and [Markdown](docs/PROJECT_REPORT.md).

## Features

- Search, create and edit books and member records.
- Issue and return books with server-side availability checks and transaction locking.
- View current inventory, open loans and overdue records.
- Protect write requests with CSRF tokens and use prepared SQL statements.
- Require staff sign-in; staff can change their passwords, and administrators can reset librarian passwords, deactivate or reactivate librarian access, and permanently remove librarian accounts. Administrator accounts are protected from staff-management actions.
- Let staff show or hide password entries and request a one-use password reset link by email. Reset links expire after one hour, are stored as hashes, and revoke existing sessions after a password change. Administrators can still set a librarian password if email recovery is unavailable.
- Require new passwords to be at least 8 characters and include a lowercase letter, an uppercase letter and a special character. A late return starts a 10-day borrowing hold after the book is returned; an outstanding overdue loan also blocks new issues.
- Restrict destructive book/member deletes to administrators.
- Keep real student roster data out of the public repository and HTTP-accessible database files.

## Requirements

- PHP 8 or later with mysqli.
- MySQL or MariaDB with InnoDB.
- Apache with .htaccess overrides enabled, or equivalent server rules for the protected folders.
- A current browser.

## Local XAMPP setup

1. Copy the project to htdocs/libraryflow.
2. Start Apache and MySQL in XAMPP.
3. In phpMyAdmin, import database/libraryflow.sql, then database/libraryflow_demo_data.sql for the catalogue.
4. The public repository does not include real student roster data. If your institution separately supplies an approved local import, keep it on the trusted computer and outside Git; do not publish it or include real student details in screenshots.
5. If the local database settings differ, copy config/local.php.example to config/local.php and set the local credentials. config/local.php is ignored by Git. The default root with an empty password is for local XAMPP development only.
6. From the same computer, open http://localhost/libraryflow/setup.php and create the first administrator. The one-time setup is limited to localhost and is disabled when APP_ENV=production.
7. Sign in at http://localhost/libraryflow/login.php. An administrator can add and manage librarian accounts under Staff access.

For self-service password recovery, set `public_url` and `mail_from` in `config/local.php` and configure PHP's mail transport in `php.ini` or through the hosting provider. `public_url` is the full application base URL, such as `http://localhost/libraryflow` on local XAMPP or the HTTPS site URL in production. Local XAMPP does not include a configured mail service by default; set up an approved SMTP relay before expecting reset messages. PHP's `mail()` must accept the message for a link to remain usable. If mail is not configured or sending fails, the generic recovery page directs staff to an administrator, who can set a librarian password. Apply `database/migrations/20261016_password_reset.sql` to an existing database; a fresh import of `database/libraryflow.sql` already includes the recovery tables.

For a project installed in another folder under htdocs, the application derives its base URL from the Apache document root.

## Database and sample data

database/libraryflow.sql creates the catalogue, member, issue, staff-user and login-throttling tables. It does not insert staff passwords. database/libraryflow_demo_data.sql contains Project Gutenberg catalogue metadata only and records its source. The local roster seed contains names and roll numbers from the supplied college list and is ignored by Git.

Do not add real student contact data, borrowing history, database exports or backups to a public repository. Use only synthetic records for public screenshots. If sensitive information has already been committed, deleting the file in a later commit does not remove it from earlier Git history; follow GitHub's sensitive-data removal guidance.

## Roles

- Administrator: all library operations, deletes, and staff account/role management.
- Librarian: catalogue, member, issue, return, search and report operations; cannot delete records or manage staff access.

Passwords are hashed with PHP's password hashing API. Failed sign-in attempts and password reset requests are temporarily rate-limited by hashed email/IP keys. Reset tokens are random, one-use, expire after one hour, and only their SHA-256 hashes are stored. Sessions use HTTP-only, SameSite cookies, secure cookies when HTTPS is detected, expire after 30 minutes of inactivity, and rotate after sign-in and password changes. Changing or resetting a password invalidates other active sessions for that account.

## Configuration for an internet-facing deployment

XAMPP is a local development setup, not a public production host. Before allowing public or institutional access:

- Use a maintained production server with HTTPS and a database user limited to this application's database. Set APP_ENV=production, LIBRARYFLOW_DB_HOST, LIBRARYFLOW_DB_NAME, LIBRARYFLOW_DB_USER and LIBRARYFLOW_DB_PASSWORD; never use MySQL root.
- Create the first administrator while the server is restricted to localhost or an authenticated private tunnel, then switch to production mode before public access.
- Confirm the host enforces the repository's .htaccess protections or configure equivalent server rules. The database, config, includes, docs and tmp folders must not be downloadable.
- Keep real student data on an institution-approved system. Complete and approve the deployment-specific privacy notice and terms with the responsible institution's name, contact, purpose, retention, backup and support details.
- Configure encrypted backups, test restoration, restrict server/database administration, keep dependencies and PHP/MariaDB patched, and review logs so they do not contain student data or passwords.
- Run the application's acceptance and security checks in a staging copy with synthetic records before importing real data.

The privacy and terms pages describe the application but still require institution-specific review and completion. Their presence alone does not make a deployment production-ready.

## Checks

Run the HTTP smoke suite only against an isolated database whose name starts with libraryflow_test_. Start the PHP development server on loopback with APP_ENV=test and LIBRARYFLOW_DB_NAME=libraryflow_test_quality, then run tests/http-smoke.ps1 from a second PowerShell window. APP_ENV=test refuses to connect to any database that does not use that prefix. The suite creates synthetic users, books, members and loans; review and remove its test database after the run. Never point it at the live roster.

The latest isolated HTTP run completed 103 checks. It covers authentication, sign-in throttling, session rotation and invalidation, protected administrator accounts, administrator and self-service password resets, reset-token revocation/reuse protections, password visibility controls, deactivation/permanent removal, password rules, CSRF, validation, CRUD, late-return borrowing holds, search, reporting, totals reconciliation and HTML escaping. Separate checks confirmed migration idempotency, hashed token storage and expiry rejection. Apache-specific access denial for database/config/docs files and backup restoration must also be checked on the target server.
