<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CollectedItemController;
use App\Http\Controllers\CollectionRunController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WatchSourceController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(Auth::check() ? 'dashboard' : 'login'));
Route::get('/index', fn () => redirect()->route(Auth::check() ? 'dashboard' : 'login'));

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// ログイン済みユーザーが閲覧・操作できる通常機能。
Route::middleware('auth')->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/items', [CollectedItemController::class, 'index'])->name('items.index');
    Route::get('/items/{item}', [CollectedItemController::class, 'show'])->name('items.show');
    Route::get('/items/{item}/pdf', [CollectedItemController::class, 'downloadPdf'])->name('items.pdf');
    Route::post('/items/{item}/read', [CollectedItemController::class, 'markRead'])->name('items.read');
});

// 収集設定やユーザー管理は管理者だけに絞る。
Route::middleware(['auth', 'admin'])->group(function (): void {
    Route::resource('users', UserController::class)->except(['show', 'destroy']);
    Route::resource('companies', CompanyController::class)->except(['show', 'destroy']);
    Route::resource('watch-sources', WatchSourceController::class)
        ->parameters(['watch-sources' => 'watchSource'])
        ->except(['show', 'destroy']);
    Route::post('/watch-sources/{watchSource}/test', [WatchSourceController::class, 'test'])->name('watch-sources.test');
    Route::post('/watch-sources/{watchSource}/collect', [WatchSourceController::class, 'collect'])->name('watch-sources.collect');
    Route::get('/collection-runs', [CollectionRunController::class, 'index'])->name('collection-runs.index');
    Route::get('/collection-runs/{collectionRun}', [CollectionRunController::class, 'show'])->name('collection-runs.show');
});
