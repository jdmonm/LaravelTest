# Library Desk

Library Desk is a Laravel and Vue application for managing a small library catalogue, circulation, and staff access.

## What it includes

- Searchable catalogue with create, edit, and delete operations.
- Copy-aware check-out and check-in workflows with due dates and overdue status.
- Member-scoped loan history: members can only see their own loans.
- Role and permission management with three built-in roles.
- Dashboard metrics for titles, available copies, active loans, and overdue loans.
- An authenticated Library assistant for live catalogue availability and permission-scoped loan workflows.
- A local Model Context Protocol (MCP) server for live catalogue data and authenticated, non-mutating loan preparation.
- Existing Laravel starter-kit authentication features, including passkeys and two-factor authentication.

## Roles

| Role        | Access                                                                           |
| ----------- | -------------------------------------------------------------------------------- |
| `admin`     | Full catalogue, circulation, reports, member, and access-control permissions.    |
| `librarian` | Manages the catalogue, member check-outs/check-ins, member records, and reports. |
| `member`    | Browses the catalogue and sees only their own loans.                             |

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

| Role      | Email                    |
| --------- | ------------------------ |
| Admin     | `admin@library.test`     |
| Librarian | `librarian@library.test` |
| Member    | `member@library.test`    |

`LibraryDemoSeeder` does not create these accounts or the sample catalogue outside `local` and `testing`. Production seeding creates only the roles and permissions required by the application; provision production accounts separately.

## Validation

```bash
php artisan test
npm run types:check
npm run build
```

## Library assistant and MCP

The Library assistant is available at `/assistant` for authenticated, verified users. It can search the live catalogue, show availability, and show a user only their own loan history. Librarians and administrators can prepare a checkout, then explicitly confirm it in the application. The checkout itself reuses the normal inventory-safe transaction.

The assistant sends the current user message, a bounded recent conversation history, and data returned by permission-aware Library Desk tools to OpenAI. A member cannot receive another member's loans, and the model cannot perform a checkout directly. Conversation history is encrypted before it is stored in the encrypted server session and is discarded whenever the user's effective permissions change.

To enable it locally, set these values in `.env`. Keep `OPENAI_API_KEY` out of source control and rotate any key that was exposed outside a secure secret store.

```dotenv
OPENAI_API_KEY=
OPENAI_MODEL=gpt-4o-mini-2024-07-18
OPENAI_BASE_URL=https://api.openai.com/v1
OPENAI_REQUEST_TIMEOUT=20
SESSION_ENCRYPT=true
```

Production deployments should use HTTPS and set `SESSION_SECURE_COOKIE=true`. The application defaults this cookie flag to `true` when `APP_ENV=production`.

The MCP server is intentionally local. All registered MCP operations are read-only and idempotent: none can create a loan, decrement inventory, check in a book, or edit catalogue data.

### MCP actions

| Tool or resource                 | What it does                                            | Inputs                                                                                                            | Access and result                                                                                                                                                                                                    |
| -------------------------------- | ------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `search-library-books`           | Searches the live catalogue by title, author, or ISBN.  | Optional `query` string; optional `limit` from `1` to `20` (default `8`).                                         | Available through local MCP. Returns matching books with identifiers, bibliographic data, total/available copy counts, and availability.                                                                             |
| `get-library-book`               | Gets current details for one catalogue record.          | Required integer `book_id`.                                                                                       | Available through local MCP. Returns the book description, bibliographic data, copy counts, and current availability.                                                                                                |
| `library-desk://catalogue/books` | Reads a JSON catalogue snapshot.                        | None.                                                                                                             | Available through local MCP as an `application/json` resource. Returns the first `20` catalogue records with current availability.                                                                                   |
| `list-my-library-loans`          | Lists the authenticated user's own loans.               | Optional `status`: `all`, `active`, `overdue`, or `returned`; optional `limit` from `1` to `20` (default `10`).   | Requires an authenticated Library Desk user with `loans.view-own` or `loans.view`. Results remain scoped to that user's `borrower_id`, even for staff. Returns book details, check-out/due/return dates, and status. |
| `prepare-library-checkout`       | Validates a prospective checkout without changing data. | Required integer `book_id`; required integer `borrower_id`; optional future `due_at` date in `YYYY-MM-DD` format. | Requires an authenticated librarian or administrator with `loans.manage`. Verifies the book, borrower, availability, and due date, then returns a checkout draft for explicit confirmation in Library Desk.          |

### MCP transport limits

`php artisan mcp:start library-desk` uses the local stdio transport. It does not include an authenticated Library Desk browser session, so only the catalogue tools and catalogue resource are available in that mode.

The own-loans and checkout-preparation tools are registered only when an MCP transport supplies an authenticated `App\Models\User`. This application does not currently expose a public authenticated MCP HTTP endpoint; adding one requires a deliberate OAuth or token-authentication design.

### Actions not available through MCP

- Confirm or create a checkout, decrement inventory, or check in a loan.
- Create, edit, or delete books.
- Create, edit, or manage members, roles, or permissions.
- Read another member's loans.

An authorized staff member must explicitly confirm a prepared checkout in the Library Desk application, where the existing transaction-safe checkout action creates the loan and updates stock. Inspect the locally available MCP surface with:

```bash
php artisan mcp:inspector library-desk
```

An MCP client can start it with:

```bash
php artisan mcp:start library-desk
```

No public MCP HTTP route is registered. Exposing one requires a deliberate OAuth or token-authentication design.

## Authentication and SSO

The application already includes session authentication, passkeys, and two-factor authentication from the Laravel starter kit. Role-based authorization is active for the library domain. An external SSO provider is intentionally not configured because it requires provider-specific client credentials and callback URLs; the role model and authenticated routes are ready to use with a future OIDC or SAML integration.
