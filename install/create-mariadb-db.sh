#!/usr/bin/env bash
#
# script to create database and database user
# usage: ./create-mariadb-db.sh database_new_name database_new_user 'databese_new_password'
#
set -euo pipefail

if [ "$#" -ne 3 ]; then
  echo "Użycie: $0 <nazwa_bazy> <nazwa_uzytkownika_db> <haslo_uzytkownika_db>"
  echo "Przykład: $0 template_www template_user 'MocneHaslo123!'"
  exit 1
fi

DB_NAME="$1"
DB_USER="$2"
DB_PASS="$3"
DB_HOST="localhost"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCHEMA_FILE="$SCRIPT_DIR/template-schema.sql"
SEED_FILE="$SCRIPT_DIR/template-seed.sql"

APP_ADMIN_USER="admin"
APP_ADMIN_EMAIL="admin@localhost"

if [ ! -f "$SCHEMA_FILE" ]; then
  echo "Błąd: brak pliku schema: $SCHEMA_FILE"
  exit 1
fi

if [ ! -f "$SEED_FILE" ]; then
  echo "Błąd: brak pliku seed: $SEED_FILE"
  exit 1
fi

if ! command -v mysql >/dev/null 2>&1; then
  echo "Błąd: nie znaleziono polecenia mysql."
  exit 1
fi

if ! command -v php >/dev/null 2>&1; then
  echo "Błąd: nie znaleziono polecenia php."
  exit 1
fi

read -r -p "Login admina MariaDB: " ADMIN_USER
read -r -s -p "Hasło admina MariaDB: " ADMIN_PASS
echo

while true; do
  read -r -s -p "Hasło dla użytkownika aplikacyjnego admin: " APP_ADMIN_PASS
  echo
  read -r -s -p "Powtórz hasło dla użytkownika aplikacyjnego admin: " APP_ADMIN_PASS_CONFIRM
  echo

  if [ -z "$APP_ADMIN_PASS" ]; then
    echo "Hasło admina aplikacyjnego nie może być puste."
    continue
  fi

  if [ "$APP_ADMIN_PASS" != "$APP_ADMIN_PASS_CONFIRM" ]; then
    echo "Hasła nie są takie same. Spróbuj ponownie."
    continue
  fi

  break
done

MYSQL_BASE=(mysql -h "$DB_HOST" -u "$ADMIN_USER" "-p$ADMIN_PASS" --default-character-set=utf8mb4 --batch --skip-column-names)
MYSQL_DB=(mysql -h "$DB_HOST" -u "$ADMIN_USER" "-p$ADMIN_PASS" --default-character-set=utf8mb4 --batch --skip-column-names "$DB_NAME")

echo "Sprawdzam połączenie z MariaDB..."
"${MYSQL_BASE[@]}" -e "SELECT 1;" >/dev/null

echo "Tworzę bazę danych..."
"${MYSQL_BASE[@]}" -e "
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;
"

echo "Tworzę użytkownika bazy i nadaję uprawnienia..."
"${MYSQL_BASE[@]}" -e "
CREATE USER IF NOT EXISTS '$DB_USER'@'$DB_HOST' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'$DB_HOST' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'$DB_HOST';
FLUSH PRIVILEGES;
"

echo "Importuję schema..."
mysql -h "$DB_HOST" -u "$ADMIN_USER" "-p$ADMIN_PASS" --default-character-set=utf8mb4 "$DB_NAME" < "$SCHEMA_FILE"

echo "Importuję seed..."
mysql -h "$DB_HOST" -u "$ADMIN_USER" "-p$ADMIN_PASS" --default-character-set=utf8mb4 "$DB_NAME" < "$SEED_FILE"

echo "Sprawdzam wymagane tabele..."
for tbl in users roles permissions user_roles role_permissions pages page_permissions; do
  EXISTS=$("${MYSQL_DB[@]}" -e "SHOW TABLES LIKE '$tbl';")
  if [ "$EXISTS" != "$tbl" ]; then
    echo "Błąd: brak tabeli $tbl"
    exit 1
  fi
