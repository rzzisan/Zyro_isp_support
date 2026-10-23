<?php

use App\Models\Membership;
use App\Models\PermissionGroup;
use Illuminate\Database\Migrations\Migration;

// "আমার কাজ" left the menu lists (it follows the "ফিল্ড টেকনিশিয়ান" tick): take it out of the saved lists too, so
// the team and group forms save without "invalid".
return new class extends Migration
{
    public function up(): void
    {
        foreach ([Membership::class, PermissionGroup::class] as $model) {
            foreach ($model::whereNotNull('permissions')->get() as $row) {
                if (in_array('field_jobs', (array) $row->permissions, true)) {
                    $row->permissions = array_values(array_diff($row->permissions, ['field_jobs']));
                    $row->saveQuietly();
                }
            }
        }
    }

    public function down(): void {}
};
