# Area views — conventions for the work streams

The frontend foundation (decision U-130 in `docs/05-umsetzungsentscheidungen.md`) prepared
the frontend so that three work streams can build the area views **in parallel, in the same
working tree, without editing the same files**. This document is binding for all three. Read
`CLAUDE.md`, `docs/features/W1-frontend-geruest.md`, `docs/features/W2-mehrsprachigkeit.md`
and your package documents (A1–A6, S4) first.

## 1. Who owns what

| Work stream | Packages | Page directories | Catalogue files | URL prefixes |
|---|---|---|---|---|
| `domains-admins-lists` | A1, A4, A5 | `pages/domains/`, `pages/admins/`, `pages/lists/` | `i18n/de/domain.ts`, `i18n/de/admin.ts`, `i18n/de/list.ts` | `/domains`, `/admins`, `/lists` |
| `accounts-addresses` | A2, A3 | `pages/accounts/`, `pages/addresses/` | `i18n/de/account.ts`, `i18n/de/address.ts` | `/accounts`, `/addresses` |
| `operations` | A6, S4 | `pages/repairs/`, `pages/reconciliation/`, `pages/health/`, `pages/audit/` | `i18n/de/maintenance.ts`, `i18n/de/audit.ts` | `/maintenance/repairs`, `/maintenance/reconciliation`, `/maintenance/health`, `/audit` |

You own **everything inside your page directories** (pages, local components, hooks, tests)
and **your catalogue files**. Nothing else.

**Shared — do not edit:** `app/`, `layout/`, `auth/`, `api/`, `components/`, `test/`,
`i18n/` (except your own `i18n/de/<area>.ts`), `pages/*.tsx` at the top level, this README,
`package.json`, `eslint.config.js`, `vite.config.ts` and everything outside `frontend/`.
If a shared building block lacks something you need, build it locally in your page directory
(for example `pages/domains/QuotaField.tsx`) and **report it** in your result so the
coordinator can lift it into `components/` afterwards. Never fork a shared file by copying
it into your directory and changing the copy's behaviour silently.

The navigation (`app/navigation.tsx`) and the route table (`app/routes.tsx`) are already
complete for all nine pages. You do not touch either.

## 2. Routes

Every page directory has a `routes.tsx` that exports its `RouteObject[]`; `app/routes.tsx`
mounts them under `AppLayout`. Add your detail, create and edit routes **there**, under your
URL prefixes:

```tsx
export const domainRoutes: RouteObject[] = [
  { path: '/domains', element: <RequirePermission permission="domain.read"><DomainListPage /></RequirePermission> },
  { path: '/domains/:domain', element: <RequirePermission permission="domain.read"><DomainDetailPage /></RequirePermission> },
];
```

- Wrap every route in `RequirePermission` with the permission the page's **read** operation
  declares as `x-permission` in the contract.
- Cross-links into another stream's area use that area's list URL with a query parameter
  (`/accounts?domain=example.test`), never a route the other stream has not declared. Agree on
  the parameter name in your result if you introduce one; the receiving stream decides
  whether to honour it. Agreed and honoured so far: `/accounts?domain=<name>`,
  `/addresses?domain=<name>`, `/audit?request_id=<id>` (U-132).
- Path parameters are names as they appear in the URL; encode them with
  `encodeURIComponent` when building links.

## 3. Texts and keys

- **No visible string in code** (E-16). Every text is a key in your catalogue file.
- Keys follow U-88: `<area>.<view>.<element>[.<detail>]`, lowerCamelCase segments, e.g.
  `domain.detail.action.rename`, `account.list.column.quota`. Your file may only contain keys
  that start with its area (`domain.` in `de/domain.ts`); `catalogue.test.ts` fails otherwise.
- Write keys **literally** at the call site, also in lookup tables
  (`const STATUS_KEYS = { open: 'maintenance.repairs.status.open', … }`). A key that no source
  file names literally fails the "unused key" check — and so does a key you leave behind
  after deleting the view that used it.
- Plurals: `<key>#one` / `<key>#other`, called with the base key and `count`.
- Placeholders: `{{name}}`; numbers are formatted by `t()`.
- Generic button labels that already exist may be reused: `action.cancel`, `action.confirm`,
  `action.delete`, `action.save`, `action.saving`, `action.close`. **Do not add new `action.*`,
  `nav.*`, `state.*`, `table.*` or `confirm.*` keys** — those live in the shared `i18n/de.ts`.
  Put a label you need into your own area instead (`domain.list.action.create`).
- **Error texts:** `i18n/de.errors.ts` already translates every `ErrorCode` of the contract.
  Do not edit it. A new error code is a contract change and an escalation, not part of a view.
- Dates, numbers and sizes only through `useFormatters()`: `dateTime()` (browser time zone,
  with zone abbreviation, U-92), `date()`, `number()`, `bytes()`, `kibibytes()` for the KiB
  values of Cyrus and `domain.quota` (U-94).

## 4. Permissions

- `useSession().can('<permission>')` hides what the role may not use: create/edit/delete
  buttons, whole sections. It is **rendering only**; the backend decides.
- Use the permission names from the contract's `x-permission` (`domain.write`,
  `account.delete`, `maintenance.write`, …).
- The two roles (from `RolePermissions.php`): a **superuser** has everything; a
  **domain master** has `domain.read`, `account.*`, `address.*`, `profile.*` and the session —
  scoped to their domains. The navigation already reflects that.
- A foreign object answers `404`, not `403` (CLAUDE.md). Render a `404` from the API with
  `ErrorState`; do not invent a "not yours" message.

## 5. Building blocks (`components/`)

