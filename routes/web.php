<?php

use Illuminate\Support\Facades\Route;

Route::get('/locale/{locale}', function (string $locale) {
    abort_unless(array_key_exists($locale, config('locales.supported', [])), 404);

    session(['locale' => $locale]);
    app()->setLocale($locale);

    return redirect()->back(fallback: url('/admin'));
})->where('locale', 'ar|en')->name('locale.switch');

Route::get('/', function () {
    return view('welcome');
});
