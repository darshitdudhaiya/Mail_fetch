<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClickUp;
use App\Http\Controllers\SheetController;
use Illuminate\Support\Facades\Route;


Route::post('/auth/token', [AuthController::class, 'handleToken']);
Route::get('/auth/user', [AuthController::class, 'getCurrentUser']);
Route::post('/auth/logout', [AuthController::class, 'logout']);

Route::get('/emails/unreplied', [AuthController::class, 'getUnrepliedEmails']);
Route::get('/emails/{messageId}', [AuthController::class, 'getEmailDetails']);


Route::get('/sheets/sheet-data', [SheetController::class, 'getSheetData']);

Route::get('/clickup/teams', [ClickUp::class, 'getTeams']);
Route::get('/clickup/workspaces', [ClickUp::class, 'getWorkspaces']);
Route::get('/clickup/workspace/{teamId}/members', [ClickUp::class, 'getWorkspaceMembers']);
Route::get('/clickup/workspace/{teamId}/spaces', [ClickUp::class, 'getSpacesForWorkspace']);
Route::get('/clickup/space/{spaceId}/lists', [ClickUp::class, 'getListsForSpace']);
Route::get('/clickup/list/{listId}/tasks', [ClickUp::class, 'getTasksForList']);
Route::get('/clickup/list/{listId}/statuses', [ClickUp::class, 'getListStatuses']);
Route::get('/clickup/task/{taskId}/last-comment', [ClickUp::class, 'getLastComment']);
Route::put('/clickup/task/{taskId}/status', [ClickUp::class, 'updateTaskStatus']);
Route::get('/clickup/list/{listId}/completed-tasks', [ClickUp::class, 'getCompletedTasks']);
Route::put('/clickup/task/{taskId}/close', [ClickUp::class, 'closeTask']);
Route::post('/clickup/task/{taskId}/comment', [ClickUp::class, 'saveComment']);
Route::post('/clickup/list/{listId}/task', [ClickUp::class, 'createTask']);
Route::get('/clickup/space/{spaceId}/lists-tasks', [ClickUp::class, 'getAllListsData']);
Route::get('/clickup/space/{spaceId}/lists', [ClickUp::class, 'getLists']);
Route::put('/clickup/task/{taskId}/reopen', [ClickUp::class, 'reopenTask']);
Route::put('/clickup/task/{taskId}/due-date', [ClickUp::class, 'updateDueDate']);


