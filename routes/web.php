<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\GenerationController;

Route::get('/', function () {
    return view('home');
});

Route::get('/generate', function () {
    return view('generate');
});
