<?php

declare(strict_types=1);

use App\Domain\Enums\OperationAttemptPhase;
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
        Schema::create('operation_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('operation_id')
                ->references('id')
                ->on('external_operations')
                ->cascadeOnDelete();
            $table->integer('sequence');
            $table->enum('phase', OperationAttemptPhase::cases());
            $table->string('outcome')->nullable();
            $table->string('error_code')->nullable();
            $table->timestampTz('started_at', precision: 6);
            $table->timestampTz('finished_at', precision: 6)->nullable();
            $table->integer('duration_ms')->nullable();

            $table->unique(
                [
                    'operation_id',
                    'sequence',
                ]
            );
        });

        DB::statement(
            'ALTER TABLE operation_attempts ADD CONSTRAINT "operation_attempts_sequence_positive_check" CHECK ( sequence > 0 )'
        );
        DB::statement(
            'ALTER TABLE operation_attempts ADD CONSTRAINT "operation_attempts_duration_ms_nonnegative_check" CHECK ( duration_ms >= 0 )'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('operation_attempts');
    }
};
