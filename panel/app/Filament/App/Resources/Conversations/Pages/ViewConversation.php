<?php

namespace App\Filament\App\Resources\Conversations\Pages;

use App\Filament\App\Resources\Conversations\Concerns\InboxList;
use App\Filament\App\Resources\Conversations\ConversationResource;
use App\Models\BillingCustomer;
use App\Models\BillingTicket;
use App\Models\WaContact;
use App\Models\WaDraft;
use App\Services\WhatsApp;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\WithFileUploads;
use RuntimeException;

/** One conversation: messages, the bot's drafts, staff reply box, assign and bot pause. */
class ViewConversation extends Page
{
    use InboxList;
    use InteractsWithRecord;
    use WithFileUploads;

    protected static string $resource = ConversationResource::class;

    protected string $view = 'filament.app.inbox';

    public string $reply = '';

    public const MANUAL_PAUSE_HOURS = 12;

    public int $pauseMinutes = 30;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $attachment = null;

    public function removeAttachment(): void
    {
        $this->attachment = null;
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function contact(): WaContact
    {
        /** @var WaContact */
        return $this->getRecord();
    }

    public function getTitle(): string|Htmlable
    {
        $c = $this->contact();

        return ($c->name ?: __('নাম নেই')).' · '.$c->displayNumber();
    }

    /** The chat header shows who it is; the page keeps only its action buttons. */
    public function getHeading(): string|Htmlable
    {
        return __('ইনবক্স');
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        $company = Filament::getTenant();

        return [
            \App\Filament\App\TicketActions::newTicket($this->contact()->customer_id)->label(__('টিকিট খুলুন'))->color('gray'),
            Action::make('take')->label(__('আমি নিলাম'))->icon(Heroicon::OutlinedHandRaised)
                ->visible(fn () => $this->contact()->assigned_user_id !== auth()->id())
                ->action(function () {
                    $this->contact()->forceFill(['assigned_user_id' => auth()->id()])->save();
                    Notification::make()->success()->title(__('কনভারসেশন আপনার দায়িত্বে'))->send();
                }),
            Action::make('assign')->label(__('অন্যকে দিন'))->icon(Heroicon::OutlinedUserGroup)->color('gray')
                ->schema([
                    Select::make('user_id')->label(__('কার দায়িত্বে'))
                        ->options(fn () => $company->users()->pluck('users.name', 'users.id'))
                        ->placeholder(__('কেউ না')),
                ])
                ->fillForm(fn () => ['user_id' => $this->contact()->assigned_user_id])
                ->action(function (array $data) use ($company) {
                    $id = $data['user_id'] ?? null;
                    abort_if($id && ! $company->users()->whereKey($id)->exists(), 403);
                    $this->contact()->forceFill(['assigned_user_id' => $id])->save();
                    Notification::make()->success()->title(__('দায়িত্ব বদলানো হয়েছে'))->send();
                }),
            Action::make('mute')->label(__('নোটিফিকেশন বন্ধ'))->icon(Heroicon::OutlinedBellSlash)->color('gray')
                ->visible(fn () => ! $this->contact()->notify_muted)
                ->requiresConfirmation()
                ->modalDescription(__('এই নম্বর মেসেজ দিলে কেউ বেল, সাউন্ড বা পুশ নোটিফিকেশন পাবে না। চ্যাট ইনবক্সে আগের মতোই আসবে, বটও আগের মতো উত্তর দেবে।'))
                ->action(function () {
                    $this->contact()->forceFill(['notify_muted' => true])->save();
                    Notification::make()->success()->title(__('এই নম্বরের নোটিফিকেশন বন্ধ'))->send();
                }),
            Action::make('unmute')->label(__('নোটিফিকেশন চালু'))->icon(Heroicon::OutlinedBell)->color('gray')
                ->visible(fn () => $this->contact()->notify_muted)
                ->action(function () {
                    $this->contact()->forceFill(['notify_muted' => false])->save();
                    Notification::make()->success()->title(__('এই নম্বরের নোটিফিকেশন আবার চালু'))->send();
                }),
            Action::make('pause')->label(__('বট থামান'))->icon(Heroicon::OutlinedPause)->color('warning')
                ->visible(fn () => ! $this->contact()->isBotPaused())
                ->requiresConfirmation()
                ->modalDescription(__('এই নম্বরে বট ১২ ঘণ্টা উত্তর দেবে না, তারপর নিজে থেকে আবার চালু হবে। আগেই চালু করতে চাইলে "বট চালু করুন" চাপুন।'))
                ->action(function () {
                    // a manual pause lasts MANUAL_PAUSE_HOURS, then the bot answers this number again on its own
                    $this->contact()->forceFill(['bot_paused' => false, 'bot_paused_until' => now()->addHours(self::MANUAL_PAUSE_HOURS)])->save();
                    Notification::make()->success()->title(__('এই নম্বরে বট ১২ ঘণ্টার জন্য থামানো হয়েছে'))->send();
                }),
            Action::make('resume')->label(__('বট চালু করুন'))->icon(Heroicon::OutlinedPlay)->color('success')
                ->visible(fn () => $this->contact()->isBotPaused())
                ->action(function () {
                    // a customer already identified stays identified; an unfinished identification starts fresh
                    $state = $this->contact()->ident_state;
                    $keep = ($state['stage'] ?? null) === 'ok' || ($state['mode'] ?? null) === 'technician';
                    $this->contact()->forceFill(['bot_paused' => false, 'bot_paused_until' => null,
                        'ident_state' => $keep ? $state : null])->save();
                    Notification::make()->success()->title(__('এই নম্বরে বট আবার চালু'))->send();
                }),
        ];
    }

    /** Agent sets or changes the chat's customer ID; the bot then always uses that line (no expiry, never asks). */
    public function linkCustomerAction(): Action
    {
        return Action::make('linkCustomer')
            ->label(fn () => $this->contact()->customer_set_at ? __('বদলান') : ($this->contact()->customer_id ? __('নিশ্চিত / বদলান') : __('ID দিন')))
            ->icon(Heroicon::OutlinedPencilSquare)->link()->size('sm')
            ->modalHeading(__('এই চ্যাটের কাস্টমার'))
            ->modalDescription(__('যে লাইন দেবেন, বট এই নম্বরে সবসময় সেই লাইন ধরে উত্তর দেবে, পরিচয় আর জিজ্ঞেস করবে না।'))
            ->modalSubmitActionLabel(__('সেভ করুন'))
            ->fillForm(fn () => ['customer_id' => $this->contact()->suggestedCustomerId()])
            ->schema([
                Select::make('customer_id')->label(__('কাস্টমার'))->required()->searchable()
                    ->helperText(__('কাস্টমার ID, মোবাইল, নাম বা username লিখে খুঁজুন'))
                    ->getSearchResultsUsing(fn (string $search) => collect(\App\Filament\App\TicketActions::searchCustomers($search))
                        ->mapWithKeys(fn ($c) => [$c['customer_id'] => BillingCustomer::searchLabel($c)])->all())
                    ->getOptionLabelUsing(function ($value) {
                        $c = BillingCustomer::where('company_id', $this->contact()->company_id)->whereNull('gone_at')
                            ->where('customer_id', $value)->orderBy('is_left')->first();

                        return $c ? BillingCustomer::searchLabel($c) : 'ID '.$value;
                    }),
            ])
            ->action(function (array $data) {
                $id = (string) $data['customer_id'];
                abort_unless(BillingCustomer::where('company_id', $this->contact()->company_id)->where('customer_id', $id)->exists(), 422);
                // the engine checks customer_set_at before anything else, so ident_state can stay as it is
                $this->contact()->forceFill(['customer_id' => $id, 'customer_set_by' => auth()->id(), 'customer_set_at' => now()])->save();
                Notification::make()->success()->title(__('এই চ্যাট এখন কাস্টমার ID :id-এর', ['id' => $id]))
                    ->body(__('বট এই লাইন ধরেই উত্তর দেবে।'))->send();
            });
    }

    /** Back to the bot's own identification (the last ID stays as a hint). */
    public function unlinkCustomerAction(): Action
    {
        return Action::make('unlinkCustomer')->label(__('সরান'))->icon(Heroicon::OutlinedXMark)->link()->size('sm')->color('danger')
            ->visible(fn () => (bool) $this->contact()->customer_set_at)
            ->requiresConfirmation()
            ->modalHeading(__('এজেন্টের দেওয়া কাস্টমার ID সরাবেন?'))
            ->modalDescription(__('বট আবার নিজে লাইন চিনবে (নিবন্ধিত নম্বর বা আগের ID দিয়ে), না চিনলে কাস্টমারের কাছে ID চাইবে।'))
            ->action(function () {
                $this->contact()->forceFill(['customer_set_by' => null, 'customer_set_at' => null])->save();
                Notification::make()->success()->title(__('এজেন্টের দেওয়া ID সরানো হয়েছে'))->send();
            });
    }

    public function send(): void
    {
        $this->validate([
            'reply' => [$this->attachment ? 'nullable' : 'required', 'string', 'max:4000'],
            'pauseMinutes' => ['integer', 'min:0', 'max:4320'],
            'attachment' => ['nullable', 'file', 'max:12288',
                'mimetypes:'.implode(',', array_keys(WhatsApp::MEDIA_TYPES))],
        ], [
            'reply.required' => __('মেসেজ লিখুন'),
            'attachment.max' => __('ফাইল ১২ MB-এর বেশি হতে পারবে না'),
            'attachment.mimetypes' => __('শুধু ছবি (JPG/PNG/WEBP), PDF, MP4 বা MP3 পাঠানো যায়'),
        ]);
        try {
            $m = $this->attachment
                ? WhatsApp::sendMedia($this->contact(), $this->attachment->getRealPath(), $this->attachment->getMimeType(),
                    $this->attachment->getClientOriginalName(), $this->reply, auth()->user(), $this->pauseMinutes)
                : WhatsApp::sendText($this->contact(), $this->reply, auth()->user(), $this->pauseMinutes);
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }
        $this->reply = '';
        $this->attachment = null;
        $this->contact()->refresh();
        Notification::make()->success()
            ->title($m->status === 'test' ? __('টেস্ট মোড: সেভ হয়েছে, কাস্টমারের কাছে যায়নি') : __('পাঠানো হয়েছে'))
            ->body($this->pauseMinutes > 0 ? __('এই নম্বরে বট :time চুপ থাকবে।', ['time' => self::pauseLabel($this->pauseMinutes)]) : null)
            ->send();
    }

    /** Copy a bot draft into the reply box so a person can check, edit and send it. */
    public function useDraft(int $id): void
    {
        $draft = WaDraft::where('contact_id', $this->contact()->id)
            ->where('company_id', $this->contact()->company_id)->findOrFail($id);
        $this->reply = (string) $draft->draft;
    }

    protected function getViewData(): array
    {
        $c = $this->contact();
        $messages = $c->messages()->with('user')->latest('id')->limit(200)->get();
        // drafts that never reached the customer (shadow / dry run / failed); sent ones are already messages
        $drafts = $c->drafts()->where('mode', '!=', 'sent')->latest('id')->limit(100)->get();
        $items = $messages->map(fn ($m) => ['kind' => 'message', 'at' => $m->created_at, 'm' => $m])
            ->concat($drafts->map(fn ($d) => ['kind' => 'draft', 'at' => $d->created_at, 'm' => $d]))
            ->sortBy(fn ($i) => [$i['at']?->timestamp ?? 0, $i['kind'] === 'draft' ? 1 : 0, $i['m']->id])
            ->values();

        return [
            'contact' => $c,
            'items' => $items,
            'windowOpen' => $c->windowOpen(),
            'messageCount' => $c->messages()->count(),
            'sending' => WhatsApp::sendingEnabled(),
            'company' => Filament::getTenant(),
            'tickets' => $c->customer_id
                ? BillingTicket::where('company_id', $c->company_id)->where('customer_id', $c->customer_id)
                    ->orderByRaw("state IN ('pending', 'processing') DESC")->latest('opened_at')->limit(5)->get()
                : collect(),
        ];
    }

    public static function pauseLabel(int $minutes): string
    {
        $digits = app()->getLocale() === 'bn' ? ['0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯'] : [];

        return $minutes % 60 === 0 ? __(':n ঘণ্টা', ['n' => strtr((string) ($minutes / 60), $digits)])
            : __(':n মিনিট', ['n' => strtr((string) $minutes, $digits)]);
    }
}
