<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Audits are immutable once written, so there is no updated_at.
        Schema::create('audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->morphs('subject');
            $table->string('event');
            $table->jsonb('changes');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['household_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audits');
    }
};
