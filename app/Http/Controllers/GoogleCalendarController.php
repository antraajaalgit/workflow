<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GoogleCalendarController extends Controller
{
    public function connect(Request $request): RedirectResponse
    {
        $this->userId($request);
        abort_unless(config('services.google.client_id') && config('services.google.client_secret'), 500, 'Google Calendar credentials are not configured.');
        $state = Str::random(40);
        $request->session()->put('google_oauth_state', $state);

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => config('services.google.redirect'),
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/calendar.readonly',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]));
    }

    public function callback(Request $request): RedirectResponse
    {
        $userId = $this->userId($request);
        abort_unless($request->filled('state') && hash_equals((string) $request->session()->pull('google_oauth_state'), (string) $request->state), 419, 'Invalid Google authorization state.');
        if ($request->filled('error')) return redirect('/?google=denied');

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $request->string('code')->toString(),
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => config('services.google.redirect'),
            'grant_type' => 'authorization_code',
        ])->throw()->json();

        DB::table('google_calendar_tokens')->updateOrInsert(['user_id' => $userId], [
            'access_token' => Crypt::encryptString($response['access_token']),
            'refresh_token' => isset($response['refresh_token']) ? Crypt::encryptString($response['refresh_token']) : DB::table('google_calendar_tokens')->where('user_id', $userId)->value('refresh_token'),
            'expires_at' => now()->addSeconds((int) ($response['expires_in'] ?? 3600)),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return redirect('/?google=connected');
    }

    public function status(Request $request): JsonResponse
    {
        $userId = $this->userId($request);
        return response()->json(['connected' => DB::table('google_calendar_tokens')->where('user_id', $userId)->exists()]);
    }

    public function events(Request $request): JsonResponse
    {
        $userId = $this->userId($request);
        $input = $request->validate(['timeMin' => ['required','date'], 'timeMax' => ['required','date','after:timeMin']]);
        $token = DB::table('google_calendar_tokens')->where('user_id', $userId)->first();
        if (!$token) return response()->json(['connected' => false, 'events' => []]);

        $accessToken = $this->accessToken($token);
        $response = Http::withToken($accessToken)->get('https://www.googleapis.com/calendar/v3/calendars/primary/events', [
            'timeMin' => $input['timeMin'], 'timeMax' => $input['timeMax'], 'singleEvents' => 'true',
            'orderBy' => 'startTime', 'maxResults' => 250,
        ])->throw()->json();

        $events = collect($response['items'] ?? [])->filter(fn($event) => ($event['status'] ?? '') !== 'cancelled')->map(fn($event) => [
            'id' => $event['id'], 'title' => $event['summary'] ?? 'Busy',
            'start' => $event['start']['dateTime'] ?? $event['start']['date'] ?? null,
            'end' => $event['end']['dateTime'] ?? $event['end']['date'] ?? null,
            'htmlLink' => $event['htmlLink'] ?? null,
        ])->values();

        return response()->json(['connected' => true, 'events' => $events]);
    }

    public function disconnect(Request $request): JsonResponse
    {
        DB::table('google_calendar_tokens')->where('user_id', $this->userId($request))->delete();
        return response()->json(['connected' => false]);
    }

    private function accessToken(object $token): string
    {
        if (!$token->expires_at || now()->lt(now()->parse($token->expires_at)->subMinute())) return Crypt::decryptString($token->access_token);
        abort_unless($token->refresh_token, 401, 'Google Calendar authorization expired. Please reconnect.');
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google.client_id'), 'client_secret' => config('services.google.client_secret'),
            'refresh_token' => Crypt::decryptString($token->refresh_token), 'grant_type' => 'refresh_token',
        ])->throw()->json();
        DB::table('google_calendar_tokens')->where('user_id', $token->user_id)->update([
            'access_token' => Crypt::encryptString($response['access_token']),
            'expires_at' => now()->addSeconds((int) ($response['expires_in'] ?? 3600)), 'updated_at' => now(),
        ]);
        return $response['access_token'];
    }

    private function userId(Request $request): string
    {
        $id = $request->session()->get('nagare_user_id');
        $user = $id ? DB::table('users')->where('id', $id)->first() : null;
        abort_unless($user, 401, 'Please sign in.');
        abort_if($user->role === 'client', 403, 'Google Calendar is available to admins and team members.');
        return $id;
    }
}
