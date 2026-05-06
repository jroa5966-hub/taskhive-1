<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskTransfer extends Model
{
    protected $fillable = [
        'task_id',
        'from_project_id',
        'to_project_id',
        'transferred_by',
        'reason',
        'transferred_at'
    ];

    protected $casts = [
        'transferred_at' => 'datetime',
    ];

    /**
     * Get the task that was transferred
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Get the project the task was transferred from
     */
    public function fromProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'from_project_id');
    }

    /**
     * Get the project the task was transferred to
     */
    public function toProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'to_project_id');
    }

    /**
     * Get the user who performed the transfer
     */
    public function transferredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transferred_by');
    }
}