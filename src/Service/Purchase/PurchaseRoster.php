<?php

declare(strict_types=1);

namespace App\Service\Purchase;

use App\Security\JwtUser;
use App\Enum\Purchase\PurchaseCapability;
use App\Enum\Purchase\PurchaseRoleCode;
use App\Repository\Purchase\PurchaseApproverRoleRepository;

/**
 * Состав модуля закупок: какие роли у человека и что эти роли позволяют.
 *
 * Единственное место, которое отвечает на вопрос «имеет ли право» вне маршрута.
 * Глобальных ROLE_PURCHASE_* больше нет: они были вторым источником правды —
 * роль в security.yaml и роль модуля неизбежно разъезжались, и никто не мог
 * сказать, какая из них настоящая.
 *
 * Что роль позволяет, знает PurchaseRoleCode; кому она выдана — база. Отсюда
 * и разделение: запрос к базе тут один, остальное — арифметика по enum.
 *
 * ROLE_ADMIN проходит все полномочия, как в канбане, и это не удобство, а
 * страховка: если полномочие «конвейер исполнения» не окажется ни у кого,
 * оплаченные заявки встанут насмерть, и разжать модуль будет некому.
 *
 * Адресность шага админ при этом НЕ обходит: иначе любой шаг стал бы для него
 * «моим», список превратился бы в кашу, а подпись — в подпись неизвестно за
 * кого. Застрявший шаг админ разжимает, выдав себе роль явно.
 *
 * Роли пользователя кешируются на запрос: presenter спрашивает их у каждой
 * строки списка, а список — это двадцать строк на страницу.
 */
final class PurchaseRoster
{
    /** @var array<int, list<PurchaseRoleCode>> роли по id пользователя */
    private array $rolesByUser = [];

    public function __construct(
        private readonly PurchaseApproverRoleRepository $approverRoleRepo,
    ) {
    }

    /**
     * Роли модуля, выданные этому человеку.
     *
     * @return list<PurchaseRoleCode>
     */
    public function rolesOf(int $userId): array
    {
        if ($userId === 0) {
            return [];
        }

        return $this->rolesByUser[$userId] ??= $this->approverRoleRepo->findRoleCodesForUser($userId);
    }

    /**
     * Коды ролей строками — для запросов, которые отбирают ролевые шаги.
     *
     * @return list<string>
     */
    public function roleCodesOf(int $userId): array
    {
        return array_values(array_map(
            static fn (PurchaseRoleCode $code): string => $code->value,
            $this->rolesOf($userId),
        ));
    }

    public function hasRole(int $userId, PurchaseRoleCode $code): bool
    {
        return in_array($code, $this->rolesOf($userId), true);
    }

    /** Полномочие есть хотя бы у одной из его ролей. ROLE_ADMIN — всегда. */
    public function can(JwtUser $user, PurchaseCapability $capability): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        foreach ($this->rolesOf($user->getId()) as $code) {
            if ($code->hasCapability($capability)) {
                return true;
            }
        }

        return false;
    }

    /**
     * id носителей роли — кому уходит уведомление о ролевом шаге маршрута.
     *
     * @return list<int>
     */
    public function usersOfRole(?PurchaseRoleCode $code): array
    {
        return $code === null ? [] : $this->approverRoleRepo->findUserIdsByRoleCodes([$code]);
    }

    /**
     * Носители полномочия — кому уходят уведомления «модулю», а не шагу:
     * новая заявка, отмена, готовность к оплате.
     *
     * @return list<int> id пользователей
     */
    public function usersWith(PurchaseCapability $capability): array
    {
        return $this->approverRoleRepo->findUserIdsByRoleCodes(
            PurchaseRoleCode::withCapability($capability),
        );
    }

    /**
     * Проверяем по самому пользователю, а не через Security: roster нужен и в
     * консоли, и в обработчике сообщений, где текущего токена нет.
     */
    public function isAdmin(JwtUser $user): bool
    {
        return in_array('ROLE_ADMIN', $user->getRoles(), true);
    }
}
