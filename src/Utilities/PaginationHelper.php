<?php

declare(strict_types=1);

namespace App\Utilities;

use App\Core\Request;

/**
 * قرارداد یکپارچهٔ صفحه‌بندی برای اندپوینت‌های فهرستی.
 *
 * صفحه‌بندی «آپشنال» است: فقط وقتی کلاینت `paginate=1` بفرستد فعال می‌شود
 * تا کلاینت‌های موجود (صفحه‌های وب) بدون تغییر به کار ادامه دهند.
 *
 * پارامترهای کوئری:
 *   paginate   = 1 برای فعال‌سازی
 *   page       شماره صفحه (پیش‌فرض ۱)
 *   per_page   تعداد در صفحه (پیش‌فرض ۲۰، حداکثر ۱۰۰)
 *
 * پاسخ در کلید `pagination` شامل page، per_page، total و total_pages است.
 */
final class PaginationHelper
{
    public const DEFAULT_PER_PAGE = 20;
    public const MAX_PER_PAGE = 100;

    /** آیا کلاینت صفحه‌بندی خواسته است؟ */
    public static function requested(Request $request): bool
    {
        return $request->getQueryParam('paginate') === '1';
    }

    /**
     * خواندن page/per_page از کوئری با محدودهای امن.
     *
     * @return array{page:int, per_page:int}
     */
    public static function fromQuery(Request $request): array
    {
        $page = max(1, (int) ($request->getQueryParam('page') ?? 1));
        $perPage = (int) ($request->getQueryParam('per_page') ?? self::DEFAULT_PER_PAGE);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage ?: self::DEFAULT_PER_PAGE));
        return ['page' => $page, 'per_page' => $perPage];
    }

    /**
     * برش فهرست بر اساس صفحه و ساخت بلوک متادیتای صفحه‌بندی.
     *
     * @param array{page:int, per_page:int} $opts
     * @return array{items: array, pagination: array{page:int, per_page:int, total:int, total_pages:int}}
     */
    public static function paginate(array $items, array $opts): array
    {
        $total = count($items);
        $totalPages = max(1, (int) ceil($total / $opts['per_page']));
        $page = min($opts['page'], $totalPages);
        $offset = ($page - 1) * $opts['per_page'];
        return [
            'items' => array_values(array_slice($items, $offset, $opts['per_page'])),
            'pagination' => [
                'page' => $page,
                'per_page' => $opts['per_page'],
                'total' => $total,
                'total_pages' => $totalPages,
            ],
        ];
    }
}
