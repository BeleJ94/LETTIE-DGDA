# Lettie-DGDA

Gestion et traçabilité des correspondances de la DGDA. PHP 8.2 sans framework, MariaDB, jQuery, DataTables.

## Installation locale (XAMPP)

```
composer install
cp .env.example .env
php bin/migrate.php
php bin/seed-demo.php
```

Adresse : http://127.0.0.1/Lettie-DGDA. Compte de démonstration : `admin@lettie.local` / `Lettie-Demo-2026`.

La base est `lettie_dgda_app`. Ne pas utiliser `lettie_dgda`, qui appartient au logiciel Lettie existant.

Avant toute mise en production : supprimer les comptes de démonstration, saisir l'organigramme réel et renseigner `APP_URL`, qui figure dans les QR codes.

## Tests

```
vendor/bin/phpunit
```
