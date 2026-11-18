<?php

use App\Http\Controllers\FormBuilderController;
use App\Http\Controllers\HrisUnitMappingController;
use App\Http\Controllers\IndicatorController;
use App\Http\Controllers\IndicatorUnitMappingController;
use App\Http\Controllers\JotformImportController;
use App\Http\Controllers\ObservationController;
use App\Http\Controllers\ObservationLookupController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicSurveyController;
use App\Http\Controllers\SimutuController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\UserController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Dashboard SIMUTU
|--------------------------------------------------------------------------
*/

Route::redirect('/', '/dashboard')
    ->name('simutu.index');

Route::redirect('/simutu', '/dashboard')
    ->name('simutu.dashboard');

/*
|--------------------------------------------------------------------------
| Survei Kepuasan Pasien (publik, tanpa login)
|--------------------------------------------------------------------------
| Hanya formulir survei ini yang terbuka untuk umum (App\Support\PublicSurvey).
| CSRF berlaku seperti halaman lain; pengiriman dibatasi per IP.
*/

Route::get('/survei-kepuasan-pasien', [PublicSurveyController::class, 'show'])
    ->name('survey.show');

Route::post('/survei-kepuasan-pasien', [PublicSurveyController::class, 'store'])
    ->middleware('throttle:public-survey')
    ->name('survey.store');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [SimutuController::class, 'index'])
        ->name('dashboard');

    /*
    |--------------------------------------------------------------------------
    | Transaksi Observasi
    |--------------------------------------------------------------------------
    | Akses per peran diatur oleh ObservationPolicy.
    */

    Route::get('/observations', [ObservationController::class, 'index'])
        ->name('observations.index');

    Route::get('/observations/create', [ObservationController::class, 'create'])
        ->name('observations.create');

    Route::get('/observations/indicator/{indicator}', [ObservationController::class, 'create'])
        ->whereNumber('indicator')
        ->name('observations.create.indicator');

    // Searchable dropdowns of the observation form (employees, doctors).
    Route::get('/observations/lookup/{source}', ObservationLookupController::class)
        ->whereIn('source', ['employees', 'doctors'])
        ->middleware('throttle:120,1')
        ->name('observations.lookup');

    Route::post('/observations', [ObservationController::class, 'store'])
        ->name('observations.store');

    Route::get('/observations/export/{format}', [ObservationController::class, 'export'])
        ->whereIn('format', ['excel', 'pdf'])
        ->name('observations.export');

    Route::get('/observations/{observation}', [ObservationController::class, 'show'])
        ->whereNumber('observation')
        ->name('observations.show');

    Route::get('/observations/{observation}/edit', [ObservationController::class, 'edit'])
        ->whereNumber('observation')
        ->name('observations.edit');

    Route::put('/observations/{observation}', [ObservationController::class, 'update'])
        ->whereNumber('observation')
        ->name('observations.update');

    Route::delete('/observations/{observation}', [ObservationController::class, 'destroy'])
        ->whereNumber('observation')
        ->name('observations.destroy');

    /*
    |--------------------------------------------------------------------------
    | Master Indikator
    |--------------------------------------------------------------------------
    */

    Route::get('/indicators/{category}', [IndicatorController::class, 'show'])
        ->middleware('role:'.User::ROLE_ADMIN.','.User::ROLE_PIC_MUTU)
        ->where('category', 'inm|imp-rs|imp-unit')
        ->name('indicators.show');

    Route::middleware('role:'.User::ROLE_ADMIN)->group(function () {
        Route::get('/indicators/{category}/create', [IndicatorController::class, 'create'])
            ->where('category', 'imp-rs|imp-unit')
            ->name('indicators.create');

        Route::post('/indicators/{category}', [IndicatorController::class, 'store'])
            ->where('category', 'imp-rs|imp-unit')
            ->name('indicators.store');

        Route::get('/indicators/{category}/{indicator}/edit', [IndicatorController::class, 'edit'])
            ->where('category', 'imp-rs|imp-unit')
            ->name('indicators.edit');

        Route::put('/indicators/{category}/{indicator}', [IndicatorController::class, 'update'])
            ->where('category', 'imp-rs|imp-unit')
            ->name('indicators.update');

        Route::patch('/indicators/{category}/{indicator}/status', [IndicatorController::class, 'toggleStatus'])
            ->where('category', 'imp-rs|imp-unit')
            ->name('indicators.toggle-status');

        Route::delete('/indicators/{category}/{indicator}', [IndicatorController::class, 'destroy'])
            ->where('category', 'imp-rs|imp-unit')
            ->name('indicators.destroy');

        /*
        |--------------------------------------------------------------------------
        | Form Builder Indikator (Admin)
        |--------------------------------------------------------------------------
        */

        Route::prefix('/form-builder/{indicator}')
            ->whereNumber(['indicator', 'version'])
            ->name('form-builder.')
            ->group(function () {
                Route::get('/', [FormBuilderController::class, 'show'])->name('show');
                Route::post('/draft', [FormBuilderController::class, 'storeDraft'])->name('draft.store');
                Route::get('/versions/{version}', [FormBuilderController::class, 'preview'])->name('preview');
                Route::get('/versions/{version}/edit', [FormBuilderController::class, 'edit'])->name('edit');
                Route::put('/versions/{version}', [FormBuilderController::class, 'update'])->name('update');
                Route::post('/versions/{version}/publish', [FormBuilderController::class, 'publish'])->name('publish');
                Route::delete('/versions/{version}', [FormBuilderController::class, 'destroy'])->name('destroy');
            });

        /*
        |--------------------------------------------------------------------------
        | Mapping Indikator & Unit (Admin)
        |--------------------------------------------------------------------------
        */

        Route::prefix('/indicator-unit-mappings')
            ->whereNumber(['indicator', 'unit'])
            ->name('indicator-unit-mappings.')
            ->group(function () {
                Route::get('/', [IndicatorUnitMappingController::class, 'index'])->name('index');
                Route::get('/{indicator}/edit', [IndicatorUnitMappingController::class, 'edit'])->name('edit');
                Route::put('/{indicator}', [IndicatorUnitMappingController::class, 'update'])->name('update');
                Route::delete('/{indicator}/units/{unit}', [IndicatorUnitMappingController::class, 'destroy'])->name('destroy');
            });

        /*
        |--------------------------------------------------------------------------
        | Mapping Departemen HRIS ke Unit (Admin)
        |--------------------------------------------------------------------------
        */

        Route::prefix('/hris-mappings')->name('hris-mappings.')->group(function () {
            Route::get('/', [HrisUnitMappingController::class, 'index'])->name('index');
            Route::post('/', [HrisUnitMappingController::class, 'store'])->name('store');
            Route::post('/refresh', [HrisUnitMappingController::class, 'refresh'])->name('refresh');
            Route::post('/refresh-doctors', [HrisUnitMappingController::class, 'refreshDoctors'])->name('refresh-doctors');
            Route::delete('/{mapping}', [HrisUnitMappingController::class, 'destroy'])->whereNumber('mapping')->name('destroy');
        });

        /*
        |--------------------------------------------------------------------------
        | Impor Definisi Formulir Jotform (Admin)
        |--------------------------------------------------------------------------
        */

        Route::prefix('/jotform-import')->name('jotform-import.')->group(function () {
            Route::get('/', [JotformImportController::class, 'index'])->name('index');
            Route::post('/import-all', [JotformImportController::class, 'importAll'])->name('import-all');
            Route::post('/{form}/mapping', [JotformImportController::class, 'map'])->whereNumber('form')->name('map');
            Route::post('/{form}/import', [JotformImportController::class, 'import'])->whereNumber('form')->name('import');
        });

        /*
        |--------------------------------------------------------------------------
        | Master Unit
        |--------------------------------------------------------------------------
        */

        Route::get('/units', [UnitController::class, 'index'])
            ->name('units.index');

        Route::post('/units', [UnitController::class, 'store'])
            ->name('units.store');

        Route::put('/units/{unit}', [UnitController::class, 'update'])
            ->name('units.update');

        Route::patch('/units/{unit}/status', [UnitController::class, 'toggleStatus'])
            ->name('units.toggle-status');

        Route::delete('/units/{unit}', [UnitController::class, 'destroy'])
            ->name('units.destroy');

        /*
        |--------------------------------------------------------------------------
        | Master Pengguna
        |--------------------------------------------------------------------------
        */

        Route::get('/users', [UserController::class, 'index'])
            ->name('users.index');

        Route::get('/users/create', [UserController::class, 'create'])
            ->name('users.create');

        Route::post('/users', [UserController::class, 'store'])
            ->name('users.store');

        Route::get('/users/hris-employees/{employee}', [UserController::class, 'hrisEmployee'])
            ->where('employee', '[0-9a-f]{32}')
            ->name('users.hris-employee');

        Route::get('/users/{user}/edit', [UserController::class, 'edit'])
            ->name('users.edit');

        Route::put('/users/{user}', [UserController::class, 'update'])
            ->name('users.update');

        Route::patch('/users/{user}/status', [UserController::class, 'toggleStatus'])
            ->name('users.toggle-status');

        Route::delete('/users/{user}', [UserController::class, 'destroy'])
            ->name('users.destroy');
    });

    /*
    |--------------------------------------------------------------------------
    | Profil Pengguna Breeze
    |--------------------------------------------------------------------------
    */

    Route::get('/profile', [ProfileController::class, 'edit'])
        ->name('profile.edit');

    Route::patch('/profile', [ProfileController::class, 'update'])
        ->name('profile.update');

    Route::delete('/profile', [ProfileController::class, 'destroy'])
        ->name('profile.destroy');
});

/*
|--------------------------------------------------------------------------
| Authentication Breeze
|--------------------------------------------------------------------------
*/

require __DIR__ . '/auth.php';
