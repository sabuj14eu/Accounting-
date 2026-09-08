<?php

declare(strict_types=1);

namespace Poland\Laravel\Auth;

use Filament\Auth\Events\Registered;
use Illuminate\Support\Str;

/**
 * Every customer who creates an account gets their OWN team.
 *
 * Upstream's registration action attached each new user to `Team::first()`:
 * every self-registered customer joined the same team and could see the same
 * books. A personal team per customer is what the ERP's own multi-tenancy
 * expects (current_team_id, team-scoped resources, the first-use setup
 * wizard), and it is the only arrangement under which one customer's data is
 * invisible to the next.
 *
 * No role is assigned. A customer is a customer; administrator access is
 * granted deliberately by an operator (bin/grant-admin.sh), never at sign-up.
 */
final class PersonalTeamOnRegistration
{
    public function handle(Registered $event): void
    {
        $user = $event->getUser();

        if (! method_exists($user, 'ownedTeams')) {
            return;
        }

        if ($user->ownedTeams()->exists()) {
            return;
        }

        $name = trim((string) ($user->name ?? ''));
        $teamName = $name === '' ? 'Moja firma' : Str::limit($name, 40, '').' — firma';

        // ownedTeams() is a hasMany on user_id: the relation sets the foreign
        // key itself, which is why this works although Team does not list
        // user_id as fillable.
        $team = $user->ownedTeams()->create([
            'name' => $teamName,
            'personal_team' => true,
        ]);

        $user->forceFill(['current_team_id' => $team->getKey()])->save();
    }
}
