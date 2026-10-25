<?php

namespace App\Console\Commands;

use App\Actions\Relief\RecordUserChange;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ResetStaffPassword extends Command
{
    protected $signature = 'familink:reset-password {email : The email address of the account} {--force : Do not ask for confirmation}';

    protected $description = 'Set a temporary password for an account, for example when nobody who can use the Staff accounts page remembers theirs';

    public function handle(RecordUserChange $record): int
    {
        $user = User::query()->with('role')->where('email', (string) $this->argument('email'))->first();

        if ($user === null) {
            $this->components->error('No account has that email address.');

            return self::FAILURE;
        }

        $role = $user->role->name;

        if (! $this->option('force') && ! $this->confirm("Reset the password for {$user->name} ({$role})? They will be signed out everywhere.", false)) {
            $this->components->warn('Nothing was changed.');

            return self::FAILURE;
        }

        // Easy to read out loud and type: letters and digits only, with no look-alike characters.
        $temporary = collect(str_split(Str::upper(Str::random(40))))
            ->reject(fn (string $character): bool => in_array($character, ['0', 'O', '1', 'I', 'L'], true))
            ->take(12)
            ->implode('');
        $temporary = substr($temporary, 0, 4).'-'.substr($temporary, 4, 4).'-'.substr($temporary, 8, 4);

        DB::transaction(function () use ($user, $temporary, $record): void {
            $user->forceFill([
                'password' => $temporary,
                'must_change_password' => true,
                'remember_token' => Str::random(60),
            ])->save();

            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();

            $record->resetFromServer($user);
        });

        $this->components->info("The password for {$user->email} was reset and the person was signed out everywhere.");
        $this->line("  Temporary password: <options=bold>{$temporary}</>");
        $this->line('  Tell it to them in person or by phone. It is shown only now. They must choose their own at the next login.');

        return self::SUCCESS;
    }
}
