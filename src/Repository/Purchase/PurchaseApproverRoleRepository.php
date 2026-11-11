<?php

declare(strict_types=1);

namespace App\Repository\Purchase;

use App\Entity\Purchase\PurchaseApproverRole;
use App\Enum\Purchase\PurchaseRoleCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PurchaseApproverRole>
 */
class PurchaseApproverRoleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PurchaseApproverRole::class);
    }

    /**
     * Роли модуля, выданные этому человеку. Строк на человека единицы, поэтому
     * берём связки целиком и разворачиваем в коды на месте.
     *
     * @return list<PurchaseRoleCode>
     */
    public function findRoleCodesForUser(int $userId): array
    {
        /** @var list<PurchaseApproverRole> $links */
        $links = $this->createQueryBuilder('ar')
            ->innerJoin('ar.approver', 'a')
            ->andWhere('a.userId = :user')
            ->setParameter('user', $userId)
            ->getQuery()
            ->getResult();

        $codes = [];
        foreach ($links as $link) {
            $code = $link->getRoleCode();
            if ($code !== null) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * id носителей перечисленных ролей — для рассылки уведомлений на ролевой шаг.
     * Дубли (человек с двумя ролями из списка) снимает DISTINCT.
     *
     * @param list<PurchaseRoleCode> $codes
     * @return list<int>
     */
    public function findUserIdsByRoleCodes(array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('ar')
            ->select('DISTINCT a.userId')
            ->innerJoin('ar.approver', 'a')
            ->andWhere('ar.roleCode IN (:codes)')
            ->setParameter(
                'codes',
                array_map(static fn (PurchaseRoleCode $code): string => $code->value, $codes),
                ArrayParameterType::STRING,
            )
            ->getQuery()
            ->getSingleColumnResult();

        return array_map('intval', $rows);
    }
}
