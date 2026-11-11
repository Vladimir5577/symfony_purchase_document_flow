<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Message\NotificationMessage;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Отправка уведомлений в сервис нотификаций — копия публикатора монолита.
 * Получатели и актор — id: таблицы пользователей у сервиса нет.
 *
 * Один публикатор на все модули — намеренно. Отдельные NotificationPublisher
 * на каждый модуль воспроизвели бы ровно ту окаменелость, из-за которой
 * появился прежний NotificationService с методом на каждый случай
 * (notifyDocumentSent рядом с notifyNewIncomingDocument): текст, получатели и
 * транспорт в одном методе, и на каждое новое событие — ещё один метод.
 *
 * Здесь транспорт отделён от смысла. Кто именно получатель и как звучит
 * заголовок — знает модуль, он и передаёт готовое.
 */
final class NotificationPublisher
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {}

    /**
     * @param string               $module     модуль-источник: purchase, document, inventory…
     * @param string               $event      событие модуля: submitted, incoming, comment_added…
     * @param iterable<int|null>     $recipients id тех, кому показать; актор отсеивается, повторы схлопываются
     * @param string               $title      готовый заголовок
     * @param string|null          $link       маршрут SPA (именно SPA, не Twig-маршрут монолита)
     * @param string               $typeLabel  подпись категории для списка уведомлений
     * @param string|null          $message    дополнительный текст
     * @param array<string, mixed> $data       произвольные данные, уезжают в extra
     */
    public function publish(
        string $module,
        string $event,
        iterable $recipients,
        string $title,
        ?string $link = null,
        ?int $actorId = null,
        string $typeLabel = '',
        ?string $message = null,
        array $data = [],
    ): void {
        // Ключом по id: один пользователь мог попасть в список дважды —
        // например, и как участник, и как администратор.
        $ids = [];
        foreach ($recipients as $id) {
            if ($id !== null && $id !== $actorId) {
                $ids[$id] = $id;
            }
        }

        // Некому показывать — незачем и публиковать.
        if ($ids === []) {
            return;
        }

        $this->messageBus->dispatch(
            new NotificationMessage(
                eventId: Uuid::v7()->toRfc4122(),
                recipients: array_values($ids),
                title: $title,
                typeLabel: $typeLabel,
                message: $message,
                link: $link,
                actorId: (int) $actorId,
                data: $data,
            ),
            [new AmqpStamp($module . '.notification.' . $event)],
        );
    }
}
