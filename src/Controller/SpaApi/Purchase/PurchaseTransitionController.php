<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Purchase;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Purchase\PurchaseApprovalStage;
use App\Entity\Purchase\PurchaseApprovalTask;
use App\Entity\Purchase\PurchaseRequest;
use App\Security\JwtUser;
use App\Service\Directory\DirectoryClient;
use App\Enum\Purchase\PurchaseStagePurpose;
use App\Repository\Purchase\PurchaseRequestRepository;
use App\Service\Purchase\PurchaseAccess;
use App\Service\Purchase\PurchaseApiPresenter;
use App\Service\Purchase\PurchaseApprovalWorkflow;
use App\Service\Purchase\PurchaseTransitionException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Движение заявки: подача и решения по задачам маршрута.
 *
 * Право решить не выводится из статуса и зашитого списка ролей — оно читается из
 * самой задачи: кому она адресована, тот и решает. Поэтому ни новый согласующий,
 * ни перенос оплаты на другого человека не требуют правок здесь.
 *
 * Отдельных ручек под конвейер исполнения нет: оплата и поставка — те
 * же задачи маршрута, и закрываются той же кнопкой, что подпись бухгалтерии.
 */
#[Route('/spa/api/purchases/{id}', requirements: ['id' => '\d+'])]
final class PurchaseTransitionController extends AbstractController
{
    public function __construct(
        private readonly PurchaseRequestRepository $purchaseRepo,
        private readonly DirectoryClient $directory,
        private readonly PurchaseApprovalWorkflow $workflow,
        private readonly PurchaseApiPresenter $presenter,
        private readonly PurchaseAccess $access,
    ) {
    }

