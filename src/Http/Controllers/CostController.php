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
            if ($autoIssue) {
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

    public function submitPayment(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return (new Response())->setStatusCode(401)->setJson([
                'success' => false, 'message' => 'Authentication required',
            ]);
        }
        $data = $request->getJsonBody() ?? [];
        try {
            $payment = $this->service->submitPayment($data, (int) $userId);
            return (new Response())->setStatusCode(201)->setJson([
                'success' => true,
                'message' => 'Payment submitted. Please upload receipt.',
                'data' => $payment->toArray(),
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
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
}
