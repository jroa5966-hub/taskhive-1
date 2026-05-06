<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\User;
use App\Models\TaskTransfer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class TaskController extends Controller
{
    private function userProjects()
    {
        /** @var User $user */
        $user = Auth::user();
        return $user->projects()->orderBy('name')->get();
    }

    public function index()
    {
        /** @var User $user */
        $user = Auth::user();
        $tasks = $user->isAdmin()
            ? Task::with('user')->latest()->get()
            : $user->tasks()->latest()->get();
        return Inertia::render('Tasks/Index', [
            'tasks' => $tasks->map(function($t) {
                return [
                    'id'          => $t->id,
                    'title'       => $t->title,
                    'description' => $t->description,
                    'priority'    => $t->priority,
                    'status'      => $t->status,
                    'due_date'    => $t->due_date ? $t->due_date->format('Y-m-d') : null,
                    'user'        => $t->relationLoaded('user') ? ['name' => $t->user ? $t->user->name : null] : null,
                    'is_overdue'  => $t->status !== 'done' && $t->due_date && $t->due_date->lt(now()->startOfDay()),
                ];
            }),
            'isAdmin' => $user->isAdmin(),
        ]);
    }

    public function create()
    {
        /** @var User $user */
        $user = Auth::user();
        abort_if(!$user->isAdmin(), 403, 'Only admins can create tasks.');
        $projects = $this->userProjects();
        $users = $user->isAdmin()
            ? User::where('role', 'user')->orderBy('name')->get()
            : collect();
        return Inertia::render('Tasks/Create', [
            'projects' => $projects->map(function($p) { return ['id' => $p->id, 'name' => $p->name]; }),
            'users'    => $users->map(function($u) { return ['id' => $u->id, 'name' => $u->name]; }),
            'isAdmin'  => $user->isAdmin(),
            'authId'   => $user->id,
        ]);
    }

    public function store(Request $request)
    {
        /** @var User $authUser */
        $authUser = Auth::user();
        abort_if(!$authUser->isAdmin(), 403, 'Only admins can create tasks.');

        $rules = [
            'tasks'               => 'required|array|min:1',
            'tasks.*.title'       => 'required|string|max:255',
            'tasks.*.description' => 'nullable|string',
            'tasks.*.priority'    => 'required|in:low,medium,high',
            'tasks.*.status'      => 'required|in:todo,in_progress,done',
            'tasks.*.due_date'    => 'required|date',
            'tasks.*.project_id'  => 'nullable|exists:projects,id',
            'tasks.*.assign_to'   => $authUser->isAdmin() ? 'required|exists:users,id' : 'nullable|exists:users,id',
        ];

        $request->validate($rules);

        foreach ($request->input('tasks') as $taskData) {
            /** @var User $targetUser */
            $targetUser = ($authUser->isAdmin() && !empty($taskData['assign_to']))
                ? User::findOrFail($taskData['assign_to'])
                : $authUser;

            $targetUser->tasks()->create([
                'title'       => $taskData['title'],
                'description' => $taskData['description'] ?? null,
                'priority'    => $taskData['priority'],
                'status'      => $taskData['status'],
                'due_date'    => $taskData['due_date'] ?: null,
                'project_id'  => !empty($taskData['project_id']) ? $taskData['project_id'] : null,
            ]);
        }

        $count = count($request->input('tasks'));
        $message = $count === 1 ? 'Task created successfully.' : "{$count} tasks created successfully.";

        return redirect()->route('tasks.index')->with('success', $message);
    }

    public function edit(Task $task)
    {
        /** @var User $user */
        $user = Auth::user();
        abort_if($task->user_id !== Auth::id() && !$user->isAdmin(), 403);

        $isOverdue = $task->status !== 'done'
            && $task->due_date
            && $task->due_date->lt(now()->startOfDay());
        if ($isOverdue && !$user->isAdmin()) {
            return redirect()->route('tasks.index')
                ->with('error', 'This task is overdue and cannot be edited.');
        }

        $projects = $this->userProjects();
        $task->load('comments.user');
        return Inertia::render('Tasks/Edit', [
            'task'     => [
                'id'          => $task->id,
                'title'       => $task->title,
                'description' => $task->description,
                'priority'    => $task->priority,
                'status'      => $task->status,
                'due_date'    => $task->due_date ? $task->due_date->format('Y-m-d') : null,
                'project_id'  => $task->project_id,
                'comments'    => $task->comments->map(function($c) {
                    return [
                        'id'         => $c->id,
                        'body'       => $c->body,
                        'created_at' => $c->created_at->diffForHumans(),
                        'user'       => ['id' => $c->user->id, 'name' => $c->user->name],
                    ];
                }),
            ],
            'projects' => $projects->map(function($p) { return ['id' => $p->id, 'name' => $p->name]; }),
            'isAdmin'  => $user->isAdmin(),
            'authId'   => Auth::id(),
        ]);
    }

    public function update(Request $request, Task $task)
    {
        /** @var User $user */
        $user = Auth::user();
        abort_if($task->user_id !== Auth::id() && !$user->isAdmin(), 403);

        $isOverdue = $task->status !== 'done'
            && $task->due_date
            && $task->due_date->lt(now()->startOfDay());
        if ($isOverdue && !$user->isAdmin()) {
            return redirect()->route('tasks.index')
                ->with('error', 'This task is overdue and cannot be edited.');
        }

        if ($user->isAdmin()) {
            $validated = $request->validate([
                'title'       => 'required|string|max:255',
                'description' => 'nullable|string',
                'priority'    => 'required|in:low,medium,high',
                'status'      => 'required|in:todo,in_progress,done',
                'due_date'    => 'nullable|date',
                'project_id'  => 'nullable|exists:projects,id',
            ]);
        } else {
            $validated = $request->validate([
                'status' => 'required|in:todo,in_progress,done',
            ]);
        }

        $task->update($validated);

        return redirect()->route('tasks.index')->with('success', 'Task updated successfully.');
    }

    public function destroy(Task $task)
    {
        /** @var User $user */
        $user = Auth::user();
        // Only admins can delete tasks
        abort_if(!$user->isAdmin(), 403);
        $task->delete();
        return redirect()->route('tasks.index')->with('success', 'Task deleted successfully.');
    }

    public function toggleStatus(Task $task)
    {
        /** @var User $user */
        $user = Auth::user();
        abort_if($task->user_id !== Auth::id() && !$user->isAdmin(), 403);
        // Non-admins cannot reopen a completed task
        abort_if($task->status === 'done' && !$user->isAdmin(), 403);
        // Non-admins cannot toggle overdue (missing) tasks
        $isOverdue = $task->status !== 'done'
            && $task->due_date
            && $task->due_date->lt(now()->startOfDay());
        abort_if($isOverdue && !$user->isAdmin(), 403);
        if ($task->status === 'todo') {
            $nextStatus = 'in_progress';
        } elseif ($task->status === 'in_progress') {
            $nextStatus = 'done';
        } else {
            $nextStatus = 'todo';
        }
        $task->update(['status' => $nextStatus]);
        return redirect()->route('tasks.index')->with('success', 'Task status updated.');
    }

    /**
     * Transfer a task to a different project with transaction integrity
     * Demonstrates complex database operations with multiple related tables
     */
    public function transfer(Request $request, Task $task)
    {
        /** @var User $user */
        $user = Auth::user();
        abort_if(!$user->isAdmin(), 403, 'Only admins can transfer tasks.');

        $request->validate([
            'project_id' => 'required|exists:projects,id|different:' . $task->project_id,
            'reason' => 'nullable|string|max:255',
        ]);

        $newProjectId = $request->input('project_id');
        $reason = $request->input('reason');

        // Verify the new project belongs to the authenticated user
        $newProject = $user->projects()->findOrFail($newProjectId);

        // Use database transaction to ensure data consistency across multiple tables
        DB::transaction(function () use ($task, $newProject, $user, $reason) {
            $oldProjectId = $task->project_id;

            // Update task's project relationship (foreign key)
            $task->update(['project_id' => $newProject->id]);

            // Create a transfer record (demonstrates complex relationships)
            TaskTransfer::create([
                'task_id' => $task->id,
                'from_project_id' => $oldProjectId,
                'to_project_id' => $newProject->id,
                'transferred_by' => $user->id,
                'reason' => $reason,
                'transferred_at' => now(),
            ]);

            // Create a comment documenting the transfer (multi-table relationship)
            $task->comments()->create([
                'user_id' => $user->id,
                'body' => "Task transferred from project '{$task->project->name}' to '{$newProject->name}' by {$user->name}" .
                         ($reason ? ". Reason: {$reason}" : ""),
            ]);
        });

        return redirect()->route('tasks.index')->with('success', 'Task transferred successfully.');
    }

    /**
     * Get task transfer statistics using complex joins
     * Demonstrates advanced query building with relationships and aggregations
     */
    public function transferStats()
    {
        /** @var User $user */
        $user = Auth::user();
        abort_if(!$user->isAdmin(), 403);

        // Complex query demonstrating multiple table joins with foreign key relationships
        $stats = DB::table('task_transfers')
            ->join('tasks', 'task_transfers.task_id', '=', 'tasks.id')
            ->join('projects as from_projects', 'task_transfers.from_project_id', '=', 'from_projects.id')
            ->join('projects as to_projects', 'task_transfers.to_project_id', '=', 'to_projects.id')
            ->join('users as transfer_users', 'task_transfers.transferred_by', '=', 'transfer_users.id')
            ->where('from_projects.user_id', $user->id)
            ->where('to_projects.user_id', $user->id)
            ->select(
                'transfer_users.name as transferred_by',
                'from_projects.name as from_project',
                'to_projects.name as to_project',
                DB::raw('COUNT(*) as transfers_count'),
                DB::raw('MAX(task_transfers.transferred_at) as last_transfer'),
                DB::raw('GROUP_CONCAT(DISTINCT tasks.title SEPARATOR "; ") as transferred_tasks')
            )
            ->groupBy('transfer_users.id', 'transfer_users.name', 'from_projects.id', 'from_projects.name', 'to_projects.id', 'to_projects.name')
            ->orderBy('transfers_count', 'desc')
            ->get();

        return response()->json($stats);
    }
}
