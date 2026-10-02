# Despliegue de `api-base` en Amazon Lightsail

Guía para levantar el entorno QA/Staging de DerBanks (API Laravel + MySQL + MongoDB, todo en Docker) sobre una instancia Ubuntu de Amazon Lightsail. Sin TLS por ahora (no hay dominio todavía) — se accede por HTTP sobre la IP estática.

No se incluye Redis: `CACHE_STORE` y `QUEUE_CONNECTION` usan `database`, así que hoy no hace falta — agregarlo sería un contenedor más sin uso real.

Instancia elegida: **1 GB RAM / 1 vCPU** (el plan más barato de Lightsail). Es justo para 3 contenedores a la vez, por eso el paso del swapfile abajo **no es opcional**.

---

## 1. Crear la instancia

1. En la consola de Lightsail → **Create instance**.
2. Plataforma: **Linux/Unix** → blueprint **OS Only** → **Ubuntu 24.04 LTS**.
3. Plan: **$5/mes (1 GB RAM, 1 vCPU, 40 GB SSD)**.
4. Nómbrala, por ejemplo `derbanks-qa`, y créala.

## 2. Red: IP estática y firewall

1. Pestaña **Networking** de la instancia → **Create static IP** → asóciala a esta instancia. Sin esto, la IP cambia cada vez que la instancia se reinicia.
2. En la misma pestaña, en **Firewall**, deja solo:
   - `SSH` (22)
   - `HTTP` (80)
3. No abras 3306 (MySQL) ni 27017 (Mongo) — esos puertos no se publican en el `docker-compose.yml`, solo son accesibles entre contenedores.

## 3. Conectarte por SSH

Usa el botón "Connect using SSH" de la consola, o tu propio cliente con la llave que descargaste al crear la instancia:

```bash
ssh -i LightsailDefaultKey.pem ubuntu@<IP_ESTATICA>
```

## 4. Crear el swapfile (obligatorio con 1 GB de RAM)

Con app + MySQL + MongoDB corriendo a la vez, 1 GB se queda corto en picos. Esto evita que el sistema mate procesos por falta de memoria:

```bash
sudo fallocate -l 2G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
free -h   # confirma que el swap aparece
```

## 5. Instalar Docker Engine + Compose

```bash
sudo apt-get update
sudo apt-get install -y ca-certificates curl gnupg
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
sudo chmod a+r /etc/apt/keyrings/docker.gpg
echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo $VERSION_CODENAME) stable" \
  | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo usermod -aG docker ubuntu
newgrp docker   # o cierra y vuelve a abrir la sesion SSH
docker --version
docker compose version
```

## 6. Traer el código

```bash
sudo mkdir -p /opt/api-base
sudo chown ubuntu:ubuntu /opt/api-base
git clone <URL_DEL_REPO_api-base> /opt/api-base
cd /opt/api-base
```

## 7. Configurar el entorno

```bash
cp .env.docker.example .env
nano .env   # reemplaza TODOS los CHANGE_ME y el APP_URL con la IP estatica
```

Como mínimo tienes que cambiar: `APP_URL`, `DB_PASSWORD`, `DB_ROOT_PASSWORD`, `MONGO_ROOT_PASSWORD`.

## 8. Levantar el stack

```bash
docker compose build
docker compose up -d
```

El `entrypoint.sh` del contenedor `app` corre automáticamente `migrate --force` + `route:cache` + `view:cache` en cada arranque — no hace falta ejecutarlos a mano (`config:cache` queda deshabilitado a propósito, ver Notas). Pero la primera vez `APP_KEY` está vacío, así que:

```bash
docker compose exec app php artisan key:generate
docker compose restart app   # para que la app arranque con la key real
```

## 9. Sembrar datos

```bash
docker compose exec app php artisan db:seed --force
docker compose exec app php artisan db:seed --class=QaIntegrante3Seeder --force
```

(El `--force` es obligatorio: con `APP_ENV=production` Laravel pide confirmación interactiva antes de sembrar, y como no hay una terminal interactiva dentro de `docker compose exec`, sin `--force` el comando se cancela solo.)

(El segundo seeder asume una base recién migrada — si necesitas resembrar, haz `docker compose exec app php artisan migrate:fresh` primero y repite ambos `db:seed`.)

## 10. Confirmar que todo quedó arriba

```bash
docker compose ps
```

Debes ver `mysql_db` y `mongo_db` en estado `healthy`, y `api_base_app` en `running`.

---

## Checklist de humo (para el Formato 2 — Informe de Alistamiento)

| Componente | Cómo verificar | Resultado esperado |
|---|---|---|
| API Laravel | `curl -i http://<IP>/up` | HTTP 200 (ruta de salud nativa de Laravel) |
| MySQL | `docker compose ps mysql` | `healthy` |
| MongoDB | `docker compose ps mongo` | `healthy` |
| MongoDB (conexión real desde la app) | `docker compose exec app php artisan tinker --execute="echo App\Models\Mongo\AuditLog::count();"` | Un número, sin error de conexión |
| Variable de tasa de cambio | `docker compose exec app php artisan tinker --execute="echo env('TIPO_CAMBIO_USD_GTQ');"` | `7.65` (no vacío — confirma que `env_file` la inyecta correctamente al contenedor) |
| Firewall | Captura de la pestaña Networking de Lightsail | Solo 22 y 80 abiertos |
| Datos de prueba | Login/consulta con un registro de `QaIntegrante3Seeder` | Responde con los datos sembrados |

---

## Notas

- **`php artisan config:cache` está deshabilitado a propósito** en `docker/entrypoint.sh`: la configuración de `l5-swagger` trae un objeto (`OpenApi\Analysers\ReflectionAnalyser`) que Laravel no puede serializar, y el comando falla duro con ese paquete instalado (se confirmó en pruebas locales: sin este ajuste el contenedor entra en loop de reinicio). `route:cache` y `view:cache` sí se mantienen porque no tienen ese problema.
- **Sin HTTPS todavía.** Si más adelante consiguen un dominio, las dos rutas más simples son: (a) Load Balancer de Lightsail con certificado administrado (~$18/mes extra, cero mantenimiento), o (b) un contenedor Caddy/nginx + certbot en la misma instancia (gratis, pero hay que mantenerlo). Cualquiera de las dos requiere un dominio apuntando a la IP estática.
- **No subas `.env` a git.** Ya está en `.gitignore`; el `.env.docker.example` es la plantilla pública sin secretos reales.
- Si el equipo detecta que una credencial real (por ejemplo, de un MongoDB Atlas usado antes) quedó expuesta en algún `.env` local, rotarla/revocarla independientemente de este despliegue.
