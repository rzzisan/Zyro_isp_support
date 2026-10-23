<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\CallLog;
use App\Models\User;
use App\Support\Menu;
use App\Support\Softphone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** Android app sign-in: Desk email + password -> a personal access token per device (Sanctum). */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email|max:255', 'password' => 'required|string|max:255',
            'device' => 'nullable|string|max:120', 'privacy' => 'nullable|boolean']);
        $user = User::whereRaw('lower(email) = ?', [mb_strtolower(trim($data['email']))])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => __('ইমেইল বা পাসওয়ার্ড ভুল')]);
        }
        if (! $user->companies()->exists()) {
            throw ValidationException::withMessages(['email' => __('আপনি কোনো কোম্পানির সদস্য নন')]);
        }
        // the app shows the privacy policy (location recording for field work) and signs in only once it is accepted
        if (! ($data['privacy'] ?? false)) {
            throw ValidationException::withMessages(['email' => __('প্রাইভেসি পলিসি মেনে নিয়ে লগইন করুন (পুরোনো অ্যাপ হলে নতুন অ্যাপ নিন)')]);
        }
        static::acceptPrivacy($user);
        $token = $user->createToken('android:'.($data['device'] ?? 'phone'))->plainTextToken;

        return response()->json(['token' => $token] + static::profile($user));
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(static::profile($request->user()));
    }

    /** A member signed in before the policy came accepts it in the app (it blocks the app until then). */
    public function privacy(Request $request): JsonResponse
    {
        static::acceptPrivacy($request->user());

        return response()->json(static::profile($request->user()->fresh()));
    }

    /** Accepting the policy is the technicians' consent to location recording in every company they work in. */
    public static function acceptPrivacy(User $user): void
    {
        $user->forceFill(['privacy_accepted_at' => $user->privacy_accepted_at ?? now()])->save();
        Membership::where('user_id', $user->id)->whereNull('location_consent_at')->update(['location_consent_at' => now()]);
    }

    /** This phone's Firebase token, so the engine can push new WhatsApp messages to it (gone with the sign-in). */
    public function pushToken(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:512']]);
        $access = $request->user()->currentAccessToken();
        abort_unless($access instanceof \Laravel\Sanctum\PersonalAccessToken, 422);
        DB::table('app_push_tokens')->where('personal_access_token_id', $access->id)->where('token', '!=', $data['token'])->delete();
        DB::table('app_push_tokens')->updateOrInsert(['token' => $data['token']], [
            'user_id' => $request->user()->id, 'personal_access_token_id' => $access->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    /** Change one's own password: needs the current one; every other phone is signed out (browsers too, via AuthenticateSession), this phone stays. */
    public function password(Request $request): JsonResponse
    {
        $data = $request->validate(['current_password' => 'required|string|max:255',
            'password' => 'required|string|min:8|max:255|confirmed|different:current_password']);
        $user = $request->user();
        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => __('বর্তমান পাসওয়ার্ড ভুল')]);
        }
        $user->forceFill(['password' => $data['password'], 'remember_token' => null])->save();
        $current = $user->currentAccessToken();
        $user->tokens()->when($current instanceof \Laravel\Sanctum\PersonalAccessToken, fn ($q) => $q->where('id', '!=', $current->id))->delete();

        return response()->json(['ok' => true]);
    }

    /** Sign out on this phone only (other devices keep their tokens). */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }

    /** The user and every company they work in, with the menus they may open there (Menu permissions). */
    public static function profile(User $user): array
    {
        $companies = $user->companies()->orderBy('companies.name')->get()->map(function ($company) use ($user) {
            $role = Membership::where('company_id', $company->id)->where('user_id', $user->id)->value('role');

            // the call log needs a hotline (SIP) or WhatsApp calls set up, like the panel's page
            $hotline = CallLog::enabledFor($company->id);

            return ['id' => $company->id, 'slug' => $company->slug, 'name' => $company->name, 'role' => $role,
                'menus' => array_values(array_filter(array_keys(Menu::ITEMS),
                    fn ($k) => Menu::allows($user, $company, $k) && ($k !== 'calls' || $hotline)
                        && ($k !== 'phone' || Softphone::available($company))))];
        })->values();

        return ['user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'locale' => $user->locale,
            'privacy_accepted' => $user->privacy_accepted_at !== null],
            'companies' => $companies];
    }
}
