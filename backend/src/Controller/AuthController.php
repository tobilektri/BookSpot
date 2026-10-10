<?php

declare(strict_types=1);

namespace BookSpot\Controller;

use BookSpot\ApiException;
use BookSpot\App;
use BookSpot\Auth;
use BookSpot\Config;
use BookSpot\Http\Response;
use BookSpot\InviteService;
use BookSpot\PushService;
use BookSpot\Request;

final class AuthController extends Controller
{
    /** App start: who am I, can I register, which features are available? */
    public function status(Request $request): array
    {
        $auth = new Auth($this->db());
        $impersonator = Auth::impersonator();
        return [
            'user' => Auth::user(),
            'impersonator' => $impersonator === null ? null : ['id' => $impersonator['id'], 'username' => $impersonator['username']],
            'registration_open' => $auth->registrationOpen(),
            'needs_setup' => $auth->userCount() === 0,
            'push_available' => PushService::available(),
            'app_name' => Config::get('APP_NAME'),
            'version' => App::VERSION,
        ];
    }

    public function login(Request $request): array
    {
        $user = (new Auth($this->db()))->attemptLogin((string) $request->input('login', ''), (string) $request->input('password', ''), $request->ip);
        Auth::loginSession($user);
        return ['user' => $user];
    }

    public function register(Request $request): Response
    {
        $user = (new Auth($this->db()))->register(
            (string) $request->input('username', ''),
            (string) $request->input('email', ''),
            (string) $request->input('password', ''),
            (string) $request->input('locale', 'de'),
        );
        Auth::loginSession($user);
        return self::created(['user' => $user]);
    }

    public function logout(Request $request): array
    {
        Auth::logoutSession();
        return ['ok' => true];
    }

    public function onboarded(Request $request): array
    {
        $user = $this->user($request);
        // An admin looking at the app as someone else must not complete their introduction.
        if (Auth::impersonator() === null) {
            (new Auth($this->db()))->markOnboarded($user['id']);
        }
        return ['ok' => true];
    }

    public function stopImpersonation(Request $request): array
    {
        return ['user' => Auth::stopImpersonation()];
    }

    /** Invitation link (public): who is invited? */
    public function inviteInfo(Request $request): array
    {
        return (new InviteService($this->db()))->info((string) ($request->params['token'] ?? $request->query('token', '')));
    }

    public function acceptInvite(Request $request): array
    {
        $user = (new InviteService($this->db()))->accept((string) $request->input('token', ''), (string) $request->input('password', ''));
        Auth::loginSession($user);
        return ['user' => $user];
    }

    public function profile(Request $request): array
    {
        return ['user' => $this->user($request)];
    }

    public function updateProfile(Request $request): array
    {
        $user = $this->user($request);
        return ['user' => (new Auth($this->db()))->updateProfile($user['id'], $request->body)];
    }

    /** auth.php?action=… */
    public function legacy(Request $request): array|Response
    {
        $action = $request->action();
        if (!in_array($action, ['status', 'invite_info', 'profile'], true)) {
            self::requirePost($request);
        }
        return match ($action) {
            'status' => $this->status($request),
            'login' => $this->login($request),
            'register' => $this->register($request),
            'onboarded' => $this->onboarded($request),
            'stop_impersonation' => $this->stopImpersonation($request),
            'invite_info' => $this->inviteInfo($request),
            'accept_invite' => $this->acceptInvite($request),
            'logout' => $this->logout($request),
            'profile' => $request->isSafe() ? $this->profile($request) : $this->updateProfile($request),
            default => throw new ApiException('unknown_action', 404),
        };
    }
}
