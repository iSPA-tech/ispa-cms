<?php

uses(\Cms\Core\Tests\TestCase::class);

use App\Models\Province;
use Cms\Core\Database\Seeders\ThanglequocProvinceSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->artisan('migrate', [
        '--path' => 'cms/Core/database/migrations/2010_01_01_000001_create_enumerates_table.php',
        '--realpath' => false,
    ])->run();
    $this->artisan('migrate', [
        '--path' => 'cms/Core/database/migrations/2022_02_09_104556_create_languages_table.php',
        '--realpath' => false,
    ])->run();
    $this->artisan('migrate', [
        '--path' => 'database/migrations/2022_02_09_104704_create_provinces_table.php',
        '--realpath' => false,
    ])->run();

    DB::table('enumerates')->insert([
        'enum_key' => 'status_published',
        'name' => 'Published',
        'status' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

test('thanglequoc province seeder replaces legacy hierarchy with canonical new provinces and wards', function (): void {
    $makeProvince = static fn (array $attributes): Province => Province::withoutEvents(fn () => Province::query()->create([
        'uuid' => (string) Str::uuid(),
        'uid' => Str::random(10),
        'name' => 'Legacy Province',
        'global_name' => 'Legacy Province',
        'slug' => Str::slug($attributes['name'] ?? 'legacy-province') . '-' . Str::lower(Str::random(4)),
        'type' => 1,
        'parent_id' => null,
        'config' => ['legacy' => true],
        'status' => 'status_published',
        ...$attributes,
    ]));

    $country = $makeProvince([
        'name' => 'Việt Nam',
        'global_name' => 'Viet Nam',
        'slug' => 'viet-nam',
        'type' => 0,
        'config' => ['country' => true],
    ]);
    $legacyHoChiMinh = $makeProvince([
        'name' => 'TP. HCM',
        'global_name' => 'Ho Chi Minh City',
        'slug' => 'tp-hcm',
        'type' => 2,
        'parent_id' => $country->id,
        'config' => ['legacy_alias' => 'TP. HCM'],
    ]);
    $legacyExactHoChiMinh = $makeProvince([
        'name' => 'Thành phố Hồ Chí Minh',
        'global_name' => 'Ho Chi Minh City',
        'slug' => 'thanh-pho-ho-chi-minh-legacy',
        'type' => 2,
        'parent_id' => $country->id,
        'config' => ['legacy_alias' => 'same visible name'],
    ]);
    $legacyBenTre = $makeProvince([
        'name' => 'Tỉnh Bến Tre',
        'global_name' => 'Ben Tre Province',
        'slug' => 'ben-tre-province',
        'type' => 1,
        'parent_id' => $country->id,
    ]);
    $legacyBenTreCity = $makeProvince([
        'name' => 'Thành phố Bến Tre',
        'global_name' => 'Ben Tre City',
        'slug' => 'ben-tre-city',
        'type' => 2,
        'parent_id' => $legacyBenTre->id,
    ]);
    $legacyBenTreWard = $makeProvince([
        'name' => 'Phường 1',
        'global_name' => 'Ward 1',
        'slug' => 'phuong-1-ben-tre',
        'type' => 4,
        'parent_id' => $legacyBenTreCity->id,
    ]);

    Province::withoutEvents(function (): void {
        $this->seed(ThanglequocProvinceSeeder::class);
        $this->seed(ThanglequocProvinceSeeder::class);
    });

    expect(Province::query()->count())->toBe(3356)
        ->and(Province::query()->whereNotNull('parent_id')->count())->toBe(3355)
        ->and(Province::query()->whereNull('parent_id')->count())->toBe(1)
        ->and(Province::withTrashed()->whereKey($legacyHoChiMinh->id)->firstOrFail()->trashed())->toBeTrue()
        ->and(Province::withTrashed()->whereKey($legacyExactHoChiMinh->id)->firstOrFail()->trashed())->toBeTrue()
        ->and(Province::withTrashed()->whereKey($legacyBenTre->id)->firstOrFail()->trashed())->toBeTrue()
        ->and(Province::withTrashed()->whereKey($legacyBenTreCity->id)->exists())->toBeFalse()
        ->and(Province::withTrashed()->whereKey($legacyBenTreWard->id)->exists())->toBeFalse()
        ->and(Province::withTrashed()->where('name', 'Thành phố Hồ Chí Minh')->count())->toBe(2)
        ->and(Province::query()->where('parent_id', $country->id)->whereIn('type', [1, 2])->count())->toBe(34)
        ->and($country->fresh()->config)->toBe(['country' => true]);

    $haNoi = Province::query()
        ->where('slug', 'thanglequoc-2026-province-01-ha-noi')
        ->firstOrFail();
    $hoChiMinh = Province::query()
        ->where('slug', 'thanglequoc-2026-province-79-ho-chi-minh')
        ->firstOrFail();
    $giaLai = Province::query()
        ->where('slug', 'thanglequoc-2026-province-52-gia-lai')
        ->firstOrFail();
    $iaMo = Province::query()
        ->where('slug', 'thanglequoc-2026-ward-23938-ia-mo')
        ->firstOrFail();
    $baChe = Province::query()
        ->where('slug', 'thanglequoc-2026-ward-06978-ba-che')
        ->firstOrFail();

    expect($haNoi->name)->toBe('Thành phố Hà Nội')
        ->and($haNoi->global_name)->toBe('Ha Noi City')
        ->and(DB::table('provinces')->where('id', $haNoi->id)->value('status'))->toBe('status_published')
        ->and($haNoi->type)->toBe(2)
        ->and($haNoi->parent_id)->toBe($country->id)
        ->and($haNoi->config['administrative_dataset'])->toBe('thanglequoc_vn_units')
        ->and($haNoi->config['administrative_version'])->toBe('2026')
        ->and($haNoi->config['administrative_level'])->toBe('province')
        ->and($haNoi->config['thanglequoc']['source_repo'])->toBe('thanglequoc/vietnamese-provinces-database')
        ->and($haNoi->config['thanglequoc']['province']['Code'])->toBe('01')
        ->and($haNoi->config['thanglequoc']['province']['AdministrativeUnitFullNameEn'])->toBe('Municipality')
        ->and($hoChiMinh->name)->toBe('Thành phố Hồ Chí Minh')
        ->and($hoChiMinh->global_name)->toBe('Ho Chi Minh City')
        ->and($hoChiMinh->slug)->toBe('thanglequoc-2026-province-79-ho-chi-minh')
        ->and($hoChiMinh->type)->toBe(2)
        ->and($hoChiMinh->parent_id)->toBe($country->id)
        ->and($hoChiMinh->config['administrative_dataset'])->toBe('thanglequoc_vn_units')
        ->and($hoChiMinh->config['thanglequoc']['province']['Code'])->toBe('79')
        ->and($hoChiMinh->config)->not->toHaveKey('legacy_config')
        ->and($iaMo->parent_id)->toBe($giaLai->id)
        ->and($iaMo->name)->toBe('Xã Ia Mơ')
        ->and(DB::table('provinces')->where('id', $iaMo->id)->value('status'))->toBe('status_published')
        ->and($iaMo->type)->toBe(4)
        ->and($iaMo->config['administrative_level'])->toBe('ward')
        ->and($iaMo->config['thanglequoc']['province']['Code'])->toBe('52')
        ->and($iaMo->config['thanglequoc']['ward']['Code'])->toBe('23938')
        ->and($iaMo->config['thanglequoc']['ward']['AdministrativeUnitFullNameEn'])->toBe('Commune')
        ->and($baChe->name)->toBe('Xã Ba Chẽ')
        ->and($baChe->config['thanglequoc']['ward']['Code'])->toBe('06978');
});
