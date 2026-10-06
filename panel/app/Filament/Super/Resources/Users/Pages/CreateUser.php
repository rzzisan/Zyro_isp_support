<?php

namespace App\Filament\Super\Resources\Users\Pages;

use App\Filament\Super\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;
}
