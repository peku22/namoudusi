# Plugin plan: namoudusi-webinars

Custom plugin for selling **recorded** and **live** webinars on top of WooCommerce.
Location: `public/wp-content/plugins/namoudusi-webinars/`.

## Goals

- A buyer gets access to a webinar page in **My Account → Moje webináře**.
- **Recorded webinar:** Bunny Stream video, playable only inside the account section (signed, short-lived embed URL with a domain restriction).
- **Live webinar:** personal Zoom join link per buyer (Zoom registrant). It is shown as soon as the meeting is known, with an .ics file and reminders 24 h and 1 h before. A recording can be added later and then plays on the same page.
- Both types can carry **documents** (PDF etc.), stored outside the public web and served only to people with access.
- Access length is set **per product**: forever, or N days.
- The shop keeps working when the plugin is deactivated.

## Design decisions

| Decision | Choice | Why |
|---|---|---|
| Product type | Normal *simple + virtual* WooCommerce product with a "Webinář" data tab (`type = recorded/live`) | Deactivating the plugin leaves products and orders intact; payment gateways and invoicing plugins work best with simple products |
| Source of truth for access | Own **entitlements** table | One rule for video, Zoom and documents. Supports expiry, revoking on refund, manual grants without an order, and documents added after purchase |
| Documents | Own field in the Webinář tab. Files are uploaded to a protected folder (`uploads/namoudusi-private/`, `.htaccess: Require all denied`) and served via PHP after an access check | Unlike Woo's downloadable-file permissions, this works for manual grants and for files added later, and follows the same expiry |
| Zoom data | Stored on the entitlement (registrant ID, join URL) and shown in the order admin | Works for manual grants and for meeting IDs added after purchase |
| Grant moment | Order status `completed`. Paid orders that contain only webinar products are auto-completed | Woo leaves virtual, non-downloadable orders in `processing` otherwise |
| Revoke | Order `refunded` / `cancelled` → entitlement revoked, Zoom registrant cancelled | |
| Repeat purchase | Same user + product → one entitlement; expiry is extended | |
| Checkout consent | Woo **Additional Checkout Fields API** (required checkbox) | Woo 11 uses the block checkout; the classic `woocommerce_checkout_fields` hooks don't apply there |
| Secrets | `wp-config.php` constants only. Zoom OAuth access token cached in a transient (short-lived, not a credential) | CLAUDE.md requirement |
| Runtime deps | None. Own PSR-4 autoloader; Composer for dev tools only (PHPCS/WPCS, PHPStan, PHPUnit) | Deploy is rsync. No `vendor/` needed on the server |
| Strings | English source, Czech translation (`languages/namoudusi-webinars-cs_CZ.po`) | WordPress standard; tooling (`wp i18n make-pot`) works |
| Compatibility | Declare HPOS + cart/checkout blocks compatibility; orders only via `wc_get_order()` / `$order->get_meta()` | HPOS is on |

## Product fields (Webinář tab)

| Field | Meta key | Notes |
|---|---|---|
| Type | `_nmd_type` | `recorded` / `live` |
| Bunny video ID | `_nmd_bunny_video_id` | Required for recorded; optional for live (recording added later) |
| Start | `_nmd_starts_at` | Live only; entered in Europe/Prague, stored as UTC |
| Duration (min) | `_nmd_duration` | Live only; used for .ics and "ended" state |
| Zoom meeting/webinar ID | `_nmd_zoom_id` | Live only; can be filled in later |
| Zoom kind | `_nmd_zoom_kind` | `meeting` (default) / `webinar` |
| Access | `_nmd_access_days` | Empty = forever, otherwise number of days |
| Documents | `_nmd_documents` | List of `{id, title, path}` in the protected folder |

Capacity for live webinars = WooCommerce stock.

## Database (versioned migrations)

`{prefix}nmd_entitlements`

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| user_id | bigint | |
| product_id | bigint | unique (user_id, product_id) |
| order_id | bigint NULL | NULL for manual grants |
| source | varchar(20) | `order` / `manual` |
| granted_at | datetime (UTC) | |
| expires_at | datetime NULL | NULL = forever |
| revoked_at | datetime NULL | |
| zoom_registrant_id | varchar(64) NULL | |
| zoom_join_url | text NULL | |

Migrator: option `nmd_db_version`. Numbered migration classes run in order on `plugins_loaded` (admin + CLI), using `dbDelta`. `uninstall.php` keeps data unless `NAMOUDUSI_WEBINARS_DELETE_DATA_ON_UNINSTALL` is true.

## Flows