| Need | Use |
|---|---|
| Page head, breadcrumbs, document title | `PageHeader` (`title`, `breadcrumbs`, `description`, `actions`) — do not call `useDocumentTitle` yourself |
| Server-side list | `useTableState` + `DataTable` + `FilterField` |
| Loading, failure, no permission | `LoadingState`, `ErrorState`, `ForbiddenState` (`StateViews.tsx`) |
| A failed request in a form or dialog | `ProblemAlert` — catalogue text by `code`, request id, reload offer on `412` |
| Form fields and buttons | `useRequiredCheck`, `useFieldErrors`, `fieldProps`, `FormActions` (`form.tsx`) |
| Confirmation before a change | `ConfirmDialog`; with `typedConfirmation` for anything that destroys mail |
| "Saved." after a change | `useNotify()` — success only; failures stay next to the input |
| Conditional writes | `client.getVersioned()` + `ifMatch(etag)` (`api/etag.ts`) |
| Whether a write waits for the configuration run | `client.requestVersioned(...)` + `hasPendingConfigurationRun(exchange)` (`api/client.ts`) |
| Label–value pairs of one object | `DefinitionList`, `DefinitionItem`, `Monospace` (`DefinitionList.tsx`) |
| Server error on one entry of a list field (`body.members.2`) | `errorAtOrBelow` (`form.tsx`) |
| A contract error code that arrives as a field (`last_error`) | `useErrorCodeText()` (`i18n/problemText.ts`) |

### Lists

```tsx
const SORT = ['name', 'max_accounts', 'domain_quota'] as const satisfies readonly SortFieldOf<'listDomains'>[];

const table = useTableState({ sortFields: SORT, defaultSort: 'name', filters: { name: 'text' } });
const domains = useQuery({
  queryKey: ['domains', 'list', table.query],
  queryFn: ({ signal }) => client.get('/domains', { query: table.query, signal }),
  placeholderData: keepPreviousData,
});
```

- The sort fields are an **allowlist typed against the contract** (`SortFieldOf<operation>`).
  Offer only fields from the operation's `sort` enum; the hook ignores anything else in the
  URL (CLAUDE.md rule 2). Declare the allowlist and the filter spec as module constants.
- Enumeration filters: pass the allowed values (`filters: { status: ['open', 'resolved'] }`);
  free text: `'text'`. The audit trail and the repair queue default to `defaultOrder: 'desc'`
  as the contract does.
- MUI `Table`, never the `DataGrid` (U-73). Sorting, filtering and paging happen on the server.

### Writes

- Load what you will change or delete with `client.getVersioned(...)` and send
  `headers: ifMatch(etag)` with the `PATCH`/`DELETE`. A missing header is `428`, a stale one
  `412`; on `412` reload the object (`ProblemAlert`'s `onReload`) and let the user decide again.
- Mutations never retry (already configured). After success, invalidate the affected query
  keys and confirm with `useNotify()`.
- Destructive operations (deleting a domain, an account, an administrator, a list; renaming a
  domain) go through `ConfirmDialog` with `destructive` and, where mail or access is lost,
  `typedConfirmation` set to the object's name. Removing `postmaster`/`abuse` needs
  `confirm_reserved=true` (U-109); ask explicitly before sending it.
- Forms: the client checks required fields only; everything else is the server's answer,
  mapped onto fields by path (`body.<property>`). No form library (U-73). Keep inputs on
  failure, store nothing (U-74) — inputs here regularly contain passwords.

### Query keys

The first segment is the area, so streams can invalidate each other's data without
coordination: `['domains', …]`, `['admins', …]`, `['lists', …]`, `['accounts', …]`,
`['addresses', …]`, `['repairs', …]`, `['reconciliation', …]`, `['health', …]`,
`['audit', …]`. Use only your own roots for queries; invalidating another root after a write
that changes its data is allowed and expected (creating an account changes the domain's
`account_count`: invalidate `['domains']`).

## 6. Tests

- Vitest + Testing Library in jsdom, no browser driver (U-75). Tests sit next to the code in
  your page directory: `pages/domains/DomainListPage.test.tsx`.
- Page tests go through the **whole application**: `renderApp(responder, { initialEntries })`
  from `test/renderApp.tsx` with a scripted `fetch` (`test/fetchDouble.ts`) — a stored token
  in `localStorage` plus an `/auth/me` answer gets you signed in (see
  `app/navigation.test.tsx`). Assert on what the user sees and on the requests recorded by
  the double (method, URL with query, `If-Match`, body).
- Cover per view at least: the list request with sort/filter/page parameters; empty state;
  an error rendered via the catalogue; permission-dependent buttons for both roles; the write
  with `If-Match`; a `412`; field errors landing on the right field.
- Do not edit the stubs' existing headings away without keeping the key in use, and keep
  `app/navigation.test.tsx` green: each page must still render its `h1` with the title key it
  has now.

## 7. Checks and commits

Before every commit, in `frontend/`:

```sh
npm run typecheck && npm run lint && npm test && npm run build
```

`npm test` and `npm run typecheck` need the contract bundle (`php bin/openapi-bundle.php`
in the F5 container). No new npm dependencies — that is an escalation (E-12).

Commit only your own paths, staged and committed with an explicit pathspec so that another
stream's staged files are not swept in:

```sh
git add frontend/src/pages/domains frontend/src/i18n/de/domain.ts
git commit -m "Build the domain list" -- frontend/src/pages/domains frontend/src/i18n/de/domain.ts
```

Package documents (`docs/features/A*.md`, `S4-*.md`) and the decision log are German and
follow the existing format; the decision log is shared — append new entries at the end of the
numbered list and expect to rebase onto the other streams' entries.
