<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\CostService;

final class CostController
{
    private CostService $service;

    public function __construct()
    {
        $this->service = new CostService();
    }

    public function store(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false,
                'message' => 'Authentication required',
            ]);
        }
        $data = $request->getJsonBody() ?? [];
        try {
            $cost = $this->service->createCost($data, (int) $userId);

            // صدور فوری برای مخاطبان (پیش‌فرض روشن است؛ با ارسال صریح «خیر» قابل غیرفعال‌شدن)
            $autoIssue = !array_key_exists('auto_issue', $data) || filter_var($data['auto_issue'], FILTER_VALIDATE_BOOLEAN);
            $issueInfo = null;
            // قالب دوره‌ای صادر نمی‌شود؛ کران در هر نوبت نمونهٔ آن را می‌سازد و صادر می‌کند
            if ($autoIssue && $cost->cost_type !== 'recurring') {
                try {
                    $issueInfo = $this->service->issueCost((int) $cost->id, (int) $userId);
                    $cost = $this->service->getCost((int) $cost->id) ?? $cost;
                } catch (\Exception $issueException) {
                    // خطای صدور نباید مانع ثبت خود هزینه شود
                    $issueInfo = ['issued' => 0, 'skipped' => 0, 'error' => $issueException->getMessage()];
                }
            }

            return (new Response())->setStatusCode(201)->setJson([
                'success' => true,
                'message' => 'Cost created',
                'data' => ['cost' => $cost->toArray(), 'issue' => $issueInfo],
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * صدور هزینه برای مخاطبان انتخاب‌شده: ایجاد ردیف پرداخت + اعلان.
     * POST /api/costs/{id}/issue
     */
    public function issue(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false,
                'message' => 'Authentication required',
            ]);
        }
        $id = (int) ($request->getAttribute('id') ?? 0);
        try {
            $result = $this->service->issueCost($id, (int) $userId);
            return (new Response())->setJson([
                'success' => true,
                'message' => $result['issued'] > 0
                    ? sprintf('هزینه برای %d نفر صادر شد.', $result['issued'])
                    : 'این هزینه قبلاً برای مخاطبان صادر شده است.',
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function index(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false,
                'message' => 'Authentication required',
            ]);
        }
        $buildingId = (int) ($request->getQueryParam('building_id') ?? 0);
        if ($buildingId <= 0) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => 'building_id query parameter is required',
            ]);
        }
        try {
            $this->service->ensureMonthlyCharge($buildingId, (int) $userId);
            $costs = $this->service->listCostsByBuilding($buildingId);
            return (new Response())->setJson([
                'success' => true,
                'data' => array_map(fn($c) => $c->toArray(), $costs),
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function indexPayments(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false,
                'message' => 'Authentication required',
            ]);
        }
        $buildingId = (int) ($request->getQueryParam('building_id') ?? 0);
        if ($buildingId <= 0) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => 'building_id query parameter is required',
            ]);
        }
        try {
            $payments = $this->service->listPaymentsByBuilding($buildingId);
            return (new Response())->setJson([
                'success' => true,
                'data' => $payments,
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function summary(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false,
                'message' => 'Authentication required',
            ]);
        }
        $buildingId = (int) ($request->getQueryParam('building_id') ?? 0);
        if ($buildingId <= 0) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => 'building_id query parameter is required',
            ]);
        }
        $this->service->ensureMonthlyCharge($buildingId, (int) $userId);
        $summary = $this->service->getFinancialSummary($buildingId);
        return (new Response())->setJson([
            'success' => true,
            'data' => $summary,
        ]);
    }

    /**
     * ثبت پرداخت توسط ساکن — فیش واریزی (تصویر رسید) «الزامی» است و همراه
     * فرم به‌صورت چندبخشی ارسال می‌شود.
     */
    public function submitPayment(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false, 'message' => 'Authentication required',
            ]);
        }
        $data = $request->getJsonBody() ?? [];
        if ($data === []) {
            $data = array_filter(
                [
                    'payment_id' => $request->getPostParam('payment_id'),
                    'cost_id' => $request->getPostParam('cost_id'),
                    'amount_paid' => $request->getPostParam('amount_paid'),
                    'notes' => $request->getPostParam('notes'),
                ],
                static fn ($v) => $v !== null
            );
        }

        $receiptContent = null;
        $receiptName = null;
        $file = $request->getFile('receipt');
        if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $content = file_get_contents((string) $file['tmp_name']);
            if ($content === false) {
                return (new Response())->setStatusCode(400)->setJson([
                    'success' => false, 'message' => 'خواندن فایل فیش واریزی ناموفق بود.',
                ]);
            }
            $receiptContent = $content;
            $receiptName = (string) ($file['name'] ?? 'receipt');
        }

        try {
            $payment = $this->service->submitPayment($data, (int) $userId, $receiptContent, $receiptName);
            return (new Response())->setStatusCode(201)->setJson([
                'success' => true,
                'message' => 'پرداخت شما همراه با فیش واریزی ثبت شد و برای تأیید مدیر ارسال شد.',
                'data' => $payment->toArray(),
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * جزئیات یک پرداخت برای مالک ردیف یا مدیر ساختمان (نمایش فیش واریزی).
     * برای سایر کاربران 403 برمی‌گرداند.
     */
    public function showPayment(Request $request): Response
    {
        $userId = (int) ($request->getAttribute('user_id') ?? 0);
        $paymentId = (int) ($request->getAttribute('payment_id') ?? 0);
        if (!$userId || !$paymentId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false, 'message' => 'Authentication or payment required',
            ]);
        }

        $payment = $this->service->getPaymentById($paymentId);
        if (!$payment) {
            return (new Response())->setStatusCode(404)->setJson([
                'success' => false, 'message' => 'Payment not found',
            ]);
        }
        $cost = $this->service->getCostById($payment->cost_id);
        if (!$cost) {
            return (new Response())->setStatusCode(404)->setJson([
                'success' => false, 'message' => 'Cost not found',
            ]);
        }
        $isPayer = $payment->user_id === $userId;
        $isManager = $this->service->isManagerOfBuilding($userId, $cost->building_id);
        if (!$isPayer && !$isManager) {
            return (new Response())->setStatusCode(403)->setJson([
                'success' => false, 'message' => 'به این پرداخت دسترسی ندارید.',
            ]);
        }

        $data = $payment->toArray();
        $data['receipt_path'] = $payment->receipt_path;
        $data['cost_title'] = $cost->title;
        $data['building_id'] = $cost->building_id;
        return (new Response())->setJson([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function uploadReceipt(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $paymentId = (int) ($request->getAttribute('payment_id') ?? 0);
        if (!$userId || !$paymentId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false, 'message' => 'Authentication or payment required',
            ]);
        }

        $file = $request->getFile('receipt');
        $isPublic = filter_var($request->getPostParam('is_public', '0'), FILTER_VALIDATE_BOOLEAN);

        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => 'Receipt file is required',
            ]);
        }

        $content = file_get_contents($file['tmp_name']);
        if ($content === false) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => 'Failed to read receipt file',
            ]);
        }

        try {
            $path = $this->service->uploadReceipt($paymentId, $content, $file['name'], (int) $userId, $isPublic);
            return (new Response())->setJson([
                'success' => true,
                'message' => 'Receipt uploaded successfully',
                'data' => ['path' => $path],
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * رد پرداخت توسط مدیر (پول به حساب نیامده) همراه با دلیل؛ به پرداخت‌کننده اعلان می‌رود.
     */
    public function rejectPayment(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $paymentId = (int) ($request->getAttribute('payment_id') ?? 0);
        if (!$userId || !$paymentId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false, 'message' => 'Authentication or payment required',
            ]);
        }
        $data = $request->getJsonBody() ?? [];
        $reason = $data['reason'] ?? ($request->getPostParam('reason') ?? null);
        try {
            $rejected = $this->service->rejectPayment($paymentId, (int) $userId, $reason);
            return (new Response())->setJson([
                'success' => $rejected,
                'message' => $rejected ? 'پرداخت رد شد و به پرداخت‌کننده اطلاع داده شد.' : 'رد پرداخت ناموفق بود.',
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false, 'message' => $e->getMessage(),
            ]);
        }
    }

    /** شناسه‌های پرداخت از بدنهٔ درخواست گروهی؛ حداکثر ۱۰۰ مورد در هر فراخوانی */
    private function bulkPaymentIds(Request $request): ?array
    {
        $data = $request->getJsonBody() ?? [];
        $ids = $data['ids'] ?? [];
        if (!is_array($ids)) {
            return null;
        }
        $clean = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn ($v) => $v > 0
        )));
        if ($clean === [] || count($clean) > 100) {
            return null;
        }
        return $clean;
    }

    /** تأیید گروهی پرداخت‌ها توسط مدیر ساختمان */
    public function bulkConfirmPayments(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false, 'message' => 'Authentication required',
            ]);
        }
        $ids = $this->bulkPaymentIds($request);
        if ($ids === null) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false, 'message' => 'شناسهٔ پرداخت‌ها معتبر نیست (حداکثر ۱۰۰ مورد).',
            ]);
        }
        $result = $this->service->bulkConfirmPayments($ids, (int) $userId);
        return (new Response())->setJson([
            'success' => true,
            'message' => sprintf('%d پرداخت تأیید شد؛ %d مورد ناموفق بود.', $result['processed'], $result['failed']),
            'data' => $result,
        ]);
    }

    /** رد گروهی پرداخت‌ها توسط مدیر ساختمان با دلیل مشترک */
    public function bulkRejectPayments(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false, 'message' => 'Authentication required',
            ]);
        }
        $ids = $this->bulkPaymentIds($request);
        if ($ids === null) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false, 'message' => 'شناسهٔ پرداخت‌ها معتبر نیست (حداکثر ۱۰۰ مورد).',
            ]);
        }
        $data = $request->getJsonBody() ?? [];
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false, 'message' => 'دلیل رد گروهی الزامی است.',
            ]);
        }
        $result = $this->service->bulkRejectPayments($ids, (int) $userId, $reason);
        return (new Response())->setJson([
            'success' => true,
            'message' => sprintf('%d پرداخت رد شد؛ %d مورد ناموفق بود.', $result['processed'], $result['failed']),
            'data' => $result,
        ]);
    }

    /** ماندهٔ بدهکار/طلبکار هر واحد ساختمان */
    public function unitBalances(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $buildingId = (int) ($request->getAttribute('building_id') ?? 0);
        if (!$userId || !$buildingId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false, 'message' => 'Authentication or building required',
            ]);
        }
        try {
            $balances = $this->service->getUnitBalances($buildingId, (int) $userId);
            return (new Response())->setJson([
                'success' => true,
                'data' => $balances,
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false, 'message' => $e->getMessage(),
            ]);
        }
    }

    public function confirmPayment(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $paymentId = (int) ($request->getAttribute('payment_id') ?? 0);
        if (!$userId || !$paymentId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false, 'message' => 'Authentication or payment required',
            ]);
        }
        try {
            $updated = $this->service->confirmPayment($paymentId, (int) $userId);
            return (new Response())->setJson([
                'success' => $updated,
                'message' => $updated ? 'Payment confirmed' : 'Failed to confirm',
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ثبت شارژ ماهیانه ثابت برای ماه جاری.
     * POST /api/costs/monthly-charge با building_id در بدنه
     */
    /**
     * پیش‌نمایش محاسبه شارژ ماهیانه (سهم هر واحد) بدون ثبت هزینه.
     * برای نمایش «چه کسی چقدر می‌پردازد» در صفحه مالی.
     */
    public function update(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false, 'message' => 'Authentication required',
            ]);
        }
        $id = (int) ($request->getAttribute('id') ?? 0);
        $data = $request->getJsonBody() ?? [];
        try {
            $cost = $this->service->updateCost($id, $data, (int) $userId);
            if (!$cost) {
                return (new Response())->setStatusCode(404)->setJson([
                    'success' => false, 'message' => 'Cost not found',
                ]);
            }
            return (new Response())->setJson([
                'success' => true,
                'message' => 'Cost updated',
                'data' => $cost->toArray(),
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false, 'message' => $e->getMessage(),
            ]);
        }
    }

    public function destroy(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false, 'message' => 'Authentication required',
            ]);
        }
        $id = (int) ($request->getAttribute('id') ?? 0);
        try {
            $deleted = $this->service->deleteCost($id, (int) $userId);
            return (new Response())->setJson([
                'success' => $deleted,
                'message' => $deleted ? 'Cost deleted' : 'Cost not found',
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false, 'message' => $e->getMessage(),
            ]);
        }
    }

    public function chargePreview(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false,
                'message' => 'Authentication required',
            ]);
        }
        $buildingId = (int) ($request->getQueryParam('building_id') ?? 0);
        if ($buildingId <= 0) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => 'building_id query parameter is required',
            ]);
        }
        $breakdown = $this->service->calculateMonthlyCharges($buildingId);
        return (new Response())->setJson([
            'success' => true,
            'data' => $breakdown,
        ]);
    }

    public function monthlyCharge(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false,
                'message' => 'Authentication required',
            ]);
        }
        $data = $request->getJsonBody() ?? [];
        $buildingId = (int) ($data['building_id'] ?? 0);
        if ($buildingId <= 0) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => 'building_id is required',
            ]);
        }
        try {
            $cost = $this->service->createMonthlyCharge($buildingId, (int) $userId);
            return (new Response())->setStatusCode(201)->setJson([
                'success' => true,
                'message' => 'شارژ ماهیانه ثبت شد.',
                'data' => $cost->toArray(),
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function createPenaltySetting(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false, 'message' => 'Authentication required',
            ]);
        }
        $data = $request->getJsonBody() ?? [];
        try {
            $setting = $this->service->createPenaltySetting($data, (int) $userId);
            return (new Response())->setStatusCode(201)->setJson([
                'success' => true,
                'message' => 'Penalty setting created',
                'data' => $setting->toArray(),
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /** لیست تنظیم‌های جریمه ساختمان — ?building_id= */
    public function indexPenaltySettings(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false, 'message' => 'Authentication required',
            ]);
        }
        $buildingId = (int) ($request->getQueryParam('building_id') ?? 0);
        if ($buildingId <= 0) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false, 'message' => 'building_id is required',
            ]);
        }
        try {
            $settings = $this->service->listPenaltySettings($buildingId, (int) $userId);
            return (new Response())->setJson([
                'success' => true,
                'data' => array_map(fn($s) => $s->toArray(), $settings),
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false, 'message' => $e->getMessage(),
            ]);
        }
    }

    /** ویرایش تنظیم جریمه — فقط مدیر ساختمان */
    public function updatePenaltySetting(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false, 'message' => 'Authentication required',
            ]);
        }
        $id = (int) ($request->getAttribute('id') ?? 0);
        if ($id <= 0) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false, 'message' => 'id is required',
            ]);
        }
        $data = $request->getJsonBody() ?? [];
        try {
            $setting = $this->service->updatePenaltySetting($id, $data, (int) $userId);
            return (new Response())->setJson([
                'success' => true,
                'message' => 'Penalty setting updated',
                'data' => $setting->toArray(),
            ]);
        } catch (\App\Exceptions\ValidationException $e) {
            return (new Response())->setStatusCode(422)->setJson([
                'success' => false, 'message' => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            $status = $e->getMessage() === 'Penalty setting not found' ? 404 : 400;
            return (new Response())->setStatusCode($status)->setJson([
                'success' => false, 'message' => $e->getMessage(),
            ]);
        }
    }

    /** حذف تنظیم جریمه — فقط مدیر ساختمان */
    public function destroyPenaltySetting(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false, 'message' => 'Authentication required',
            ]);
        }
        $id = (int) ($request->getAttribute('id') ?? 0);
        if ($id <= 0) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false, 'message' => 'id is required',
            ]);
        }
        try {
            $deleted = $this->service->deletePenaltySetting($id, (int) $userId);
            return (new Response())->setJson([
                'success' => $deleted,
                'message' => $deleted ? 'Penalty setting deleted' : 'Failed to delete penalty setting',
            ]);
        } catch (\Exception $e) {
            $status = $e->getMessage() === 'Penalty setting not found' ? 404 : 400;
            return (new Response())->setStatusCode($status)->setJson([
                'success' => false, 'message' => $e->getMessage(),
            ]);
        }
    }

    /** گردش حساب واحدها (لجر) — مدیر ساختمان */
    public function ledger(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $buildingId = (int) ($request->getAttribute('building_id') ?? 0);
        if (!$userId || !$buildingId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false, 'message' => 'Authentication or building required',
            ]);
        }
        try {
            return (new Response())->setJson([
                'success' => true,
                'data' => $this->service->getBuildingLedger($buildingId, (int) $userId),
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false, 'message' => $e->getMessage(),
            ]);
        }
    }

    /** گزارش ریز مانده‌ها به تفکیک ماه شمسی */
    public function monthlyReport(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $buildingId = (int) ($request->getAttribute('building_id') ?? 0);
        if (!$userId || !$buildingId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false, 'message' => 'Authentication or building required',
            ]);
        }
        $unitFilter = (int) ($request->getQueryParam('unit_id') ?? 0);
        try {
            return (new Response())->setJson([
                'success' => true,
                'data' => $this->service->getMonthlyReport($buildingId, (int) $userId, $unitFilter > 0 ? $unitFilter : null),
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false, 'message' => $e->getMessage(),
            ]);
        }
    }

    /** ثبت مستقیم پرداخت برای واحد توسط مدیر */
    public function directPayment(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $buildingId = (int) ($request->getAttribute('building_id') ?? 0);
        if (!$userId || !$buildingId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false, 'message' => 'Authentication or building required',
            ]);
        }
        $body = $request->getJsonBody() ?? [];
        try {
            $payment = $this->service->recordDirectPayment(
                $buildingId,
                (int) ($body['unit_id'] ?? 0),
                (float) ($body['amount'] ?? 0),
                isset($body['notes']) ? (string) $body['notes'] : null,
                (int) $userId
            );
            return (new Response())->setStatusCode(201)->setJson([
                'success' => true,
                'message' => 'پرداخت مستقیم ثبت و تأیید شد.',
                'data' => ['payment_id' => $payment->id],
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false, 'message' => $e->getMessage(),
            ]);
        }
    }

    /** ثبت مستقیم بدهی برای یک واحد توسط مدیر */
    public function unitCharge(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $buildingId = (int) ($request->getAttribute('building_id') ?? 0);
        if (!$userId || !$buildingId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false, 'message' => 'Authentication or building required',
            ]);
        }
        $body = $request->getJsonBody() ?? [];
        try {
            $cost = $this->service->recordUnitCharge(
                $buildingId,
                (int) ($body['unit_id'] ?? 0),
                (float) ($body['amount'] ?? 0),
                (string) ($body['title'] ?? ''),
                (int) $userId,
                !empty($body['due_date']) ? (string) $body['due_date'] : null
            );
            return (new Response())->setStatusCode(201)->setJson([
                'success' => true,
                'message' => 'بدهی برای واحد ثبت و صادر شد.',
                'data' => ['cost_id' => $cost->id],
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false, 'message' => $e->getMessage(),
            ]);
        }
    }

    /** اجرای دستی موتور دوره‌ای: صدور نوبت‌های سررسیدشده + شارژ ماه جاری ساختمان */
    public function recurringGenerate(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $buildingId = (int) ($request->getAttribute('building_id') ?? 0);
        if (!$userId || !$buildingId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false, 'message' => 'Authentication or building required',
            ]);
        }
        if (!$this->service->isBuildingManager((int) $userId, $buildingId)) {
            return (new Response())->setStatusCode(403)->setJson([
                'success' => false, 'message' => 'فقط مدیر ساختمان می‌تواند موتور دوره‌ای را اجرا کند.',
            ]);
        }
        try {
            // اجرای دستی فقط همان ساختمان را پردازش می‌کند (سریع و بدون اثر روی بقیه)
            $monthly = $this->service->generateMonthlyChargeForBuilding($buildingId);
            $recurring = $this->service->generateDueRecurringCostsForBuilding($buildingId);
            return (new Response())->setJson([
                'success' => true,
                'message' => 'موتور دوره‌ای اجرا شد.',
                'data' => [
                    'monthly_issued' => $monthly['created'],
                    'recurring_issued' => $recurring['generated'],
                    'recurring_ended' => $recurring['ended'],
                ],
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false, 'message' => $e->getMessage(),
            ]);
        }
    }
}
