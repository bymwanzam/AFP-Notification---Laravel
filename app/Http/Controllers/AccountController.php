<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Accounts;
use App\Support\Access;
use App\Support\Branding;
use App\Support\OrgUnits;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Sign-in, passwords, profiles and the support account's Accounts page.
 *
 * The login page has four modes: "login"; "setup", while there are no accounts yet, which makes the first account
 * and the region it works for; "change", for a signed-in account that must set its own password before going on;
 * and "password" (at /password), where any signed-in account changes its password when it wants to.
 */
class AccountController extends Controller
{
    private function loginPage(string $mode, string $next)
    {
        $regions = $mode === 'setup' ? array_column(OrgUnits::all(), 'name') : [];

        return response()->view('accounts.login', ['logo' => Branding::dataUri(Branding::logo()),
            'icon' => Branding::dataUri(Branding::emblem()),
            'data' => ['mode' => $mode, 'next' => $next, 'regions' => $regions]]);
    }

    public function login(Request $request)
    {
        $next = Access::safeNext($request->query('next'));
        $user = $request->user();
        if ($user && ! $user->must_change) {
            return redirect($next);
        }
        $mode = $user ? 'change' : (Accounts::hasAccounts() ? 'login' : 'setup');

        return \App\Http\Middleware\NoCache::apply($this->loginPage($mode, $next));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    public function passwordPage(Request $request)
    {
        return $this->loginPage('password', Access::safeNext($request->query('next')));
    }

    /** /api/login, and /api/setup: the first account, only while there are no accounts. */
    public function loginApi(Request $request)
    {
        try {
            $d = Access::body($request);
            $username = (string) ($d['username'] ?? '');
            $pw = (string) ($d['password'] ?? '');
            if ($request->is('api/setup')) {
                if (Accounts::hasAccounts()) {
                    return Access::error(409, 'An account already exists. Reload the page to sign in.');
                }
                if ($problem = Accounts::checkNew($username, $pw, (string) ($d['confirm'] ?? ''))) {
                    return Access::error(400, $problem);
                }
                try {
                    Accounts::createAccount($username, $pw, (string) ($d['name'] ?? ''), (string) ($d['region'] ?? ''));
                } catch (\InvalidArgumentException $e) {
                    return Access::error(400, $e->getMessage());
                }
            }
            $user = Accounts::verify($username, $pw);
        } catch (\InvalidArgumentException $e) {
            return Access::error(401, $e->getMessage());
        }
        Auth::login($user);
        $request->session()->regenerate();
        $go = Access::safeNext((string) ($d['next'] ?? ''));

        // An account that must set its own password goes to the change-password form first.
        return Access::ok(['go' => $user->must_change ? '/login?next='.rawurlencode($go) : $go]);
    }

    public function passwordApi(Request $request)
    {
        try {
            $d = Access::body($request);
            Accounts::changePassword($request->user(), (string) ($d['current'] ?? ''), (string) ($d['password'] ?? ''),
                (string) ($d['confirm'] ?? ''));
        } catch (\InvalidArgumentException $e) {
            return Access::error(400, $e->getMessage());
        }
        // Stays signed in here; other sign-ins end.
        DB::table('sessions')->where('user_id', $request->user()->id)->where('id', '!=', $request->session()->getId())->delete();

        return Access::ok(['go' => Access::safeNext((string) ($d['next'] ?? ''))]);
    }

    public function me(Request $request)
    {
        return Access::ok(['user' => $request->user()->username, 'role' => Accounts::role($request->user())]);
    }

    // ---------- profile: full name, cadre and picture ----------
    public function profilePage(Request $request)
    {
        $data = Accounts::profile($request->user()) + ['next' => Access::safeNext($request->query('next'))];

        return view('accounts.profile', ['logo' => Branding::dataUri(Branding::emblem()), 'data' => $data]);
    }

    public function profileApi(Request $request)
    {
        if ($request->isMethod('get')) {
            return Access::ok(Accounts::profile($request->user()));
        }
        try {
            $d = Access::body($request);
            Accounts::setProfile($request->user(), (string) ($d['name'] ?? ''), (string) ($d['cadre'] ?? ''));
        } catch (\InvalidArgumentException $e) {
            return Access::error(400, $e->getMessage());
        }

        return Access::ok(Accounts::profile($request->user()));
    }

    /** GET: your own picture; support may see anyone's (?u=, on the Accounts page). POST: set or remove yours. */
    public function photoApi(Request $request)
    {
        if ($request->isMethod('post')) {
            try {
                Accounts::setPhoto($request->user(), $request->getContent());
            } catch (\InvalidArgumentException $e) {
                return Access::error(400, $e->getMessage());
            }

            return Access::ok(Accounts::profile($request->user()));
        }
        $who = $request->user();
        if (Accounts::isSupport($who) && $request->query('u')) {
            $who = User::where('username', Accounts::key($request->query('u')))->first();
        }
        $f = $who ? Accounts::photoPath($who) : null;
        if (! $f) {
            return Access::error(404, 'No picture.');
        }
        $type = Branding::imageType(file_get_contents($f, false, null, 0, 16))[1] ?? 'image/png';

        return response()->file($f, ['Content-Type' => $type]);
    }

    // ---------- the support account's Accounts page ----------
    public function accountsPage(Request $request)
    {
        if (! Accounts::isSupport($request->user())) {
            return redirect('/start');
        }

        return view('accounts.accounts', ['logo' => Branding::dataUri(Branding::emblem()),
            'data' => ['regions' => array_column(OrgUnits::all(), 'name'), 'national' => OrgUnits::NATIONAL]]);
    }

    /** Support: list accounts; add one, reset its password, move it, rename, unlock or remove it. */
    public function usersApi(Request $request)
    {
        if (! Accounts::isSupport($request->user())) {
            return Access::error(403, 'Only the support account manages accounts.');
        }
        if ($request->isMethod('get')) {
            return Access::ok(['users' => Accounts::listUsers()]);
        }
        try {
            $d = Access::body($request);
            $out = Accounts::manage((string) ($d['action'] ?? ''), (string) ($d['username'] ?? ''), $d);
        } catch (\InvalidArgumentException $e) {
            return Access::error(400, $e->getMessage());
        }

        return Access::ok($out + ['users' => Accounts::listUsers()]);
    }
}
