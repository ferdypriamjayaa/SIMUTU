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
        // The Laporan Observasi menu, and at least one category (INM / IMP) to look at.
        return $user->hasPermission('reports.view') && $user->canAccessAnyArea('view');
    }

    public function view(User $user, Observation $observation): bool
    {
        if (! $user->canAccessCategory($this->categoryOf($observation), 'view')) {
            return false;
        }

        if ($user->canViewAllUnits()) {
            return true;
        }

        return $user->unit_id !== null
            && $observation->unit_id === $user->unit_id;
    }

    /**
     * The Input Observasi menu plus inm.create and/or imp.create; observations
     * are recorded for the user's own active unit. Which indicators may be
     * recorded is narrowed per category in ObservationController.
     */
    public function create(User $user): Response
    {
        if (! $user->hasPermission('observations.create') || ! $user->canAccessAnyArea('create')) {
            return Response::deny('Jabatan Anda tidak dapat menginput observasi.');
        }

        if ($user->activeUnit() === null) {
            return Response::deny('Akun Anda belum terhubung dengan unit yang aktif. Hubungi administrator.');
        }

        return Response::allow();
    }

    /**
     * Holders of the category's edit permission (inm / imp / imp_unit .edit) with
     * access to every unit may correct any such observation; others only those
     * of their own active unit, for indicators still active and assigned to it.
     */
    public function update(User $user, Observation $observation): Response
    {
        return $this->manage($user, $observation, 'mengubah', 'edit');
    }

    /**
     * Deleting follows the same rules as editing, with the category's delete
     * permission (inm.delete / imp.delete / imp_unit.delete).
     */
    public function delete(User $user, Observation $observation): Response
    {
        return $this->manage($user, $observation, 'menghapus', 'delete');
    }

    private function manage(User $user, Observation $observation, string $action, string $ability): Response
    {
        if (! $user->canAccessCategory($this->categoryOf($observation), $ability)) {
            return Response::deny("Jabatan Anda tidak dapat {$action} observasi.");
        }

        if (ObservationAnswers::versionFor($observation) === null) {
            return Response::deny("Observasi ini tidak memiliki formulir yang dapat Anda {$action}.");
        }

        if ($user->canViewAllUnits()) {
            return Response::allow();
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
     * Excel and PDF downloads of one indicator's report: the export permission
     * of its category. Without an indicator: any category's export permission.
     */
    public function export(User $user, ?Indicator $indicator = null): bool
    {
        return $indicator === null
            ? $user->canAccessAnyArea('export')
            : $user->canAccessCategory($indicator->loadMissing('category')->category?->code, 'export');
    }

    private function categoryOf(Observation $observation): ?string
    {
        return $observation->loadMissing('indicator.category')->indicator?->category?->code;
    }
}
