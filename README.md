# WP ERP — Budgeting (scaffold)

This directory contains a scaffold for a WP ERP Budgeting module.

What is included in this scaffold:

- `erp-budgeting.php` — main plugin bootstrap that checks WP ERP is active and runs migrations on activation.
- `includes/class-erp-budgeting-migration.php` — DB migration class creating initial tables.
- `assets/` — placeholder for the React + TypeScript + Tailwind admin UI.

How to use

1. Copy the folder to `wp-content/plugins/erp-budgeting` in your WordPress installation.
2. Activate the plugin from the WordPress Plugins screen. The migration will run on activation and create required tables.
3. The plugin intentionally checks that WP ERP is active; if it is not found an admin notice will be shown.

Next steps (suggested):

- Implement repository and service classes under `includes/src/` using PSR-4 namespaced classes.
- Add REST endpoints and permission callbacks (see `includes/src/Http/RestController.php`).
- Scaffold the React + TypeScript admin UI in `assets/` and add build tooling (Vite) and Tailwind.
- Implement budget listeners and the WP-Cron reconcile job (see `includes/src/Listener/TransactionListener.php` and `includes/src/Cron/Reconciler.php`).
- Use Composer and PSR-4 autoloading: run `composer dump-autoload` in plugin root to generate `vendor/autoload.php` or use the built-in Autoloader.
