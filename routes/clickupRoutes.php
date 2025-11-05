<?php

use App\Http\Controllers\ClickUpController;
use Illuminate\Support\Facades\Route;

Route::prefix('clickup')->group(function () {
    Route::get('/auth/user', [ClickUpController::class, 'getUser']);
    Route::get('/teams', [ClickUpController::class, 'getTeams']);
    Route::get('/workspaces', [ClickUpController::class, 'getWorkspaces']);
    Route::get('/workspace/{teamId}/members', [ClickUpController::class, 'getWorkspaceMembers']);
    Route::get('/workspace/{teamId}/spaces', [ClickUpController::class, 'getSpacesForWorkspace']);
    Route::get('/space/{spaceId}/lists', [ClickUpController::class, 'getListsForSpace']);
    Route::get('/space/{spaceId}/lists-tasks', [ClickUpController::class, 'getAllListsData']);
    Route::get('/list/{listId}/tasks', [ClickUpController::class, 'getTasksForList']);
    Route::get('/list/{listId}/statuses', [ClickUpController::class, 'getListStatuses']);
    Route::get('/list/{listId}/completed-tasks', [ClickUpController::class, 'getCompletedTasks']);
    Route::post('/list/{listId}/task', [ClickUpController::class, 'createTask']);
    Route::put('/task/{taskId}/status', [ClickUpController::class, 'updateTaskStatus']);
    Route::put('/task/{taskId}/due-date', [ClickUpController::class, 'updateDueDate']);
    Route::put('/task/{taskId}/close', [ClickUpController::class, 'closeTask']);
    Route::put('/task/{taskId}/reopen', [ClickUpController::class, 'reopenTask']);
    Route::get('/task/{taskId}/last-comment', [ClickUpController::class, 'getLastComment']);
    Route::post('/task/{taskId}/comment', [ClickUpController::class, 'saveComment']);
});
