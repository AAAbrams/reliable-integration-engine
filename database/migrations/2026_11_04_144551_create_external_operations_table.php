<?php

declare(strict_types=1);

use App\Domain\Enums\ExternalOperationState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('external_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('client_id');
            $table->string('idempotency_key');
            $table->char('request_hash', 64);
            $table->jsonb('request_snapshot');
            $table->enum('state', ExternalOperationState::cases())->default(
                ExternalOperationState::Queued
            );
            $table->string('provider_operation_id')->nullable();
            $table->integer('attempts')->default(0);
            $table->timestampTz('available_at', precision: 6)->useCurrent();
            $table->uuid('lock_token')->nullable();
            $table->timestampTz('lease_expires_at', precision: 6)->nullable();
            $table->jsonb('result')->nullable();
            $table->string('error_code')->nullable();
            $table->timestampTz('completed_at', precision: 6)->nullable();
            $table->timestampTz('created_at', precision: 6)->useCurrent();
            $table->timestampTz('updated_at', precision: 6)->useCurrent();

            $table->unique(
                [
                    'client_id',
                    'idempotency_key',
                ]
            );
            $table->index(
                [
                    'state',
                    'available_at',
                ]
            );
            $table->index(
                [
                    'state',
                    'lease_expires_at',
                ]
            );
        });

        DB::statement(
            'ALTER TABLE external_operations ADD CONSTRAINT "external_operations_attempts_nonnegative_check" CHECK ( attempts >= 0 )'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('external_operations');
    }
};
