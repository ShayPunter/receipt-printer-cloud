<?php

use App\Http\Controllers\RecurringTaskController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

// Recurring Tasks routes
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/recurring-tasks', [RecurringTaskController::class, 'index'])->name('recurring-tasks.index');
    Route::get('/recurring-tasks/create', [RecurringTaskController::class, 'create'])->name('recurring-tasks.create');
    Route::post('/recurring-tasks', [RecurringTaskController::class, 'store'])->name('recurring-tasks.store');
    Route::get('/recurring-tasks/{recurringTask}/edit', [RecurringTaskController::class, 'edit'])->name('recurring-tasks.edit');
    Route::patch('/recurring-tasks/{recurringTask}', [RecurringTaskController::class, 'update'])->name('recurring-tasks.update');
    Route::delete('/recurring-tasks/{recurringTask}', [RecurringTaskController::class, 'destroy'])->name('recurring-tasks.destroy');
    Route::post('/recurring-tasks/{recurringTask}/toggle', [RecurringTaskController::class, 'toggle'])->name('recurring-tasks.toggle');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
