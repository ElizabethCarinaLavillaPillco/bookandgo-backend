#!/bin/bash

# Run Laravel optimizations
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Run migrations (use with caution in production)
php artisan migrate --force

# Start Apache
apache2-foreground