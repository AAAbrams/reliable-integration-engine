<?php

declare(strict_types=1);

namespace App\Domain\Enums;

enum ExternalOperationState: string
{
    /**
     * принята и сохранена, готова к первому запуску
     */
    case Queued = 'queued';
    /**
     * lease выдан worker/poller; выполняется отправка или проверка статуса
     */
    case Running = 'running';
    /**
     * внешний процесс создан, следующая проверка в available_at
     */
    case WaitingRemote = 'waiting_remote';
    /**
     * временная ошибка, ожидается повтор после backoff
     */
    case RetryWait = 'retry_wait';
    /**
     * финальный результат атомарно сохранён; terminal
     */
    case Completed = 'completed';
    /**
     * постоянная ошибка или исчерпанный лимит; terminal
     */
    case Failed = 'failed';
}