**Purchase → access**
1. Order paid; webinar-only orders are auto-completed.
2. `woocommerce_order_status_completed` → for each webinar line item, create or extend the entitlement. `expires_at = start + access_days`, where start is the purchase date for recorded webinars and the event start for live ones (so a short access period can't expire before the live session).
3. Live + Zoom ID known → async Action Scheduler job registers the buyer in Zoom and stores the join URL.
4. A "Přístup k webináři" (webinar access) email goes out with the link to the account page (+ .ics for live).

**Zoom ID added or changed later** → on product save, schedule a batch job that registers all active holders (and cancels old registrants if the ID changed).

**Start time changed** → reschedule reminders; the next reminder email carries the new time (+ updated .ics).

**Reminders** → per product, Action Scheduler single actions at start −24 h and −1 h. Each one sends a reminder email (with the personal join link) to every active holder. Unscheduled when the product is trashed or switched to recorded.

**Refund / cancel** → revoke the entitlement, cancel the Zoom registrant.

**Manual grant** → admin: user profile / product screen "Udělit přístup" (grant access), with optional expiry. Same flow from step 3 on.

## Front end (My Account)

- `/muj-ucet/webinare/`: list of the user's webinars (upcoming live first, then recorded). Expired entries are shown greyed out with a link to buy again.
- `/muj-ucet/webinare/{product-id}/`: detail page:
  - Recorded: Bunny iframe; URL signed with `sha256(token_key + video_id + expires)`, valid ~1 h. Allowed referrer set to our domains in Bunny.
  - Live: date and time, personal Zoom link (or "Odkaz doplníme" ("link coming soon") until it's known), .ics download; after the event, the recording if `_nmd_bunny_video_id` is set.
  - Documents: links to `/muj-ucet/webinare/{id}/dokument/{doc-id}/`, which checks access and streams the file (`Content-Disposition: attachment`, `X-Robots-Tag: noindex`).
- Product page: if the user already has access, show "Přejít na webinář" (go to the webinar) instead of "Add to cart".
- Templates in `templates/`, overridable from the theme (`namoudusi-webinars/...`).

## Configuration (`wp-config.php`)

```php
define( 'NAMOUDUSI_BUNNY_LIBRARY_ID', '' );
define( 'NAMOUDUSI_BUNNY_TOKEN_KEY', '' );     // Stream library → Security → token authentication key
define( 'NAMOUDUSI_ZOOM_ACCOUNT_ID', '' );     // Zoom Server-to-Server OAuth app
define( 'NAMOUDUSI_ZOOM_CLIENT_ID', '' );
define( 'NAMOUDUSI_ZOOM_CLIENT_SECRET', '' );
```

Per environment (`WP_ENVIRONMENT_TYPE`):
- `local`: when Zoom constants are missing, a fake Zoom client returns dummy join URLs (logged); emails go to Mailpit.
- `staging`: real APIs, test meetings only. Outgoing emails limited to an allow-list.
- `production`: real everything. Missing constants → admin notice; the plugin never fatals.

## Code structure

```
namoudusi-webinars/
  namoudusi-webinars.php        bootstrap: PHP/Woo checks, autoloader, Plugin::boot()
  uninstall.php
  src/
    Plugin.php                  wires services to hooks
    Config.php                  constants + environment
    Database/Migrator.php, Migrations/M001_CreateEntitlements.php
    Product/WebinarFields.php   admin tab + saving
    Product/Webinar.php         read model over product meta
    Access/EntitlementRepository.php, AccessService.php, OrderListener.php
    Documents/DocumentStorage.php, DocumentController.php
    Video/BunnyStream.php       signed embed URLs
    Zoom/ZoomClient.php, FakeZoomClient.php, ZoomRegistrar.php
    Calendar/Ics.php
    Email/AccessGrantedEmail.php, ReminderEmail.php   (WC_Email subclasses)
    Reminders/ReminderScheduler.php
    Checkout/DigitalConsent.php
    Account/WebinarsEndpoint.php
    Admin/AccessAdmin.php       manual grants, access info on order screen
  templates/                    myaccount + email templates
  languages/
  tests/                        PHPUnit (pure logic: expiry, token signing, .ics)
  composer.json                 dev tools only
```

## Milestones

| # | Scope | Done when |
|---|---|---|
| M1 | Skeleton: bootstrap, autoloader, Config, Migrator + entitlements table, HPOS/blocks compatibility, PHPCS + PHPStan + PHPUnit | Plugin activates and deactivates cleanly; table created; lint passes |
| M2 | Webinář product tab; OrderListener (grant / extend / revoke, auto-complete); manual grant in admin | Test order via WP-CLI creates the entitlement; refund revokes it |
| M3 | My Account list + detail; protected documents upload + download | Buyer sees the page and downloads the PDF; a non-buyer and an anonymous visitor get 403; direct file URL is denied by Apache |
| M4 | Bunny signed player | Video plays in the account; the copied embed URL stops working after expiry and on other domains |
| M5 | Zoom registrants (incl. late ID, cancel), access email + .ics | Personal join URL on the page and in the email; refund cancels the registrant |
| M6 | Reminders 24 h / 1 h, reschedule on time change | Emails arrive in Mailpit at the right times (tested with a near-future start) |
| M7 | Checkout consent checkbox | Checkout fails without it; consent + timestamp visible on the order |

Separate tracks, not in this plugin: payment gateway (Comgate/GoPay), Fakturoid, GitHub Actions deploy to Webglobe, theme/design.

## Resolved decisions

1. **Zoom:** regular *Meetings* by default; *Webinars* add-on also supported (per-product setting `_nmd_zoom_kind = meeting|webinar`).
2. **Live access period** counts from the event start.
3. **Video protection:** signed token + domain restriction; no DRM.

## Access terms and service end

- **Snapshot at purchase:** each order line stores the access terms that were sold (`_nmd_access_days` at purchase time, terms version). Changing a product later never shortens access already sold.
- **Product page wording** is generated from the access setting so it always matches the T&C (e.g. "Přístup: 90 dní" / "Přístup bez časového omezení po dobu provozování služby", i.e. "Access: 90 days" / "Access with no time limit while the service operates").
- **Service end tool (later milestone):** WP-CLI command / admin action to set an end date for all open-ended entitlements, notify all active holders by email with the notice period, and optionally offer a final download of the documents.
