<?php

namespace Tests\Feature;

use App\Afp\Cases;
use App\Afp\Register;
use App\Models\SmsMessage;
use App\Models\User;
use App\Services\Sms;
use App\Support\OrgUnits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SMS to district officers, against a copy of the register with one past-due and one due-soon case, and a fake
 * Fish Africa API (nothing is really sent).
 */
class SmsTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;
    private string $past;
    private string $soon;
    private array $pastCase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/afp-sms-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        $src = Cases::registerPath();
        $this->assertFileExists($src, 'The test needs a register in DATA_DIR (a copy, never the real one).');
        copy($src, $this->dir.'/AFP nofitication.xlsx');
        config(['surveillance.afp_register' => $this->dir.'/AFP nofitication.xlsx', 'surveillance.data_dir' => $this->dir,
            'surveillance.sms.app_id' => 'app', 'surveillance.sms.app_secret' => 'secret', 'surveillance.sms.sender_id' => 'GHS-AFP']);

        // Two Upper East cases: one 70 days from onset (past due), one 55 (due in 5 days); neither followed up.
        $ue = OrgUnits::regionOf('Upper East');
        $cases = array_values(array_filter(Cases::regionCases(Cases::registerPath(), $ue), fn ($c) => OrgUnits::districtRegion($c['district'])[0]));
        [$this->past, $this->soon] = [$cases[0]['epid'], $cases[1]['epid']];
        $reg = new Register(Cases::registerPath());
        foreach ([$this->past => 70, $this->soon => 55] as $epid => $days) {
            $raw = $reg->caseValues($epid);
            $raw[$reg->fieldLike('onset')->col] = date('Y-m-d', strtotime("-$days days"));
            $outcome = $reg->fieldLike('outcome');
            $raw[$outcome->col] = collect($outcome->options)->first(fn ($o) => strtolower($o) !== 'fup done');
            [$v, $e] = $reg->prepareEdit($epid, $raw);
            $this->assertSame([], $e);
            $reg->updateCase($epid, $v);
        }
        $this->pastCase = array_values(array_filter(Cases::loadCases(Cases::registerPath()), fn ($c) => $c['epid'] === $this->past))[0];
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (array_diff(scandir($this->dir), ['.', '..']) as $f) {
            unlink($this->dir.'/'.$f);
        }
        rmdir($this->dir);
    }

    private function user(string $region = 'Upper East'): User
    {
        return User::create(['username' => 'u'.bin2hex(random_bytes(3)), 'password' => 'password-123', 'region' => $region]);
    }

    private function saveOfficer(User $u, string $phone = '024 123 4567')
    {
        [, $d] = OrgUnits::districtRegion($this->pastCase['district']);

        return $this->actingAs($u)->postJson('/api/sms/officers', ['code' => $d[0], 'name' => 'Amina DSO', 'phone' => $phone]);
    }

    private function fakeApi(int $status = 202, ?array $body = null): void
    {
        Http::fake(['api.letsfish.africa/*' => Http::response($body ?? ['success' => true, 'status' => 202,
            'message' => 'Request accepted for processing',
            'data' => [['recipient' => '233241234567', 'reference' => 'sms_abc123', 'status' => 'queued']]], $status)]);
    }

    public function test_phone_numbers_are_kept_as_233(): void
    {
        $this->assertSame('233241234567', Sms::phone('024 123 4567'));
        $this->assertSame('233241234567', Sms::phone('+233 24 123 4567'));
        $this->assertSame('233501234567', Sms::phone('233501234567'));
        $this->assertSame('024 123 4567', Sms::local('233241234567'));
        $this->expectException(\InvalidArgumentException::class);
        Sms::phone('12345');
    }

    public function test_preview_says_past_due_and_names_the_officer(): void
    {
        $u = $this->user();
        $this->saveOfficer($u)->assertOk();
        $p = $this->actingAs($u)->getJson('/api/sms/preview?epid='.$this->past)->assertOk()->json();
        $this->assertSame('past', $p['status']);
        $this->assertSame(['name' => 'Amina DSO', 'phone' => '024 123 4567'], $p['officer']);
        $this->assertStringContainsString('OVERDUE by 10 days', $p['message']);
        $this->assertStringContainsString($this->past, $p['message']);
        $this->assertStringContainsString('Upper East RHD Surveillance', $p['message']);
        $soon = $this->actingAs($u)->getJson('/api/sms/preview?epid='.$this->soon)->assertOk()->json();
        $this->assertSame('soon', $soon['status']);
        $this->assertStringContainsString('due in 5 days', $soon['message']);
    }

    public function test_send_calls_fish_africa_and_logs_it(): void
    {
        $this->fakeApi();
        $u = $this->user();
        $this->saveOfficer($u)->assertOk();
        $r = $this->actingAs($u)->postJson('/api/sms/send', ['epid' => $this->past])->assertOk()->json('sms');
        $this->assertSame('queued', $r['status']);
        $this->assertSame('Amina DSO', $r['to']);
        Http::assertSent(fn ($req) => $req->url() === 'https://api.letsfish.africa/v1/sms'
            && $req->hasHeader('Authorization', 'Bearer app.secret')
            && $req['sender_id'] === 'GHS-AFP' && $req['recipients'] === ['233241234567']
            && str_contains($req['message'], 'OVERDUE'));
        $this->assertSame(1, SmsMessage::count());
        // The AFP page shows it under the case.
        $case = collect($this->actingAs($u)->getJson('/api/data')->json('cases'))->firstWhere('epid', $this->past);
        $this->assertSame('queued', $case['sms']['status']);
    }

    public function test_no_second_sms_within_a_day(): void
    {
        $this->fakeApi();
        $u = $this->user();
        $this->saveOfficer($u);
        $this->actingAs($u)->postJson('/api/sms/send', ['epid' => $this->past])->assertOk();
        $this->actingAs($u)->postJson('/api/sms/send', ['epid' => $this->past])->assertStatus(400)
            ->assertJsonFragment(['error' => 'An SMS about '.$this->past.' went to Amina DSO at '.now()->format('d M H:i').'. It can be sent again after 24 hours.']);
        Http::assertSentCount(1);
    }

    public function test_no_officer_number_means_no_sms(): void
    {
        $this->fakeApi();
        $this->actingAs($this->user())->postJson('/api/sms/send', ['epid' => $this->past])->assertStatus(400)
            ->assertJsonPath('error', fn ($m) => str_contains($m, 'Add it under SMS contacts first'));
        Http::assertNothingSent();
    }

    public function test_no_credit_is_reported_and_logged_as_failed(): void
    {
        Http::fake(['api.letsfish.africa/*' => Http::sequence()
            ->push(['success' => false, 'error' => ['status' => 402, 'title' => 'Insufficient balance', 'detail' => '']], 402)
            ->push(['success' => true, 'data' => [['recipient' => '233241234567', 'reference' => 'sms_2', 'status' => 'queued']]], 202)]);
        $u = $this->user();
        $this->saveOfficer($u);
        $this->actingAs($u)->postJson('/api/sms/send', ['epid' => $this->past])->assertStatus(502)
            ->assertJsonPath('error', 'The SMS account has no credit left. Top it up on the Fish Africa dashboard.');
        $this->assertSame('failed', SmsMessage::first()->status);
        // A failed SMS can be tried again at once.
        $this->actingAs($u)->postJson('/api/sms/send', ['epid' => $this->past])->assertOk();
    }

    public function test_cases_outside_the_account_region_cannot_be_texted(): void
    {
        $this->fakeApi();
        $this->actingAs($this->user('Volta'))->postJson('/api/sms/send', ['epid' => $this->past])->assertStatus(400)
            ->assertJsonPath('error', 'There is no case '.$this->past.' in this region.');
        Http::assertNothingSent();
    }

    public function test_bad_numbers_are_refused_and_blank_removes(): void
    {
        $u = $this->user();
        $this->saveOfficer($u, '0123')->assertStatus(400)->assertJsonPath('error', 'Enter a Ghana mobile number, like 024 123 4567.');
        $this->saveOfficer($u)->assertOk();
        $this->saveOfficer($u, '')->assertOk();
        $this->assertSame(0, \App\Models\DistrictOfficer::count());
    }

    public function test_support_saves_the_keys_in_the_app_and_they_are_used(): void
    {
        $this->fakeApi();
        $support = User::forceCreate(['username' => 'admin', 'password' => 'password-123', 'is_superuser' => true]);
        $this->actingAs($this->user())->getJson('/api/sms/settings')->assertStatus(403);
        $this->actingAs($this->user())->postJson('/api/sms/settings', ['appId' => 'x', 'appSecret' => 'y', 'senderId' => 'Z'])->assertStatus(403);

        $this->actingAs($support)->postJson('/api/sms/settings', ['appId' => 'myapp', 'appSecret' => 'mysecret', 'senderId' => 'UER-SURV'])
            ->assertOk()->assertJsonPath('source', 'app')->assertJsonMissingPath('appSecret');
        $this->assertStringNotContainsString('mysecret', \App\Models\AppSetting::find('letsfish.app_secret')->value);
        $this->assertStringNotContainsString('mysecret', $this->actingAs($support)->getJson('/api/sms/settings')->content());
        // A blank secret keeps the saved one.
        $this->actingAs($support)->postJson('/api/sms/settings', ['appId' => 'myapp', 'appSecret' => '', 'senderId' => 'UER-SURV'])->assertOk();

        $this->actingAs($support)->postJson('/api/sms/test', ['phone' => '0241234567'])->assertOk()->assertJsonPath('to', '024 123 4567');
        Http::assertSent(fn ($req) => $req->hasHeader('Authorization', 'Bearer myapp.mysecret') && $req['sender_id'] === 'UER-SURV');

        $this->actingAs($support)->postJson('/api/sms/settings', ['appId' => 'myapp', 'appSecret' => '', 'senderId' => 'far too long a sender'])
            ->assertStatus(400);
        $this->actingAs($support)->postJson('/api/sms/settings', ['remove' => true])->assertOk()->assertJsonPath('source', 'env');
    }

    public function test_signed_out_calls_are_refused(): void
    {
        $this->getJson('/api/sms/preview?epid='.$this->past)->assertStatus(401);
    }
}
