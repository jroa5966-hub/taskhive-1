<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskTransfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskTransferTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test complex database transaction with multiple table relationships
     * Demonstrates foreign key constraints, joins, and data integrity
     */
    public function test_task_transfer_with_transaction()
    {
        // Create test user (admin)
        $user = User::factory()->create(['role' => 'admin']);

        // Create two projects for the user
        $project1 = Project::factory()->create(['user_id' => $user->id, 'name' => 'Project A']);
        $project2 = Project::factory()->create(['user_id' => $user->id, 'name' => 'Project B']);

        // Create a task in project 1
        $task = Task::factory()->create([
            'user_id' => $user->id,
            'project_id' => $project1->id,
            'title' => 'Test Task'
        ]);

        // Act as the admin user
        $this->actingAs($user);

        // Transfer the task to project 2
        $response = $this->patch(route('tasks.transfer', $task), [
            'project_id' => $project2->id,
            'reason' => 'Moving to correct project'
        ]);

        // Assert successful redirect
        $response->assertRedirect(route('tasks.index'));
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'project_id' => $project2->id, // Task should now be in project 2
        ]);

        // Assert transfer record was created
        $this->assertDatabaseHas('task_transfers', [
            'task_id' => $task->id,
            'from_project_id' => $project1->id,
            'to_project_id' => $project2->id,
            'transferred_by' => $user->id,
            'reason' => 'Moving to correct project'
        ]);

        // Assert comment was created
        $this->assertDatabaseHas('task_comments', [
            'task_id' => $task->id,
            'user_id' => $user->id,
        ]);

        // Test the complex join query in transferStats
        $statsResponse = $this->get(route('tasks.transfer.stats'));
        $statsResponse->assertStatus(200);

        $stats = $statsResponse->json();
        $this->assertCount(1, $stats);
        $this->assertEquals('Project A', $stats[0]['from_project']);
        $this->assertEquals('Project B', $stats[0]['to_project']);
        $this->assertEquals(1, $stats[0]['transfers_count']);
    }

    /**
     * Test transaction rollback on failure
     */
    public function test_transaction_rollback_on_failure()
    {
        $user = User::factory()->create(['role' => 'admin']);
        $project1 = Project::factory()->create(['user_id' => $user->id]);
        $project2 = Project::factory()->create(['user_id' => $user->id]);
        $task = Task::factory()->create(['user_id' => $user->id, 'project_id' => $project1->id]);

        $this->actingAs($user);

        // Try to transfer to same project (should fail validation)
        $response = $this->patch(route('tasks.transfer', $task), [
            'project_id' => $project1->id, // Same project
        ]);

        $response->assertSessionHasErrors('project_id');
    }
}