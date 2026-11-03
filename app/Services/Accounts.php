<?php

namespace App\Services;

use App\Models\LoginLock;
use App\Models\User;
use App\Support\Branding;
use App\Support\OrgUnits;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Account rules: one region per account (or Ghana for a national administrator), the support account (the
 * superuser) that sees every region, a 5-try lockout, profiles with cadre and picture, and passwords someone else
 * set that must be changed at the next sign-in. Problems are thrown as \InvalidArgumentException with the message
 * to show.
 */
class Accounts
{
    public const SUPPORT_USERNAME = 'admin';  // no other account may take it
    public const RESERVED = ['admin'];
    public const MIN_PASSWORD = 8;
    public const MAX_FAILS = 5;
    public const LOCK_MINUTES = 5;
    public const MAX_PHOTO = 3 * 1024 * 1024;
    public const CADRES = ['Regional Surveillance Officer', 'Deputy Regional Surveillance Officer', 'District Surveillance Officer',
        'Disease Control Officer', 'Regional Director of Health Services', 'Deputy Director of Public Health',
        'Public Health Nurse', 'Health Information Officer', 'Epidemiologist', 'Data Manager', 'Other'];

    public static function key($username): string
    {
        return strtolower(trim((string) ($username ?? '')));
    }

    private static function fail(string $message): never
    {
        throw new \InvalidArgumentException($message);
    }

    // ---------- roles ----------
    public static function isSupport(?User $u): bool
    {
        return (bool) $u?->is_superuser;
    }

    public static function isNational(?User $u): bool
    {
        return $u && ! $u->is_superuser && in_array($u->region, [OrgUnits::NATIONAL, 'National'], true);
    }

    public static function seesAll(?User $u): bool
    {
        return self::isSupport($u) || self::isNational($u);
    }

    public static function role(User $u): string
    {
        return self::isSupport($u) ? 'support' : (self::isNational($u) ? 'national' : 'regional');
    }

    /** The region of a regional account. One with no region yet gets the default region (Upper East). */
    public static function userRegion(User $u): array
    {
        $unit = OrgUnits::regionOf($u->region);
        if (! $unit) {
            $unit = OrgUnits::currentRegion();
            $u->region = $unit['name'];
            $u->save();
        }

        return $unit;
    }

    /** Whether anyone but support has an account (the first one is made on the login page). */
    public static function hasAccounts(): bool
    {
        return User::where('is_superuser', false)->exists();
    }

    // ---------- making and checking accounts ----------
    /** The problem with a new username and password, or null. */
    public static function checkNew($username, $password, $confirm = null): ?string
    {
        $k = self::key($username);
        if (! preg_match('/^[a-z0-9._-]{3,32}$/', $k)) {
            return 'Usernames are 3 to 32 letters, numbers, dots, dashes or underscores.';
        }
        if (in_array($k, self::RESERVED, true)) {
            return "The username $k is reserved. Choose another one.";
        }
        if (User::where('username', $k)->exists()) {
            return "There is already an account called $k.";
        }
        if (strlen((string) $password) < self::MIN_PASSWORD) {
            return 'Use a password of at least '.self::MIN_PASSWORD.' characters.';
        }
        if ($confirm !== null && $password !== $confirm) {
            return 'The two passwords do not match.';
        }

        return null;
    }

    /** The stored name of a chosen region: a region, or Ghana. */
    public static function regionName($region): string
    {
        if (in_array(strtolower(trim((string) $region)), [strtolower(OrgUnits::NATIONAL), 'national'], true)) {
            return OrgUnits::NATIONAL;
        }
        $unit = OrgUnits::regionOf($region);

        return $unit ? $unit['name'] : self::fail('Choose your region.');
    }

    public static function createAccount($username, $password, $fullName = '', $region = '', bool $mustChange = false): User
    {
        if ($problem = self::checkNew($username, $password)) {
            self::fail($problem);
        }
        $reg = self::regionName($region);

        return DB::transaction(fn () => User::create(['username' => self::key($username), 'password' => $password,
            'full_name' => trim((string) $fullName), 'region' => $reg, 'must_change' => $mustChange]));
    }

    /** The account if the password is right; throws with the message to show otherwise. */
    public static function verify($username, $password): User
    {
        $k = self::key($username);
        $now = now();
        $lock = LoginLock::find($k);
        if ($lock && $lock->locked_until && $lock->locked_until > $now) {
            $mins = intdiv((int) $now->diffInSeconds($lock->locked_until), 60) + 1;
            self::fail("Too many wrong passwords. Try again in $mins min.");
        }
        $user = User::where('username', $k)->first();
        if ($user && Hash::check((string) $password, $user->password)) {
            LoginLock::where('username', $k)->delete();

            return $user;
        }
        $lock ??= new LoginLock(['username' => $k, 'failures' => 0]);
        if ($lock->locked_until && $lock->locked_until <= $now) {
            $lock->failures = 0;
            $lock->locked_until = null;
        }
        $lock->failures++;
        if ($lock->failures >= self::MAX_FAILS) {
            $lock->failures = 0;
            $lock->locked_until = $now->copy()->addMinutes(self::LOCK_MINUTES);
        }
        $lock->save();
        self::fail('The username or password is not right.');
    }

