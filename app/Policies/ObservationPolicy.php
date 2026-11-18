<?php

namespace App\Policies;

use App\Models\Indicator;
use App\Models\Observation;
use App\Models\User;
use App\Support\ObservationAnswers;
use Illuminate\Auth\Access\Response;

class ObservationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(User::ROLE_ADMIN, User::ROLE_PIC_MUTU, User::ROLE_PIC_UNIT);
    }

    public function view(User $user, Observation $observation): bool
    {
        if ($user->canViewAllUnits()) {
            return true;
        }

        return $user->isPicUnit()
            && $user->unit_id !== null
            && $observation->unit_id === $user->unit_id;
    }

    /**
     * PIC Unit (and Admin) record observations for their own active unit.
     */
    public function create(User $user): Response
    {
        if (! $user->hasRole(User::ROLE_ADMIN, User::ROLE_PIC_UNIT)) {
            return Response::deny('Jabatan Anda tidak dapat menginput observasi.');
        }

        if ($user->activeUnit() === null) {
            return Response::deny('Akun Anda belum terhubung dengan unit yang aktif. Hubungi administrator.');
        }

        return Response::allow();
    }

    /**
     * Admin may correct any INM observation. PIC Unit may correct observations
     * of their own active unit, for indicators still active and assigned to it.
     * PIC Mutu reviews data and does not record or change it.
     */
    public function update(User $user, Observation $observation): Response
    {
        return $this->manage($user, $observation, 'mengubah');
    }

    /**
     * Deleting follows the same rules as editing.
     */
    public function delete(User $user, Observation $observation): Response
    {
        return $this->manage($user, $observation, 'menghapus');
    }

    private function manage(User $user, Observation $observation, string $action): Response
    {
        if (ObservationAnswers::versionFor($observation) === null) {
            return Response::deny("Observasi ini tidak memiliki formulir yang dapat Anda {$action}.");
        }

        if ($user->isAdmin()) {
            return Response::allow();
        }

        if (! $user->isPicUnit()) {
            return Response::deny("Jabatan Anda tidak dapat {$action} observasi.");
        }

        $unit = $user->activeUnit();

        if ($unit === null || $observation->unit_id !== $unit->id) {
            return Response::deny("Anda hanya dapat {$action} observasi milik unit Anda.");
        }

        $assigned = Indicator::observable()
            ->assignedTo($unit->id)
            ->whereKey($observation->indicator_id)
            ->exists();

        return $assigned
            ? Response::allow()
            : Response::deny('Indikator ini tidak aktif atau tidak lagi ditetapkan untuk unit Anda.');
    }

    /**
     * Excel/PDF reports are for Admin and PIC Mutu.
     */
    public function export(User $user): bool
    {
        return $user->canViewAllUnits();
    }
}
