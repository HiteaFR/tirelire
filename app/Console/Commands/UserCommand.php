<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class UserCommand extends Command
{
    /** Variable lue par --password-env, pour les installations automatisées. */
    public const PASSWORD_ENV = 'FINANCE_USER_PASSWORD';

    public const MIN_PASSWORD_LENGTH = 12;

    protected $signature = 'finance:user
        {email : Login email address}
        {--name= : Display name (default: the part of the address before @)}
        {--password-env : Read the password from the FINANCE_USER_PASSWORD variable instead of asking for it}';

    protected $description = 'Create a user, or reset the password of an existing user';

    public function handle(): int
    {
        $email = mb_strtolower(trim($this->argument('email')));
        if (Validator::make(['email' => $email], ['email' => 'required|email'])->fails()) {
            $this->error(__('Invalid email address: :email', ['email' => $email]));

            return self::FAILURE;
        }

        $password = $this->password();
        if ($password === null) {
            return self::FAILURE;
        }

        $user = User::firstOrNew(['email' => $email]);
        $exists = $user->exists;

        $user->name = $this->option('name') ?: ($user->name ?? Str::before($email, '@'));
        $user->password = $password;
        // Invalide les cookies « se souvenir de moi » émis avec l'ancien mot de passe.
        $user->remember_token = Str::random(60);
        $user->save();

        $this->info($exists ? __('Password of :email reset.', ['email' => $email]) : __('User :email created.', ['email' => $email]));

        return self::SUCCESS;
    }

    /** Mot de passe validé, ou null après avoir affiché l'erreur. */
    private function password(): ?string
    {
        if ($this->option('password-env')) {
            $password = (string) getenv(self::PASSWORD_ENV);
        } elseif ($this->input->isInteractive()) {
            $password = (string) $this->secret(__('Password'));
            if ($password !== (string) $this->secret(__('Password confirmation'))) {
                $this->error(__('The two passwords do not match.'));

                return null;
            }
        } else {
            $this->error(__('Password required: run the command interactively, or pass --password-env with the :variable variable.', ['variable' => self::PASSWORD_ENV]));

            return null;
        }

        $validator = Validator::make(
            ['password' => $password],
            ['password' => ['required', Password::min(self::MIN_PASSWORD_LENGTH)]],
        );

        if ($validator->fails()) {
            $this->error($validator->errors()->first('password'));

            return null;
        }

        return $password;
    }
}
