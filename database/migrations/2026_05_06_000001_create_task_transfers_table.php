<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Creates a task_transfers table to demonstrate complex database relationships
     * and multi-table transactions with foreign key constraints.
     */
    public function up(): void
    {
        Schema::create('task_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('to_project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('transferred_by')->constrained('users')->cascadeOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('transferred_at');
            $table->timestamps();

            // Ensure from and to projects are different
            $table->check('from_project_id != to_project_id');

            // Index for performance on complex queries
            $table->index(['task_id', 'transferred_at']);
            $table->index(['transferred_by', 'transferred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_transfers');
    }
};