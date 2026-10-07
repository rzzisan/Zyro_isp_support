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

    public static function solvers(int $companyId, string $complainId): array
    {
        return static::call('get', "/internal/{$companyId}/tickets/{$complainId}/solvers") ?? [];
    }

    /** Copy all billing customers now (normally nightly); returns total / created / gone. */
    public static function syncCustomers(int $companyId): array
    {
        return static::call('post', "/internal/{$companyId}/customers/sync") ?? [];
    }

    /** Live connection, ONU and bill state of one customer. */
    public static function customerLive(int $companyId, int $headerId): array
    {
        return static::call('get', "/internal/{$companyId}/customers/{$headerId}/live") ?? [];
    }
}
