# Reliable Integration Engine (RIE) — MVP Architecture v0

**Дата:** 2026-10-30
**Назначение:** проектная спецификация для старта разработки 31.10.2026.
**Статус:** Approved as MVP baseline; решения ниже — предлагаемые конкретизации исходной концепции, а не уже
реализованный функционал.

## 1. Цель и демонстрационный сценарий

Показать надёжный жизненный цикл долгой внешней операции в PHP backend, когда внешняя система может вернуть `202`,
временную ошибку, timeout при уже принятой операции, или повторить результат.

Сценарий: асинхронная обработка вымышленного документа через **Fake DocumentProcessor**:

1. Клиент отправляет `POST /api/operations` и `Idempotency-Key`.
2. Приложение сохраняет операцию и отвечает `202 Accepted` с её `id`.
3. Worker захватывает запись из PostgreSQL с lease/fencing, затем **вне транзакции** вызывает HTTP Provider c исходным
   ключом.
4. Provider возвращает `202` и свой `provider_operation_id`; операция переходит в `waiting_remote`.
5. Poller узнаёт результат через `GET` у провайдера и атомарно фиксирует терминальное состояние.
6. `GET /api/operations/{id}` позволяет видеть состояние, попытки и безопасный код ошибки.
7. Сбойные сценарии управляются в Fake Provider: 429/503/timeout/malformed/delay/duplicate.

## 2. Что входит / не входит

**MVP:** Laravel 13 + PHP 8.4; PostgreSQL; Docker Compose; API; сохранение запроса и идемпотентность; конечный автомат;
DB scanner и конкурентные workers; lease + fencing; adapter внешнего HTTP API; polling; bounded retry/backoff+jitter;
Fake Provider; unit/integration/e2e tests; README + демонстрация.

**Не входит:** frontend, RabbitMQ, Outbox, webhooks, универсальный provider SDK, микросервисная платформа, Composer
package, ручной replay терминальных операций, реальные внешние/медицинские API.

**Не заявлять exactly-once:** делаем at-least-once attempts + локальную идемпотентность и идемпотентность demo provider.
Реальный провайдер без idempotency не исключает дублей побочных эффектов.

## 3. Модули и границы

```text
POST/GET HTTP API, Artisan CLI
       |
Application: SubmitOperation / ExecuteOperation / PollOperation
       |
Domain: Operation, TransitionPolicy, FailureClassifier, RetryPolicy
       |
Ports: OperationRepository / ExternalOperationGateway / Clock
       |
Adapters: PostgreSQL repository / HTTP FakeProvider gateway
       |
PostgreSQL         Fake DocumentProcessor HTTP service
```

Первый релиз — **один Laravel-репозиторий**; Fake Provider — отдельный HTTP процесс или контейнер того же
demo-окружения. Разделение по ответственности, без преждевременного выделения library и сложной микросервисной
инфраструктуры.

## 4. Состояния и переходы

| State            | Смысл                                                                |
|------------------|----------------------------------------------------------------------|
| `queued`         | принята и сохранена, готова к первому запуску                        |
| `running`        | lease выдан worker/poller; выполняется отправка или проверка статуса |
| `waiting_remote` | внешний процесс создан, следующая проверка в `available_at`          |
| `retry_wait`     | временная ошибка, ожидается повтор после backoff                     |
| `completed`      | финальный результат атомарно сохранён; terminal                      |
| `failed`         | постоянная ошибка или исчерпанный лимит; terminal                    |

**Предлагаемые разрешённые переходы:**

```text
queued        --claim--> running
retry_wait    --claim due--> running
waiting_remote --claim poll due--> running
running       --remote accepted/pending--> waiting_remote
running       --transient failure--> retry_wait
running       --definitive result--> completed
running       --permanent failure / exhausted budget--> failed
running       --lease expired--> reclaimed by scanner (new token)
```