    public static function changePassword(User $u, $current, $new, $confirm): void
    {
        if (! Hash::check((string) $current, $u->password)) {
            self::fail('Your current password is not right.');
        }
        if (strlen((string) $new) < self::MIN_PASSWORD) {
            self::fail('Use a password of at least '.self::MIN_PASSWORD.' characters.');
        }
        if ($new !== $confirm) {
            self::fail('The two new passwords do not match.');
        }
        if ($new === $current) {
            self::fail('Choose a password different from the current one.');
        }
        $u->password = $new;
        $u->must_change = false;
        $u->save();
    }

    public static function tempPassword(): string
    {
        $part = fn () => rtrim(strtr(base64_encode(random_bytes(4)), '+/', '-_'), '=');

        return $part().'-'.$part().'-'.$part();
    }

    /** Sign an account out everywhere (after its region, password or the account itself changes). */
    public static function endSessions(User $u): void
    {
        DB::table('sessions')->where('user_id', $u->id)->delete();
    }

    // ---------- support: managing accounts ----------
    public static function listUsers(): array
    {
        return User::where('is_superuser', false)->orderBy('username')->get()->map(fn (User $u) => [
            'username' => $u->username, 'name' => $u->full_name, 'region' => $u->region, 'cadre' => $u->cadre,
            'mustChange' => $u->must_change, 'photo' => self::photoPath($u) !== null,
        ])->all();
    }

    public static function account($username): User
    {
        return User::where('username', self::key($username))->where('is_superuser', false)->first()
            ?? self::fail('There is no account called '.self::key($username).'.');
    }

    /** One action from the Accounts page. Returns extra results (a password to hand over). */
    public static function manage(string $action, $username, array $data): array
    {
        if ($action === 'add') {
            $pw = (string) ($data['password'] ?? '') ?: self::tempPassword();
            self::createAccount($username, $pw, (string) ($data['name'] ?? ''), (string) ($data['region'] ?? ''), true);

            return ['password' => $pw];
        }
        $u = self::account($username);
        switch ($action) {
            case 'reset':
                $pw = (string) ($data['password'] ?? '') ?: self::tempPassword();
                if (strlen($pw) < self::MIN_PASSWORD) {
                    self::fail('Use a password of at least '.self::MIN_PASSWORD.' characters.');
                }
                $u->password = $pw;
                $u->must_change = true;
                $u->save();
                self::endSessions($u);
                LoginLock::where('username', $u->username)->delete();

                return ['password' => $pw];
            case 'region':
                $u->region = self::regionName((string) ($data['region'] ?? ''));
                $u->save();
                self::endSessions($u);
                break;
            case 'rename':
                $name = trim((string) ($data['name'] ?? ''));
                if ($name === '') {
                    self::fail('Enter the full name.');
                }
                $u->full_name = mb_substr($name, 0, 80);
                $u->save();
                break;
            case 'unlock':
                LoginLock::where('username', $u->username)->delete();
                break;
            case 'remove':
                self::endSessions($u);
                if ($f = self::photoPath($u)) {
                    @unlink($f);
                }
                $u->delete();
                break;
            default:
                self::fail('Unknown action.');
        }

        return [];
    }

    // ---------- profile: full name, cadre and picture ----------
    public static function profile(User $u): array
    {
        $r = self::role($u);

        return ['username' => $u->username, 'name' => $u->full_name, 'cadre' => $u->cadre,
            'region' => $r === 'support' ? '' : $u->region, 'role' => $r, 'photo' => self::photoPath($u) !== null,
            'cadres' => self::CADRES];
    }

    public static function setProfile(User $u, $name, $cadre): void
    {
        $name = trim((string) $name);
        $cadre = trim((string) $cadre);
        if ($name === '') {
            self::fail('Enter your full name.');
        }
        if (mb_strlen($name) > 80) {
            self::fail('Keep the full name under 80 characters.');
        }
        if ($cadre !== '' && ! in_array($cadre, self::CADRES, true)) {
            self::fail('Choose a cadre from the list.');
        }
        $u->full_name = $name;
        $u->cadre = $cadre;
        $u->save();
    }

    public static function photosDir(): string
    {
        return rtrim(config('surveillance.private_dir'), '/\\').'/photos';
    }

    public static function photoPath(User $u): ?string
    {
        $f = $u->photo ? self::photosDir().'/'.$u->photo : null;

        return $f && is_file($f) ? $f : null;
    }

    /** Store the account's picture (PNG or JPEG, under 3 MB); an empty body removes it. */
    public static function setPhoto(User $u, string $body): void
    {
        $type = self::imageType($body);
        if ($body !== '' && ! $type) {
            self::fail('Choose a PNG or JPEG picture.');
        }
        if (strlen($body) > self::MAX_PHOTO) {
            self::fail('Choose a picture under 3 MB.');
        }
        if ($old = self::photoPath($u)) {
            @unlink($old);
        }
        $u->photo = '';
        if ($body !== '') {
            @mkdir(self::photosDir(), 0700, true);
            $u->photo = $u->id.'-'.bin2hex(random_bytes(4)).$type[0];
            file_put_contents(self::photosDir().'/'.$u->photo, $body);
        }
        $u->save();
    }

    public static function imageType(string $body): ?array
    {
        return Branding::imageType($body);
    }
}
