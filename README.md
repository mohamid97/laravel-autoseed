# laravel-autoseed

```bash
composer require your-vendor/laravel-autoseed --dev
php artisan vendor:publish --tag=autoseed-config   # optional

php artisan migrate:fresh
php artisan autoseed:run                              # default_rows per table
php artisan autoseed:run --rows=200
php artisan autoseed:run --table=users:1000 --table=order_items:x10
php artisan autoseed:run --multiplier=5 --fresh --seed=42
php artisan autoseed:run --only=users --only=orders
```

Requires Laravel 11+ (uses Schema::getTables/getColumns/getForeignKeys/getIndexes).