`running` используется и для submit, и для poll; различение — наличием `provider_operation_id` / типом выполняемого
шага. Новая версия worker никогда не должна переносить старый результат по чужому fencing token. При истечении lease
требуется новый claim с новым токеном.

## 5. Инварианты и идемпотентность

- Уникальность в PostgreSQL: `(client_id, idempotency_key)`.
- При повторе того же ключа и **канонически эквивалентного** запроса выдавать тот же `operation.id`, не создавать новую
  запись.
- При том же ключе и другом `request_hash` возвращать `409 Conflict`.
- В MVP `client_id` может быть фиксированным именем demo-клиента на стороне приложения; не полагаться на клиентский
  заголовок как на доверенный identity. Авторизация нескольких клиентов — вне MVP.
- Хэш строится по каноническому представлению `type` + `payload` (детерминированный порядок ключей объектов и
  однозначное JSON-кодирование). Массивы сохраняют порядок. Снимок запроса после создания не меняется.
- У провайдера на submit всегда используется тот же внешний `Idempotency-Key`; если HTTP timeout произошёл **после
  принятия** запроса, повтор должен вернуть тот же remote operation ID, а не создать второй.
- HTTP вызов провайдера никогда не выполняется под открытой DB-транзакцией.
- Коммит результата / смена state допускаются только владельцу актуального `lock_token`, пока lease ещё действует. Сами
  операции изменения — атомарны.
- Retry ограничивается числом попыток; 429 (`Retry-After`) и 503 считаются временными, типичная 400 — постоянной.
  Malformed response — контролируемая ошибка с явной политикой, не успешное завершение.
- Payload и токены не записываются в лог; наружу выходят только безопасные коды ошибок.

## 6. Предварительная схема PostgreSQL

`external_operations`

| Поле                                       | Назначение                                   |
|--------------------------------------------|----------------------------------------------|
| `id` UUID PK                               | публичный ID операции                        |
| `client_id` string                         | scope идемпотентности                        |
| `idempotency_key` string                   | ключ клиента                                 |
| `request_hash` char(64)                    | SHA-256 канонического запроса                |
| `request_snapshot` jsonb                   | immutable вход (только синтетические данные) |
| `state` string                             | explicit state machine                       |
| `provider_operation_id` string NULL        | remote ID, если уже известен                 |
| `attempts` integer                         | число попыток обработки                      |
| `available_at` timestamptz                 | следующая доступность для worker/poll        |
| `lock_token` UUID NULL                     | fencing token текущего claim                 |
| `lease_expires_at` timestamptz NULL        | срок владения                                |
| `result` jsonb NULL                        | финальный результат                          |
| `error_code` string NULL                   | безопасный код последней ошибки              |
| `created_at`, `updated_at`, `completed_at` | аудит времени                                |

Индексы: `UNIQUE(client_id, idempotency_key)`, `(state, available_at)`, `(state, lease_expires_at)`.

`operation_attempts`: `id`, `operation_id`, `sequence`, `phase` (`submit`/`poll`), `outcome`, `error_code`,`started_at`,
`finished_at`, `duration_ms`. Не хранить sensitive bodies. История `operation_events` опциональна и не блокирует MVP.

Возможные решения при реализации: короткие транзакции + `FOR UPDATE SKIP LOCKED` для claim; дальнейшие
`UPDATE ... WHERE id = ? AND lock_token = ? AND lease_expires_at > now()` для CAS/fencing. При восстановлении учитывать,
что в `running` может быть `provider_operation_id` уже созданного провайдером процесса.

## 7. Черновой HTTP контракт

**POST `/api/operations`**

```http
Idempotency-Key: demo-doc-001
Content-Type: application/json
```

```json
{
    "type": "document_processing",
    "payload": {
        "document_type": "invoice",
        "document_ref": "DEMO-001"
    }
}
```

Ответ: `202 Accepted`; повтор идентичного запроса по тому же ключу возвращает тот же `id`; конфликт payload — `409`.

