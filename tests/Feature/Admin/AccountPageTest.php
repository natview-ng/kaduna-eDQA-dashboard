<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Auth\AuthAudit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// Phase 5 step 7: My account (/admin/account). Change password (SECURITY.md §2) and see your own
// recent sign-ins; unlocking an account stays a console command.

beforeEach(function (): void {
    $this->user = User::factory()->create(['name' => 'Hauwa Bello']);
});

/** A sign-in as the listener records it from a request: its IP and browser (the test runs in the console). */
function recordSignIn(User $user, string $ip, string $agent, bool $newDevice): void
{
    $context = ['ip' => $ip, 'user_agent' => $agent];
    AuthAudit::record('login', $user, context: $context);
    if ($newDevice) {
        AuthAudit::record('login-new-device', $user, context: $context);
    }
}

it('needs a signed-in administrator who has confirmed the password', function (): void {
    $this->get('/admin/account')->assertRedirect('/login');
    $this->actingAs($this->user)->get('/admin/account')->assertRedirect(route('password.confirm'));
    $this->actingAs($this->user)->get('/admin/settings/security')->assertRedirect('/admin/account');
});

it('lists this user\'s recent sign-ins: time, browser, the IP as a /24 range, new device or not', function (): void {
    $other = User::factory()->create();
    Carbon::setTestNow('2026-11-01 08:00:00');
    recordSignIn($this->user, '102.89.34.17', 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/131.0 Safari/537.36', true);
    Carbon::setTestNow('2026-11-03 18:30:00');
    recordSignIn($this->user, '102.89.34.200', 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/131.0 Safari/537.36', false);
    recordSignIn($other, '41.58.1.9', 'Mozilla/5.0 Firefox/132.0', true);

    $this->actingAs($this->user)->withSession(['auth.password_confirmed_at' => time()])->get('/admin/account')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/account')
        ->has('logins', 2)
        ->where('logins.0.at', '2026-11-03T18:30:00+01:00')
        ->where('logins.0.ip_range', '102.89.34.0/24')
        ->where('logins.0.new_device', false)
        ->where('logins.0.browser', fn (string $browser): bool => str_contains($browser, 'Chrome'))
        ->where('logins.1.new_device', true)
        ->where('unlockCommand', 'php artisan edqa:admin:unlock {email}'));
});

it('changes the password under the policy, ends the other sessions and audits it', function (): void {
    DB::table('sessions')->insert(['id' => 'other-session', 'user_id' => $this->user->id, 'ip_address' => '10.0.0.1', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()]);

    $this->actingAs($this->user)->from('/admin/account')->put(route('admin.user-password.update'), [
        'current_password' => 'password', 'password' => 'short', 'password_confirmation' => 'short',
    ])->assertSessionHasErrors('password');
    $this->actingAs($this->user)->from('/admin/account')->put(route('admin.user-password.update'), [
        'current_password' => 'wrong-password', 'password' => 'Correct-Horse-12!', 'password_confirmation' => 'Correct-Horse-12!',
    ])->assertSessionHasErrors('current_password');

    $this->actingAs($this->user)->from('/admin/account')->put(route('admin.user-password.update'), [
        'current_password' => 'password', 'password' => 'Correct-Horse-12!', 'password_confirmation' => 'Correct-Horse-12!',
    ])->assertSessionHasNoErrors()->assertRedirect('/admin/account');

    expect(DB::table('sessions')->where('id', 'other-session')->exists())->toBeFalse()
        ->and(DB::table('activity_log')->where('event', 'password-changed')->where('causer_id', $this->user->id)->exists())->toBeTrue();
});
