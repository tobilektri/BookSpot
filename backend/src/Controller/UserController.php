<?php

declare(strict_types=1);

namespace BookSpot\Controller;

use BookSpot\ApiException;
use BookSpot\Auth;
use BookSpot\Http\Response;
use BookSpot\InviteService;
use BookSpot\Request;

/**
 * User management. Creating with {send_invite: true} may omit the password:
 * the user sets it via the invitation link.
 */
final class UserController extends Controller
{
    /** Admins: all accounts; users: id + username only (e.g. to pick a borrower) */
    public function index(Request $request): array
    {
        $user = $this->user($request);
        $users = (new Auth($this->db()))->listUsers();
        if ($user['role'] !== Auth::ROLE_ADMIN) {
            $users = array_map(fn ($u) => ['id' => $u['id'], 'username' => $u['username']], $users);
        }
        return ['users' => $users];
    }

    /** {username, email, password, role: admin|user, locale, send_invite} */
    public function store(Request $request): Response
    {
        $admin = $this->admin($request);
        $input = $request->body;
        $sendInvite = filter_var($input['send_invite'] ?? false, FILTER_VALIDATE_BOOL);
        if ($sendInvite && trim((string) ($input['password'] ?? '')) === '') {
            // Unknown random password – the user chooses their own via the invitation link.
            $input['password'] = bin2hex(random_bytes(24));
        }
        $user = (new Auth($this->db()))->createUser($input);
        $result = ['user' => $user];
        if ($sendInvite) {
            $result['invite'] = (new InviteService($this->db()))->invite($user['id'], $admin['id'], InviteService::baseUrl());
        }
        return self::created($result);
    }

    /** {role, email, password} */
    public function update(Request $request): array
    {
        $admin = $this->admin($request);
        return ['user' => (new Auth($this->db()))->updateUser($admin['id'], $this->userId($request), $request->body)];
    }

    /** Not yourself, never the last admin */
    public function destroy(Request $request): array
    {
        $admin = $this->admin($request);
        (new Auth($this->db()))->deleteUser($admin['id'], $this->userId($request));
        return ['ok' => true];
    }

    /** (Re)send the invitation link by mail */
    public function invite(Request $request): array
    {
        $admin = $this->admin($request);
        return ['invite' => (new InviteService($this->db()))->invite($this->userId($request), $admin['id'], InviteService::baseUrl())];
    }

    /** Act as this user (ended via auth stop_impersonation) */
    public function impersonate(Request $request): array
    {
        $admin = $this->admin($request);
        return ['user' => Auth::startImpersonation($admin, $this->userId($request))];
    }

    private function userId(Request $request): int
    {
        return $this->id($request, 'id', 'user_not_found');
    }

    /** users.php */
    public function legacy(Request $request): array|Response
    {
        if ($request->isSafe()) {
            return $this->index($request);
        }
        if ($request->method === 'POST' && $request->action() === 'invite') {
            return $this->invite($request);
        }
        if ($request->method === 'POST' && $request->action() === 'impersonate') {
            return $this->impersonate($request);
        }
        return match ($request->method) {
            'POST' => $this->store($request),
            'PUT', 'PATCH' => $this->update($request),
            'DELETE' => $this->destroy($request),
            default => throw new ApiException('method_not_allowed', 405),
        };
    }
}
