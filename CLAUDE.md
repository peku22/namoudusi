# Project: Na mou duši – eshop with recorded & live webinars

## Stack
- WordPress + WooCommerce, standard structure (no Bedrock)
- Custom plugin: public/wp-content/plugins/namoudusi-webinars (only our code is in Git); detailed plan in docs/plugin-plan.md
- Hosting: Webglobe (Czech), staging on subdomain, production; deploy via GitHub Actions + rsync over SSH (check SSH port, possibly 222)
- Local: plain docker compose (compose.yaml, project name `namoudusi`, network `namoudusi_default`, volume `namoudusi_db_data`), runs alongside flatio – never touch flatio containers, no global prune
  - Services: web (PHP 8.5 + Apache, matches Webglobe) http://namoudusi.localhost:8180 (own cookie scope, not plain localhost), db MariaDB 10.5 on 127.0.0.1:3307, Mailpit http://localhost:8125, Adminer http://localhost:8181 (server `db`), cron (runs due WP-cron events every 60 s)
  - Fresh clone: `bin/setup.sh` (idempotent; creates .env, wp-config.php, installs WP + WooCommerce with project defaults)
  - Plugin checks: `docker compose exec -u www-data -w /var/www/html/wp-content/plugins/namoudusi-webinars web composer check` (PHPCS + PHPStan + PHPUnit)
  - Plugin smoke tests (real WP + HTTP, create and remove their own data): `docker compose exec -u www-data web wp eval-file wp-content/plugins/namoudusi-webinars/tests/smoke/<access-flow|account-flow|zoom-flow|schedule-flow|checkout-flow>.php` (zoom-flow and schedule-flow need the local fake Zoom client = no Zoom constants)
  - i18n: English source strings, Czech in languages/*.po; after string changes run in the plugin dir: `wp i18n make-pot . languages/namoudusi-webinars.pot --exclude=vendor,tests --skip-js`, update the .po, then `wp i18n make-mo languages && wp i18n make-php languages`
  - Admin CLI: `wp nmd access list|grant|revoke` (use --customer, not --user), `wp nmd zoom check <id>|sync <product>`
  - Docroot ./public; WP-CLI: `docker compose exec -u www-data web wp ...`; Xdebug via XDEBUG_MODE in .env

## Product types (simple + virtual Woo products with a "Webinář" tab; access via own entitlements table)
1. Recorded webinar: Bunny Stream video, playable only in My Account → Moje webináře
   (short-lived token-signed embed URL + domain restriction; no shareable link).
2. Live webinar: Zoom meeting; per-buyer registrant join_url shown on the account page as soon as the
   meeting ID is known; .ics; reminders 24h and 1h before via Action Scheduler; recording can be added later.
- Both: optional documents (PDF…) in a protected folder, served only after an access check.
- Access per product: forever or N days. Capacity for live = stock.

## Requirements
- Czech payment gateway (Comgate/GoPay – TBD), invoicing via Fakturoid (TBD)
- Checkout checkbox: consent to immediate delivery of digital content + loss of withdrawal right (store in order meta)
- API keys in wp-config.php constants, never in DB; behaviour depends on WP_ENVIRONMENT_TYPE
- Plugin must not break the shop when deactivated; DB tables via versioned migrations
- Lean setup: lightweight theme, no page builder, minimal plugins, real cron instead of WP-cron, HPOS enabled