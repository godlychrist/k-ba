<?php

namespace App\Http\Controllers;

use App\Models\BalanceAdjustment;
use App\Models\BankStatement;
use App\Models\MailAccount;
use App\Models\User;
use App\Services\BalanceProjector;
use App\Services\MailProvider;
use App\Services\ReceiptParser;
use App\Services\StatementImporter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExpenseController extends Controller
{
    public function session(Request $request)
    {
        $guard = Auth::guard();
        if ($guard->check() && ! $request->hasCookie($guard->getRecallerName())) {
            $guard->setRememberDuration(60 * 24 * 30);
            $guard->login($request->user(), true);
        }

        return response()->json(['authenticated' => $guard->check(), 'csrf' => csrf_token()])->header('Cache-Control', 'no-store');
    }

    public function connect(Request $request, string $provider)
    {
        abort_unless(in_array($provider, ['gmail', 'outlook']), 404);
        $input = $request->validate(['email' => 'required|email|max:254']);
        $config = config("expenses.$provider");
        abort_unless($config['client_id'] && $config['client_secret'], 503, 'Falta configurar las credenciales del proveedor de correo.');
        $state = Str::random(64);
        $verifier = Str::random(96);
        $request->session()->put('mail_oauth', ['state' => $state, 'provider' => $provider, 'verifier' => $verifier, 'email' => $input['email']]);

        return redirect()->away($config['authorize'].'?'.http_build_query([
            'client_id' => $config['client_id'], 'redirect_uri' => $config['redirect_uri'],
            'response_type' => 'code', 'scope' => $config['scope'], 'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256', 'access_type' => 'offline', 'prompt' => 'consent', 'login_hint' => $input['email'],
        ]));
    }

    public function callback(Request $request, string $provider, MailProvider $mail)
    {
        $oauth = $request->session()->pull('mail_oauth');
        abort_unless($oauth && $oauth['provider'] === $provider && is_string($request->state) && hash_equals($oauth['state'], $request->state), 403, 'Autorización inválida o vencida.');
        if ($request->has('error')) {
            return redirect()->away(config('expenses.frontend_url').'/?connection=cancelled');
        }
        $request->validate(['code' => 'required|string']);
        $tokens = $mail->exchange($provider, ['grant_type' => 'authorization_code', 'code' => $request->code, 'code_verifier' => $oauth['verifier']]);
        $email = $mail->profile($provider, $tokens['access_token']);
        abort_unless(strcasecmp($email, $oauth['email']) === 0, 422, 'Autoriza la misma dirección de correo que escribiste.');
        $existing = MailAccount::where('provider', $provider)->where('email', $email)->first();
        if (Auth::check()) {
            abort_if($existing && $existing->user_id !== Auth::id(), 409, 'Este correo pertenece a otra sesión.');
            $user = $request->user();
        } elseif ($existing) {
            $user = User::findOrFail($existing->user_id);
        } else {
            $user = User::firstOrCreate(['email' => $email], ['name' => Str::before($email, '@'), 'password' => Str::random(64)]);
        }
        $account = MailAccount::firstOrNew(['provider' => $provider, 'email' => $email]);
        $account->fill(['user_id' => $user->id, 'access_token' => $tokens['access_token'], 'refresh_token' => $tokens['refresh_token'] ?? $account->refresh_token, 'expires_at' => now()->addSeconds($tokens['expires_in'])])->save();
        Auth::guard()->setRememberDuration(60 * 24 * 30);
        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->away(config('expenses.frontend_url'));
    }

    public function sync(Request $request, MailProvider $mail, ReceiptParser $parser)
    {
        $lock = Cache::lock('mail-sync-'.$request->user()->id, 600);
        abort_unless($lock->get(), 409, 'Ya hay una actualización en curso.');
        try {
            if ($request->boolean('rescan')) {
                MailAccount::where('user_id', $request->user()->id)->update(['sync_cursor' => null, 'sync_started_at' => null, 'last_synced_at' => null]);
            }
            $more = false;
            foreach (MailAccount::where('user_id', $request->user()->id)->get() as $account) {
                if ($account->sync_since !== config('expenses.import_since')) {
                    $account->update(['sync_since' => config('expenses.import_since'), 'sync_cursor' => null, 'sync_started_at' => null, 'last_synced_at' => null]);
                }
                if (! $account->sync_started_at) {
                    $account->update(['sync_started_at' => now()]);
                }
                $started = $account->sync_started_at;
                $page = $mail->page($account);
                DB::transaction(function () use ($page, $account, $parser, $started) {
                    foreach ($page['messages'] as $message) {
                        $existing = DB::table('mail_records')->where('mail_account_id', $account->id)->where('message_id', $message['id'])->first();
                        $parsed = $parser->parse($message['subject']."\n".$message['body'], $message['received_at']);
                        $attributes = ['mail_account_id' => $account->id, 'message_id' => $message['id'], 'subject' => mb_substr($message['subject'], 0, 255), 'received_at' => $message['received_at'], 'status' => $parsed['transaction'] || $parsed['balance'] || ($existing && $existing->status === 'parsed') ? 'parsed' : 'unrecognized', 'updated_at' => now()];
                        if ($existing) {
                            $record = $existing->id;
                            DB::table('mail_records')->where('id', $record)->update($attributes);
                        } else {
                            $record = DB::table('mail_records')->insertGetId(array_merge($attributes, ['created_at' => now()]));
                        }
                        foreach (['transaction' => 'transactions', 'balance' => 'balance_snapshots'] as $key => $table) {
                            if ($parsed[$key]) {
                                $row = DB::table($table)->where('mail_record_id', $record)->first();
                                $values = array_merge($parsed[$key], ['updated_at' => now()]);
                                if ($row) {
                                    $query = DB::table($table)->where('id', $row->id);
                                    if ($table === 'transactions') {
                                        $query->where('user_edited', false);
                                    }
                                    $query->update($values);
                                } else {
                                    DB::table($table)->insert(array_merge($values, ['mail_record_id' => $record, 'created_at' => now()]));
                                }
                            }
                        }
                        app(StatementImporter::class)->import($record, $message['attachments'] ?? []);
                    }
                    $account->update(['sync_cursor' => $page['cursor'], 'sync_started_at' => $page['cursor'] ? $started : null, 'last_synced_at' => $page['cursor'] ? $account->last_synced_at : $started]);
                });
                $more = $more || (bool) $page['cursor'];
            }

            return response()->json(['has_more' => $more]);
        } catch (RequestException $exception) {
            if ($exception->response->status() === 401) {
                return response()->json(['message' => 'El proveedor de correo requiere una nueva autorización. Vuelve a conectar tu correo.', 'reconnect' => true], 409);
            }
            throw $exception;
        } finally {
            $lock->release();
        }
    }

    public function dashboard(Request $request)
    {
        $since = CarbonImmutable::parse(config('expenses.import_since'), 'America/Costa_Rica')->utc()->toDateTimeString();
        $ids = MailAccount::where('user_id', $request->user()->id)->pluck('id');
        $transactions = DB::table('transactions')->leftJoin('mail_records', 'mail_records.id', '=', 'transactions.mail_record_id')->where(function ($query) use ($ids, $since, $request) {
            $query->where('transactions.user_id', $request->user()->id)->orWhere(function ($emails) use ($ids, $since) {
                $emails->whereIn('mail_records.mail_account_id', $ids)->where('mail_records.received_at', '>=', $since);
            });
        })->where('occurred_at', '>=', $since)->orderByDesc('occurred_at')->select('transactions.*', 'mail_records.subject')->get();
        $balances = DB::table('balance_snapshots')->join('mail_records', 'mail_records.id', '=', 'balance_snapshots.mail_record_id')->whereIn('mail_records.mail_account_id', $ids)->where('mail_records.received_at', '>=', $since)->orderByDesc('observed_at')->select('balance_snapshots.*', 'mail_records.mail_account_id')->get()->unique(fn ($b) => $b->mail_account_id.':'.$b->account_reference.':'.$b->currency)->values();

        $records = DB::table('mail_records')->whereIn('mail_account_id', $ids)->where('received_at', '>=', $since)->select('id');
        $statements = BankStatement::whereIn('mail_record_id', $records)->where('status', '!=', 'reference')->orderByRaw('closing_date DESC NULLS LAST')->orderByDesc('id')->get();
        $adjustments = BalanceAdjustment::where('user_id', $request->user()->id)->get();
        $links = DB::table('debit_card_links')->where('user_id', $request->user()->id)->get(['card_reference', 'account_reference', 'currency']);
        $projections = app(BalanceProjector::class)->project($statements, $adjustments, $transactions, $links);

        return response()->json(['debit_card_links' => $links, 'import_since' => config('expenses.import_since'), 'statements' => $statements, 'projected_balances' => $projections, 'user' => ['id' => $request->user()->id, 'email' => $request->user()->email], 'accounts' => MailAccount::whereIn('id', $ids)->get(['id', 'email', 'provider', 'last_synced_at', 'sync_started_at']), 'transactions' => $transactions, 'balances' => $balances, 'scanned' => DB::table('mail_records')->whereIn('mail_account_id', $ids)->where('received_at', '>=', $since)->count(), 'unrecognized' => DB::table('mail_records')->whereIn('mail_account_id', $ids)->where('received_at', '>=', $since)->where('status', 'unrecognized')->count()])->header('Cache-Control', 'no-store');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }
}
