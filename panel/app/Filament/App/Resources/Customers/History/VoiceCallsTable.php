<?php

namespace App\Filament\App\Resources\Customers\History;

use App\Models\VoiceReminderCall;
use App\Models\VoiceReminderSetting;
use App\Support\AltNumbers;
use App\Support\VoiceReminders;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Voice reminder calls to the customer (any of their numbers), whether calls or SMS tell them, their other numbers. */
class VoiceCallsTable extends HistoryTable
{
    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => $this->rows(VoiceReminderCall::class)->where('kind', '!=', 'test')->orderByDesc('id'))
            ->heading(__('রিমাইন্ডার কল'))
            ->description(fn () => $this->note())
            ->paginated([10, 25, 50])->defaultPaginationPageOption(10)
            ->emptyStateHeading(__('এখনো কোনো রিমাইন্ডার কল যায়নি'))
            ->columns([
                TextColumn::make('created_at')->label(__('সময়'))->formatStateUsing(fn ($state) => $state?->timezone(self::TZ)->format('d M Y h:i A')),
                TextColumn::make('kind')->label(__('ধরন'))->badge()->color('gray')->formatStateUsing(fn ($state) => __(VoiceReminderCall::KINDS[$state] ?? $state)),
                TextColumn::make('number')->label(__('নম্বর'))
                    ->formatStateUsing(fn ($state, VoiceReminderCall $r) => '0'.substr((string) ($r->alt_number ?: $state), 3))
                    ->description(fn (VoiceReminderCall $r) => $r->alt_of ? __('বিকল্প নম্বর') : null),
                TextColumn::make('status')->label(__('অবস্থা'))->badge()
                    ->color(fn ($state) => $state === 'done' ? 'success' : (in_array($state, ['no_answer', 'busy', 'failed'], true) ? 'warning' : 'gray'))
                    ->formatStateUsing(fn ($state) => __(VoiceReminderCall::STATUSES[$state] ?? $state)),
                TextColumn::make('result')->label(__('ফলাফল'))->formatStateUsing(fn ($state) => __(VoiceReminderCall::RESULTS[$state] ?? $state))
                    ->description(fn (VoiceReminderCall $r) => $r->keys ? __('বাটন: :k', ['k' => $r->keys]) : null),
                TextColumn::make('seconds')->label(__('সময়'))->formatStateUsing(fn ($state) => $state ? gmdate('i:s', (int) $state) : ''),
            ]);
    }

    /** "কলে জানানো হয় · এই নম্বরে পাওয়া গেছে: … · অন্য নম্বর: …" */
    private function note(): string
    {
        $c = $this->record;
        if (! $c) {
            return '';
        }
        $s = VoiceReminderSetting::where('company_id', $c->company_id)->first();
        $regular = (bool) (VoiceReminders::regulars((int) $c->company_id, [(int) $c->header_id])[$c->header_id] ?? false);
        $parts = [$regular && $s?->sms_saving ? __('বিল রিমাইন্ডার কলে জানানো হয় (নিয়মিত কল ধরেন; SMS শুধু কলে না পেলে)') : __('বিল রিমাইন্ডার SMS-এ জানানো হয়')];
        $alts = AltNumbers::list($c);
        $reached = $alts->filter(fn ($r) => $r['reached_at']);
        if ($reached->isNotEmpty()) {
            $parts[] = __('এই নম্বরে পাওয়া গেছে: :n', ['n' => $reached->map(fn ($r) => '0'.substr($r['number'], 3))->join(', ')]);
        }
        $parts[] = $alts->isEmpty() ? __('বিকল্প নম্বর নেই') : __('বিকল্প নম্বর: :n', ['n' => $alts->map(fn ($r) => AltNumbers::label($r))->join(', ')]);

        return implode(' · ', $parts);
    }
}
