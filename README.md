# Микросервис закупок

## Установка в docker

Все команды на хосте — из корня проекта.

### 1. Файл .env

```bash
cp .env.example .env
```

### 2. Сборка

```bash
docker compose build
```

### 3. Запуск

```bash
docker compose up -d
```

### 4. Вход в контейнер

```bash
docker exec -it php_purchase bash
```

### 5. Зависимости

```bash
composer install
```

### 6. Права

```bash
chown -R 1000:1000 /var/www/html
```

```bash
chmod -R 777 var
```

### 7. Миграции

```bash
php bin/console doctrine:migrations:migrate
```

### 8. dbgate

```bash
docker compose -f docker-compose.dbgate.yml up -d
```

http://localhost:8094

## Xdebug

В `docker_env/php/conf.d/xdebug.ini` заменить `xdebug.mode=off` на `xdebug.mode=debug`. Перезапустить контейнер.

Сервер — `purchase`, путь на сервере — `/var/www/html`.

## Команды

```bash
php bin/console doctrine:migrations:diff
```

```bash
php bin/console doctrine:migrations:migrate
```

```bash
php bin/console debug:router
```
