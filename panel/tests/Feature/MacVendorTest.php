<?php

namespace Tests\Feature;

use App\Console\Commands\ImportMacVendors;
use App\Support\MacVendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Router brand from the MAC MikroTik sees: offline vendor list imported from Wireshark's manuf file. */
class MacVendorTest extends TestCase
{
    use RefreshDatabase;

    private const MANUF = "# comment\n"
        ."00:0A:EB\tTpLinkTechno\tTp-Link Technologies Co.,Ltd.\n"
        ."C8:3A:35\tTenda\tTenda Technology Co., Ltd.\n"
        ."00:1B:C5\tIEEERegistr\tIEEE Registration Authority\n"
        ."00:1B:C5:00:00/36\tConverging\tConverging Systems Inc.\n"
        ."70:B3:D5:F0/28\tShenzhenAbc\tShenzhen Abc Intelligent Technology Co.,Ltd\n"
        ."E4:F3:F5\tMercury\tSHENZHEN MERCURY COMMUNICATION TECHNOLOGIES CO.,LTD.\n";

    public function test_brand_names_are_short(): void
    {
        $this->assertSame('TP-Link', MacVendor::brandFrom('Tp-Link Technologies Co.,Ltd.'));
        $this->assertSame('Tenda', MacVendor::brandFrom('Tenda Technology Co., Ltd.'));
        $this->assertSame('Abc', MacVendor::brandFrom('Shenzhen Abc Intelligent Technology Co.,Ltd'));
        $this->assertSame('Mercury', MacVendor::brandFrom('SHENZHEN MERCURY COMMUNICATION TECHNOLOGIES CO.,LTD.'));
        $this->assertSame('Intel', MacVendor::brandFrom('Intel Corporate'));
        $this->assertNotSame('Intel', MacVendor::brandFrom('Shenzhen Intelligent Home Co.,Ltd'));
    }

    public function test_import_and_longest_prefix_lookup(): void
    {
        $rows = ImportMacVendors::parse(self::MANUF);
        $this->assertSame(['000AEB', 'C83A35', '001BC5', '001BC5000', '70B3D5F', 'E4F3F5'], array_keys($rows));

        // a broken (too short) download leaves the table as it was
        Http::fake(['www.wireshark.org/*' => Http::response(gzencode(self::MANUF))]);
        $this->artisan('mac-vendors:import')->assertFailed();
        $this->assertSame(0, \DB::table('mac_vendors')->count());

        \DB::table('mac_vendors')->insert(array_values($rows));
        $this->assertSame('TP-Link', MacVendor::brand('00:0a:eb:12:34:56'));
        $this->assertSame('TP-Link', MacVendor::label('00-0A-EB-12-34-56'));
        $this->assertSame('Converging', MacVendor::brand('00:1B:C5:00:00:AA'));      // MA-S block wins
        $this->assertSame('IEEE Registration Authority', MacVendor::brand('00:1B:C5:99:00:AA'));
        $this->assertSame('Abc', MacVendor::brand('70:B3:D5:F1:23:45'));                   // MA-M block
        $this->assertSame(__('র‍্যান্ডম MAC'), MacVendor::brand('A2:8F:06:05:37:40'));           // locally administered
        $this->assertNull(MacVendor::brand('B0:00:00:00:00:01'));
        $this->assertSame('B0:00:00:00:00:01', MacVendor::label('B0:00:00:00:00:01'));     // unknown: the MAC as before
        $this->assertNull(MacVendor::label(null));
        $this->assertStringContainsString('Tp-Link Technologies', MacVendor::hint('00:0A:EB:12:34:56'));
    }
}
