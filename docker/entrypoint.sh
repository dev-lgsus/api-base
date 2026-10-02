#!/bin/sh
set -e

php artisan config:clear
php artisan migrate --force

# config:cache queda deshabilitado a proposito: la config de l5-swagger trae
# un objeto (OpenApi\Analysers\ReflectionAnalyser) que Laravel no puede
# serializar, y `artisan config:cache` falla duro con ese paquete instalado.
# route:cache y view:cache no tienen ese problema y si se mantienen.
php artisan route:cache
php artisan view:cache

exec "$@"
