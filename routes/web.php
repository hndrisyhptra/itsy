<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\FinanceDocumentController;
use App\Http\Controllers\MasterDesignatorController;
use App\Http\Controllers\NegotiationProjectController;
use App\Http\Controllers\PaymentProjectController;
use App\Http\Controllers\PrototypeBoqController;
use App\Http\Controllers\PrototypeController;
use App\Http\Controllers\PrototypeExportController;
use App\Http\Controllers\PrototypeScanController;
use App\Http\Controllers\TermWorkEvidenceController;
use App\Http\Controllers\UploadQueueController;
use App\Http\Middleware\ActiveAccount;
use App\Http\Middleware\PrivatePrototype;
use Illuminate\Support\Facades\Route;

Route::middleware(PrivatePrototype::class)->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('/login', [AuthController::class, 'show'])->name('login');
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:20,1');
        Route::post('/setup', [AuthController::class, 'setup'])->middleware('throttle:5,1')->name('setup');
    });
    Route::middleware(['auth', 'auth.session', ActiveAccount::class])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/akun', [AccountController::class, 'index'])->name('accounts.index');
        Route::get('/akun/baru', [AccountController::class, 'edit'])->name('accounts.create');
        Route::post('/akun', [AccountController::class, 'save'])->name('accounts.store');
        Route::get('/akun/{user}/edit', [AccountController::class, 'edit'])->name('accounts.edit');
        Route::put('/akun/{user}', [AccountController::class, 'save'])->name('accounts.update');
        Route::match(['get', 'post'], '/api/finance/spk/{spk}/payee', [FinanceDocumentController::class, 'payee'])->where('spk', '[a-zA-Z0-9-]{3,100}')->middleware('throttle:60,1');
        Route::match(['get', 'post'], '/api/finance/terms/{term}/kasbon', [FinanceDocumentController::class, 'kasbon'])->where('term', '[a-zA-Z0-9-]{3,100}')->middleware('throttle:60,1');
        Route::post('/api/finance/uploads/{kind}/{document}', [FinanceDocumentController::class, 'upload'])->where('kind', 'mandor-ktp|mandor-bank|transfer-proof')->where('document', '[a-zA-Z0-9-]{3,100}')->middleware('throttle:prototype-scans');
        Route::get('/api/finance/files/{file}', [FinanceDocumentController::class, 'file'])->whereUuid('file');
        Route::get('/api/prototype/state', [PrototypeController::class, 'state'])->name('prototype.state');
        Route::get('/api/prototype/revision', [PrototypeController::class, 'revision'])->name('prototype.revision')->middleware('throttle:60,1');
        Route::get('/api/prototype/negotiation/projects', [NegotiationProjectController::class, 'projects'])->middleware('throttle:120,1');
        Route::get('/api/prototype/negotiation/projects/{project}/plans', [NegotiationProjectController::class, 'plans'])->whereUuid('project')->middleware('throttle:120,1');
        Route::get('/api/prototype/payment-selection/projects', [PaymentProjectController::class, 'projects'])->middleware('throttle:120,1');
        Route::get('/api/prototype/payment-selection/projects/{project}/orders', [PaymentProjectController::class, 'orders'])->whereUuid('project')->middleware('throttle:120,1');
        Route::get('/api/masters', [MasterDesignatorController::class, 'index']);
        Route::get('/api/masters/{entity}/template', [MasterDesignatorController::class, 'template'])->where('entity', 'designator|khs')->middleware('throttle:20,1');
        Route::post('/api/masters/{entity}/import', [MasterDesignatorController::class, 'import'])->where('entity', 'designator|khs')->middleware('throttle:20,1');
        Route::post('/api/uploads/boq', [UploadQueueController::class, 'store'])->middleware('throttle:prototype-scans');
        Route::post('/api/uploads/payments/{term}/evidence', [UploadQueueController::class, 'store'])->where('term', '[a-zA-Z0-9-]{3,100}')->middleware('throttle:prototype-scans');
        Route::get('/api/uploads', [UploadQueueController::class, 'index']);
        Route::get('/api/uploads/{task}', [UploadQueueController::class, 'show'])->whereUuid('task');
        Route::post('/api/uploads/{task}/resume', [UploadQueueController::class, 'resume'])->whereUuid('task')->middleware('throttle:5,1');
        Route::post('/api/masters/{entity}', [MasterDesignatorController::class, 'store'])->where('entity', 'designator|khs|paket-khs')->middleware('throttle:60,1');
        Route::post('/api/prototype/commands', [PrototypeController::class, 'command'])->middleware('throttle:180,1')->name('prototype.commands');
        Route::get('/api/prototype/exports/csv', PrototypeExportController::class)->middleware('throttle:30,1')->name('prototype.csv');
        Route::get('/api/prototype/backup', [PrototypeController::class, 'backup'])->middleware('throttle:30,1')->name('prototype.backup');
        Route::post('/api/prototype/boq/import', [PrototypeBoqController::class, 'store'])->middleware('throttle:prototype-scans');
        Route::get('/api/prototype/boq/{project}/source', [PrototypeBoqController::class, 'show'])->where('project', '[a-zA-Z0-9-]{3,100}');
        Route::post('/api/prototype/payments/{term}/scan', [PrototypeScanController::class, 'store'])->where('term', '[a-zA-Z0-9-]{3,100}')->middleware('throttle:prototype-scans');
        Route::get('/api/prototype/payments/{term}/scan', [PrototypeScanController::class, 'show'])->where('term', '[a-zA-Z0-9-]{3,100}');
        Route::post('/api/prototype/payments/{term}/work-evidence', [TermWorkEvidenceController::class, 'store'])->where('term', '[a-zA-Z0-9-]{3,100}')->middleware('throttle:prototype-scans');
        Route::get('/api/prototype/payments/{term}/work-evidence/{evidence}', [TermWorkEvidenceController::class, 'show'])->where('term', '[a-zA-Z0-9-]{3,100}')->whereUuid('evidence');
        Route::get('/', fn () => redirect()->route('prototype.dashboard'));
        Route::get('/dashboard', [PrototypeController::class, 'show'])->name('prototype.dashboard');
        Route::get('/{module}/{path?}', [PrototypeController::class, 'show'])
            ->where('module', 'project-plan|boq|ba-kh|spk|termin|inbox|ba-pp|master-designator|master-khs|laporan|pengaturan')
            ->where('path', '[a-zA-Z0-9/-]+');
    });
});
