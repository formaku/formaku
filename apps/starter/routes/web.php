<?php

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

Route::get('/login', fn () => view('formaku::login'))->name('login');
Route::post('/login', function (Request $r) {
    $d = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
    if (! Auth::attempt($d)) {
        return back()->withErrors(['email' => 'Nieprawidłowe dane logowania.']);
    }$r->session()->regenerate();

    return redirect()->intended(Auth::user()->role === 'client' ? '/portal' : '/formaku');
})->middleware('throttle:10,1');
Route::post('/logout', function (Request $r) {
    Auth::logout();
    $r->session()->invalidate();
    $r->session()->regenerateToken();

    return redirect('/');
});

Route::get('/register', function () {
    abort_unless(config('formaku.registration'), 404);

    return view('formaku::register');
});
Route::post('/register', function (Request $r) {
    abort_unless(config('formaku.registration'), 404);
    $d = $r->validate(['name' => 'required|string|max:100', 'email' => 'required|email|unique:users', 'password' => 'required|confirmed|min:12']);
    $u = User::create(['name' => $d['name'], 'email' => $d['email'], 'password' => Hash::make($d['password'])]);
    event(new Registered($u));
    Auth::login($u);
    $r->session()->regenerate();

    return redirect('/email/verify');
})->middleware('throttle:5,1');
Route::get('/email/verify', fn () => view('formaku::verify'))->middleware('auth')->name('verification.notice');
Route::get('/email/verify/{id}/{hash}', function (EmailVerificationRequest $r) {
    $r->fulfill();

    return redirect('/portal');
})->middleware(['auth', 'signed', 'throttle:6,1'])->name('verification.verify');
Route::post('/email/verification-notification', function (Request $r) {
    $r->user()->sendEmailVerificationNotification();

    return back()->with('status', 'Wysłano wiadomość weryfikacyjną.');
})->middleware(['auth', 'throttle:6,1'])->name('verification.send');
Route::get('/forgot-password', fn () => view('formaku::password', ['reset' => false]))->name('password.request');
Route::post('/forgot-password', function (Request $r) {
    $r->validate(['email' => 'required|email']);
    Password::sendResetLink($r->only('email'));

    return back()->with('status', 'Jeśli konto istnieje, wysłaliśmy instrukcję.');
})->middleware('throttle:5,1')->name('password.email');
Route::get('/reset-password/{token}', fn (string $token) => view('formaku::password', ['reset' => true, 'token' => $token]))->name('password.reset');
Route::post('/reset-password', function (Request $r) {
    $r->validate(['token' => 'required', 'email' => 'required|email', 'password' => 'required|confirmed|min:12']);
    $status = Password::reset($r->only('email', 'password', 'password_confirmation', 'token'), function ($u, $password) {
        $u->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
        event(new PasswordReset($u));
    });

    return $status === Password::PASSWORD_RESET ? redirect('/login') : back()->withErrors(['email' => __($status)]);
})->middleware('throttle:5,1')->name('password.update');
