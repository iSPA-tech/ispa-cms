<?php

namespace App\Console\Commands\Data;

use Throwable;
use Transliterator;
use RuntimeException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class CountryCoordsCommand extends Command
{
    /** @var bool */
    public bool $isDiscovery = false;


    protected $signature = 'cms:province-coords
        {--in= : Đường dẫn file PHP chứa $countries ban đầu (vd storage/app/countries.php)}
        {--out= : Đường dẫn output file PHP (vd storage/app/countries_with_coords.php)}
        {--csv= : URL CSV nguồn toạ độ}';

    protected $description = 'Merge lat/lon centroid theo quốc gia vào mảng $countries hiện có';

    /** Alias tên để khớp với tên trong CSV Google */
    protected $aliases = [
        'czech republic' => 'czechia',
        'ivory coast' => "côte d'ivoire",
        'south korea' => 'korea, republic of',
        'north korea' => "korea, democratic people's republic of",
        'russia' => 'russian federation',
        'moldova' => 'moldova, republic of',
        'venezuela' => 'venezuela, bolivarian republic of',
        'tanzania' => 'tanzania, united republic of',
        'laos' => "lao people's democratic republic",
        'syria' => 'syrian arab republic',
        'bolivia' => 'bolivia, plurinational state of',
        'iran' => 'iran, islamic republic of',
        'micronesia' => 'micronesia, federated states of',
        'palestine' => 'palestinian territories',
        'brunei' => 'brunei darussalam',
        'cape verde' => 'cabo verde',
        'macedonia' => 'macedonia [fyrom]',
        'north macedonia' => 'macedonia [fyrom]',
        'swaziland' => 'eswatini',
        'uae' => 'united arab emirates',
        'uk' => 'united kingdom',
        'vatican' => 'vatican city',
        'holy see' => 'vatican city',
        'burma' => 'myanmar [burma]',
        'myanmar' => 'myanmar [burma]',
        'republic of the congo' => 'congo [republic]',
        'democratic republic of the congo' => 'congo [drc]',
        'cocos islands' => 'cocos [keeling] islands',
        'falkland islands' => 'falkland islands [islas malvinas]',
        'east timor' => 'timor-leste',
        'pitcairn' => 'pitcairn islands',
        'curacao' => 'netherlands antilles',
        'curaçao' => 'netherlands antilles',
        'sao tome and principe' => 'são tomé and príncipe',
        'reunion' => 'réunion',
        'st martin' => 'saint martin',
        'st barthelemy' => 'saint barthélemy',
    ];

    public function handle(): int
    {
        $in = $this->option('in') ?: app_path('Console/Commands/Data/countries.php');
        $out = $this->option('out') ?: base_path('cms/Core/database/seeders/exported/provinces.php');
        $csvUrl = $this->option('csv') ?: app_path('Console/Commands/Data/countries_geo.csv');

        // --- 1) Nạp mảng $countries gốc ---
        if (!file_exists($in)) {
            $this->error("Không tìm thấy file input: {$in}");
            return self::FAILURE;
        }
        /** @var array $countries */
        $countries = (static function ($path) {
            /** @noinspection PhpIncludeInspection */
            return include $path;
        })($in);

        if (!is_array($countries)) {
            $this->error('File input không trả về mảng $countries hợp lệ.');
            return self::FAILURE;
        }

        // --- 2) Nạp CSV từ local (mặc định) hoặc tải qua HTTP nếu là URL ---
        $this->info("Đang nạp CSV: {$csvUrl}");
        $csv = '';

        // Nếu đối số là URL http/https thì tải qua HTTP, ngược lại đọc file local
        if (preg_match('/^https?:\/\//i', $csvUrl)) {
            try {
                $resp = Http::timeout(30)->get($csvUrl);
            } catch (Throwable $e) {
                $this->error('Lỗi khi tải CSV: ' . $e->getMessage());
                return self::FAILURE;
            }
            if (!$resp->ok()) {
                $this->error('Không tải được CSV (HTTP ' . $resp->status() . ')');
                return self::FAILURE;
            }
            $csv = $resp->body();
        } else {
            // Đọc từ file local
            $path = $csvUrl;
            if (!file_exists($path)) {
                $this->error('Không tìm thấy file CSV local: ' . $path);
                return self::FAILURE;
            }
            $csv = file_get_contents($path);
        }

        if ($csv === false || trim($csv) === '') {
            $this->error('CSV rỗng hoặc không đọc được.');
            return self::FAILURE;
        }

        // Loại BOM nếu có
        if (str_starts_with($csv, "\xEF\xBB\xBF")) {
            $csv = substr($csv, 3);
        }

        $csv = trim($csv);

        // --- 3) Parse CSV -> map theo ISO2 và theo tên tiếng Anh ---
        $lines = array_map('trim', preg_split('/\r\n|\n|\r/', $csv));
        $header = str_getcsv(array_shift($lines));
        $idx = array_flip(array_map('mb_strtolower', $header));

        foreach (['country', 'latitude', 'longitude', 'name'] as $col) {
            if (!array_key_exists($col, $idx)) {
                $this->error("CSV thiếu cột bắt buộc: {$col}");
                return self::FAILURE;
            }
        }

        $byName = [];
        foreach ($lines as $row) {
            if ($row === '') {
                continue;
            }
            $cols = str_getcsv($row);
            $iso2 = strtoupper($cols[$idx['country']] ?? '');
            $lat = (float)($cols[$idx['latitude']] ?? 0);
            $lon = (float)($cols[$idx['longitude']] ?? 0);
            $name = trim($cols[$idx['name']] ?? '');
            if ($name !== '') {
                $normName = $this->norm($name);
                $byName[$normName] = ['lat' => $lat, 'lon' => $lon, 'iso2' => $iso2];

                // Also index a de-bracketed version (e.g. "Falkland Islands [Islas Malvinas]" -> "Falkland Islands")
                $deBracket = preg_replace('/\s*\[[^\]]*\]\s*/u', ' ', $name);
                $deBracket = preg_replace('/\s+/', ' ', trim($deBracket));
                $normDeBracket = $this->norm($deBracket);
                $byName[$normDeBracket] = ['lat' => $lat, 'lon' => $lon, 'iso2' => $iso2];
            }
        }

        // --- 4) Merge toạ độ vào $countries ---
        $enriched = [];
        $notFound = [];
        $this->output->progressStart(count($countries));

        foreach ($countries as $c) {
            $gname = $c['global_name'] ?? ($c['name'] ?? null);
            $lat = $lon = null;
            $hit = null;

            if ($gname) {
                $key = $this->norm($gname);
                $hit = $byName[$key] ?? null;

                if (!$hit && isset($this->aliases[$key])) {
                    $altKey = $this->norm($this->aliases[$key]);
                    $hit = $byName[$altKey] ?? null;
                }

                if ($hit) {
                    $lat = $hit['lat'];
                    $lon = $hit['lon'];
                } else {
                    $notFound[] = $gname;
                }
            } else {
                $notFound[] = '(không có global_name)';
            }
            // Trong foreach khi duyệt $countries
            $iso2 = $hit['iso2'] ?? null;
            if ($iso2) {
                $flag = strtolower($iso2) . '.svg';
            } else {
                // fallback: lấy từ global_name (chỉ khi thiếu iso2)
                $flag = strtolower(str_replace(' ', '-', $gname)) . '.svg';
            }

            $c['uuid'] = str()->uuid()->toString();
            $c['uid'] = str()->random(8);
            $c['slug'] = str($gname)->slug()->toString();
            $c['type'] = 0;
            $c['parent_id'] = null;
            $c['language_id'] = null;
            $c['maps_id'] = null;
            $c['latitude'] = $lat;
            $c['longitude'] = $lon;
            $c['priority'] = 0;
            $config = [
                "flag" => $flag, "zipCode" => null, "webDefault" => false, "webShow" => false, "url" => null,
                "organization" => null, "timeZone" => $c['timeZone'], "phoneCode" => $c['phoneCode'], "areaName" => null,
            ];
            $c['config'] = $config;

            //  unset timeZone và phoneCode (đã chuyển vào config)
            unset($c['timeZone'], $c['phoneCode']);

            $enriched[] = $c;

            $this->output->progressAdvance();
        }
        $this->output->progressFinish();

        // --- 5) Ghi file output (PHP array) ---
        $export = "<?php\nreturn " . var_export($enriched, true) . ";\n";
        $concurrentDirectory = dirname($out);
        if (!is_dir($concurrentDirectory)) {
            if (!mkdir($concurrentDirectory, 0777, true) && !is_dir($concurrentDirectory)) {
                throw new RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
            }
        }
        file_put_contents($out, $export, LOCK_EX);

        $this->info("Đã ghi: {$out}");
        if (!empty($notFound)) {
            $notFound = array_values(array_unique($notFound));
            $this->warn("Chưa khớp toạ độ cho " . count($notFound) . " mục. Ví dụ: " . implode(', ', array_slice($notFound, 0, 10)) . (count($notFound) > 10 ? ' ...' : ''));
            $this->line('→ Bạn có thể bổ sung alias trong $aliases hoặc chuẩn hoá tên global_name để khớp với CSV.');
        }

        return self::SUCCESS;
    }

    /** Chuẩn hoá chuỗi so khớp tên */
    protected function norm(string $s): string
    {
        $s = trim($s);
        // Lowercase
        $s = mb_strtolower($s, 'UTF-8');
        // Replace ampersand with 'and'
        $s = str_replace('&', 'and', $s);
        // Remove diacritics (accents)
        if (class_exists('Transliterator')) {
            $trans = Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
            if ($trans) {
                $s = $trans->transliterate($s);
            }
        } else {
            $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
        }
        // Normalize common punctuation variations (curly quotes, etc.)
        $s = str_replace(["’", "‘", "´", "`"], "'", $s);
        $s = str_replace(['“', '”'], '"', $s);
        // Collapse multiple spaces
        $s = preg_replace('/\s+/', ' ', $s);
        return $s;
    }
}
