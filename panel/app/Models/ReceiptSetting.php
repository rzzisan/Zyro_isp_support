<?php

namespace App\Models;

use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What the money receipt prints around the bill: company header, QR, footer, paper width. One row per company. */
class ReceiptSetting extends Model
{
    public const QR_NOTE = 'বিকাশ পেমেন্ট করতে পাশের কোড টি স্ক্যান করুন।';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['paper_width' => 'integer'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** The company's row, or an unsaved one prefilled from the company itself. */
    public static function forCompany(Company $company): self
    {
        return static::firstOrNew(['company_id' => $company->id], [
            'company_name' => $company->name, 'email' => $company->contact_email, 'mobile' => $company->contact_phone,
            'qr_note' => self::QR_NOTE, 'paper_width' => 80,
        ]);
    }

    /** The QR link as inline SVG, or null when none is set. */
    public function qrSvg(): ?string
    {
        if (blank($this->qr_link)) {
            return null;
        }

        return (new QRCode(new QROptions([
            'outputType' => QROutputInterface::MARKUP_SVG, 'outputBase64' => false, 'addQuietzone' => false,
            'svgAddXmlHeader' => false, 'drawLightModules' => false,
        ])))->render(trim($this->qr_link));
    }
}
