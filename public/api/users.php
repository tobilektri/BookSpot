<?php

declare(strict_types=1);

require __DIR__ . '/../../backend/bootstrap.php';

use BookSpot\Api;
use BookSpot\App;
use BookSpot\ApiException;
use BookSpot\Auth;
use BookSpot\InviteService;
use BookSpot\Request;

/*
 * User management.
 * GET    users.php          admins: all accounts; users: id + username only (e.g. to pick a borrower)
 * POST   users.php          admin: create {username, email, password, role: admin|user, locale}
 * PUT    users.php?id=1     admin: {role, email, password}
 * DELETE users.php?id=1     admin: delete account (not yourself, never the last admin)
 * POST   users.php?action=invite&id=1       admin: (re)send invitation link by mail
 * POST   users.php?action=impersonate&id=1  admin: act as this user (auth.php?action=stop_impersonation)
 *
 * Creating with {send_invite: true} may omit the password: the user sets it via the link.
 */
Api::run(function (Request $req) {
    $user = Auth::requireUser();
    $auth = new Auth(App::db());

    if ($req->isSafe()) {
        $users = $auth->listUsers();
        if ($user['role'] !== Auth::ROLE_ADMIN) {
            $users = array_map(fn ($u) => ['id' => $u['id'], 'username' => $u['username']], $users);
        }
        return ['users' => $users];
    }

    $admin = Auth::requireAdmin();
    $id = $req->queryInt('id');
    $invites = new InviteService(App::db());

    if ($req->method === 'POST' && $req->action() === 'invite') {
        return ['invite' => $invites->invite($id ?? throw ApiException::notFound('user_not_found'), $admin['id'], InviteService::baseUrl())];
    }
    if ($req->method === 'POST' && $req->action() === 'impersonate') {
        return ['user' => Auth::startImpersonation($admin, $id ?? throw ApiException::notFound('user_not_found'))];
    }

    return match ($req->method) {
        'POST' => (function () use ($auth, $req, $admin, $invites) {
            $input = $req->body;
            $sendInvite = filter_var($input['send_invite'] ?? false, FILTER_VALIDATE_BOOL);
            if ($sendInvite && trim((string) ($input['password'] ?? '')) === '') {
                // Unknown random password – the user chooses their own via the invitation link.
                $input['password'] = bin2hex(random_bytes(24));
            }
            $user = $auth->createUser($input);
            Api::status(201);
            $result = ['user' => $user];
            if ($sendInvite) {
                $result['invite'] = $invites->invite($user['id'], $admin['id'], InviteService::baseUrl());
            }
            return $result;
        })(),
        'PUT', 'PATCH' => ['user' => $auth->updateUser($admin['id'], $id ?? throw ApiException::notFound('user_not_found'), $req->body)],
        'DELETE' => (function () use ($auth, $admin, $id) {
            $auth->deleteUser($admin['id'], $id ?? throw ApiException::notFound('user_not_found'));
            return ['ok' => true];
        })(),
        default => Api::methodNotAllowed(),
    };
});
