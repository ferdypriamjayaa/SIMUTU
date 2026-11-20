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
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SimutuController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\UserController;
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
        ->middleware('can:dashboard.view')
        ->name('dashboard');

    /*
    |--------------------------------------------------------------------------
    | Transaksi Observasi
    |--------------------------------------------------------------------------
    | Menu: observations.create / reports.view. Kategori: inm.* / imp.* (IMP RS) / imp_unit.*
    | (ObservationPolicy, ReportFilters, Observation::visibleTo).
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
        ->middleware('can:indicators.view')
        ->where('category', 'inm|imp-rs|imp-unit')
        ->name('indicators.show');

    // Each route below requires its own permission (App\Support\Permissions).
    Route::group([], function () {
        Route::get('/indicators/{category}/create', [IndicatorController::class, 'create'])
            ->where('category', 'imp-rs|imp-unit')
            ->middleware('can:indicators.create')
            ->name('indicators.create');

        Route::post('/indicators/{category}', [IndicatorController::class, 'store'])
            ->where('category', 'imp-rs|imp-unit')
            ->middleware('can:indicators.create')
            ->name('indicators.store');

        Route::get('/indicators/{category}/{indicator}/edit', [IndicatorController::class, 'edit'])
            ->where('category', 'imp-rs|imp-unit')
            ->middleware('can:indicators.edit')
            ->name('indicators.edit');

        Route::put('/indicators/{category}/{indicator}', [IndicatorController::class, 'update'])
            ->where('category', 'imp-rs|imp-unit')
            ->middleware('can:indicators.edit')
            ->name('indicators.update');

        Route::patch('/indicators/{category}/{indicator}/status', [IndicatorController::class, 'toggleStatus'])
            ->where('category', 'imp-rs|imp-unit')
            ->middleware('can:indicators.edit')
            ->name('indicators.toggle-status');

        Route::delete('/indicators/{category}/{indicator}', [IndicatorController::class, 'destroy'])
            ->where('category', 'imp-rs|imp-unit')
            ->middleware('can:indicators.delete')
            ->name('indicators.destroy');

        /*
        |--------------------------------------------------------------------------
        | Form Builder Indikator (Admin)
        |--------------------------------------------------------------------------
        */

        Route::prefix('/form-builder/{indicator}')
            ->middleware('can:indicators.edit')
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
            ->middleware('can:indicator_mappings.manage')
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
            Route::get('/', [HrisUnitMappingController::class, 'index'])->middleware('can:hris_mappings.view')->name('index');
            Route::post('/', [HrisUnitMappingController::class, 'store'])->middleware('can:hris_mappings.manage')->name('store');
            Route::post('/refresh', [HrisUnitMappingController::class, 'refresh'])->middleware('can:hris_mappings.sync')->name('refresh');
            Route::post('/refresh-doctors', [HrisUnitMappingController::class, 'refreshDoctors'])->middleware('can:hris_mappings.sync')->name('refresh-doctors');
            Route::delete('/{mapping}', [HrisUnitMappingController::class, 'destroy'])->middleware('can:hris_mappings.manage')->whereNumber('mapping')->name('destroy');
        });

        /*
        |--------------------------------------------------------------------------
        | Impor Definisi Formulir Jotform (Admin)
        |--------------------------------------------------------------------------
        */

        Route::prefix('/jotform-import')->name('jotform-import.')->group(function () {
            Route::get('/', [JotformImportController::class, 'index'])->middleware('can:jotform.view')->name('index');
            Route::post('/import-all', [JotformImportController::class, 'importAll'])->middleware('can:jotform.import')->name('import-all');
            Route::post('/{form}/mapping', [JotformImportController::class, 'map'])->middleware('can:jotform.manage')->whereNumber('form')->name('map');
            Route::post('/{form}/import', [JotformImportController::class, 'import'])->middleware('can:jotform.import')->whereNumber('form')->name('import');
        });

        /*
        |--------------------------------------------------------------------------
        | Master Unit
        |--------------------------------------------------------------------------
        */

        Route::get('/units', [UnitController::class, 'index'])
            ->middleware('can:units.view')
            ->name('units.index');

        Route::post('/units', [UnitController::class, 'store'])
            ->middleware('can:units.create')
            ->name('units.store');

        Route::put('/units/{unit}', [UnitController::class, 'update'])
            ->middleware('can:units.edit')
            ->name('units.update');

        Route::patch('/units/{unit}/status', [UnitController::class, 'toggleStatus'])
            ->middleware('can:units.edit')
            ->name('units.toggle-status');

        Route::delete('/units/{unit}', [UnitController::class, 'destroy'])
            ->middleware('can:units.delete')
            ->name('units.destroy');

        /*
        |--------------------------------------------------------------------------
        | Master Pengguna
        |--------------------------------------------------------------------------
        */

        Route::get('/users', [UserController::class, 'index'])
            ->middleware('can:users.view')
            ->name('users.index');

        Route::get('/users/create', [UserController::class, 'create'])
            ->middleware('can:users.create')
            ->name('users.create');

        Route::post('/users', [UserController::class, 'store'])
            ->middleware('can:users.create')
            ->name('users.store');

        Route::get('/users/hris-employees/{employee}', [UserController::class, 'hrisEmployee'])
            ->where('employee', '[0-9a-f]{32}')
            ->middleware('can:users.create')
            ->name('users.hris-employee');

        Route::get('/users/{user}/edit', [UserController::class, 'edit'])
            ->middleware('can:users.edit')
            ->name('users.edit');

        Route::put('/users/{user}', [UserController::class, 'update'])
            ->middleware('can:users.edit')
            ->name('users.update');

        Route::patch('/users/{user}/status', [UserController::class, 'toggleStatus'])
            ->middleware('can:users.edit')
            ->name('users.toggle-status');

        Route::delete('/users/{user}', [UserController::class, 'destroy'])
            ->middleware('can:users.delete')
            ->name('users.destroy');

        /*
        |--------------------------------------------------------------------------
        | Master Jabatan (Manajemen Akses)
        |--------------------------------------------------------------------------
        */

        Route::prefix('/roles')->whereNumber('role')->name('roles.')->group(function () {
            Route::get('/', [RoleController::class, 'index'])->middleware('can:roles.view')->name('index');
            Route::get('/create', [RoleController::class, 'create'])->middleware('can:roles.manage')->name('create');
            Route::post('/', [RoleController::class, 'store'])->middleware('can:roles.manage')->name('store');
            Route::get('/{role}', [RoleController::class, 'show'])->middleware('can:roles.view')->name('show');
            Route::get('/{role}/edit', [RoleController::class, 'edit'])->middleware('can:roles.manage')->name('edit');
            Route::put('/{role}', [RoleController::class, 'update'])->middleware('can:roles.manage')->name('update');
            Route::patch('/{role}/status', [RoleController::class, 'toggleStatus'])->middleware('can:roles.manage')->name('toggle-status');
            Route::delete('/{role}', [RoleController::class, 'destroy'])->middleware('can:roles.manage')->name('destroy');
        });
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