    /** Подать заявку: автор, из редактируемого статуса. Маршрут соберётся сам. */
    #[Route('/submit', name: 'spa_api_purchases_submit', methods: ['POST'])]
    public function submit(int $id, #[CurrentUser] ?JwtUser $user): JsonResponse
    {
        return $this->transition($id, $user,
            fn (PurchaseRequest $p, JwtUser $u) => $this->access->isOwner($p, $u),
            fn (PurchaseRequest $p, JwtUser $u) => $this->workflow->submit($p, $u));
    }

    /** Кнопка редактора: рецензия пройдена или рецензии утверждены. */
    #[Route('/tasks/{taskId}/contract-review', name: 'spa_api_purchases_task_contract_review', requirements: ['taskId' => '\d+'], methods: ['POST'])]
    public function passContractReview(int $id, int $taskId, Request $request, #[CurrentUser] ?JwtUser $user): JsonResponse
    {
        // body: {fileId} — какой договор. Без него — единственный договор заявки.
        $payload = json_decode($request->getContent(), true);
        $fileId = is_array($payload) && is_int($payload['fileId'] ?? null) ? $payload['fileId'] : null;

        return $this->taskAction($id, $taskId, $user,
            fn (PurchaseRequest $p, PurchaseApprovalTask $t) => $this->workflow->passContractReview($p, $t, $fileId));
    }

    /** Закрыть свою задачу согласием. */
    #[Route('/tasks/{taskId}/approve', name: 'spa_api_purchases_task_approve', requirements: ['taskId' => '\d+'], methods: ['POST'])]
    public function approveTask(int $id, int $taskId, Request $request, #[CurrentUser] ?JwtUser $user): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $comment = is_array($payload) ? trim((string) ($payload['comment'] ?? '')) : '';
        $items = $this->collectItemEdits(is_array($payload) ? ($payload['items'] ?? []) : []);

        return $this->taskAction($id, $taskId, $user,
            fn (PurchaseRequest $p, PurchaseApprovalTask $t, JwtUser $u) => $this->workflow
                ->approveTask($p, $t, $u, $comment !== '' ? $comment : null, $items));
    }

    /** Вернуть автору со своей задачи. Комментарий обязателен. */
    #[Route('/tasks/{taskId}/reject', name: 'spa_api_purchases_task_reject', requirements: ['taskId' => '\d+'], methods: ['POST'])]
    public function rejectTask(int $id, int $taskId, Request $request, #[CurrentUser] ?JwtUser $user): JsonResponse
    {
        $comment = $this->comment($request);
        if ($comment === '') {
            return $this->json(['error' => SpaApiError::PURCHASE_COMMENT_REQUIRED], Response::HTTP_BAD_REQUEST);
        }

        return $this->taskAction($id, $taskId, $user,
            fn (PurchaseRequest $p, PurchaseApprovalTask $t, JwtUser $u) => $this->workflow
                ->rejectTask($p, $t, $u, $comment));
    }

    /**
     * Вернуть в отдел закупок со своей задачи — для бухгалтерии, юристов и всех,
     * кто идёт после закупок: они бракуют документы, а не саму заявку.
     * Комментарий обязателен.
     */
    #[Route('/tasks/{taskId}/return', name: 'spa_api_purchases_task_return', requirements: ['taskId' => '\d+'], methods: ['POST'])]
    public function returnTask(int $id, int $taskId, Request $request, #[CurrentUser] ?JwtUser $user): JsonResponse
    {
        $comment = $this->comment($request);
        if ($comment === '') {
            return $this->json(['error' => SpaApiError::PURCHASE_COMMENT_REQUIRED], Response::HTTP_BAD_REQUEST);
        }

        return $this->taskAction($id, $taskId, $user,
            fn (PurchaseRequest $p, PurchaseApprovalTask $t, JwtUser $u) => $this->workflow
                ->returnToSourcing($p, $t, $u, $comment));
    }

    /**
     * Снять свою подпись — своё решение и только его. Чью именно подпись можно
     * снять, проверяет воркфлоу, поэтому ролевого гейта здесь нет: он спрашивал
     * «ты директор» и расходился со строкой списка, где кнопка отката
     * показывалась любому подписавшему. Чужую подпись снимает возврат маршрута.
     */
    #[Route('/tasks/{taskId}/revoke', name: 'spa_api_purchases_task_revoke', requirements: ['taskId' => '\d+'], methods: ['POST'])]
    public function revokeTask(int $id, int $taskId, #[CurrentUser] ?JwtUser $user): JsonResponse
    {
        return $this->taskAction($id, $taskId, $user,
            fn (PurchaseRequest $p, PurchaseApprovalTask $t, JwtUser $u) => $this->workflow
                ->revokeTask($p, $t, $u));
    }

    /**
     * Решение разбирающего — одно на все кнопки модалки разбора.
     *
     * body: {action, items?: [{id, included, quantity}], assignments?: {stageId: [userId]},
     *        approverIds?: [], reason?}
     *   send   — применить правки состава и отправить дальше: выбранным
     *            согласантам, а если никого не выбрали — сразу следующему этапу
     *   reject — мотивированный отказ: автору на доработку, причина обязательна
     *   cancel — отказ без объяснений: заявка отменена
     */
    #[Route('/triage', name: 'spa_api_purchases_triage', methods: ['POST'])]
    public function triage(int $id, Request $request, #[CurrentUser] ?JwtUser $user): JsonResponse
    {
        if (!$user instanceof JwtUser) {
            throw $this->createAccessDeniedException();
        }

        $purchase = $this->purchaseRepo->find($id);
        if ($purchase === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $task = $this->access->findMyActiveTask($purchase, $user, PurchaseStagePurpose::TRIAGE);
        if ($task === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_TASK_NOT_ACTIVE], Response::HTTP_CONFLICT);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        $action = (string) ($payload['action'] ?? '');
        $reason = trim((string) ($payload['reason'] ?? ''));

        try {
            switch ($action) {
                case 'send':
                    $assignments = $this->collectAssignments($purchase, $payload);
                    if (is_string($assignments)) {
                        return $this->json(['error' => $assignments], Response::HTTP_BAD_REQUEST);
                    }
                    $this->workflow->triage(
                        $purchase,
                        $task,
                        $user,
                        $this->collectItemEdits($payload['items'] ?? []),
                        $assignments,
                    );
                    break;

                case 'reject':
                    if ($reason === '') {
                        return $this->json(['error' => SpaApiError::PURCHASE_COMMENT_REQUIRED], Response::HTTP_BAD_REQUEST);
                    }
                    $this->workflow->rejectTask($purchase, $task, $user, $reason);
                    break;

                case 'cancel':
                    $this->workflow->cancel($purchase, $user, $reason !== '' ? $reason : null);
                    break;

                default:
                    return $this->json(['error' => SpaApiError::PURCHASE_INVALID_STATUS], Response::HTTP_BAD_REQUEST);
            }
        } catch (PurchaseTransitionException $e) {
            return $this->json(['error' => $e->errorCode], Response::HTTP_CONFLICT);
        }

        return $this->json($this->presenter->presentDetail($purchase));
    }

    /**
     * Правки состава из payload. Позиции, которых разбирающий не прислал,
     * остаются как есть.
     *
     * @param mixed $rows
     * @return array<int, array{included: bool, quantity: string|null, price: string|null, inStock: bool|null}>
     */
    private function collectItemEdits(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $edits = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['id'])) {
                continue;
            }

            $quantity = $row['quantity'] ?? null;
            $price = $row['estimatedPrice'] ?? null;
            $edits[(int) $row['id']] = [
                'included' => (bool) ($row['included'] ?? true),
                'quantity' => is_numeric($quantity) ? (string) $quantity : null,
                'price' => is_numeric($price) ? (string) $price : null,
                'inStock' => array_key_exists('inStock', $row) ? (bool) $row['inStock'] : null,
            ];
        }

        return $edits;
    }

    /**
     * Согласанты по этапам: {stageId: [userId]}.
     *
     * Плоский approverIds тоже принимаем — маршрут с одним динамическим этапом
     * самый частый, и заставлять фронт узнавать его id ради одного списка незачем.
     *
     * Несуществующий пользователь — ошибка, а не молчаливый пропуск: разбирающий
     * считает, что отправил человеку, а тот заявки не увидит. Так же и человек не
     * из пула этапа: назначение решает, кто будет подписывать заявку, и проверять
     * это только скрытием в списке нельзя.
     *
     * @param array<string, mixed> $payload
     * @return array<int, list<JwtUser>>|string код ошибки, если состав не годится
     */
    private function collectAssignments(PurchaseRequest $purchase, array $payload): array|string
    {
        $rows = $payload['assignments'] ?? null;

        if (!is_array($rows)) {
            $flat = $payload['approverIds'] ?? [];
            if (!is_array($flat) || $flat === []) {
                return [];
            }

            $stages = $this->access->findAssignableStages($purchase, $this->currentUser());
            if (count($stages) !== 1) {
                return SpaApiError::PURCHASE_TASK_NOT_ACTIVE;
            }

            $rows = [(string) $stages[0]->getId() => $flat];
        }

        $assignments = [];
        foreach ($rows as $stageId => $ids) {
            if (!is_array($ids)) {
                return SpaApiError::PURCHASE_TASK_NOT_FOUND;
            }

            $stage = $this->findStage($purchase, (int) $stageId);
            if ($stage === null) {
                return SpaApiError::PURCHASE_TASK_NOT_FOUND;
            }

            $userIds = [];
            foreach ($ids as $userId) {
                $userId = (int) $userId;
                if ($this->directory->user($userId) === null) {
                    return SpaApiError::USER_NOT_FOUND;
                }
                if (!$this->access->canBeAssignedTo($stage, $userId)) {
                    return SpaApiError::PURCHASE_APPROVER_NOT_DEPUTY;
                }
                $userIds[] = $userId;
            }

            $assignments[(int) $stageId] = $userIds;
        }

        return $assignments;
    }

    private function findStage(PurchaseRequest $purchase, int $stageId): ?PurchaseApprovalStage
    {
        foreach ($purchase->getStages() as $stage) {
            if ($stage->getId() === $stageId) {
                return $stage;
            }
        }

        return null;
    }

    /**
     * Решение по задаче: задача должна принадлежать заявке и быть адресована
     * этому человеку — лично или через роль, записанную на ней.
     *
     * @param callable(PurchaseRequest, PurchaseApprovalTask, JwtUser): void $action
     */
    private function taskAction(int $id, int $taskId, ?JwtUser $user, callable $action): JsonResponse
    {
        if (!$user instanceof JwtUser) {
            throw $this->createAccessDeniedException();
        }

        $purchase = $this->purchaseRepo->find($id);
        if ($purchase === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $task = $purchase->findTask($taskId);
        if ($task === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_TASK_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        if (!$this->access->canActOn($task, $user)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        try {
            $action($purchase, $task, $user);
        } catch (PurchaseTransitionException $e) {
            return $this->json(['error' => $e->errorCode], Response::HTTP_CONFLICT);
        }

        return $this->json($this->presenter->presentDetail($purchase));
    }

    /**
     * @param callable(PurchaseRequest, JwtUser): bool $gate
     * @param callable(PurchaseRequest, JwtUser): void $action
     */
    private function transition(int $id, ?JwtUser $user, callable $gate, callable $action): JsonResponse
    {
        if (!$user instanceof JwtUser) {
            throw $this->createAccessDeniedException();
        }

        $purchase = $this->purchaseRepo->find($id);
        if ($purchase === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        if (!$gate($purchase, $user)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        try {
            $action($purchase, $user);
        } catch (PurchaseTransitionException $e) {
            return $this->json(['error' => $e->errorCode], Response::HTTP_CONFLICT);
        }

        return $this->json($this->presenter->presentDetail($purchase));
    }

    private function comment(Request $request): string
    {
        $payload = json_decode($request->getContent(), true) ?? [];

        return is_array($payload) ? trim((string) ($payload['comment'] ?? '')) : '';
    }

    private function currentUser(): JwtUser
    {
        $user = $this->getUser();
        if (!$user instanceof JwtUser) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
