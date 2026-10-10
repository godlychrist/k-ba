<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class PersistentSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_login_is_remembered_and_restores_after_session_expires(): void
    {
        $user = User::factory()->create();
        $cookieName = Auth::guard()->getRecallerName();
        $response = $this->actingAs($user)->getJson('/api/session')->assertOk()->assertJsonPath('authenticated', true)->assertCookie($cookieName);
        $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === $cookieName);
        $this->assertGreaterThan(time() + 29 * 86400, $cookie->getExpiresTime());
        $remember = CookieValuePrefix::remove(app('encrypter')->decrypt($cookie->getValue(), false));
        $this->flushSession();
        Auth::forgetGuards();
        $this->withCredentials()->withCookie($cookieName, $remember)->getJson('/api/session')->assertOk()->assertJsonPath('authenticated', true);
        $this->assertTrue(Auth::guard()->viaRemember());
        $this->assertSame($user->id, Auth::id());
    }

    public function test_logout_invalidates_remembered_login(): void
    {
        $user = User::factory()->create();
        $cookieName = Auth::guard()->getRecallerName();
        $response = $this->actingAs($user)->getJson('/api/session');
        $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === $cookieName);
        $remember = CookieValuePrefix::remove(app('encrypter')->decrypt($cookie->getValue(), false));
        $this->postJson('/api/logout')->assertOk();
        $this->flushSession();
        Auth::forgetGuards();
        $this->withCredentials()->withCookie($cookieName, $remember)->getJson('/api/session')->assertJsonPath('authenticated', false);
    }
}
