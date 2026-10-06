<?php

namespace App\Services;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal ISP Digital check used by the panel ("test connection"). The full read/write client
 * lives in the Python engine. The panel logs a session out unless requests look like the browser's.
 */
class IspDigitalClient
{
    private CookieJar $jar;

    public function __construct(private string $baseUrl, private string $username, private string $password)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->jar = new CookieJar;
    }

    private function http()
    {
        return Http::withOptions(['cookies' => $this->jar, 'allow_redirects' => false])
            ->timeout(20)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
                'Accept' => 'text/html,application/json;q=0.9,*/*;q=0.8',
                'Referer' => $this->baseUrl.'/Account/Login',
                'Origin' => $this->baseUrl,
            ]);
    }

    public function login(): void
    {
        $page = $this->http()->get($this->baseUrl.'/Account/Login');
        if (! preg_match('/name="__RequestVerificationToken"[^>]*value="([^"]+)"/', $page->body(), $m)) {
            throw new RuntimeException('লগইন পেজ পাওয়া যায়নি — URL ঠিক আছে কি না দেখুন।');
        }
        $form = ['__RequestVerificationToken' => $m[1], 'Username' => $this->username,
            'Password' => $this->password, 'RememberMe' => 'false'];
        foreach (['IPAddress', 'CountryName', 'Region', 'CityName', 'PostalCode', 'Latitude', 'Longitude', 'TimeZone', 'Organization'] as $k) {
            $form['VmAuthTracer.'.$k] = '';
        }
        $r = $this->http()->asForm()->post($this->baseUrl.'/Account/LoginChecker', $form);
        if ($r->status() !== 302 || ! str_contains((string) $r->header('Location'), 'Dashboard')) {
            throw new RuntimeException('ইউজারনেম বা পাসওয়ার্ড ভুল।');
        }
        if ($this->http()->get($this->baseUrl.'/EmployeeDashboard/Index')->status() !== 200) {
            throw new RuntimeException('লগইন হলো কিন্তু প্যানেল সেশন বাতিল করেছে।');
        }
    }

    /** Total customers visible to this user (proves read access works). */
    public function customerCount(): int
    {
        $r = $this->http()->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->get($this->baseUrl.'/Customer/AjaxCustomerList', ['draw' => 1, 'start' => 0, 'length' => 1]);
        if ($r->status() !== 200) {
            throw new RuntimeException('কাস্টমার তালিকা পড়া যায়নি (এই ইউজারের অনুমতি দেখুন)।');
        }

        return (int) ($r->json('iTotalRecords') ?? 0);
    }
}
