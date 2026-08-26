# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A WordPress plugin (`erp-budgeting`) that adds Budgeting & Budget Performance to **WP ERP**'s Accounting module. It is a **hard dependency** on WP ERP: `erp-budgeting.php` checks for WP ERP (`erp/wp-erp.php` or `wp-erp/wp-erp.php` active, or `wperp()`/`WeDevs_ERP` present) and bails out with an admin notice if it isn't found — nothing in `includes/src` runs otherwise.

The plugin has two halves that only talk to each other through WordPress:
- **PHP backend** (`includes/src/`) — REST API, DB schema/migrations, cron reconciliation, WP admin menu/asset wiring.
- **React admin UI** (`assets/`) — a Vite + TypeScript + Tailwind + shadcn/radix SPA mounted into a single WP admin page root.

## Build & dev commands

All frontend work happens in `assets/`:

```
cd assets
npm install
npm run dev       # Vite dev server (not wired into WP admin — build for real testing)
npm run build      # tsc -b && vite build -> outputs assets/dist (manifest-driven)
npm run lint       # eslint .
npm run preview
```

There is no PHP test suite, PHP linter config, or npm test script in this repo — don't invent one. The PHP side has no build step; it's plain PSR-4 classes loaded via `vendor/autoload.php` (Composer) or the bundled `includes/src/Autoloader.php` fallback if `vendor/` isn't present.

To see PHP changes reflected in WP admin: just reload (no PHP build step). To see React changes: run `npm run build` in `assets/` — the plugin reads `assets/dist/.vite/manifest.json` to enqueue the hashed JS/CSS (falls back to globbing `assets/dist/js/main-*.js` / `assets/dist/assets/*.css` if the manifest is missing). There's no dev-server HMR integration with WP admin.

## PHP architecture (`includes/src/`, namespace `Enle\ERP\Budgeting\`)

Everything is wired from `includes/src/Bootstrap.php` on `plugins_loaded`, instantiating (if the class exists) `Http\RestController`, `Listener\TransactionListener`, `Cron\Reconciler`, `Admin\Assets`, `Admin\Menu` — all self-registering via their own constructors (hooking `add_action`/`add_filter` themselves). To add a new component, follow this same pattern and add it to Bootstrap.

