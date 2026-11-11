<?php

declare(strict_types=1);

namespace App\Controller\SpaApi;

/**
 * Коды ошибок SPA API. Подмножество App\Controller\SpaApi\SpaApiError монолита —
 * только то, что отдаёт модуль закупок; значения те же, фронт разбирает их одинаково.
 */
final class SpaApiError
{
    public const ACCESS_DENIED = 'access_denied';
    public const COMMENT_BODY_REQUIRED = 'comment_body_required';
    public const COMMENT_BODY_TOO_LONG = 'comment_body_too_long';
    public const COMMENT_NOT_FOUND = 'comment_not_found';
    public const FILE_NOT_PROVIDED = 'file_not_provided';
    public const INVALID_JSON = 'invalid_json';
    public const USER_NOT_FOUND = 'user_not_found';
    public const ORGANIZATION_REQUIRED = 'organization_required';
    public const POST_FILE_TOO_LARGE = 'post_file_too_large';
    public const PURCHASE_NOT_FOUND = 'purchase_not_found';
    public const PURCHASE_INVALID_STATUS = 'purchase_invalid_status';
    public const PURCHASE_COMMENT_REQUIRED = 'purchase_comment_required';
    public const PURCHASE_HISTORY_NOT_FOUND = 'purchase_history_not_found';
    public const PURCHASE_ITEMS_REQUIRED = 'purchase_items_required';
    public const PURCHASE_TITLE_REQUIRED = 'purchase_title_required';
    public const PURCHASE_INVALID_ITEM = 'purchase_invalid_item';
    public const PURCHASE_INVALID_DUE_DATE = 'purchase_invalid_due_date';
    public const PURCHASE_FILE_NAME_REQUIRED = 'purchase_file_name_required';
    public const PURCHASE_FILE_NOT_FOUND = 'purchase_file_not_found';
    public const PURCHASE_FILE_LOCKED = 'purchase_file_locked';
    public const PURCHASE_CONTRACT_UNAVAILABLE = 'purchase_contract_unavailable';
    public const PURCHASE_CONTRACT_CONVERT_FAILED = 'purchase_contract_convert_failed';
    public const PURCHASE_FILE_EDITOR_FAILED = 'purchase_file_editor_failed';
    public const PURCHASE_CATEGORY_NOT_FOUND = 'purchase_category_not_found';
    public const PURCHASE_CATEGORY_IN_USE = 'purchase_category_in_use';
    public const PURCHASE_CATEGORY_NAME_REQUIRED = 'purchase_category_name_required';
    public const PURCHASE_CATEGORY_NAME_TAKEN = 'purchase_category_name_taken';
    public const PURCHASE_IMAGE_INVALID_TYPE = 'purchase_image_invalid_type';
    public const PURCHASE_INVALID_LAW = 'purchase_invalid_law';
    public const PURCHASE_INVALID_METHOD = 'purchase_invalid_method';
    public const PURCHASE_INVALID_FILE_TYPE = 'purchase_invalid_file_type';
    public const PURCHASE_APPROVER_NOT_FOUND = 'purchase_approver_not_found';
    public const PURCHASE_APPROVER_ALREADY_ADDED = 'purchase_approver_already_added';
    // Маршрут согласования
    public const PURCHASE_TASK_NOT_FOUND = 'purchase_task_not_found';
    public const PURCHASE_TASK_NOT_ACTIVE = 'purchase_task_not_active';
    public const PURCHASE_CONTRACT_REVIEW_REQUIRED = 'purchase_contract_review_required';
    public const PURCHASE_TASK_FILE_REQUIRED = 'purchase_task_file_required';
    public const PURCHASE_ITEMS_INVOICE_REQUIRED = 'purchase_items_invoice_required';
    public const PURCHASE_ITEMS_CONTRACT_REQUIRED = 'purchase_items_contract_required';
    public const PURCHASE_BINDING_INVALID = 'purchase_binding_invalid';
    /** Раскладка не снимает «на складе»: это растит сумму мимо решения по составу. */
    public const PURCHASE_INSTOCK_LOCKED = 'purchase_instock_locked';
    public const PURCHASE_TASK_NOT_REVOKABLE = 'purchase_task_not_revokable';
    /** С этого этапа возвращать автору нельзя — например, товар уже оплачен. */
    public const PURCHASE_REJECT_NOT_ALLOWED = 'purchase_reject_not_allowed';
    /** Админ выдаёт участнику роль, которой нет в PurchaseRoleCode. */
    public const PURCHASE_ROLE_NOT_FOUND = 'purchase_role_not_found';
    /** Разбирающий отмечает согласантом того, кто не входит в пул этапа. */
    public const PURCHASE_APPROVER_NOT_DEPUTY = 'purchase_approver_not_deputy';
    /** На разборе нужен ровно один профильный зам. */
    public const PURCHASE_DEPUTY_REQUIRED = 'purchase_deputy_required';
    /** Подача заявки, маршрут которой не назначен или больше не годится. */
    public const PURCHASE_ROUTE_NOT_CONFIGURED = 'purchase_route_not_configured';
    /** Двое изменили заявку одновременно — повторить с актуальными данными. */
    public const PURCHASE_CONCURRENT_UPDATE = 'purchase_concurrent_update';
    // Заготовка маршрута в админке
    public const PURCHASE_ROUTE_NOT_FOUND = 'purchase_route_not_found';
    /** Машинное имя маршрута занято другой заготовкой. */
    public const PURCHASE_ROUTE_CODE_TAKEN = 'purchase_route_code_taken';
    /**
     * Шапка заготовки не годится: пустое имя, негодный код или не указаны виды заявок.
     *
     * Отдельно от PURCHASE_ROUTE_STAGE_INVALID намеренно: раньше и то и другое
     * отвечало «проверьте этап», и клиент, забывший поле в шапке, отправлял
     * разработчика искать ошибку в дереве этапов, где её не было.
     */
    public const PURCHASE_ROUTE_META_INVALID = 'purchase_route_meta_invalid';
    /** В этапе нет задач, назначения или значения нет в справочнике. */
    public const PURCHASE_ROUTE_STAGE_INVALID = 'purchase_route_stage_invalid';
    /** Минуты на этап — целое от 1 до 525600, либо поле пустое. */
    public const PURCHASE_ROUTE_REVIEW_MINUTES_INVALID = 'purchase_route_review_minutes_invalid';
    /** В задаче нет роли, пула или назначение недопустимо в заготовке. */
    public const PURCHASE_ROUTE_TASK_INVALID = 'purchase_route_task_invalid';
    /** Заготовку нельзя выключить: она назначена маршрутом по умолчанию. */
    public const PURCHASE_ROUTE_IS_DEFAULT = 'purchase_route_is_default';
    /**
     * Правка маршрута без версии, которую админ видел, когда открывал форму.
     *
     * Отдельно от PURCHASE_CONCURRENT_UPDATE намеренно: иначе клиент, забывший
     * прислать поле, получал бы «вас опередили» и разработчик искал бы второго
     * админа, которого не было.
     */
    public const PURCHASE_ROUTE_VERSION_REQUIRED = 'purchase_route_version_required';
}
