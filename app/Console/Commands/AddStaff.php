<?php

namespace App\Console\Commands;

use App\Enums\StaffRole;
use App\Http\Requests\Api\Auth\SendCodeRequest;
use App\Models\Staff;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Staff can't sign up, so this is how Studio accounts are made. Running it again for the same
 * email updates the name and role.
 */
#[Signature('studio:add-staff {email} {name} {--role=editor : owner or editor}')]
#[Description('Add a Studio staff member, or update an existing one')]
class AddStaff extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = SendCodeRequest::normalizeEmail($this->argument('email'));
        $role = StaffRole::tryFrom((string) $this->option('role'));

        $validator = Validator::make(
            ['email' => $email, 'name' => $this->argument('name')],
            ['email' => ['required', 'email', 'max:255'], 'name' => ['required', 'string', 'max:255']],
        );

        if ($validator->fails() || $role === null) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            if ($role === null) {
                $this->components->error('The role must be owner or editor.');
            }

            return self::FAILURE;
        }

        $staff = Staff::updateOrCreate(['email' => $email], [
            'name' => $this->argument('name'),
            'role' => $role,
        ]);

        $this->components->info(sprintf(
            '%s %s (%s) as %s.',
            $staff->wasRecentlyCreated ? 'Added' : 'Updated',
            $staff->name,
            $staff->email,
            $staff->role->label(),
        ));

        return self::SUCCESS;
    }
}
