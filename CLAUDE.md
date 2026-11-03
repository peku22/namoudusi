# Project: Client eshop – online seminars & videos

## Stack
- WordPress + WooCommerce, standard structure (no Bedrock)
- Custom plugin: wp-content/plugins/clientname-seminars (only our code is in Git)
- Hosting: Webglobe (Czech), staging on subdomain, production; deploy via GitHub Actions + rsync over SSH (check SSH port, possibly 222)
- Local: plain docker compose (compose.yaml, project name `namoudusi`, network `namoudusi_net`), runs alongside flatio – never touch flatio containers, no global prune
  - Services: web (PHP 8.5 + Apache, matches Webglobe) http://namoudusi.localhost:8180 (own cookie scope, not plain localhost), db MariaDB 10.5 on 127.0.0.1:3307, Mailpit http://localhost:8125, cron (runs due WP-cron events every 60 s)
  - Docroot ./public; WP-CLI: `docker compose exec -u www-data web wp ...`; Xdebug via XDEBUG_MODE in .env

## Product types
1. Online seminar: virtual product with date/time, capacity (stock), Zoom meeting ID.
   On order completed → Zoom API registrant per buyer → unique join_url stored in order item meta,
   sent in email with .ics; reminders 24h and 1h before via Action Scheduler.
2. Online video: virtual product with Bunny Stream video ID and optional access duration.
   On order completed → row in custom entitlements table (user_id, product_id, expires_at).
   "My videos" tab in My Account; player uses short-lived Bunny token-signed embed URL.

## Requirements
- Czech payment gateway (Comgate/GoPay – TBD), invoicing via Fakturoid (TBD)
- Checkout checkbox: consent to immediate delivery of digital content + loss of withdrawal right (store in order meta)
- API keys in wp-config.php constants, never in DB; behaviour depends on WP_ENVIRONMENT_TYPE
- Plugin must not break the shop when deactivated; DB tables via versioned migrations
- Lean setup: lightweight theme, no page builder, minimal plugins, real cron instead of WP-cron, HPOS enabled