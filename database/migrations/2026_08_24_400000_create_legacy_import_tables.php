<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_invoice_imports', function (Blueprint $table) {
            $table->id();
            $table->string('matric_number');
            $table->string('invoice_number')->nullable();
            $table->json('payload');
            $table->string('status')->default('pending');
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('source_row')->nullable();
            $table->timestamps();
            $table->index(['matric_number', 'status']);
        });

        Schema::create('legacy_wallet_imports', function (Blueprint $table) {
            $table->id();
            $table->string('matric_number');
            $table->json('payload');
            $table->string('status')->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->unsignedInteger('source_row')->nullable();
            $table->timestamps();
            $table->index(['matric_number', 'status']);
            $table->index(['matric_number', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_wallet_imports');
        Schema::dropIfExists('legacy_invoice_imports');
    }
};
