<?php

namespace App\Filament\Super\Resources\Companies\RelationManagers;

use Filament\Actions\AttachAction;
use Filament\Actions\CreateAction;
use Filament\Actions\DetachAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Company members with their role inside this company. */
class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'ইউজার ও রোল';

    public static function roles(): array
    {
        return ['owner' => 'Owner', 'admin' => 'Admin', 'agent' => 'Agent'];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('নাম')->required()->maxLength(255),
            TextInput::make('email')->email()->required()->unique('users', 'email', ignoreRecord: true),
            TextInput::make('password')->label('পাসওয়ার্ড')->password()->revealable()
                ->required(fn (string $operation) => $operation === 'create')
                ->dehydrated(fn ($state) => filled($state))->minLength(10),
            Select::make('role')->label('রোল')->options(self::roles())->required()->default('agent'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->label('নাম')->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('role')->label('রোল')->badge()
                    ->formatStateUsing(fn ($state) => self::roles()[$state] ?? $state),
            ])
            ->headerActions([
                CreateAction::make()->label('নতুন ইউজার')->before(fn () => $this->ensureSeat()),
                AttachAction::make()->label('বিদ্যমান ইউজার যোগ')->preloadRecordSelect()->before(fn () => $this->ensureSeat())
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        Select::make('role')->label('রোল')->options(self::roles())->required()->default('agent'),
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
                DetachAction::make()->label('কোম্পানি থেকে সরান'),
            ]);
    }

    /** Stop when the company plan has no agent seats left. */
    protected function ensureSeat(): void
    {
        $left = $this->getOwnerRecord()->seatsLeft();
        if ($left !== null && $left <= 0) {
            \Filament\Notifications\Notification::make()->danger()
                ->title('প্যাকেজের ইউজার সীমা শেষ')
                ->body('এই কোম্পানির প্যাকেজে আর ইউজার যোগ করা যাবে না। প্যাকেজ বদলান।')->send();
            throw new \Filament\Support\Exceptions\Halt();
        }
    }
}
