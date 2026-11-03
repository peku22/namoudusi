#!/usr/bin/env bash
# Local environment bootstrap: Docker stack + WordPress + WooCommerce with project defaults.
# Safe to re-run: each step is skipped when already done; site settings are applied on first install only.
#
# Usage: bin/setup.sh
# Optional env: ADMIN_USER (default: $USER), ADMIN_EMAIL (default: git user.email), ADMIN_PASSWORD (default: random)
set -euo pipefail

cd "$(dirname "$0")/.."

WP_VERSION=7.1.3
WP_LOCALE=cs_CZ
SITE_URL=http://namoudusi.localhost:8180
SITE_TITLE="Na mou duši"
SITE_TAGLINE="Psychologické služby a terapeutické techniky"
OWN_PLUGIN=clientname-seminars

ADMIN_USER=${ADMIN_USER:-$USER}
ADMIN_EMAIL=${ADMIN_EMAIL:-$(git config user.email || true)}

step() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
wp() { docker compose exec -T -u www-data web wp "$@" </dev/null; }

command -v docker >/dev/null || { echo "Docker is not installed." >&2; exit 1; }

# --- .env ---------------------------------------------------------------------
if [[ ! -f .env ]]; then
  step "Creating .env with random DB passwords"
  sed -e "s/^HOST_UID=.*/HOST_UID=$(id -u)/" \
      -e "s/^HOST_GID=.*/HOST_GID=$(id -g)/" \
      -e "s/^DB_PASSWORD=.*/DB_PASSWORD=$(openssl rand -hex 12)/" \
      -e "s/^DB_ROOT_PASSWORD=.*/DB_ROOT_PASSWORD=$(openssl rand -hex 12)/" \
      .env.example > .env
fi

# --- Containers ---------------------------------------------------------------
step "Starting containers"
mkdir -p public   # create as current user, otherwise Docker creates the bind mount as root
docker compose up -d --build --wait

# --- WordPress core -------------------------------------------------------------
if [[ ! -f public/wp-includes/version.php ]]; then
  step "Downloading WordPress $WP_VERSION ($WP_LOCALE)"
  wp core download --version="$WP_VERSION" --locale="$WP_LOCALE" --skip-content
fi
mkdir -p public/wp-content/{plugins,themes,uploads}
[[ -f public/wp-cli.yml ]] || printf 'apache_modules:\n  - mod_rewrite\n' > public/wp-cli.yml

if [[ ! -f public/wp-config.php ]]; then
  step "Creating wp-config.php"
  cp config/wp-config.local.php public/wp-config.php
  wp config shuffle-salts
fi

# --- Install + site defaults (first run only) -----------------------------------
if ! wp core is-installed 2>/dev/null; then
  [[ -n "$ADMIN_EMAIL" ]] || { echo "Set ADMIN_EMAIL (no git user.email found)." >&2; exit 1; }
  ADMIN_PASSWORD=${ADMIN_PASSWORD:-$(openssl rand -base64 18 | tr -d '/+=' | cut -c1-20)}

  step "Installing WordPress"
  wp core install --url="$SITE_URL" --title="$SITE_TITLE" \
    --admin_user="$ADMIN_USER" --admin_email="$ADMIN_EMAIL" --admin_password="$ADMIN_PASSWORD" --skip-email
  wp language core is-installed "$WP_LOCALE" || wp language core install "$WP_LOCALE"
  wp site switch-language "$WP_LOCALE"

  step "Applying site settings"
  wp option update blogdescription "$SITE_TAGLINE"
  wp option update timezone_string Europe/Prague
  wp option update date_format "j. n. Y"
  wp option update time_format "H:i"
  wp option update start_of_week 1
  wp option update default_comment_status closed
  wp option update default_ping_status closed
  wp option update blog_public 0
  sample_posts=$(wp post list --post_type=post,page --post_status=any --format=ids)
  if [[ -n "$sample_posts" ]]; then wp post delete $sample_posts --force; fi
  sample_comments=$(wp comment list --format=ids)
  if [[ -n "$sample_comments" ]]; then wp comment delete $sample_comments --force; fi
  wp rewrite structure '/%postname%/' --hard

  CREATED_ADMIN=1
fi

# --- Theme & plugins --------------------------------------------------------------
step "Theme and plugins"
wp theme is-installed twentytwentyfive || wp theme install twentytwentyfive
wp theme activate twentytwentyfive
for t in $(wp theme list --status=inactive --field=name); do wp theme delete "$t"; done
for p in akismet hello; do
  if wp plugin is-installed "$p"; then wp plugin delete "$p"; fi
done

if ! wp plugin is-active woocommerce; then
  step "Installing WooCommerce"
  wp plugin install woocommerce --activate
  wp language plugin install woocommerce "$WP_LOCALE"
  wp wc hpos enable

  step "Applying WooCommerce settings"
  while read -r key value; do
    wp option update "$key" "$value" >/dev/null
  done <<'OPTIONS'
woocommerce_default_country CZ
woocommerce_currency CZK
woocommerce_currency_pos right_space
woocommerce_price_decimal_sep ,
woocommerce_price_num_decimals 0
woocommerce_weight_unit kg
woocommerce_dimension_unit cm
woocommerce_calc_taxes no
woocommerce_ship_to_countries disabled
woocommerce_enable_coupons yes
woocommerce_enable_guest_checkout no
woocommerce_enable_checkout_login_reminder yes
woocommerce_enable_signup_and_login_from_checkout yes
woocommerce_enable_myaccount_registration yes
woocommerce_registration_generate_password yes
woocommerce_enable_reviews no
woocommerce_allow_tracking no
woocommerce_show_marketplace_suggestions no
woocommerce_coming_soon no
OPTIONS
  wp option update woocommerce_price_thousand_sep " "   # read would strip a lone space
  wp option update woocommerce_onboarding_profile '{"skipped":true}' --format=json

  step "Creating shop pages"
  wp wc tool run install_pages --user="$ADMIN_USER"
  while IFS='|' read -r option title slug; do
    id=$(wp option get "$option")
    wp post update "$id" --post_title="$title" --post_name="$slug"
  done <<'PAGES'
woocommerce_shop_page_id|Obchod|obchod
woocommerce_cart_page_id|Košík|kosik
woocommerce_checkout_page_id|Pokladna|pokladna
woocommerce_myaccount_page_id|Můj účet|muj-ucet
PAGES
  refund_id=$(wp option get woocommerce_refund_returns_page_id 2>/dev/null || echo 0)
  if [[ "$refund_id" != 0 ]]; then
    wp post delete "$refund_id" --force
    wp option update woocommerce_refund_returns_page_id 0
  fi
  wp rewrite flush --hard
fi

if [[ -d "public/wp-content/plugins/$OWN_PLUGIN" ]] && ! wp plugin is-active "$OWN_PLUGIN"; then
  step "Activating $OWN_PLUGIN"
  wp plugin activate "$OWN_PLUGIN"
fi

# --- Summary ------------------------------------------------------------------------
step "Done"
echo "Site:    $SITE_URL"
echo "Admin:   $SITE_URL/wp-admin"
echo "Mailpit: http://localhost:$(grep -E '^MAILPIT_PORT=' .env | cut -d= -f2)"
if [[ -n "${CREATED_ADMIN:-}" ]]; then
  echo "Login:   $ADMIN_USER / $ADMIN_PASSWORD   (shown once – change it in the admin)"
fi
