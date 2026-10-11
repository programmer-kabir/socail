<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Utils/Response.php';

/**
 * SocialFlow - Workspace Isolation & Authorization Middleware
 */
class WorkspaceMiddleware
{
    public static function handle(array $request): array|bool
    {
        $userId = $request['user']['id'] ?? null;
        if (!$userId) {
            Response::error('Authentication required before workspace resolution.', 401);
            return false;
        }

        $pdo = Database::getConnection();

        // 1. Determine requested workspace ID
        $requestedWid = $_SERVER['HTTP_X_WORKSPACE_ID'] ?? $request['params']['workspace_id'] ?? null;

        if ($requestedWid !== null && is_numeric($requestedWid)) {
            $stmt = $pdo->prepare("
                SELECT w.id, w.owner_id, w.name, w.slug, w.timezone, wm.role, wm.created_at as member_since
                FROM workspaces w
                JOIN workspace_members wm ON w.id = wm.workspace_id
                WHERE wm.user_id = :uid AND w.id = :wid
            ");
            $stmt->execute([
                ':uid' => $userId,
                ':wid' => (int)$requestedWid,
            ]);
            $workspace = $stmt->fetch();

            if (!$workspace) {
                Response::error('Access denied to requested workspace.', 403);
                return false;
            }
        } else {
            // Fallback to user's first accessible workspace
            $stmt = $pdo->prepare("
                SELECT w.id, w.owner_id, w.name, w.slug, w.timezone, wm.role, wm.created_at as member_since
                FROM workspaces w
                JOIN workspace_members wm ON w.id = wm.workspace_id
                WHERE wm.user_id = :uid
                ORDER BY w.id ASC
                LIMIT 1
            ");
            $stmt->execute([':uid' => $userId]);
            $workspace = $stmt->fetch();

            if (!$workspace) {
                Response::error('No workspace accessible for this user.', 404);
                return false;
            }
        }

        $request['workspace'] = [
            'id'           => (int)$workspace['id'],
            'owner_id'     => (int)$workspace['owner_id'],
            'name'         => $workspace['name'],
            'slug'         => $workspace['slug'],
            'timezone'     => $workspace['timezone'],
            'role'         => $workspace['role'],
            'member_since' => $workspace['member_since'],
        ];

        return $request;
    }
}
