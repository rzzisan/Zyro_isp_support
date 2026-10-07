<?php

namespace App\Filament\App\Resources\Conversations\Pages;

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
use RuntimeException;

/** One conversation: messages, the bot's drafts, staff reply box, assign and bot pause. */
class ViewConversation extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ConversationResource::class;

    protected string $view = 'filament.app.conversation';

    public string $reply = '';

    public int $pauseHours = 2;

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
                ->visible(fn () => ! $this->contact()->isBotPaused())
                ->requiresConfirmation()
                ->modalDescription('এই নম্বরে বট আর উত্তর দেবে না, যতক্ষণ না আবার চালু করেন।')
                ->action(function () {
                    $this->contact()->forceFill(['bot_paused' => true])->save();
                    Notification::make()->success()->title('এই নম্বরে বট থামানো হয়েছে')->send();
                }),
            Action::make('resume')->label('বট চালু করুন')->icon(Heroicon::OutlinedPlay)->color('success')
                ->visible(fn () => $this->contact()->isBotPaused())
                ->action(function () {
                    // identification starts fresh, like the old dashboard
                    $this->contact()->forceFill(['bot_paused' => false, 'bot_paused_until' => null, 'ident_state' => null])->save();
                    Notification::make()->success()->title('এই নম্বরে বট আবার চালু')->send();
                }),
        ];
    }

    public function send(): void
    {
        $this->validate([
            'reply' => ['required', 'string', 'max:4000'],
            'pauseHours' => ['integer', 'min:0', 'max:72'],
        ], ['reply.required' => 'মেসেজ লিখুন']);
        try {
            $m = WhatsApp::sendText($this->contact(), $this->reply, auth()->user(), $this->pauseHours);
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }
        $this->reply = '';
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
            'sending' => WhatsApp::sendingEnabled(),
            'company' => Filament::getTenant(),
            'tickets' => $c->customer_id
                ? BillingTicket::where('company_id', $c->company_id)->where('customer_id', $c->customer_id)
                    ->orderByRaw("state IN ('pending', 'processing') DESC")->latest('opened_at')->limit(5)->get()
                : collect(),
        ];
    }
}
