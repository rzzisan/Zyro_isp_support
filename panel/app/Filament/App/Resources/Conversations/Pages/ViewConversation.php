<?php

namespace App\Filament\App\Resources\Conversations\Pages;

use App\Filament\App\Resources\Conversations\Concerns\InboxList;
use App\Filament\App\Resources\Conversations\ConversationResource;
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

    public int $pauseHours = 2;

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

        return ($c->name ?: 'নাম নেই').' · '.$c->displayNumber();
    }

    /** The chat header shows who it is; the page keeps only its action buttons. */
    public function getHeading(): string|Htmlable
    {
        return 'ইনবক্স';
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        $company = Filament::getTenant();

        return [
            \App\Filament\App\TicketActions::newTicket($this->contact()->customer_id)->label('টিকিট খুলুন')->color('gray'),
            Action::make('take')->label('আমি নিলাম')->icon(Heroicon::OutlinedHandRaised)
                ->visible(fn () => $this->contact()->assigned_user_id !== auth()->id())
                ->action(function () {
                    $this->contact()->forceFill(['assigned_user_id' => auth()->id()])->save();
                    Notification::make()->success()->title('কনভারসেশন আপনার দায়িত্বে')->send();
                }),
            Action::make('assign')->label('অন্যকে দিন')->icon(Heroicon::OutlinedUserGroup)->color('gray')
                ->schema([
                    Select::make('user_id')->label('কার দায়িত্বে')
                        ->options(fn () => $company->users()->pluck('users.name', 'users.id'))
                        ->placeholder('কেউ না'),
                ])
                ->fillForm(fn () => ['user_id' => $this->contact()->assigned_user_id])
                ->action(function (array $data) use ($company) {
                    $id = $data['user_id'] ?? null;
                    abort_if($id && ! $company->users()->whereKey($id)->exists(), 403);
                    $this->contact()->forceFill(['assigned_user_id' => $id])->save();
                    Notification::make()->success()->title('দায়িত্ব বদলানো হয়েছে')->send();
                }),
            Action::make('pause')->label('বট থামান')->icon(Heroicon::OutlinedPause)->color('warning')
                ->visible(fn () => ! $this->contact()->isBotPaused() && ! $this->contact()->technician())
                ->requiresConfirmation()
                ->modalDescription('এই নম্বরে বট আর উত্তর দেবে না, যতক্ষণ না আবার চালু করেন।')
                ->action(function () {
                    $this->contact()->forceFill(['bot_paused' => true])->save();
                    Notification::make()->success()->title('এই নম্বরে বট থামানো হয়েছে')->send();
                }),
            Action::make('resume')->label('বট চালু করুন')->icon(Heroicon::OutlinedPlay)->color('success')
                ->visible(fn () => $this->contact()->isBotPaused())
                ->action(function () {
                    // a customer already identified stays identified; an unfinished identification starts fresh
                    $state = $this->contact()->ident_state;
                    $keep = ($state['stage'] ?? null) === 'ok' || ($state['mode'] ?? null) === 'technician';
                    $this->contact()->forceFill(['bot_paused' => false, 'bot_paused_until' => null,
                        'ident_state' => $keep ? $state : null])->save();
                    Notification::make()->success()->title('এই নম্বরে বট আবার চালু')->send();
                }),
        ];
    }

    public function send(): void
    {
        $this->validate([
            'reply' => [$this->attachment ? 'nullable' : 'required', 'string', 'max:4000'],
            'pauseHours' => ['integer', 'min:0', 'max:72'],
            'attachment' => ['nullable', 'file', 'max:12288',
                'mimetypes:'.implode(',', array_keys(WhatsApp::MEDIA_TYPES))],
        ], [
            'reply.required' => 'মেসেজ লিখুন',
            'attachment.max' => 'ফাইল ১২ MB-এর বেশি হতে পারবে না',
            'attachment.mimetypes' => 'শুধু ছবি (JPG/PNG/WEBP), PDF, MP4 বা MP3 পাঠানো যায়',
        ]);
        try {
            $m = $this->attachment
                ? WhatsApp::sendMedia($this->contact(), $this->attachment->getRealPath(), $this->attachment->getMimeType(),
                    $this->attachment->getClientOriginalName(), $this->reply, auth()->user(), $this->pauseHours)
                : WhatsApp::sendText($this->contact(), $this->reply, auth()->user(), $this->pauseHours);
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }
        $this->reply = '';
        $this->attachment = null;
        $this->contact()->refresh();
        Notification::make()->success()
            ->title($m->status === 'test' ? 'টেস্ট মোড: সেভ হয়েছে, কাস্টমারের কাছে যায়নি' : 'পাঠানো হয়েছে')
            ->body($this->pauseHours > 0 ? "এই নম্বরে বট {$this->pauseHours} ঘণ্টা চুপ থাকবে।" : null)
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
}
