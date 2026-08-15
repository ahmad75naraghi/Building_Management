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
            return (new Response())->setStatusCode(201)->setJson([
                'success' => true,
                'message' => 'Cost created',
                'data' => $cost->toArray(),
            ]);
        } catch (\Exception $e) {
            return (new Response())->setStatusCode(400)->setJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
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
}