```json
{
    "id": "<uuid>",
    "state": "queued",
    "status_url": "/api/operations/<uuid>"
}
```

**GET `/api/operations/{id}`**

```json
{
    "id": "<uuid>",
    "state": "waiting_remote",
    "attempts": 2,
    "available_at": "<iso8601>",
    "completed_at": null,
    "error_code": null,
    "result": null
}
```

Контракт ответа с `completed` включает безопасный `result`. Сложные параметры auth/multi-tenancy исключены из demo;
`client_id` определяется сервером. Валидация входа должна быть ограниченной и предсказуемой.

**Предлагаемый Fake Provider adapter** (контракт уточняется перед реализацией):

- `POST /provider/operations` с внешним `Idempotency-Key` → `202` с `{ "id": "<remote-id>" }`.
- `GET /provider/operations/{id}` → `{ "state": "pending" | "completed" | "failed", "result": ... }`.
- Сценарии сбоев задаются demo CLI/endpoint и детерминированы для воспроизводимого тестирования.

## 8. Проверки, обязательные для демо

1. Повтор `POST` с тем же ключом / payload → один operation ID; другой payload → 409.
2. Два worker одновременно пытаются забрать одну операцию → один текущий DB owner.
3. Timeout **после принятия** provider → retry не создаёт дублированную внешнюю обработку в Fake Provider.
4. Истёкший lease / crashed worker → scanner восстанавливает работу; устаревший token не пишет результат.
5. 429 с `Retry-After`, 503, 400, malformed response → детерминированная классификация / bounded attempts.
6. Два poller и повтор remote result → итог сохраняется однократно.
7. Как минимум **один реальный e2e тест** с PostgreSQL + Fake Provider, не только моки.

## 9. План до MVP

| Дата      | Приоритетный результат                                                                         |
|-----------|------------------------------------------------------------------------------------------------|
| **30.10** | архитектура v0: scope, состояния, инварианты, данные, контракты (этот документ)                |
| **31.10** | Git repository, Laravel skeleton, Docker Compose skeleton, скелет README, backlog и тест-кейсы |
| **01.11** | основной фокус — Hexlet 7 и подготовка к интервью, без обязательного RIE-блока                 |
| **02.11** | V0: миграции + POST/GET + локальная идемпотентность; интервью в 11:00                          |
| **03.11** | worker, конкурентный claim, lease/fencing, recovery                                            |
| **04.11** | короткий блок (~75 мин): Fake Provider, базовый HTTP адаптер; HR-интервью в 13:00              |
| **05.11** | retry/polling, end-to-end resilience tests, README, walkthrough и демонстрация                 |

**Риск:** несколько критических reliability-механизмов и e2e тесты оказываются на последнем дне, а 02.11 и 04.11 заняты
интервью. Оценить прогресс 31.10 и в конце 03.11; при отставании урезать вспомогательную observability/косметику, но не
обещать полный reliability MVP без проверяемых гарантий. Deadline пока сохранён 05.11.

## 10. Definition of Done для архитектуры 30.10

- [x] Сформулирован scope MVP и явные исключения.
- [x] Описан happy path с Fake DocumentProcessor.
- [x] Зафиксированы state machine и переходы, в том числе retry/poll.
- [x] Определены идемпотентность, транзакционные границы и leasing/fencing.
- [x] Намечены схема данных PostgreSQL и два основных API endpoint.
- [x] Перечислены ключевые failure scenarios и ожидаемые проверки.
- [x] Спецификация размещена в репозитории.
- [x] Спецификация утверждена как исходная архитектурная
  основа MVP (`Approved as MVP baseline`).

---

**Происхождение:** основано на технической концепции `reliable-integration-reference-concept.md` от 27.10.2026 и
планировании RIE на 30.10–05.11.2026. Здесь приведены предлагаемые конкретизации контракта; отдельный код / сторонние
бизнес-модели не заимствуются.
