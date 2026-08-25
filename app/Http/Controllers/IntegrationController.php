<?php

namespace App\Http\Controllers;

use App\Models\IntegrationEndpoint;
use App\Models\WebhookLog;
use App\Services\PremblyService;

class IntegrationController extends Controller
{
    public function index()
    {
        return [
            'endpoints' => IntegrationEndpoint::all(),
            'paystack_configured' => (bool) config('services.paystack.secret'),
            'prembly_configured' => app(PremblyService::class)->isConfigured(),
            'recent_webhooks' => WebhookLog::query()->latest()->limit(20)->get(),
        ];
    }
}
