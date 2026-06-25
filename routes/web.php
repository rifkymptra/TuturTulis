<?php

use App\Http\Controllers\Admin\TemplateController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', [App\Http\Controllers\HomeController::class, 'index'])->name('home');

// Route Autentikasi Admin
Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login')->middleware('guest');
Route::post('/login', [LoginController::class, 'login'])->middleware('guest');
Route::post('/logout', [LoginController::class, 'logout'])->name('logout')->middleware('auth');

// Halaman Admin (Protected)
Route::middleware(['auth'])->prefix('admin')->group(function () {
    Route::get('/dashboard', [TemplateController::class, 'index'])->name('admin.dashboard');
    Route::post('/templates', [TemplateController::class, 'store'])->name('admin.templates.store');
    Route::put('/templates/{id}/fields', [TemplateController::class, 'updateFields'])->name('admin.templates.update_fields');
    Route::delete('/templates/{id}', [TemplateController::class, 'destroy'])->name('admin.templates.destroy');
});

// Route untuk memproses pembuatan dan pengunduhan dokumen hasil otomatisasi
Route::post('/export', [HomeController::class, 'export'])->name('home.export');
