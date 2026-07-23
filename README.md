# Library Desk

Library Desk is a Laravel and Vue application for managing a small library catalogue, circulation, and staff access.

## What it includes

- Searchable catalogue with create, edit, and delete operations.
- Copy-aware check-out and check-in workflows with due dates and overdue status.
- Member-scoped loan history: members can only see their own loans.
- Role and permission management with three built-in roles.
- Dashboard metrics for titles, available copies, active loans, and overdue loans.
- Existing Laravel starter-kit authentication features, including passkeys and two-factor authentication.

## Roles

| Role | Access |
| --- | --- |
| `admin` | Full catalogue, circulation, reports, member, and access-control permissions. |
| `librarian` | Manages the catalogue, member check-outs/check-ins, member records, and reports. |
| `member` | Browses the catalogue and sees only their own loans. |

Roles and permissions are provided by `spatie/laravel-permission`. Route middleware enforces every write operation on the server; navigation is only a convenience layer.

## Local setup

Requirements: PHP 8.3+, Composer, Node.js, and either SQLite or MariaDB.

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
npm install
composer run dev
```

Open `http://localhost:8000` after the development services are running.

`composer setup` also migrates and seeds the application, including the roles and permissions required before a new user can register.

The supplied Dev Container also includes MariaDB. To use it, set these values in `.env` before migrating:

```dotenv
DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=mariadb
DB_USERNAME=mariadb
DB_PASSWORD=mariadb
```

## Demo accounts

In `local` and `testing` environments, `php artisan migrate --seed` creates the following accounts. Each uses the password `password`:

| Role | Email |
| --- | --- |
| Admin | `admin@library.test` |
| Librarian | `librarian@library.test` |
| Member | `member@library.test` |

`LibraryDemoSeeder` does not create these accounts or the sample catalogue outside `local` and `testing`. Production seeding creates only the roles and permissions required by the application; provision production accounts separately.

## Validation

```bash
php artisan test
npm run types:check
npm run build
```

## Authentication and SSO

The application already includes session authentication, passkeys, and two-factor authentication from the Laravel starter kit. Role-based authorization is active for the library domain. An external SSO provider is intentionally not configured because it requires provider-specific client credentials and callback URLs; the role model and authenticated routes are ready to use with a future OIDC or SAML integration.