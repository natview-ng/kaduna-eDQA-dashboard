<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Auth\BrowserName;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/**
 * My account (/admin/account, Phase 5 step 7): change the password (SECURITY.md §2, through
 * SecurityController::update) and see this user's recent sign-ins: when (WAT), the browser, the
 * address only as its /24 range (/48 for IPv6), and whether it was a new device. Unlocking an
 * account stays a console command; the page names it.
 */
final class AccountController extends Controller
{
    public const RECENT_LOGINS = 10;

    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('admin/account', [
            'status' => $request->session()->get('status'),
            'logins' => fn (): array => self::logins($user),
            'unlockCommand' => 'php artisan edqa:admin:unlock {email}',
        ]);
    }

    /** @return list<array{at: string, browser: string, ip_range: ?string, new_device: bool}> */
    private static function logins(User $user): array
    {
        $entries = Activity::query()
            ->where('log_name', 'auth')
            ->whereIn('event', ['login', 'login-new-device'])
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->id)
            ->orderByDesc('id')
            ->limit(self::RECENT_LOGINS * 2)
            ->get(['id', 'event', 'properties', 'created_at']);

        // A new-device entry is written right after its login: it marks that login.
        $newDevice = $entries->where('event', 'login-new-device')->map(fn (Activity $a): int => (int) $a->created_at?->getTimestamp())->all();

        return $entries->where('event', 'login')->take(self::RECENT_LOGINS)->map(function (Activity $login) use ($newDevice): array {
            $at = CarbonImmutable::parse((string) $login->created_at);
            $ip = $login->getExtraProperty('ip');

            return [
                'at' => $at->toIso8601String(),
                'browser' => BrowserName::describe(is_string($login->getExtraProperty('user_agent')) ? $login->getExtraProperty('user_agent') : null),
                'ip_range' => is_string($ip) ? self::range($ip) : null,
                'new_device' => collect($newDevice)->contains(fn (int $time): bool => abs($time - $at->getTimestamp()) <= 10),
            ];
        })->values()->all();
    }

    /** "102.89.34.17" → "102.89.34.0/24"; an IPv6 address → its /48. */
    private static function range(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $parts = explode('.', $ip);

            return "{$parts[0]}.{$parts[1]}.{$parts[2]}.0/24";
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = inet_pton($ip);
            if ($packed === false) {
                return null;
            }
            $masked = inet_ntop(substr($packed, 0, 6).str_repeat("\0", 10));

            return $masked === false ? null : "{$masked}/48";
        }

        return null;
    }
}
