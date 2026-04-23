#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${1:-$(pwd)/app-template}"

echo "Tworzenie projektu w: $APP_DIR"

mkdir -p \
  "$APP_DIR/config" \
  "$APP_DIR/inc" \
  "$APP_DIR/pages" \
  "$APP_DIR/assets/css" \
  "$APP_DIR/assets/js" \
  "$APP_DIR/sql" \
  "$APP_DIR/var/logs"

touch \
  "$APP_DIR/index.php" \
  "$APP_DIR/.env.example" \
  "$APP_DIR/README.md" \
  "$APP_DIR/config/config.php" \
  "$APP_DIR/config/permissions.php" \
  "$APP_DIR/inc/db.php" \
  "$APP_DIR/inc/auth.php" \
  "$APP_DIR/inc/helpers.php" \
  "$APP_DIR/inc/csrf.php" \
  "$APP_DIR/inc/access.php" \
  "$APP_DIR/pages/header.php" \
  "$APP_DIR/pages/footer.php" \
  "$APP_DIR/pages/home.php" \
  "$APP_DIR/pages/login.php" \
  "$APP_DIR/pages/logout.php" \
  "$APP_DIR/pages/dashboard.php" \
  "$APP_DIR/pages/users.php" \
  "$APP_DIR/pages/user_form.php" \
  "$APP_DIR/pages/roles.php" \
  "$APP_DIR/pages/permissions.php" \
  "$APP_DIR/pages/forbidden.php" \
  "$APP_DIR/assets/css/style.css" \
  "$APP_DIR/assets/js/app.js" \
  "$APP_DIR/sql/schema.sql" \
  "$APP_DIR/sql/seed.sql" \
  "$APP_DIR/var/logs/.gitkeep"

echo "Gotowe."
echo "Utworzona struktura:"
find "$APP_DIR" -type f | sort