done

echo "Sprawdzam wymagane dane systemowe..."
ROLE_EXISTS=$("${MYSQL_DB[@]}" -e "SELECT COUNT(*) FROM roles WHERE name='Administrator';")
if [ "$ROLE_EXISTS" = "0" ]; then
  echo "Błąd: brak roli Administrator w tabeli roles."
  exit 1
fi

PAGES_EXISTS=$("${MYSQL_DB[@]}" -e "SELECT COUNT(*) FROM pages WHERE slug IN ('home','dashboard','users','roles','permissions','menu_manager');")
if [ "$PAGES_EXISTS" -lt "6" ]; then
  echo "Błąd: brakuje wymaganych wpisów w tabeli pages."
  exit 1
fi

echo "Generuję hash hasła użytkownika aplikacyjnego..."
APP_ADMIN_HASH="$(php -r "echo password_hash('$APP_ADMIN_PASS', PASSWORD_DEFAULT);")"

if [ -z "$APP_ADMIN_HASH" ]; then
  echo "Błąd: nie udało się wygenerować hasha hasła."
  exit 1
fi

echo "Tworzę lub aktualizuję użytkownika aplikacyjnego admin..."
"${MYSQL_DB[@]}" -e "
INSERT INTO users (username, email, password_hash, first_name, last_name, is_active)
VALUES ('$APP_ADMIN_USER', '$APP_ADMIN_EMAIL', '$APP_ADMIN_HASH', 'System', 'Administrator', 1)
ON DUPLICATE KEY UPDATE
    email = VALUES(email),
    password_hash = VALUES(password_hash),
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    is_active = VALUES(is_active);
"

echo "Przypisuję rolę Administrator..."
"${MYSQL_DB[@]}" -e "
INSERT INTO user_roles (user_id, role_id)
SELECT u.id, r.id
FROM users u
JOIN roles r ON r.name = 'Administrator'
WHERE u.username = '$APP_ADMIN_USER'
AND NOT EXISTS (
    SELECT 1
    FROM user_roles ur
    WHERE ur.user_id = u.id AND ur.role_id = r.id
);
"

echo "Weryfikuję utworzenie użytkownika..."
USER_COUNT=$("${MYSQL_DB[@]}" -e "SELECT COUNT(*) FROM users WHERE username = '$APP_ADMIN_USER';")
ROLE_COUNT=$("${MYSQL_DB[@]}" -e "
SELECT COUNT(*)
FROM user_roles ur
JOIN users u ON u.id = ur.user_id
JOIN roles r ON r.id = ur.role_id
WHERE u.username = '$APP_ADMIN_USER'
  AND r.name = 'Administrator';
")

if [ "$USER_COUNT" != "1" ]; then
  echo "Błąd: użytkownik admin nie został utworzony."
  exit 1
fi

if [ "$ROLE_COUNT" -lt "1" ]; then
  echo "Błąd: rola Administrator nie została przypisana użytkownikowi admin."
  exit 1
fi

echo
echo "Gotowe."
echo "Baza danych:          $DB_NAME"
echo "Użytkownik DB:        $DB_USER@$DB_HOST"
echo "Schema:               $SCHEMA_FILE"
echo "Seed:                 $SEED_FILE"
echo "Użytkownik aplikacji: $APP_ADMIN_USER"
echo "Email aplikacji:      $APP_ADMIN_EMAIL"
echo
echo "Weryfikacja:"
mysql -h "$DB_HOST" -u "$ADMIN_USER" "-p$ADMIN_PASS" "$DB_NAME" -e "
SELECT id, username, email, is_active FROM users WHERE username = '$APP_ADMIN_USER';
SELECT u.username, r.name AS role_name
FROM user_roles ur
JOIN users u ON u.id = ur.user_id
JOIN roles r ON r.id = ur.role_id
WHERE u.username = '$APP_ADMIN_USER';
SELECT id, slug, title, parent_id, is_public, menu_visible, sort_order
FROM pages
ORDER BY sort_order, title;
"