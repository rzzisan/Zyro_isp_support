<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Billing actions go through the engine (it holds the logged-in billing session per company). */
class Engine
{
    private static function http(): PendingRequest
    {
        return Http::baseUrl(config('services.engine.url'))->timeout(90)->acceptJson()
            ->withHeaders(['x-internal-key' => (string) config('services.engine.key')]);
    }

    private static function call(string $method, string $path, array $data = []): mixed
    {
        try {
            return static::http()->{$method}($path, $data)->throw()->json();
        } catch (RequestException $e) {
            throw new RuntimeException($e->response->json('detail') ?? 'বিলিং সফটওয়্যারে কাজটা হয়নি');
        }
    }

    /** categories / priorities / departments / employees: [id => name], cached for 10 minutes. */
    public static function ticketOptions(int $companyId): array
    {
        return Cache::remember("ticket-options:{$companyId}", 600, fn () => static::call('get', "/internal/{$companyId}/ticket-options"));
    }

    public static function customers(int $companyId, string $q): array
    {
        return static::call('get', "/internal/{$companyId}/customers", ['q' => $q]) ?? [];
    }

    /** Opens a ticket in the billing software; returns ['message' => ..., 'complain_id' => ...]. */
    public static function createTicket(int $companyId, array $data): array
    {
        // made with the signed-in member's own billing login
        return static::call('post', "/internal/{$companyId}/tickets", $data + ['user_id' => auth()->id()]);
    }

    public static function assign(int $companyId, string $complainId, array $employees, ?string $deptId, bool $smsEmployees): void
    {
        static::call('post', "/internal/{$companyId}/tickets/{$complainId}/assign",
            ['employees' => array_values($employees), 'dept_id' => $deptId, 'sms_employees' => $smsEmployees,
                'user_id' => auth()->id()]);
    }

    /** Runs the ticket sync for this company right away; returns counts. */
    public static function syncTickets(int $companyId): array
    {
        return static::call('post', "/internal/{$companyId}/tickets/sync") ?? [];
    }

    /** Mark a ticket solved in billing; the engine refuses unless the customer is online on MikroTik now. */
    public static function solve(int $companyId, string $complainId, ?string $remark): array
    {
        return static::call('post', "/internal/{$companyId}/tickets/{$complainId}/solve",
            ['remark' => (string) $remark, 'user_id' => auth()->id()]) ?? [];
    }

    public static function solvers(int $companyId, string $complainId): array
    {
        return static::call('get', "/internal/{$companyId}/tickets/{$complainId}/solvers") ?? [];
    }

    /** Copy all billing customers now (normally nightly); returns total / created / gone. */
    public static function syncCustomers(int $companyId): array
    {
        return static::call('post', "/internal/{$companyId}/customers/sync") ?? [];
    }

    /** Background customer sync if the last one is older than $maxAge seconds; returns started / running. */
    public static function refreshCustomers(int $companyId, int $maxAge = 600): array
    {
        try {
            return static::http()->timeout(5)->post("/internal/{$companyId}/customers/refresh?max_age={$maxAge}")->throw()->json() ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** Live connection, ONU and bill state of one customer. */
    public static function customerLive(int $companyId, int $headerId): array
    {
        return static::call('get', "/internal/{$companyId}/customers/{$headerId}/live") ?? [];
    }

    /** Connect to a MikroTik, read its identity and match it to the billing server name. */
    public static function testMikrotik(int $companyId, int $routerId): array
    {
        return static::call('post', "/internal/{$companyId}/mikrotik/{$routerId}/test") ?? [];
    }

    /** Client monitoring action on the customer's MikroTik: recheck | traffic | ping (read-only). */
    public static function monitor(int $companyId, int $headerId, string $what): array
    {
        return static::call('get', "/internal/{$companyId}/monitor/{$headerId}/{$what}") ?? [];
    }

    /** Read every router's online PPPoE list now; returns [{router, online, ok, error?}]. */
    public static function syncOnline(int $companyId): array
    {
        return static::call('post', "/internal/{$companyId}/ppp/sync") ?? [];
    }

    /** New-ticket form: customer/bill (our DB), connection (MikroTik), OLT/ONU (billing). */
    public static function ticketInfo(int $companyId, int $headerId): array
    {
        return static::call('get', "/internal/{$companyId}/customers/{$headerId}/ticket-info") ?? [];
    }

    /** Read an OLT's name/model over SNMP (read-only). */
    public static function testOlt(int $companyId, int $oltId): array
    {
        return static::call('post', "/internal/{$companyId}/olt/{$oltId}/test") ?? [];
    }

    /** The full instructions the bots get now: ['customer' => ..., 'technician' => ...]. */
    public static function botPrompts(int $companyId): array
    {
        return static::call('get', "/internal/{$companyId}/bot-prompts") ?? [];
    }
}
