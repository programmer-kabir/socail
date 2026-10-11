<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Utils/Response.php';

/**
 * SocialFlow - Workspace Management Controller
 */
class WorkspaceController
{
    /**
     * List all workspaces accessible by the user
     */
    public function index(array $request): void
    {
        $userId = $request['user']['id'];
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("
            SELECT w.id, w.name, w.slug, w.timezone, w.owner_id, wm.role, wm.created_at as member_since,
                   (SELECT COUNT(*) FROM workspace_members WHERE workspace_id = w.id) as member_count
            FROM workspaces w
            JOIN workspace_members wm ON w.id = wm.workspace_id
            WHERE wm.user_id = :uid
            ORDER BY w.id ASC
        ");
        $stmt->execute([':uid' => $userId]);
        $workspaces = $stmt->fetchAll();

        Response::json($workspaces, 200);
    }

    /**
     * Create a new workspace
     */
    public function create(array $request): void
    {
        $userId = $request['user']['id'];
        $body = $request['body'] ?? [];
        $name = trim($body['name'] ?? '');
        $timezone = trim($body['timezone'] ?? 'UTC');

        if (strlen($name) < 2 || strlen($name) > 100) {
            Response::error('Workspace name must be between 2 and 100 characters.', 422, [
                'name' => ['Workspace name must be between 2 and 100 characters.']
            ]);
        }

        // Validate timezone
        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            $timezone = 'UTC';
        }

        $slugBase = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
        $slug = trim($slugBase, '-') . '-' . substr(bin2hex(random_bytes(4)), 0, 6);

        $pdo = Database::getConnection();

        try {
            $pdo->beginTransaction();

            $stmtWs = $pdo->prepare("
                INSERT INTO workspaces (owner_id, name, slug, timezone)
                VALUES (:owner_id, :name, :slug, :timezone)
            ");
            $stmtWs->execute([
                ':owner_id' => $userId,
                ':name'     => $name,
                ':slug'     => $slug,
                ':timezone' => $timezone,
            ]);
            $workspaceId = (int)$pdo->lastInsertId();

            $stmtMember = $pdo->prepare("
                INSERT INTO workspace_members (workspace_id, user_id, role)
                VALUES (:workspace_id, :user_id, 'owner')
            ");
            $stmtMember->execute([
                ':workspace_id' => $workspaceId,
                ':user_id'      => $userId,
            ]);

            $pdo->commit();

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::error('Failed to create workspace: ' . $e->getMessage(), 500);
        }

        Response::json([
            'id'       => $workspaceId,
            'name'     => $name,
            'slug'     => $slug,
            'timezone' => $timezone,
            'role'     => 'owner',
        ], 201, 'Workspace created successfully.');
    }

    /**
     * Get active workspace details and members
     */
    public function current(array $request): void
    {
        $workspace = $request['workspace'];
        $workspaceId = $workspace['id'];

        $pdo = Database::getConnection();
        $stmtMembers = $pdo->prepare("
            SELECT wm.id, wm.user_id, wm.role, wm.created_at as joined_at,
                   u.name, u.email, u.avatar_url
            FROM workspace_members wm
            JOIN users u ON wm.user_id = u.id
            WHERE wm.workspace_id = :wid
            ORDER BY wm.role = 'owner' DESC, wm.created_at ASC
        ");
        $stmtMembers->execute([':wid' => $workspaceId]);
        $members = $stmtMembers->fetchAll();

        Response::json([
            'workspace' => $workspace,
            'members'   => $members,
        ], 200);
    }

    /**
     * Update active workspace settings (Owner/Admin only)
     */
    public function update(array $request): void
    {
        $workspace = $request['workspace'];
        $workspaceId = $workspace['id'];
        $userRole = $workspace['role'];

        if ($userRole !== 'owner' && $userRole !== 'admin') {
            Response::error('Only workspace owners and administrators can update workspace settings.', 403);
        }

        $body = $request['body'] ?? [];
        $name = trim($body['name'] ?? $workspace['name']);
        $timezone = trim($body['timezone'] ?? $workspace['timezone']);

        if (strlen($name) < 2 || strlen($name) > 100) {
            Response::error('Workspace name must be between 2 and 100 characters.', 422, [
                'name' => ['Workspace name must be between 2 and 100 characters.']
            ]);
        }

        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            $timezone = $workspace['timezone'];
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            UPDATE workspaces 
            SET name = :name, timezone = :timezone 
            WHERE id = :id
        ");
        $stmt->execute([
            ':name'     => $name,
            ':timezone' => $timezone,
            ':id'       => $workspaceId,
        ]);

        $workspace['name'] = $name;
        $workspace['timezone'] = $timezone;

        Response::json($workspace, 200, 'Workspace updated successfully.');
    }
}
