<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_pay_offers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->text('instructions')->nullable();
            $table->foreignId('fee_item_id')->constrained('fee_items')->restrictOnDelete();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('public_pay_requests', function (Blueprint $table) {
            $table->id();
            $table->string('public_token', 64)->unique();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offer_id')->constrained('public_pay_offers')->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('contact_email');
            $table->string('purpose')->nullable();
            $table->string('status', 32)->default('awaiting_payment')->index();
            $table->string('delivery_mode', 32)->nullable();
            $table->string('artifact_path')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['student_id', 'offer_id', 'status']);
        });

        $now = now();
        foreach ([
            ['key' => 'public_pay.offers', 'module' => 'public_pay', 'label' => 'Manage public request & pay offerings'],
            ['key' => 'public_pay.view', 'module' => 'public_pay', 'label' => 'View public request & pay requests'],
            ['key' => 'public_pay.process', 'module' => 'public_pay', 'label' => 'Process public request & pay requests'],
        ] as $perm) {
            if (DB::table('permissions')->where('key', $perm['key'])->exists()) {
                continue;
            }
            DB::table('permissions')->insert([
                ...$perm,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('public_pay_requests');
        Schema::dropIfExists('public_pay_offers');
        DB::table('permissions')->whereIn('key', [
            'public_pay.offers',
            'public_pay.view',
            'public_pay.process',
        ])->delete();
    }
};
