# Reliable Integration Engine (RIE)

[![CI](https://github.com/AAAbrams/reliable-integration-engine/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/AAAbrams/reliable-integration-engine/actions/workflows/ci.yml?query=branch%3Amain)

RIE — оригинальная эталонная реализация на Laravel для надёжного выполнения долгих внешних операций.
Проект должен показать, как сохранять запросы, обрабатывать неопределённый результат HTTP вызова,
повторять попытки и восстанавливать обработку после сбоев.

## Текущий статус

**Проект находится на стадии подготовки к реализации MVP.** Доменные механизмы RIE пока не реализованы.

В репозитории уже есть:

- Laravel skeleton со стандартной welcome page `/` и настроенным health route `/up`;
- стандартные миграции Laravel для пользователей, sessions, cache и jobs;
- миграции RIE для `external_operations` и `operation_attempts`;
- конфигурация Pest, Laravel Pint и PHPStan с Larastan;
- шаблонный Unit-тест, smoke test подключения и интеграционные тесты схемы RIE на отдельной PostgreSQL;
- Docker Compose с PHP FPM, Nginx, PostgreSQL 18 и тестовой PostgreSQL;
- workflow GitHub Actions для проверки качества кода и тестов;
- архитектурная спецификация и шаблон журнала решений.

API операций, доменные модели, PostgreSQL workers и Fake Provider ещё предстоит реализовать.
Наличие стандартной Laravel queue не означает реализации DB scanner или lease/fencing.

## Проблема и демонстрационный сценарий

Внешняя система может принять запрос и вернуть `202 Accepted`, временно отказать или вызвать timeout уже после
принятия операции. Повтор запроса в такой ситуации способен создать дублирующий побочный эффект.
Поздний или повторный результат также нужно согласовать с локальным состоянием.

**Планируемое демо:** асинхронная обработка вымышленного документа в сценарии **DocumentProcessor**.
Его реализует **Fake Provider** — демонстрационный внешний HTTP-сервис.

1. Клиент отправляет запрос с `Idempotency-Key`; RIE сохраняет операцию и возвращает её `id`.
2. Worker захватывает запись в PostgreSQL с lease/fencing и вызывает провайдера вне DB-транзакции.
3. После принятия запроса провайдером RIE сохраняет `provider_operation_id` и ожидает результат.
4. Poller проверяет внешний статус; RIE атомарно сохраняет терминальное состояние.
5. Клиент получает состояние, число попыток, результат или безопасный код ошибки.

В демо предусмотрены 429/503, timeout, malformed response, задержки и повторные результаты.
Endpoints `POST /api/operations` и `GET /api/operations/{id}` пока являются планируемым контрактом.
Подробности приведены в [архитектурной спецификации](docs/RIE-MVP-architecture-v0.md).

## Архитектурная концепция

Целевой MVP — один Laravel-репозиторий с разделением по ответственности:

- **Application:** `SubmitOperation`, `ExecuteOperation`, `PollOperation`;
- **Domain:** `Operation`, `TransitionPolicy`, `FailureClassifier`, `RetryPolicy`;
- **Ports:** `OperationRepository`, `ExternalOperationGateway`, `Clock`;
- **Adapters:** PostgreSQL repository и HTTP gateway для Fake Provider.

Эти компоненты пока описаны только в спецификации. PostgreSQL должен хранить состояние операций и данные для
DB scanner, workers и poller. Fake Provider планируется как отдельный HTTP процесс или контейнер
того же demo-окружения.

Модули, конечный автомат, схема данных и контракты описаны в
[MVP Architecture v0](docs/RIE-MVP-architecture-v0.md).

## Целевые гарантии и ограничения

Следующие свойства являются требованиями MVP и пока не подтверждены реализацией и тестами:

- Локальная идемпотентность в рамках `(client_id, idempotency_key)`: канонически эквивалентный запрос возвращает
  тот же `operation.id`; другой `request_hash` с тем же ключом приводит к `409 Conflict`.
- Неизменяемый снимок запроса и повторное использование исходного внешнего `Idempotency-Key`.
- Короткие DB-транзакции; HTTP вызовы провайдера выполняются вне транзакции.
- Атомарная запись результата только владельцем актуального `lock_token` с действующим lease.
- Восстановление обработки после истечения lease, bounded retry с backoff+jitter и polling.
- Безопасные коды ошибок; payload и токены не записываются в лог.

Модель выполнения — **at-least-once attempts** с локальной идемпотентностью и идемпотентностью Fake Provider.
**Exactly-once не заявляется:** реальный провайдер без idempotency не исключает повторных побочных эффектов.

В MVP не входят frontend, RabbitMQ, Outbox, webhooks, универсальный provider SDK, микросервисная платформа,
Composer package, ручной replay терминальных операций и реальные внешние/медицинские API.
Авторизация нескольких клиентов также вне MVP; для демо предусмотрены синтетические данные.

## Технологический стек

| Компонент     | Текущее состояние и цель                                                                                                                              |
|---------------|-------------------------------------------------------------------------------------------------------------------------------------------------------|
| PHP           | Целевая версия разработки — 8.4; требования к patch-релизу определяются зависимостями. Совместимость полного dev-окружения с PHP 8.3 не подтверждена. |
| Laravel       | Laravel 13.                                                                                                                                           |
| База данных   | PostgreSQL 18; соединение Laravel и сервер используют UTC.                                                                                  |
| Окружение     | Docker Compose: app, nginx, postgres и postgres-test (profile test).                                                                                        |
| Тестирование  | Pest 5; отдельная PostgreSQL, параметры закреплены в phpunit.xml.                                                                                   |
| Качество кода | Laravel Pint, PHPStan с Larastan, уровень 8; правила отступов и окончаний строк — в `.editorconfig`.                                                  |
| Assets        | Vite и Tailwind CSS из Laravel skeleton; frontend RIE не входит в MVP.                                                                                |

Требования и scripts: [composer.json](composer.json), [package.json](package.json).
Точные версии PHP-зависимостей зафиксированы в [composer.lock](composer.lock).

## Локальный запуск

Для запуска нужны Docker Engine / Docker Desktop и современный Docker Compose. PHP, Composer и инструменты
качества запускаются в PHP-контейнере; Node.js для текущей welcome page не требуется.
Полного demo-окружения RIE ещё нет. Команды выполняются из корня репозитория.

1. Если `.env` отсутствует, скопируйте `.env.example` в `.env`. Существующий `.env` не перезаписывайте.
   Укажите `DB_CONNECTION=pgsql`, `DB_HOST=postgres`, `DB_PORT=5432`, `DB_DATABASE=rie`, `DB_USERNAME=rie`,
   локальный `DB_PASSWORD`; для запуска без миграций — `SESSION_DRIVER=file`, `CACHE_STORE=file`,
   `QUEUE_CONNECTION=sync`. Удалите устаревший `DB_URL`, если он задан.
   Демонстрационные пароли предназначены только для локальной разработки; реальные секреты храните в `.env` вне Git.
2. На Linux задайте в `.env` `LOCAL_UID` и `LOCAL_GID` из вывода `id -u` и `id -g` владельца checkout.
   По умолчанию используются `1000:1000`; Docker Desktop на macOS обычно не требует изменения.
   PHP FPM и CLI работают от `www-data` с этими ID. Пользователю нужны права записи в `storage` и
   `bootstrap/cache`; права `777` не требуются. После изменения ID пересоберите image.
3. Запустите контейнеры и подготовьте Laravel:

```bash
docker compose config --quiet
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan config:clear
# Только если APP_KEY ещё пуст:
docker compose exec app php artisan key:generate
docker compose exec app php artisan about
```

`composer install` использует существующий lock-файл. Зависимости находятся в `vendor` bind mount,
поэтому доступны CLI и FPM. Image содержит Composer 2, PostgreSQL extensions, `pcntl` и расширения для
Laravel/Pest. Системные пакеты `libpq-dev`, `libonig-dev`, `libxml2-dev` нужны для сборки расширений;
`git` и `unzip` — для установки Composer dependencies. Новые Composer dependencies не добавляются.
Image можно использовать позднее для worker/scanner, переопределив команду; сами процессы ещё не реализованы.

Откройте [Laravel](http://localhost:8080) или проверьте HTTP:

```bash
curl --fail http://127.0.0.1:8080/
docker compose exec nginx nginx -t
docker compose ps
```

Порт задаётся `HTTP_PORT` в `.env`; при изменении согласуйте `APP_URL` и пересоздайте nginx через
`docker compose up -d nginx`. Порт слушает только `127.0.0.1`. PostgreSQL и FPM не публикуются на хост;
сервисы общаются через внутреннюю сеть Compose.

Проверка реального подключения **через Laravel**, без миграций:

```bash
docker compose exec app php artisan tinker --execute='dump(DB::selectOne("SELECT 1 AS result")); dump(DB::selectOne("SHOW TIME ZONE"));'
```

Основные данные хранятся в named volume `postgres-data`, тестовые — в `postgres-test-data`.
Для PostgreSQL 18 volumes монтируются в `/var/lib/postgresql`, стандартный PGDATA —
`/var/lib/postgresql/18/docker`. Смена `DB_PASSWORD` в `.env` не меняет пароль уже созданного DB-пользователя;
переменные `POSTGRES_*` применяются при первоначальной инициализации пустого volume.

Миграции при старте не выполняются. Чтобы создать таблицы Laravel и RIE в локальной БД приложения,
проверьте целевое подключение и выполните:

```bash
docker compose exec app php artisan migrate
```

**Не используйте `composer setup` для этого этапа:** существующий script устанавливает зависимости,
создаёт `.env`, генерирует ключ, выполняет миграции с `--force`, затем устанавливает npm dependencies и собирает assets.
Перед любым будущим запуском проверьте целевую БД: миграции применяются без подтверждения.

Остановка с сохранением обеих БД:

```bash
docker compose --profile test down
```

Повторный `docker compose up -d` использует сохранённые данные.
**Осторожно: следующая команда безвозвратно удаляет оба volumes и все данные основной и тестовой БД:**

```bash
docker compose --profile test down --volumes
```

Команды для workers, poller и Fake Provider появятся после их реализации.

## Тестирование и инструменты качества

Команды определены в [composer.json](composer.json):

| Команда            | Действие                                                         |
|--------------------|------------------------------------------------------------------|
| `composer lint`    | Проверка форматирования: `pint --test`.                          |
| `composer format`  | Форматирование PHP-кода через Pint; изменяет файлы.              |
| `composer analyse` | PHPStan с Larastan; анализ `app`, `routes`, `tests` на уровне 8. |
| `composer test`    | Очистка config cache и запуск тестов через Artisan.              |
| `composer check`   | Последовательный запуск lint, analyse и test.                    |

В [phpunit.xml](phpunit.xml) настроены suites Unit и Feature, синхронная очередь и значения подключения
по умолчанию: `postgres-test:5432/rie_test`, пользователь `rie_test`, пароль `rie-test-only`.
Это публичные демонстрационные значения исключительно для изолированного локального тестового сервиса.
Переменные окружения процесса `DB_*` имеют приоритет над этими значениями; параметры из локального `.env`
не заменяют уже заданные тестовые значения. `DB_URL` принудительно обнулён, чтобы URL основной БД
не подменил тестовое подключение. Перед запуском не экспортируйте параметры основной БД в процесс тестов.
Дополнительный `.env.testing` не требуется.
Smoke test проверяет фактический PDO-драйвер `pgsql`, `SELECT 1`, тестовую БД `rie_test` и UTC,
без привязки к имени хоста, `RefreshDatabase` и создания таблиц.

[Интеграционные тесты схемы](tests/Feature/Database) проверяют значения по умолчанию, ограничения таблиц
и каскадное удаление попыток. Они используют [DatabaseTestCase](tests/DatabaseTestCase.php) с `RefreshDatabase`.
Перед обновлением схемы базовый класс требует окружение `testing`, подключение `pgsql` и проверяет,
что PostgreSQL сообщает имя БД и роль `rie_test`. При несовпадении тест останавливается до миграций.

**Для тестов схемы используйте отдельную расходную БД.** При первом тесте с `RefreshDatabase` Laravel
выполняет `migrate:fresh`, который удаляет существующие таблицы целевой БД и заново применяет миграции.
Затем каждый тест выполняется в транзакции, которую Laravel откатывает по завершении.

```bash
docker compose --profile test up -d --wait postgres-test
docker compose exec app composer check
# Отдельные проверки:
docker compose exec app php artisan test --group=integration
docker compose exec app composer test
docker compose exec app composer lint
docker compose exec app composer analyse
# Форматирование изменяет PHP-файлы:
docker compose exec app composer format
```

Перед тестами не используйте production config cache; `composer test` очищает его автоматически.
Тестовый сервис запускается только с profile `test` (или при явном указании сервиса).

Для MVP запланированы Unit, интеграционные и e2e тесты, включая проверки конкурентного доступа
на реальном PostgreSQL и как минимум один реальный e2e тест с PostgreSQL и Fake Provider.
Обязательные сбойные сценарии перечислены в [спецификации](docs/RIE-MVP-architecture-v0.md).
До добавления тестов схемы были проверены сборка и запуск контейнеров, HTTP 200, `artisan about`,
Laravel `SELECT 1` и UTC. Установка dependencies с чистого checkout отдельно не проверялась.
Проверки использовали существующий `vendor`.
При подготовке CI повторно прошли `composer install` с существующим `vendor`, `composer validate --strict`,
`composer check-platform-reqs` и `composer check` в `app`-контейнере. Отдельно прошёл smoke test
с IP-адресом тестового контейнера через `DB_HOST` и посторонним `DB_URL`, который тестовая конфигурация обнуляет.

## Continuous Integration

Workflow [CI](.github/workflows/ci.yml) запускается при push в `main`, pull request в `main`
и вручную через `workflow_dispatch`. Один job на Ubuntu имеет лимит 15 минут и permission `contents: read`.
PHP 8.4 с `pdo_pgsql` и Composer 2 запускаются непосредственно на runner; PostgreSQL 18 — отдельный
service container с healthcheck, без постоянного volume и без секретов разработки или production.

Именованные шаги проверяют `composer validate --strict`, устанавливают dev-зависимости через
`composer install` из lock-файла с обычными Composer scripts, проверяют платформу и неизменность lock-файла,
очищают Laravel config cache, запускают `composer lint`, `composer analyse` и `composer test` (Pest).
Кешируются только скачанные Composer-архивы; `vendor` и данные PostgreSQL не сохраняются.
Тесты схемы применяют миграции через `RefreshDatabase` в шаге `composer test`. Отдельного шага миграций
в workflow нет. Сборка frontend для этих тестов не требуется.

Для первого запуска создайте pull request в `main` с workflow, откройте вкладку **Checks** либо
**Actions → CI → Code quality and tests** и проверьте результат каждого шага, включая интеграционный smoke test.
После появления workflow в основной ветке доступен **Actions → CI → Run workflow**.
Само наличие файла workflow не подтверждает успешный запуск на GitHub.

При ошибке откройте первый упавший шаг и повторите соответствующую Composer-команду локально
в `app`-контейнере; полный аналог проверок качества — `docker compose exec app composer check`
после запуска `postgres-test`, как описано выше. Для ошибок установки проверьте lock-файл и
`composer check-platform-reqs`. Для ошибок подключения изучите healthcheck service container,
наличие `pdo_pgsql`, адрес БД и приоритет настроек тестового окружения.
В Actions ожидается `127.0.0.1:5432/rie_test`, пользователь `rie_test`, временный пароль `ci_test_password`;
в Compose — `postgres-test:5432` с локальным тестовым паролем. Не подставляйте параметры основной БД.
CI использует переменные окружения и временный APP_KEY, без копирования `.env` разработчика.
Workflow проверен через `actionlint`; реальный запуск на GitHub Actions ещё не проверен.

## Roadmap

Этапы перечислены в порядке [плана до MVP](docs/RIE-MVP-architecture-v0.md), без дополнительной нумерации.

| Этап                                    | Содержание                                                                                   | Статус                                                                                                                                                      |
|-----------------------------------------|----------------------------------------------------------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Подготовка репозитория                  | Laravel skeleton, Docker Compose skeleton, первоначальный README, backlog и тест-кейсы.      | Частично готово: skeleton, Docker Compose, настройки качества и README есть; отдельного backlog и тестов бизнес-логики RIE нет. Сценарии проверок описаны в спецификации. |
| API и локальная идемпотентность         | Миграции операций, POST/GET API, локальная идемпотентность.                                  | Миграции и тесты схемы добавлены. API и обработка повторных запросов запланированы.                                                                            |
| Конкурентная обработка и восстановление | Worker, конкурентный claim, lease/fencing, recovery.                                         | Запланировано.                                                                                                                                              |
| Интеграция с Fake Provider              | Fake Provider и базовый HTTP adapter.                                                        | Запланировано.                                                                                                                                              |
| Проверка надёжности и демонстрация      | Retry/polling, end-to-end resilience tests, актуализация README, walkthrough и демонстрация. | Запланировано.                                                                                                                                              |

Календарный план в спецификации не является подтверждением выполнения этапов.

## Документация и правила работы

- [Текущая архитектурная спецификация MVP](docs/RIE-MVP-architecture-v0.md) — scope, сценарий, архитектура, инварианты,
  данные, черновые HTTP контракты и обязательные проверки.
- [Журнал решений](docs/DECISIONS.md) — фиксация изменений и уточнений архитектуры; сейчас содержит шаблон записи.
- [AGENTS.md](AGENTS.md) — правила разработки и взаимодействия с AI-агентами.

## Лицензия

Проект распространяется по лицензии [MIT](LICENSE).
