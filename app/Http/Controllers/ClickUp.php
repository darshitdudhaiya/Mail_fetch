<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ClickUp extends Controller
{
    public function getTeams()
    {
        $apiToken = env('CLICKUP_API_TOKEN'); // store your key in .env for security

        try {
            $response = Http::withHeaders([
                'Authorization' => $apiToken,
            ])->get('https://api.clickup.com/api/v2/team');

            // Return ClickUp's response directly to frontend
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
        $apiToken = env('CLICKUP_API_TOKEN');

        try {
            // Call ClickUp API
            $response = Http::withHeaders([
                'Authorization' => $apiToken,
            ])->get('https://api.clickup.com/api/v2/team');

            // Parse response
            $data = $response->json();

            if (!isset($data['teams']) || count($data['teams']) === 0) {
                return response()->json([
                    'error' => 'No teams found',
                ], 404);
            }

            // Simplify structure to send only id + name
            $workspaces = collect($data['teams'])->map(fn($team) => [
                'id' => $team['id'],
                'name' => $team['name'],
            ])->values();

            return response()->json([
                'workspaces' => $workspaces,
                'raw' => $data, // optional, full data if you want it
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
        $apiToken = env('CLICKUP_API_TOKEN');

        try {
            // Fetch workspace details from ClickUp API
            $response = Http::withHeaders([
                'Authorization' => $apiToken,
            ])->get("https://api.clickup.com/api/v2/team/{$teamId}");

            $data = $response->json();

            if (!isset($data['team']['members'])) {
                return response()->json([
                    'error' => 'No members found for this workspace',
                    'raw' => $data
                ], 404);
            }

            // Simplify the structure
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
        $apiToken = env('CLICKUP_API_TOKEN');

        try {
            // Call ClickUp API for spaces under a team
            $response = Http::withHeaders([
                'Authorization' => $apiToken,
            ])->get("https://api.clickup.com/api/v2/team/{$teamId}/space", [
                'archived' => 'false',
            ]);

            $data = $response->json();

            if (!isset($data['spaces'])) {
                return response()->json([
                    'error' => 'No spaces found for this workspace',
                    'raw' => $data,
                ], 404);
            }

            // Simplify structure: return only id + name
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
        $apiToken = env('CLICKUP_API_TOKEN');

        try {
            // Fetch folders and direct lists concurrently
            $foldersResponse = Http::withHeaders([
                'Authorization' => $apiToken,
            ])->get("https://api.clickup.com/api/v2/space/{$spaceId}/folder", [
                'archived' => 'false',
            ]);

            $listsResponse = Http::withHeaders([
                'Authorization' => $apiToken,
            ])->get("https://api.clickup.com/api/v2/space/{$spaceId}/list", [
                'archived' => 'false',
            ]);

            $foldersData = $foldersResponse->json();
            $listsData = $listsResponse->json();

            $lists = collect();

            // ✅ Extract lists from folders
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

            // ✅ Extract direct lists
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
        $apiToken = env('CLICKUP_API_TOKEN');

        try {
            // Fetch all tasks (including closed, not archived)
            $response = Http::withHeaders([
                'Authorization' => $apiToken,
            ])->get("https://api.clickup.com/api/v2/list/{$listId}/task", [
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

            // Filter out closed tasks (status.type === "closed")
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
        $apiToken = env('CLICKUP_API_TOKEN');

        try {
            // Call ClickUp API for list details
            $response = Http::withHeaders([
                'Authorization' => $apiToken,
            ])->get("https://api.clickup.com/api/v2/list/{$listId}");

            $data = $response->json();

            if (!isset($data['statuses'])) {
                return response()->json([
                    'list_id' => $listId,
                    'statuses' => [],
                    'message' => 'No statuses found for this list',
                ], 200);
            }

            // Return all statuses and identify the "closed" one
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
        $apiToken = env('CLICKUP_API_TOKEN');

        try {
            // Call ClickUp API for comments
            $response = Http::withHeaders([
                'Authorization' => $apiToken,
            ])->get("https://api.clickup.com/api/v2/task/{$taskId}/comment");

            $data = $response->json();

            // Get the last comment text
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
        $apiToken = env('CLICKUP_API_TOKEN');

        // Validate input
        $validated = $request->validate([
            'status' => 'required|string',
        ]);

        try {
            // Send PUT request to ClickUp API
            $response = Http::withHeaders([
                'Authorization' => $apiToken,
                'Content-Type' => 'application/json',
            ])->put("https://api.clickup.com/api/v2/task/{$taskId}", [
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
        $apiToken = env('CLICKUP_API_TOKEN');

        try {
            // Fetch all tasks (including closed) from ClickUp
            $response = Http::withHeaders([
                'Authorization' => $apiToken,
            ])->get("https://api.clickup.com/api/v2/list/{$listId}/task", [
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

            // ✅ Filter completed (closed) tasks only
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
        $apiToken = env('CLICKUP_API_TOKEN');

        try {
            // Get list details first to find the closed status name
            $listIdResponse = Http::withHeaders([
                'Authorization' => $apiToken,
            ])->get("https://api.clickup.com/api/v2/task/{$taskId}");

            $taskData = $listIdResponse->json();

            if (!isset($taskData['list']['id'])) {
                return response()->json([
                    'error' => 'Unable to determine list for task.',
                    'data' => $taskData
                ], 400);
            }

            $listId = $taskData['list']['id'];

            // Fetch list to get statuses
            $listResponse = Http::withHeaders([
                'Authorization' => $apiToken,
            ])->get("https://api.clickup.com/api/v2/list/{$listId}");

            $listData = $listResponse->json();

            if (!isset($listData['statuses'])) {
                return response()->json([
                    'error' => 'No statuses found for list.',
                    'list_data' => $listData
                ], 400);
            }

            // Find the closed status name
            $closedStatus = collect($listData['statuses'])->firstWhere('type', 'closed')['status'] ?? null;

            if (!$closedStatus) {
                return response()->json([
                    'error' => 'Closed status not found for this list.'
                ], 400);
            }

            // Now update the task status to closed
            $updateResponse = Http::withHeaders([
                'Authorization' => $apiToken,
                'Content-Type' => 'application/json',
            ])->put("https://api.clickup.com/api/v2/task/{$taskId}", [
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
        $apiToken = env('CLICKUP_API_TOKEN');

        $validated = $request->validate([
            'comment_text' => 'required|string',
        ]);

        try {
            // Send the comment to ClickUp
            $response = Http::withHeaders([
                'Authorization' => $apiToken,
                'Content-Type' => 'application/json',
            ])->post("https://api.clickup.com/api/v2/task/{$taskId}/comment", [
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
        $apiToken = env('CLICKUP_API_TOKEN');

        $validated = $request->validate([
            'name' => 'required|string',
            'description' => 'nullable|string',
            'due_date' => 'nullable|numeric',
            'assignees' => 'nullable|array',
        ]);

        try {
            // Prepare payload for ClickUp
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

            // Send to ClickUp API
            $response = Http::withHeaders([
                'Authorization' => $apiToken,
                'Content-Type' => 'application/json',
            ])->post("https://api.clickup.com/api/v2/list/{$listId}/task", $payload);

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
        $apiToken = env('CLICKUP_API_TOKEN');

        try {
            // Step 1: Fetch all folders in this space
            $foldersResponse = Http::withHeaders([
                'Authorization' => $apiToken,
            ])->get("https://api.clickup.com/api/v2/space/{$spaceId}/folder", [
                'archived' => 'false',
            ]);

            $foldersData = $foldersResponse->json();

            // Step 2: Fetch lists directly under the space
            $listsResponse = Http::withHeaders([
                'Authorization' => $apiToken,
            ])->get("https://api.clickup.com/api/v2/space/{$spaceId}/list", [
                'archived' => 'false',
            ]);

            $listsData = $listsResponse->json();

            // Step 3: Combine folder lists + direct lists
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

            // Step 4: Fetch tasks for all lists
            $listTasks = [];
            foreach ($allLists as $list) {
                $tasksResponse = Http::withHeaders([
                    'Authorization' => $apiToken,
                ])->get("https://api.clickup.com/api/v2/list/{$list['id']}/task", [
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
        $apiToken = env('CLICKUP_API_TOKEN');

        try {
            // Fetch both folders and direct lists in parallel
            $foldersResponse = Http::withHeaders([
                'Authorization' => $apiToken,
            ])->get("https://api.clickup.com/api/v2/space/{$spaceId}/folder", [
                'archived' => 'false',
            ]);

            $listsResponse = Http::withHeaders([
                'Authorization' => $apiToken,
            ])->get("https://api.clickup.com/api/v2/space/{$spaceId}/list", [
                'archived' => 'false',
            ]);

            $foldersData = $foldersResponse->json();
            $listsData = $listsResponse->json();

            $allLists = [];

            // Combine folder lists
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

            // Combine direct lists
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
        $apiToken = env('CLICKUP_API_TOKEN');

        try {
            // Step 1: Get the task to find its list ID
            $taskResponse = Http::withHeaders([
                'Authorization' => $apiToken,
            ])->get("https://api.clickup.com/api/v2/task/{$taskId}");

            $taskData = $taskResponse->json();

            if (!isset($taskData['list']['id'])) {
                return response()->json([
                    'error' => 'Unable to determine list for this task.',
                    'data' => $taskData,
                ], 400);
            }

            $listId = $taskData['list']['id'];

            // Step 2: Fetch list details to get statuses
            $listResponse = Http::withHeaders([
                'Authorization' => $apiToken,
            ])->get("https://api.clickup.com/api/v2/list/{$listId}");

            $listData = $listResponse->json();

            if (empty($listData['statuses'])) {
                return response()->json([
                    'error' => 'No statuses found for this list.',
                    'list_data' => $listData,
                ], 400);
            }

            // Step 3: Find the first non-closed status (open)
            $openStatus = collect($listData['statuses'])->firstWhere('type', '!=', 'closed');

            if (!$openStatus) {
                return response()->json([
                    'error' => 'No open status found for this list.',
                ], 400);
            }

            // Step 4: Update the task status to the open status
            $updateResponse = Http::withHeaders([
                'Authorization' => $apiToken,
                'Content-Type' => 'application/json',
            ])->put("https://api.clickup.com/api/v2/task/{$taskId}", [
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
        $apiToken = env('CLICKUP_API_TOKEN');

        $validated = $request->validate([
            'due_date' => 'required|numeric', // timestamp in milliseconds
        ]);

        try {
            // Send PUT request to ClickUp
            $response = Http::withHeaders([
                'Authorization' => $apiToken,
                'Content-Type' => 'application/json',
            ])->put("https://api.clickup.com/api/v2/task/{$taskId}", [
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
