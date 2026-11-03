<?php

namespace Tests\Feature;

use App\Idsr\Store;
use App\Models\User;
use App\Support\OrgUnits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Weekly IDSR, against copies of the IDSR stores in a scratch DATA_DIR (never the real ones). */
class IdsrTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $src = config('surveillance.data_dir');
        $this->dir = sys_get_temp_dir().'/idsr-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/IDSR regions', 0700, true);
        foreach (['AFP nofitication.xlsx', 'IDSR weekly data.xlsx'] as $f) {
            if (is_file("$src/$f")) {
                copy("$src/$f", "$this->dir/$f");
            }
        }
        config(['surveillance.data_dir' => $this->dir, 'surveillance.afp_register' => "$this->dir/AFP nofitication.xlsx",
            'surveillance.private_dir' => "$this->dir/private"]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f) : unlink($f);
        }
        rmdir($this->dir);
    }

    private function user(string $region = 'North East', bool $support = false): User
    {
        $u = User::create(['username' => 'u'.bin2hex(random_bytes(3)), 'password' => 'password-123', 'region' => $support ? '' : $region]);
        if ($support) {
            $u->forceFill(['is_superuser' => true])->save();
        }

        return $u;
    }

    private function counts(string $code, int $afp): array
    {
        return [$code => ['afp.CS' => $afp, 'measles.CS' => 2, 'measles.D' => 1]];
    }

    public function test_page_and_data_for_the_accounts_region(): void
    {
        $u = $this->user('North East');
        $r = $this->actingAs($u)->get('/idsr')->assertOk();
        $this->assertStringContainsString('id="bulletinCss"', $r->getContent());  // the PNG export reads it, so it stays inline
        $this->assertStringNotContainsString('being moved', $r->getContent());
        $d = $this->actingAs($u)->getJson('/api/idsr/data?region=North%20East')->assertOk()->json();
        $this->assertSame('North East', $d['region']);
        $this->assertNotEmpty($d['districts']);
        $this->assertCount(24, $d['diseases']);
    }

    public function test_other_regions_are_refused(): void
    {
        $this->actingAs($this->user('North East'))->getJson('/api/idsr/data?region=Volta')
            ->assertStatus(403)->assertJson(['error' => 'Your account is for North East Region only.']);
    }

    public function test_save_a_week_then_download_it_and_read_the_form_back(): void
    {
        $u = $this->user('Volta');
        $code = OrgUnits::regionOf('Volta')['districts'][0][0];
        $r = $this->actingAs($u)->postJson('/api/idsr/week?region=Volta', ['year' => 2026, 'week' => 40,
            'meta' => ['expected' => 18, 'received' => 17, 'ontime' => 15, 'epi' => '=not a formula', 'filled' => '05/10/2026'],
            'counts' => $this->counts($code, 3)])->assertOk()->json('data');
        $this->assertTrue($r['saved']);
        $this->assertSame(3, $r['counts']['2026-40'][$code]['afp.CS']);
        $this->assertSame(0, $r['counts']['2026-40'][$code]['cholera.CS']);
        $week = collect($r['weeks'])->firstWhere('week', 40);
        $this->assertSame(['2026-09-28', '2026-10-04', '2026-10-08', '2026-10-05'], [$week['start'], $week['end'], $week['published'], $week['filled']]);
        $this->assertSame('=not a formula', $week['epi']);

        $csv = $this->actingAs($u)->get('/api/idsr/week.csv?region=Volta&year=2026&week=40')->assertOk();
        $this->assertStringContainsString('text/csv', $csv->headers->get('Content-Type'));
        $this->assertStringContainsString('AFP CS', $csv->getContent());

        // The official form, uploaded again, matches what is on file: nothing to change.
        $form = $this->actingAs($u)->get('/api/idsr/form.xlsx?region=Volta&year=2026&week=40')->assertOk()->getContent();
        $plan = $this->actingAs($u)->call('POST', '/api/idsr/import?region=Volta&name=form.xlsx', [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], $form)
            ->assertOk()->json();
        $this->assertSame([], $plan['problems']);
        $this->assertSame(0, $plan['summary']['cells'], json_encode($plan['weeks'][0]['changes'] ?? []));
        $this->assertSame(1, $plan['summary']['weeks']);
        $this->assertSame([], $plan['newDistricts']);
        $this->assertTrue(is_file($this->dir.'/IDSR regions/IDSR weekly data - Volta.xlsx'));
    }

    public function test_a_table_upload_is_reviewed_then_applied(): void
    {
        $u = $this->user('Volta');
        [$code, $name] = OrgUnits::regionOf('Volta')['districts'][1];
        $csv = "Year,Week,District,AFP CS,Measles D,Reports expected\n2026,41,$name,4,1,18\n2026,41,Nowhere Land,x,0,\n";
        $plan = $this->actingAs($u)->call('POST', '/api/idsr/import?region=Volta&name=t.csv', [], [], [], [], $csv)->assertOk()->json();
        $this->assertSame(2, $plan['summary']['cells']);
        $this->assertStringContainsString('Row 3', $plan['problems'][0]);
        $this->assertArrayNotHasKey('data', $plan);
        $this->assertFalse(is_file($this->dir.'/IDSR regions/IDSR weekly data - Volta.xlsx'));
        $done = $this->actingAs($u)->call('POST', '/api/idsr/import?region=Volta&name=t.csv&apply=1', [], [], [], [], $csv)->assertOk()->json();
        $this->assertSame(4, $done['data']['counts']['2026-41'][$code]['afp.CS']);
        $this->assertSame(18, collect($done['data']['weeks'])->firstWhere('week', 41)['expected']);
    }

    public function test_bad_counts_and_reports_are_refused(): void
    {
        $u = $this->user('Volta');
        $code = OrgUnits::regionOf('Volta')['districts'][0][0];
        $this->actingAs($u)->postJson('/api/idsr/week', ['year' => 2026, 'week' => 40, 'meta' => ['expected' => 3, 'received' => 5], 'counts' => []])
            ->assertStatus(400)->assertJson(['error' => "Reports received can't be more than reports expected."]);
        $this->actingAs($u)->postJson('/api/idsr/week', ['year' => 2026, 'week' => 60, 'meta' => [], 'counts' => []])->assertStatus(400);
        $this->actingAs($u)->postJson('/api/idsr/week', ['year' => 2026, 'week' => 40, 'meta' => [], 'counts' => [$code => ['afp.CS' => -1]]])
            ->assertStatus(400);
    }

    public function test_only_support_deletes_a_week(): void
    {
        $this->actingAs($this->user('North East'))->postJson('/api/idsr/week/delete', ['year' => 2026, 'week' => 43])->assertStatus(403);
    }

    public function test_the_main_store_reads_as_expected(): void
    {
        $s = Store::for('North East');
        if (! is_file($s->path)) {
            $this->markTestSkipped('No main IDSR store in DATA_DIR.');
        }
        $this->assertSame('North East', $s->region);
        $this->assertNotEmpty($s->weeks);
        $this->assertSame(realpath($this->dir.'/IDSR weekly data.xlsx'), realpath($s->path));
    }

    public function test_dhims2_settings_never_show_the_token_and_fetch_fills_a_plan(): void
    {
        $u = $this->user('North East');
        $ne = OrgUnits::regionOf('North East');
        [$code, $id] = [array_key_first(array_filter($ne['ids'])), array_values(array_filter($ne['ids']))[0]];
        Http::fake([
            '*/api/me*' => Http::response(['displayName' => 'Test User']),
            '*/api/organisationUnits/*' => Http::response(['level' => 2]),
            '*/api/analytics*' => function ($req) use ($id) {
                $rep = str_contains(urldecode($req->url()), 'EXPECTED_REPORTS');

                return Http::response(['headers' => [['name' => 'dx'], ['name' => 'pe'], ['name' => 'ou'], ['name' => 'value']],
                    'rows' => $rep ? [['dsAAAAAAAAA.EXPECTED_REPORTS', '2026W40', $id, '1'], ['dsAAAAAAAAA.ACTUAL_REPORTS', '2026W40', $id, '1']]
                        : [['deAFPcases1', '2026W40', $id, '2']],
                    'metaData' => ['items' => [$id => ['name' => 'Somewhere']]]]);
            },
        ]);
        $cfg = $this->actingAs($u)->getJson('/api/idsr/dhims2')->assertOk()->json();
        $this->assertArrayNotHasKey('token', $cfg);
        $this->assertFalse($cfg['hasToken']);
        Http::fake(['*/api/dataSets*' => Http::response(['dataSets' => []])]);
        $c = $this->actingAs($u)->postJson('/api/idsr/dhims2/connect', ['url' => 'https://dhims.example.org/dhims/dhis-web-dashboard/', 'token' => 'd2pat_secret1234', 'remember' => true])
            ->assertOk()->json();
        $this->assertSame(['https://dhims.example.org/dhims', true, '1234', 'Test User'], [$c['url'], $c['remembered'], $c['tokenEnd'], $c['user']]);
        $this->assertStringNotContainsString('d2pat_secret1234', file_get_contents($this->dir.'/private/dhims2.json'));
        $plan = $this->actingAs($u)->postJson('/api/idsr/dhims2/fetch', ['ds' => 'dsAAAAAAAAA', 'mapping' => ['afp.CS' => 'deAFPcases1'],
            'year' => 2026, 'from' => 40, 'to' => 40])->assertOk()->json();
        $this->assertSame(1, $plan['summary']['weeks']);
        $this->assertSame(['field' => 'Reports expected', 'old' => null, 'new' => 1], $plan['weeks'][0]['meta'][0]);
        $name = Store::for('North East')->names()[$code];
        $this->assertContains(['district' => $name, 'field' => 'AFP CS', 'old' => null, 'new' => 2], $plan['weeks'][0]['changes'],
            json_encode($plan['weeks'][0]['changes']));
    }
}
