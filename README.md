# template-www v1.0

Szablonowy serwis WWW w PHP oparty o:
- PHP
- MariaDB
- Bootstrap
- nginx + php-fpm

Projekt jest przygotowany jako baza pod kolejne serwisy:
- logowanie użytkowników
- role i uprawnienia
- zarządzanie dostępem do stron
- menu budowane z bazy danych

## Wymagania

- AlmaLinux / Linux
- nginx
- php-fpm
- PHP z obsługą:
  - PDO
  - pdo_mysql
- MariaDB / MySQL
- git

## Struktura projektu

- `index.php` — front controller
- `config/` — konfiguracja lokalna aplikacji
- `inc/` — logika pomocnicza
- `pages/` — widoki i obsługa ekranów
- `assets/` — CSS / JS
- `install/` — pliki instalacyjne bazy danych
- `README.md` — opis projektu

## Instalacja projektu

### 1. Pobranie repozytorium

```bash
git clone git@github.com:IT-meblomaster/template-www.git
cd template-www
```

### 2. Utworzenie bazy danych i użytkownika DB

W katalogu `install` znajdują się:
- `template-schema.sql` — struktura bazy
- `template-seed.sql` — dane systemowe
- `create-mariadb-db.sh` — skrypt instalacyjny

Uruchom:

```bash
chmod +x install/create-mariadb-db.sh
./install/create-mariadb-db.sh template_www template_user 'MocneHaslo123!'
```

Skrypt:
- poprosi o login i hasło administratora MariaDB
- poprosi o hasło dla użytkownika aplikacyjnego `admin`
- utworzy bazę
- utworzy użytkownika bazy danych
- zaimportuje strukturę
- zaimportuje dane systemowe
- utworzy konto aplikacyjne `admin`

### 3. Konfiguracja aplikacji

Plik `config/config.php` jest lokalny i nie powinien trafiać do repozytorium.

Przykładowa konfiguracja:

```php
<?php
declare(strict_types=1);

return [
    'app' => [
        'name' => 'Template',
        'base_url' => '',
    ],

    'debug' => false,
    'log_errors' => true,
    'error_log' => __DIR__ . '/../var/logs/php-error.log',

    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'template_www',
        'user' => 'template_user',
        'pass' => 'TU_WPISZ_HASLO',
        'charset' => 'utf8mb4',
    ],

    'security' => [
        'session_name' => 'template_www_sess',
    ],
];
```

## Konto startowe

Po instalacji tworzony jest użytkownik:
- login: `admin`
- email: `admin@localhost`

Hasło ustawiasz podczas uruchomienia skryptu instalacyjnego.

## Uprawnienia i dostęp

Projekt korzysta z:
- użytkowników
- ról
- permissionów
- powiązań stron z wymaganymi uprawnieniami

Menu jest budowane z tabeli `pages`.

## Najważniejsze ekrany

- `Start`
- `Dashboard`
- `Ustawienia`
- `Zarządzaj dostępem`
  - `Użytkownicy`
  - `Role`
  - `Uprawnienia`

## Uruchomienie pod nginx

Przykładowa konfiguracja:

```nginx
server {
    listen 80;
    server_name template.local;

    root /var/www/template.local;
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php-fpm/www.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

## Przydatne polecenia

Sprawdzenie składni PHP:

```bash
find . -name "*.php" -exec php -l {} \;
```

Sprawdzenie składni skryptu instalacyjnego:

```bash
bash -n install/create-mariadb-db.sh
```

Status repozytorium:

```bash
git status
```

## Dalszy rozwój

Projekt można rozbudować o:
- kolejne moduły
- własne strony
- dodatkowe role
- dodatkowe poziomy uprawnień
- logi audytowe
- reset hasła
- zmianę hasła użytkownika z panelu
# tempus-www
