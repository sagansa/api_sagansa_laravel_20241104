<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;

/*
|--------------------------------------------------------------------------
| Api Routes - Generated by Vemto
|--------------------------------------------------------------------------
|
| It is not recommended to edit this file directly. Although you can do so,
| it will generate a conflict on Vemto's next build.
|
*/

Route::name('api.')
    ->prefix('api')
    ->group(function () {
        Route::post('/login', [AuthController::class, 'login'])->name(
            'api.login'
        );

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/users', [UserController::class, 'index'])->name(
                'users.index'
            );

            Route::post('/users', [UserController::class, 'store'])->name(
                'users.store'
            );

            Route::get('/users/{user}', [UserController::class, 'show'])->name(
                'users.show'
            );

            Route::put('/users/{user}', [
                UserController::class,
                'update',
            ])->name('users.update');

            Route::delete('/users/{user}', [
                UserController::class,
                'destroy',
            ])->name('users.destroy');
        });
    });
