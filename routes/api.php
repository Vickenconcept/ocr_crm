<?php

use App\Http\Controllers\Api\QuestionAnalysisController;
use App\Http\Middleware\ValidateOcrApiKey;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('status', [QuestionAnalysisController::class, 'status'])
        ->middleware(ValidateOcrApiKey::class);

    Route::post('analyze/answer', [QuestionAnalysisController::class, 'answer'])
        ->middleware(ValidateOcrApiKey::class);
});
