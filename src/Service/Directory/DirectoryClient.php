<?php

declare(strict_types=1);

namespace App\Service\Directory;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Пользователи и организации из справочника (go_directory_document_flow).
 *
 * Своих таблиц пользователей и организаций у сервиса нет: в базе только id,
 * имена приходят отсюда. Ответы запоминаются до конца запроса — один и тот же
 * человек встречается в заявке много раз (автор, задачи, история, файлы).
 */
final class DirectoryClient
{
    /** Справочник принимает не больше 500 id за раз. */
    private const BATCH = 500;

    /** @var array<int, array<string, mixed>|null> null — в справочнике такого нет */
    private array $users = [];

    /** @var array<int, array<string, mixed>|null> */
    private array $organizations = [];

    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire('%env(DIRECTORY_URL)%')]
        private readonly string $baseUrl,
        #[Autowire('%env(DIRECTORY_SERVICE_TOKEN)%')]
        private readonly string $token,
    ) {
    }

    /**
     * Пользователи по id, включая удалённых. Кого нет в справочнике — нет и в ответе.
     *
     * @param iterable<int|null> $ids
     *
     * @return array<int, array{id: int, login: string, lastname: string, firstname: string, patronymic: ?string, organization_id: ?int, profession: ?string, worker_status: ?string, avatar_url: ?string, avatar_thumbnail_url: ?string, deleted: bool}>
     */
    public function users(iterable $ids): array
    {
        $wanted = [];
        foreach ($ids as $id) {
            if ($id !== null && $id > 0) {
                $wanted[$id] = $id;
            }
        }

        $missing = array_values(array_diff_key($wanted, $this->users));
        foreach (array_chunk($missing, self::BATCH) as $chunk) {
            foreach ($chunk as $id) {
                $this->users[$id] = null;
            }
            foreach ($this->get('/users', ['ids' => implode(',', $chunk)])['users'] as $user) {
                $this->users[(int) $user['id']] = $user;
            }
        }

        return array_filter(array_intersect_key($this->users, $wanted));
    }

    /** @return array<string, mixed>|null */
    public function user(?int $id): ?array
    {
        return $id === null ? null : ($this->users([$id])[$id] ?? null);
    }

    /**
     * id активных пользователей, у которых ФИО или логин содержат строку.
     *
     * @return list<int>
     */
    public function searchUserIds(string $search): array
    {
        $search = trim($search);
        if ($search === '') {
            return [];
        }

        $ids = [];
        foreach ($this->get('/users', ['search' => mb_substr($search, 0, 100)])['users'] as $user) {
            $this->users[(int) $user['id']] = $user;
            $ids[] = (int) $user['id'];
        }

        return $ids;
    }

    /**
     * Организации по id, включая удалённые.
     *
     * @param iterable<int|null> $ids
     *
     * @return array<int, array{id: int, name: string, full_name: ?string, type: string, parent_id: ?int, deleted: bool}>
     */
    public function organizations(iterable $ids): array
    {
        $wanted = [];
        foreach ($ids as $id) {
            if ($id !== null && $id > 0) {
                $wanted[$id] = $id;
            }
        }

        $missing = array_values(array_diff_key($wanted, $this->organizations));
        foreach (array_chunk($missing, self::BATCH) as $chunk) {
            foreach ($chunk as $id) {
                $this->organizations[$id] = null;
            }
            foreach ($this->get('/organizations', ['ids' => implode(',', $chunk)])['organizations'] as $organization) {
                $this->organizations[(int) $organization['id']] = $organization;
            }
        }

        return array_filter(array_intersect_key($this->organizations, $wanted));
    }

    /** @return array<string, mixed>|null */
    public function organization(?int $id): ?array
    {
        return $id === null ? null : ($this->organizations([$id])[$id] ?? null);
    }

    /**
     * «Организация / Филиал / Департамент» — от корня к самой организации.
     * Повторяет DocumentApiPresenter::buildOrganizationPath монолита.
     */
    public function organizationPath(?int $id): string
    {
        $parts = [];
        $seen = [];
        for ($current = $this->organization($id); $current !== null; $current = $this->organization($current['parent_id'])) {
            if (isset($seen[$current['id']])) {
                break;
            }
            $seen[$current['id']] = true;
            $name = trim((string) $current['name']);
            if ($name !== '') {
                $parts[] = $name;
            }
        }

        return $parts === [] ? '—' : implode(' / ', array_reverse($parts));
    }

    /**
     * id организации и всех её активных дочерних.
     *
     * @return list<int>
     */
    public function organizationDescendantIds(int $id): array
    {
        return array_map('intval', $this->get('/organizations/' . $id . '/descendants', [])['ids']);
    }

    /**
     * «Фамилия Имя Отчество», а если пусто — логин.
     *
     * @param array<string, mixed>|null $user
     */
    public static function fullName(?array $user): string
    {
        if ($user === null) {
            return '';
        }

        $name = trim(sprintf('%s %s %s', $user['lastname'] ?? '', $user['firstname'] ?? '', $user['patronymic'] ?? ''));

        return $name !== '' ? $name : (string) ($user['login'] ?? '');
    }

    /** «Фамилия Имя» пользователя по id; пусто, если его нет в справочнике. */
    public function shortNameOf(?int $id): string
    {
        return self::shortName($this->user($id));
    }

    /**
     * «Фамилия Имя», а если пусто — логин. Так подписывал людей модуль закупок
     * в монолите.
     *
     * @param array<string, mixed>|null $user
     */
    public static function shortName(?array $user): string
    {
        if ($user === null) {
            return '';
        }

        $name = trim(($user['lastname'] ?? '') . ' ' . ($user['firstname'] ?? ''));

        return $name !== '' ? $name : (string) ($user['login'] ?? '');
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function get(string $path, array $query): array
    {
        return $this->http->request('GET', rtrim($this->baseUrl, '/') . $path, [
            'query' => $query,
            'headers' => ['X-Service-Token' => $this->token],
            'timeout' => 5,
        ])->toArray();
    }
}
