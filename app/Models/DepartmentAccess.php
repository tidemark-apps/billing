<?php

namespace App\Models;

/**
 * Which departments a staff member works in. A staff member in no department sees only the tickets assigned
 * to them; admins see everything regardless.
 */
class DepartmentAccess
{
    /** @return array<int, int> */
    public static function idsFor(User $user): array
    {
        return $user->departments()->pluck('ticket_departments.id')->map(fn ($id) => (int) $id)->all();
    }
}
