<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ClickUp extends Controller
{
    private $apiToken;
    private $clickupBaseApi;

    public function __construct()
    {
        $this->apiToken = env('CLICKUP_API_TOKEN');
        $this->clickupBaseApi = env('CLICKUP_BASE_API');
    }


    /**
     * Fetch all teams from ClickUp
     */
    public function getTeams()
    {

        try {
            $response = Http::withHeaders([
                'Authorization' => $this->apiToken,
            ])->get("{$this->clickupBaseApi}/team");

            return response()->json($response->json(), $response->status());
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch ClickUp teams',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fetch all workspaces (teams) from ClickUp
     */
    public function getWorkspaces()
    {

        try {
            $response = Http::withHeaders([
                'Authorization' => $this->apiToken,
            ])->get("{$this->clickupBaseApi}/team");

            $data = $response->json();

            if (!isset($data['teams']) || count($data['teams']) === 0) {
                return response()->json([
                    'error' => 'No teams found',
                ], 404);
            }

            $workspaces = collect($data['teams'])->map(fn($team) => [
                'id' => $team['id'],
                'name' => $team['name'],
            ])->values();

            return response()->json([
                'workspaces' => $workspaces,
                'raw' => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch workspaces',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fetch workspace (team) members from ClickUp
     */
    public function getWorkspaceMembers($teamId)
    {

        try {
            $response = Http::withHeaders([
                'Authorization' => $this->apiToken,
            ])->get("{$this->clickupBaseApi}/team/{$teamId}");

            $data = $response->json();

            if (!isset($data['team']['members'])) {
                return response()->json([
                    'error' => 'No members found for this workspace',
                    'raw' => $data
                ], 404);
            }

            $members = collect($data['team']['members'])->map(fn($m) => [
                'id' => $m['user']['id'] ?? null,
                'username' => $m['user']['username'] ?? null,
                'email' => $m['user']['email'] ?? null,
            ])->filter(fn($m) => $m['id'] !== null)->values();

            return response()->json([
                'team_id' => $teamId,
                'members' => $members,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch workspace members',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fetch all spaces for a given ClickUp workspace (team)
     */
    public function getSpacesForWorkspace($teamId)
    {

        try {
            $response = Http::withHeaders([
                'Authorization' => $this->apiToken,
            ])->get("{$this->clickupBaseApi}/team/{$teamId}/space", [
                'archived' => 'false',
            ]);

            $data = $response->json();

            if (!isset($data['spaces'])) {
                return response()->json([
                    'error' => 'No spaces found for this workspace',
                    'raw' => $data,
                ], 404);
            }

            $spaces = collect($data['spaces'])->map(fn($s) => [
                'id' => $s['id'],
                'name' => $s['name'],
            ])->values();

            return response()->json([
                'team_id' => $teamId,
                'spaces' => $spaces,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch spaces for workspace',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fetch all lists for a given ClickUp space (both inside folders and direct)
     */
    public function getListsForSpace($spaceId)
    {

        try {
            $foldersResponse = Http::withHeaders([
                'Authorization' => $this->apiToken,
            ])->get("{$this->clickupBaseApi}/space/{$spaceId}/folder", [
                'archived' => 'false',
            ]);

            $listsResponse = Http::withHeaders([
                'Authorization' => $this->apiToken,
            ])->get("{$this->clickupBaseApi}/space/{$spaceId}/list", [
                'archived' => 'false',
            ]);

            $foldersData = $foldersResponse->json();
            $listsData = $listsResponse->json();

            $lists = collect();

            if (isset($foldersData['folders'])) {
                foreach ($foldersData['folders'] as $folder) {
                    if (isset($folder['lists'])) {
                        foreach ($folder['lists'] as $list) {
                            $lists->push([
                                'id' => $list['id'],
                                'name' => "{$folder['name']} / {$list['name']}",
                            ]);
                        }
                    }
                }
            }

            if (isset($listsData['lists'])) {
                foreach ($listsData['lists'] as $list) {
                    $lists->push([
                        'id' => $list['id'],
                        'name' => $list['name'],
                    ]);
                }
            }

            if ($lists->isEmpty()) {
                return response()->json([
                    'space_id' => $spaceId,
                    'lists' => [],
                    'message' => 'No lists found for this space',
                ], 200);
            }

            return response()->json([
                'space_id' => $spaceId,
                'lists' => $lists->values(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch lists for space',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fetch all tasks for a specific ClickUp list
     */
    public function getTasksForList($listId)
    {

        try {
            $response = Http::withHeaders([
                'Authorization' => $this->apiToken,
            ])->get("{$this->clickupBaseApi}/list/{$listId}/task", [
                'archived' => 'false',
                'include_closed' => 'true',
            ]);

            $data = $response->json();

            if (!isset($data['tasks'])) {
                return response()->json([
                    'list_id' => $listId,
                    'tasks' => [],
                    'message' => 'No tasks found for this list',
                ], 200);
            }

            $openTasks = collect($data['tasks'])->filter(function ($task) {
                return ($task['status']['type'] ?? null) !== 'closed';
            })->values();

            return response()->json([
                'list_id' => $listId,
                'tasks' => $openTasks,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch tasks for list',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fetch available statuses for a given ClickUp list
     */
    public function getListStatuses($listId)
    {

        try {
            $response = Http::withHeaders([
                'Authorization' => $this->apiToken,
            ])->get("{$this->clickupBaseApi}/list/{$listId}");

            $data = $response->json();

            if (!isset($data['statuses'])) {
                return response()->json([
                    'list_id' => $listId,
                    'statuses' => [],
                    'message' => 'No statuses found for this list',
                ], 200);
            }

            $closedStatus = collect($data['statuses'])
                ->firstWhere('type', 'closed');

            return response()->json([
                'list_id' => $listId,
                'statuses' => $data['statuses'],
                'closed_status' => $closedStatus['status'] ?? null,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to load statuses for list',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fetch the last comment for a specific ClickUp task
     */
    public function getLastComment($taskId)
    {

        try {
            $response = Http::withHeaders([
                'Authorization' => $this->apiToken,
            ])->get("{$this->clickupBaseApi}/task/{$taskId}/comment");

            $data = $response->json();

            $lastComment = null;

            if (isset($data['comments']) && count($data['comments']) > 0) {
                $last = end($data['comments']);
                $lastComment = $last['comment_text'] ?? null;
            }

            return response()->json([
                'task_id' => $taskId,
                'last_comment' => $lastComment,
                'total_comments' => count($data['comments'] ?? []),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch last comment for task',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update a ClickUp task's status
     */
    public function updateTaskStatus(Request $request, $taskId)
    {

        $validated = $request->validate([
            'status' => 'required|string',
        ]);

        try {
            $response = Http::withHeaders([
                'Authorization' => $this->apiToken,
                'Content-Type' => 'application/json',
            ])->put("{$this->clickupBaseApi}/task/{$taskId}", [
                'status' => $validated['status'],
            ]);

            $data = $response->json();

            if ($response->successful() && isset($data['id'])) {
                return response()->json([
                    'success' => true,
                    'task_id' => $taskId,
                    'new_status' => $validated['status'],
                    'clickup_response' => $data,
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'error' => $data['err'] ?? 'Could not update task status',
                    'raw' => $data,
                ], $response->status());
            }
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to update task status',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fetch all completed (closed) tasks for a given list
     */
    public function getCompletedTasks($listId)
    {

        try {
            $response = Http::withHeaders([
                'Authorization' => $this->apiToken,
            ])->get("{$this->clickupBaseApi}/list/{$listId}/task", [
                'archived' => 'false',
                'include_closed' => 'true',
            ]);

            $data = $response->json();

            if (!isset($data['tasks'])) {
                return response()->json([
                    'list_id' => $listId,
                    'tasks' => [],
                    'message' => 'No tasks found for this list',
                ], 200);
            }

            $completedTasks = collect($data['tasks'])->filter(function ($task) {
                return ($task['status']['type'] ?? null) === 'closed';
            })->values();

            return response()->json([
                'list_id' => $listId,
                'tasks' => $completedTasks,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch completed tasks',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Close a ClickUp task (set its status to closed)
     */
    public function closeTask($taskId)
    {

        try {
            $listIdResponse = Http::withHeaders([
                'Authorization' => $this->apiToken,
            ])->get("{$this->clickupBaseApi}/task/{$taskId}");

            $taskData = $listIdResponse->json();

            if (!isset($taskData['list']['id'])) {
                return response()->json([
                    'error' => 'Unable to determine list for task.',
                    'data' => $taskData
                ], 400);
            }

            $listId = $taskData['list']['id'];

            $listResponse = Http::withHeaders([
                'Authorization' => $this->apiToken,
            ])->get("{$this->clickupBaseApi}/list/{$listId}");

            $listData = $listResponse->json();

            if (!isset($listData['statuses'])) {
                return response()->json([
                    'error' => 'No statuses found for list.',
                    'list_data' => $listData
                ], 400);
            }

            $closedStatus = collect($listData['statuses'])->firstWhere('type', 'closed')['status'] ?? null;

            if (!$closedStatus) {
                return response()->json([
                    'error' => 'Closed status not found for this list.'
                ], 400);
            }

            $updateResponse = Http::withHeaders([
                'Authorization' => $this->apiToken,
                'Content-Type' => 'application/json',
            ])->put("{$this->clickupBaseApi}/task/{$taskId}", [
                'status' => $closedStatus,
            ]);

            $data = $updateResponse->json();

            if ($updateResponse->successful() && isset($data['id'])) {
                return response()->json([
                    'success' => true,
                    'task_id' => $taskId,
                    'new_status' => $closedStatus,
                    'clickup_response' => $data,
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => 'Could not close the task',
                'clickup_response' => $data,
            ], $updateResponse->status());
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to close task',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Save (add) a comment on a ClickUp task
     */
    public function saveComment(Request $request, $taskId)
    {

        $validated = $request->validate([
            'comment_text' => 'required|string',
        ]);

        try {
            $response = Http::withHeaders([
                'Authorization' => $this->apiToken,
                'Content-Type' => 'application/json',
            ])->post("{$this->clickupBaseApi}/task/{$taskId}/comment", [
                'comment_text' => $validated['comment_text'],
            ]);

            $data = $response->json();

            if ($response->successful() && isset($data['id'])) {
                return response()->json([
                    'success' => true,
                    'task_id' => $taskId,
                    'comment' => $data,
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => $data['err'] ?? 'Failed to add comment',
                'clickup_response' => $data,
            ], $response->status());
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error saving comment',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a new task in a ClickUp list
     */
    public function createTask(Request $request, $listId)
    {

        $validated = $request->validate([
            'name' => 'required|string',
            'description' => 'nullable|string',
            'due_date' => 'nullable|numeric',
            'assignees' => 'nullable|array',
        ]);

        try {
            $payload = [
                'name' => $validated['name'],
                'description' => $validated['description'] ?? '',
            ];

            if (!empty($validated['due_date'])) {
                $payload['due_date'] = $validated['due_date'];
            }

            if (!empty($validated['assignees'])) {
                $payload['assignees'] = $validated['assignees'];
            }

            $response = Http::withHeaders([
                'Authorization' => $this->apiToken,
                'Content-Type' => 'application/json',
            ])->post("{$this->clickupBaseApi}/list/{$listId}/task", $payload);

            $data = $response->json();

            if ($response->successful() && isset($data['id'])) {
                return response()->json([
                    'success' => true,
                    'task' => $data,
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => $data['err'] ?? 'Failed to create task',
                'clickup_response' => $data,
            ], $response->status());
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error creating task',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fetch all lists (folders + direct) and their tasks for a given space
     */
    public function getAllListsData($spaceId)
    {

        try {
            $foldersResponse = Http::withHeaders([
                'Authorization' => $this->apiToken,
            ])->get("{$this->clickupBaseApi}/space/{$spaceId}/folder", [
                'archived' => 'false',
            ]);

            $foldersData = $foldersResponse->json();

            $listsResponse = Http::withHeaders([
                'Authorization' => $this->apiToken,
            ])->get("{$this->clickupBaseApi}/space/{$spaceId}/list", [
                'archived' => 'false',
            ]);

            $listsData = $listsResponse->json();

            $allLists = collect();

            if (!empty($foldersData['folders'])) {
                foreach ($foldersData['folders'] as $folder) {
                    if (!empty($folder['lists'])) {
                        foreach ($folder['lists'] as $list) {
                            $allLists->push([
                                'id' => $list['id'],
                                'name' => "{$folder['name']} / {$list['name']}",
                            ]);
                        }
                    }
                }
            }

            if (!empty($listsData['lists'])) {
                foreach ($listsData['lists'] as $list) {
                    $allLists->push([
                        'id' => $list['id'],
                        'name' => $list['name'],
                    ]);
                }
            }

            if ($allLists->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'message' => 'No lists found in this space.',
                    'lists' => [],
                ]);
            }

            $listTasks = [];
            foreach ($allLists as $list) {
                $tasksResponse = Http::withHeaders([
                    'Authorization' => $this->apiToken,
                ])->get("{$this->clickupBaseApi}/list/{$list['id']}/task", [
                    'archived' => 'false',
                    'include_closed' => 'true',
                ]);

                $tasksData = $tasksResponse->json();
                $listTasks[] = [
                    'list_id' => $list['id'],
                    'list_name' => $list['name'],
                    'tasks' => $tasksData['tasks'] ?? [],
                ];
            }

            return response()->json([
                'success' => true,
                'space_id' => $spaceId,
                'lists' => $allLists,
                'list_tasks' => $listTasks,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch all lists and tasks',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fetch folders and lists for a given ClickUp space
     */
    public function getLists($spaceId)
    {

        try {
            $foldersResponse = Http::withHeaders([
                'Authorization' => $this->apiToken,
            ])->get("{$this->clickupBaseApi}/space/{$spaceId}/folder", [
                'archived' => 'false',
            ]);

            $listsResponse = Http::withHeaders([
                'Authorization' => $this->apiToken,
            ])->get("{$this->clickupBaseApi}/space/{$spaceId}/list", [
                'archived' => 'false',
            ]);

            $foldersData = $foldersResponse->json();
            $listsData = $listsResponse->json();

            $allLists = [];

            if (!empty($foldersData['folders'])) {
                foreach ($foldersData['folders'] as $folder) {
                    if (!empty($folder['lists'])) {
                        foreach ($folder['lists'] as $list) {
                            $allLists[] = [
                                'id' => $list['id'],
                                'name' => "{$folder['name']} / {$list['name']}",
                            ];
                        }
                    }
                }
            }

            if (!empty($listsData['lists'])) {
                foreach ($listsData['lists'] as $list) {
                    $allLists[] = [
                        'id' => $list['id'],
                        'name' => $list['name'],
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'space_id' => $spaceId,
                'lists' => $allLists,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch lists for space',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Reopen a ClickUp task (change status from closed to an open status)
     */
    public function reopenTask($taskId)
    {

        try {
            $taskResponse = Http::withHeaders([
                'Authorization' => $this->apiToken,
            ])->get("{$this->clickupBaseApi}/task/{$taskId}");

            $taskData = $taskResponse->json();

            if (!isset($taskData['list']['id'])) {
                return response()->json([
                    'error' => 'Unable to determine list for this task.',
                    'data' => $taskData,
                ], 400);
            }

            $listId = $taskData['list']['id'];

            $listResponse = Http::withHeaders([
                'Authorization' => $this->apiToken,
            ])->get("{$this->clickupBaseApi}/list/{$listId}");

            $listData = $listResponse->json();

            if (empty($listData['statuses'])) {
                return response()->json([
                    'error' => 'No statuses found for this list.',
                    'list_data' => $listData,
                ], 400);
            }

            $openStatus = collect($listData['statuses'])->firstWhere('type', '!=', 'closed');

            if (!$openStatus) {
                return response()->json([
                    'error' => 'No open status found for this list.',
                ], 400);
            }

            $updateResponse = Http::withHeaders([
                'Authorization' => $this->apiToken,
                'Content-Type' => 'application/json',
            ])->put("{$this->clickupBaseApi}/task/{$taskId}", [
                'status' => $openStatus['status'],
            ]);

            $updateData = $updateResponse->json();

            if ($updateResponse->successful() && isset($updateData['id'])) {
                return response()->json([
                    'success' => true,
                    'task_id' => $taskId,
                    'new_status' => $openStatus['status'],
                    'clickup_response' => $updateData,
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => 'Failed to reopen task.',
                'clickup_response' => $updateData,
            ], $updateResponse->status());
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error reopening task.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update the due date of a ClickUp task
     */
    public function updateDueDate(Request $request, $taskId)
    {

        $validated = $request->validate([
            'due_date' => 'required|numeric', // timestamp in milliseconds
        ]);

        try {
            $response = Http::withHeaders([
                'Authorization' => $this->apiToken,
                'Content-Type' => 'application/json',
            ])->put("{$this->clickupBaseApi}/task/{$taskId}", [
                'due_date' => $validated['due_date'],
            ]);

            $data = $response->json();

            if ($response->successful()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Due date updated successfully.',
                    'task_id' => $taskId,
                    'clickup_response' => $data,
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => $data['err'] ?? 'Failed to update due date',
                'raw' => $data,
            ], $response->status());
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error updating due date',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
