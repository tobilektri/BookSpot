<?php

declare(strict_types=1);

require __DIR__ . '/../../backend/bootstrap.php';

use BookSpot\Api;
use BookSpot\App;
use BookSpot\ApiException;
use BookSpot\Auth;
use BookSpot\Request;

/*
 * User management.
 * GET    users.php          admins: all accounts; users: id + username only (e.g. to pick a borrower)
 * POST   users.php          admin: create {username, email, password, role: admin|user, locale}
 * PUT    users.php?id=1     admin: {role, email, password}
 * DELETE users.php?id=1     admin: delete account (not yourself, never the last admin)
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
    return match ($req->method) {
        'POST' => (function () use ($auth, $req) {
            Api::status(201);
            return ['user' => $auth->createUser($req->body)];
        })(),
        'PUT', 'PATCH' => ['user' => $auth->updateUser($admin['id'], $id ?? throw ApiException::notFound('user_not_found'), $req->body)],
        'DELETE' => (function () use ($auth, $admin, $id) {
            $auth->deleteUser($admin['id'], $id ?? throw ApiException::notFound('user_not_found'));
            return ['ok' => true];
        })(),
        default => Api::methodNotAllowed(),
    };
});
