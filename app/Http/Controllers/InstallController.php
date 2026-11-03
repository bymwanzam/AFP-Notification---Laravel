<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Accounts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Setup from the browser, for hosting without a terminal: /install?token=<INSTALL_TOKEN from .env>.
 * Checks PHP, folders and the document root, then makes or updates the database and makes the support account
 * (its password is shown once). Safe to run again after every update. Runs without sessions, so it works before
 * the database exists.
 */
class InstallController extends Controller
{
    private const EXTENSIONS = ['mbstring', 'openssl', 'zip', 'gd', 'xml', 'xmlreader', 'xmlwriter',
        'simplexml', 'dom', 'fileinfo', 'curl', 'iconv', 'ctype', 'tokenizer', 'zlib'];

    /** The database the app uses: sqlite (a file in database/) or mysql / mariadb (cPanel > MySQL Databases). */
    private function driver(): string
    {
        return (string) config('database.connections.'.config('database.default').'.driver');
    }

    /** [ok, problem] for connecting to a MySQL / MariaDB database. */
    private function connects(): array
    {
        try {
            DB::connection()->getPdo();

            return [true, ''];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    private function allowed(Request $request): bool
    {
        $token = (string) config('surveillance.install_token');

        return strlen($token) >= 20 && hash_equals($token, (string) $request->input('token'));
    }

    /** [label, ok, what to do] for everything the app needs. */
    private function checks(): array
    {
        $app = basename(base_path());  // the app's folder in the home folder, e.g. surveillance
        $out = [['PHP '.PHP_VERSION, PHP_VERSION_ID >= 80300, 'In cPanel > Select PHP Version, choose 8.3 and click Set as current.']];
        $need = array_merge(self::EXTENSIONS, $this->driver() === 'sqlite' ? ['pdo_sqlite', 'sqlite3'] : ['pdo_mysql']);
        $missing = array_values(array_filter($need, fn ($e) => ! extension_loaded($e)));
        $out[] = ['PHP extensions', ! $missing, 'In cPanel > Select PHP Version > Extensions, tick: '.implode(', ', $missing).'.'];
        foreach (['storage', 'storage/framework/sessions', 'storage/framework/views', 'storage/framework/cache', 'storage/logs',
            'storage/app/data', 'storage/app/private', 'bootstrap/cache', 'database'] as $dir) {
            $p = base_path($dir);
            if (! is_dir($p)) {
                @mkdir($p, 0755, true);
            }
            $out[] = ["Folder $dir can be written", is_dir($p) && is_writable($p), "In File Manager, set the permissions of $app/$dir to 755."];
        }
        $root = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
        $docroot = $root !== '' ? realpath($root) : false;
        $public = realpath(public_path());
        $out[] = ['The website shows only the public folder', ! $docroot || $docroot === $public,
            'In cPanel > Domains > '.request()->getHost()." > Manage, set the document root to $app/public."];
        if ($this->driver() !== 'sqlite') {
            $c = config('database.connections.'.config('database.default'));
            [$ok, $why] = extension_loaded('pdo_mysql') ? $this->connects() : [false, 'pdo_mysql is missing'];
            $out[] = ['MySQL database '.$c['database'].' (user '.$c['username'].')', $ok,
                'The app could not connect: '.$why.'. In .env check DB_DATABASE, DB_USERNAME and DB_PASSWORD (put the password in "quotes"), '
                .'and in cPanel > MySQL Databases add the user to the database with ALL PRIVILEGES.'];
        }
        $register = \App\Afp\Cases::registerPath();
        $out[] = ['AFP register '.basename($register), is_file($register),
            "The workbooks are missing from $app/storage/app/data: extract the full zip again in the home folder."];

        return $out;
    }

    private function page(string $body)
    {
        return response('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<meta name="robots" content="noindex"><title>AFP Watch Out setup</title><style>'
            .'body{font:16px/1.5 system-ui,sans-serif;max-width:44em;margin:6vh auto;padding:0 16px;color:#141a24;background:#f3f5f8}'
            .'h1{font-size:1.5rem}li{margin:6px 0}.ok{color:#19794a}.no{color:#c4320a}.todo{display:block;color:#5b6577;font-size:.9rem}'
            .'.box{background:#fff;border:1px solid #d9dee7;border-radius:10px;padding:16px 18px;margin:16px 0}'
            .'code{background:#e3edfc;padding:2px 6px;border-radius:4px;font-size:1.05rem}button{font:inherit;font-weight:600;background:#0a5bd6;color:#fff;border:0;border-radius:8px;padding:10px 20px;cursor:pointer}'
            .'</style></head><body><h1>AFP Watch Out · setup</h1>'.$body.'</body></html>', 200, ['Cache-Control' => 'no-store']);
    }

    public function show(Request $request)
    {
        if (! $this->allowed($request)) {
            abort(404);
        }
        $checks = $this->checks();
        $list = '';
        foreach ($checks as [$label, $ok, $todo]) {
            $list .= '<li class="'.($ok ? 'ok' : 'no').'">'.($ok ? '✔ ' : '✘ ').e($label).($ok ? '' : '<span class="todo">'.e($todo).'</span>').'</li>';
        }
        $ready = ! array_filter($checks, fn ($c) => ! $c[1]);
        $form = $ready
            ? '<form method="post"><input type="hidden" name="token" value="'.e($request->input('token')).'"><button>Set up the database</button></form>'
              .'<p>Safe to press again after every update: it never removes data or changes passwords.</p>'
            : '<p class="no">Fix the items marked ✘, then reload this page.</p>';

        return $this->page('<div class="box"><ul>'.$list.'</ul></div>'.$form);
    }

    public function run(Request $request)
    {
        if (! $this->allowed($request)) {
            abort(404);
        }
        if (array_filter($this->checks(), fn ($c) => ! $c[1])) {
            return redirect('/install?token='.urlencode($request->input('token')));
        }
        $db = (string) config('database.connections.sqlite.database');
        if (config('database.default') === 'sqlite' && $db !== ':memory:' && ! is_file($db)) {
            touch($db);
        }
        try {
            Artisan::call('migrate', ['--force' => true]);
        } catch (\Throwable $e) {
            report($e);

            return $this->page('<div class="box no">The database could not be set up: '.e($e->getMessage()).'</div>');
        }
        $made = '';
        if (! User::where('username', Accounts::SUPPORT_USERNAME)->exists()) {
            $pw = Accounts::tempPassword().'-'.Accounts::tempPassword();
            $u = new User(['username' => Accounts::SUPPORT_USERNAME]);
            $u->forceFill(['is_superuser' => true, 'region' => '', 'must_change' => true, 'password' => $pw])->save();
            $made = '<div class="box"><p><b>Support account made.</b> Write the password down now: it is not shown again.</p>'
                .'<p>Username: <code>admin</code><br>Password: <code>'.e($pw).'</code></p>'
                .'<p>The first sign-in asks for a new password.</p></div>';
        }

        return $this->page('<div class="box ok">✔ The database is ready.</div>'.$made
            .'<p><a href="/login">Open the sign-in page</a></p>');
    }
}
