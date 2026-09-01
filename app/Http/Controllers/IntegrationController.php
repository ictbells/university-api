<?php

namespace App\Http\Controllers;

use App\Models\IntegrationEndpoint;
use App\Models\WebhookLog;
use App\Services\PremblyService;
use App\Support\PaymentGatewaySettings;

class IntegrationController extends Controller
{
    public function index()
    {
        return [
            'endpoints' => IntegrationEndpoint::all(),
            'paystack_configured' => PaymentGatewaySettings::paystackConfigured(),
            'wema_configured' => PaymentGatewaySettings::wemaConfigured(),
            'prembly_configured' => app(PremblyService::class)->isConfigured(),
            'recent_webhooks' => WebhookLog::query()->latest()->limit(20)->get(),
        ];
    }
}