- **`Migration::createTables()`** — runs on plugin activation (`register_activation_hook` in `erp-budgeting.php`). Creates six tables via `dbDelta`: `erp_budgets`, `erp_budget_lines`, `erp_budget_periods`, `erp_budget_variances`, `erp_budget_logs`, `erp_budget_alerts`. Also does an ad-hoc `ALTER TABLE` to backfill `fiscal_year` on `erp_budgets` for older installs — this is the pattern to follow for future schema migrations (dbDelta won't drop/alter columns reliably, so manual `ALTER TABLE ... IF NOT EXISTS`-style checks are added alongside it).
- **`Repository\BudgetRepository`** — the only class that touches `$wpdb` directly. All budget/line/log CRUD goes through here.
- **`Service\BudgetService`** — business logic: create/update budgets, resolve `fiscal_year` → `start_date`/`end_date` by calling WP ERP's own REST endpoint (`/erp/v1/accounting/v1/opening-balances/names`) via `wp_remote_get`, compute budget-vs-actual and fiscal-year reports. Validates that budget lines only reference **Income (chart_id 4) or Expense (chart_id 5)** ledger accounts via WP ERP's `erp_acct_get_ledger()` (validation is skipped if that function isn't available).
- **`Service\BudgetCalculator`** — pure functions for variance and favorability (expense accounts are unfavorable when actual > budgeted; income accounts are the reverse).
- **`Http\RestController`** — registers all `erp/v1/*` REST routes (see below). Every route's `permission_callback` is the `manage_erp_budgets` capability.
- **`Listener\TransactionListener`** — hooks `erp_ac_accounting_transaction_saved` (fired by WP ERP core) to append rows to `erp_budget_logs` per transaction line. This is how "actuals" get recorded incrementally as accounting transactions happen.
- **`Cron\Reconciler`** — hourly WP-Cron job (`erp_budget_reconcile_event`, scheduled/unscheduled on plugin (de)activation) that recomputes `erp_budget_periods` and appends `erp_budget_variances` rows for every budget line, using a transient lock (`erp_budget_reconcile_lock`, 5 min) to avoid overlapping runs. Can also be triggered manually via `POST /erp/v1/reconcile`.
- **`Admin\Menu`** — registers the WP admin submenu pages under WP ERP's own top-level menu (detected via `is_plugin_active`, falls back to `index.php`/Tools if WP ERP's slug can't be detected). Registers *hidden* helper routes (`erp-budgeting-new`, `erp-budgeting-reports`) purely so WP doesn't 403 direct navigation to those React routes — they're hidden from the visible menu via injected admin CSS. Also grants the `manage_erp_budgets` capability to `administrator`/`admin` roles and WP ERP's accounting-manager role on every `admin_init`, and handles the legacy cashbook admin-post save.
- **`Admin\Assets`** — enqueues the React bundle only on plugin admin pages (`str_contains($hook, 'erp-budgeting')`), localizes `wpApiSettings` (REST root + nonce) and `erpBudgetingSettings` (admin URLs, current page slug, mapped React route, cashbook nonce/data) for the SPA to read from `window`. Also has a `rest_post_dispatch` filter that transparently restricts WP ERP's `/erp/v1/accounting/v1/ledgers` REST response to `chart_id` 4/5 (Income/Expense) **only when the request's HTTP referer is one of the budgeting admin pages** — this is a UI-scoping hack, not a security boundary, and doesn't touch the underlying chart of accounts.
- **`erp-budgeting.php`** (root) also defines a standalone `admin-post` AJAX handler (`erp_budgeting_get_transactions_handler`, action `erp_budgeting_get_transactions`) used by the CashBook React page: it internally calls WP ERP's sales/purchases/expenses REST endpoints (`rest_do_request`, falling back to an external `wp_remote_get` if that fails), flattens/normalizes their differently-shaped payloads with a recursive extractor, and applies hardcoded local filtering (type in payment/purchase/expense, status "paid") plus date sorting — all filtering happens in PHP after fetching everything, not via query params to WP ERP.

### REST API surface (namespace `erp/v1`, all gated on `manage_erp_budgets`)

```
GET    /erp/v1/budgets
GET    /erp/v1/budgets/{id}
POST   /erp/v1/budgets
PUT    /erp/v1/budgets/{id}
DELETE /erp/v1/budgets/{id}
GET    /erp/v1/reports/budget-vs-actual?budget_id=&start_date=&end_date=
GET    /erp/v1/budgets/reports?fiscal_year=&period=&department_id=
POST   /erp/v1/reconcile
POST   /erp/v1/internal/run-migrations   # temporary, meant to be removed after use
```

Note: this plugin also *consumes* WP ERP's own accounting REST namespace (`erp/v1/accounting/v1/...` — ledgers, opening-balances, sales/purchases/expenses transactions) both server-side (`BudgetService`, the admin-post handler) and client-side (`assets/src/lib/budgets.ts`). When changing budget/ledger logic, check both directions of that dependency.

## Frontend architecture (`assets/src/`)

Vite React SPA, mounted at `#erp-budgeting-root` (`main.tsx`) inside whatever WP admin page rendered that div (`Admin\Menu::render_page`). There's a single build output (`main.tsx` is the only Vite entry — see `vite.config.ts`), and routing is **not** based on browser URL paths but on the WP admin `page` query param:

- `Admin\Assets::enqueue_assets` maps `$_GET['page']` → an `initialRoute` string (`/`, `/budget/new`, `/reports`, `/cashbook`) and localizes it as `erpBudgetingSettings.currentPage`/`initialRoute`.
- `App.tsx` reads `erpBudgetingSettings.currentPage` (not `initialRoute`) and switches between `BudgetList`, `BudgetEditor`, `Reports`, `CashBook` — plus a special case: on the base `erp-budgeting` page, a `?budget=<id>` query param renders `BudgetEditor` instead of `BudgetList`.
- `react-router-dom`'s `BrowserRouter` wraps the app but in-app routes aren't actually driven by it the way you'd expect in a typical SPA — page selection is the manual switch in `App.tsx` described above, keyed off WP's own admin URL/query params.
- Data fetching uses `swr`, configured in `App.tsx` with a fetcher that reads `window.wpApiSettings.nonce`. `hooks/useApi.ts` is a second, separate fetch wrapper (hardcodes the `/wp-json/erp/v1` prefix) used for direct calls outside SWR.
- `lib/budgets.ts` has client-side aggregation helpers (`fetchBudgetLinesForYear`, `fetchBudgetLinesWithLedgers`) that stitch together this plugin's `/budgets` endpoint with WP ERP's `/accounting/v1/ledgers` and `/accounting/v1/opening-balances` endpoints — mirrors the "Income/Expense only" and fiscal-year-resolution logic that also lives in PHP (`BudgetService`), so changes to that business rule may need updating in both places.
- `components/ui/*` is a shadcn/radix component set (button, card, dialog, dropdown-menu, form, select, table, calendar, etc.) — reuse these rather than hand-rolling primitives.
- All cross-window contracts (what PHP localizes vs. what the SPA reads) are declared in `src/types/global.d.ts` — keep it in sync when adding/renaming localized settings.

## Cross-cutting gotchas

- Both PHP (`BudgetService::validateBudgetLines`) and the frontend enforce that budget lines only reference Income/Expense ledger accounts (chart_id 4/5); the frontend's `Admin\Assets::filter_ledgers_for_budget_page` REST filter also hides non-4/5 ledgers from the account picker, but only for requests whose `Referer` header matches a budgeting admin page — it's cosmetic, not authoritative. Treat the PHP-side validation in `BudgetService` as the real rule.
- `fiscal_year` on a budget is a convenience input, not a stored source of truth for date range: it's resolved to `start_date`/`end_date` (via WP ERP's opening-balances "names" endpoint, falling back to calendar-year `Jan 1–Dec 31` if unresolved) at create/update time in `BudgetService`, and independently re-derived by the frontend in `lib/budgets.ts` for reporting views.
- WP ERP itself may be installed under different plugin folder names (`erp/wp-erp.php` vs `wp-erp/wp-erp.php`); this affects both the "is ERP active" check and admin-menu parent-slug detection (`Admin\Menu::register_menus`) — handle both when touching either.
