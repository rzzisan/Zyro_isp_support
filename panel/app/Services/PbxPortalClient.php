<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Logs into the hotline PBX's web portal (FusionPBX, as pbx.bd / alphapbx use) like a browser and reads its call
 * records (CDR) through the portal's own CSV export. There is no API, so a page that does not look as expected is
 * saved to storage/app/pbx-debug/ (never the password) and the error says so.
 */
class PbxPortalClient
{
    public const TZ = 'Asia/Dhaka';

    private const CDR_PAGE = '/app/xml_cdr/xml_cdr.php';

    private const EXPORT = '/app/xml_cdr/xml_cdr_export.php';

    private CookieJar $jar;

    private bool $loggedIn = false;

    public function __construct(private string $baseUrl, private string $username, private string $password)
    {
        $this->baseUrl = rtrim(preg_match('#^https?://#i', $baseUrl) ? $baseUrl : 'https://'.$baseUrl, '/');
        $this->jar = new CookieJar;
    }

    private function http()
    {
        return Http::withOptions(['cookies' => $this->jar, 'allow_redirects' => false])
            ->timeout(60)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
                'Accept' => 'text/html,text/csv,*/*;q=0.8',
                'Referer' => $this->baseUrl.self::CDR_PAGE,
            ]);
    }

    public function login(): void
    {
        $page = $this->http()->get($this->baseUrl.'/login.php');
        if ($page->status() !== 200 || ! str_contains($page->body(), "name='username'") && ! str_contains($page->body(), 'name="username"')) {
            throw new RuntimeException(__('পোর্টালের লগইন পেজ পাওয়া যায়নি — ঠিকানা ঠিক আছে কি না দেখুন।'));
        }
        $form = $this->hiddenInputs($page->body()) + ['username' => $this->username, 'password' => $this->password];
        $this->http()->asForm()->withHeaders(['Referer' => $this->baseUrl.'/login.php'])->post($this->baseUrl.'/login.php', $form);

        $cdr = $this->http()->get($this->baseUrl.self::CDR_PAGE);
        if ($cdr->status() === 302 && str_contains((string) $cdr->header('Location'), 'login')) {
            throw new RuntimeException(__('ইউজারনেম বা পাসওয়ার্ড ভুল।'));
        }
        if ($cdr->status() !== 200) {
            $this->debug('cdr-page', $cdr->body());
            throw new RuntimeException(__('লগইন হলো কিন্তু কল রেকর্ড (CDR) পেজ খুলল না (HTTP :s) — এই ইউজারের CDR দেখার অনুমতি আছে কি না দেখুন।', ['s' => $cdr->status()]));
        }
        $this->loggedIn = true;
    }

    /**
     * Call records that started in [$from, $to) (Bangladesh time), as associative rows keyed by the CSV header.
     *
     * @return list<array<string, string>>
     */
    public function cdrs(CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! $this->loggedIn) {
            $this->login();
        }
        $filters = ['export_format' => 'csv',
            'start_stamp_begin' => $from->timezone(self::TZ)->format('Y-m-d H:i'),
            'end_stamp_begin' => '', 'start_stamp_end' => $to->timezone(self::TZ)->format('Y-m-d H:i')];

        // newer FusionPBX: the export is a POST from the CDR page carrying its token; older: a plain GET
        $page = $this->http()->get($this->baseUrl.self::CDR_PAGE, array_diff_key($filters, ['export_format' => 1]));
        $post = $this->http()->asForm()->post($this->baseUrl.self::EXPORT, array_merge($this->hiddenInputs($page->body()), $filters));
        if ($this->isCsv($post->body())) {
            return $this->parseCsv($post->body());
        }
        $get = $this->http()->get($this->baseUrl.self::EXPORT, $filters);
        if ($this->isCsv($get->body())) {
            return $this->parseCsv($get->body());
        }
        $this->debug('cdr-page', $page->body());
        $this->debug('export-post', $post->body());
        $this->debug('export-get', $get->body());
        throw new RuntimeException(__('পোর্টাল থেকে CSV আসেনি (HTTP :a / :b)। পেজগুলো storage/app/pbx-debug/-এ রাখা আছে।', ['a' => $post->status(), 'b' => $get->status()]));
    }

    /** One CSV row as our pbx_cdrs columns (raw keeps the whole row). */
    public static function normalize(array $row): ?array
    {
        $uuid = $row['xml_cdr_uuid'] ?? $row['uuid'] ?? null;
        if (blank($uuid)) {
            return null;
        }
        $at = function (string $epoch, string $stamp) use ($row) {
            if (! empty($row[$epoch]) && ctype_digit((string) $row[$epoch])) {
                return CarbonImmutable::createFromTimestamp((int) $row[$epoch], 'UTC');
            }
            if (! empty($row[$stamp])) {
                return CarbonImmutable::parse($row[$stamp], self::TZ)->utc();
            }

            return null;
        };

        return [
            'uuid' => mb_substr($uuid, 0, 64),
            'direction' => mb_substr($row['direction'] ?? '', 0, 20) ?: null,
            'caller_name' => mb_substr($row['caller_id_name'] ?? '', 0, 120) ?: null,
            'caller_number' => mb_substr($row['caller_id_number'] ?? '', 0, 40) ?: null,
            'destination' => mb_substr($row['destination_number'] ?? $row['caller_destination'] ?? '', 0, 60) ?: null,
            'started_at' => $at('start_epoch', 'start_stamp'),
            'answered_at' => $at('answer_epoch', 'answer_stamp'),
            'ended_at' => $at('end_epoch', 'end_stamp'),
            'duration' => (int) ($row['duration'] ?? 0),
            'billsec' => (int) ($row['billsec'] ?? 0),
            'hangup_cause' => mb_substr($row['hangup_cause'] ?? '', 0, 60) ?: null,
            'raw' => array_diff_key($row, ['xml' => 1, 'json' => 1]),
        ];
    }

    private function isCsv(string $body): bool
    {
        $head = ltrim(substr($body, 0, 2000), "\xEF\xBB\xBF \r\n\t");
        if ($head === '' || $head[0] === '<') {
            return false;
        }
        $first = strtok($head, "\n");

        return str_contains($first, ',') && (str_contains($first, 'uuid') || str_contains($first, 'caller_id'));
    }

    /** @return list<array<string, string>> */
    private function parseCsv(string $body): array
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, preg_replace('/^\xEF\xBB\xBF/', '', $body));
        rewind($fh);
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), fgetcsv($fh, escape: '\\') ?: []);
        $rows = [];
        while (($line = fgetcsv($fh, escape: '\\')) !== false) {
            if ($line === [null] || count($line) !== count($header)) {
                continue;
            }
            $rows[] = array_combine($header, array_map(fn ($v) => (string) $v, $line));
        }
        fclose($fh);

        return $rows;
    }

    /** @return array<string, string> every hidden input of the page (FusionPBX puts its CSRF token in one) */
    private function hiddenInputs(string $html): array
    {
        $out = [];
        preg_match_all('/<input\b[^>]*>/i', $html, $tags);
        foreach ($tags[0] as $tag) {
            if (! preg_match('/type\s*=\s*[\'"]hidden[\'"]/i', $tag) || ! preg_match('/name\s*=\s*[\'"]([^\'"]+)[\'"]/i', $tag, $n)) {
                continue;
            }
            preg_match('/value\s*=\s*[\'"]([^\'"]*)[\'"]/i', $tag, $v);
            $out[html_entity_decode($n[1])] = html_entity_decode($v[1] ?? '');
        }

        return $out;
    }

    private function debug(string $name, string $body): void
    {
        Storage::disk('local')->put('pbx-debug/'.$name.'-'.now()->format('Ymd-His').'.html', $body);
    }
}
