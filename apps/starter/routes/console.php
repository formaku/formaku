<?php

use App\Models\User;
use Formaku\Cms\Jobs\NotifyRecordOwner;
use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Record;
use Formaku\Cms\Services\RecordService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;

Artisan::command('formaku:admin {email}', function () {
    $email = $this->argument('email');
    if (User::where('email', $email)->exists()) {
        $this->error('User already exists');

        return 1;
    }$password = $this->secret('Password (minimum 12 characters)');
    if (strlen($password) < 12) {
        $this->error('Password too short');

        return 1;
    }$u = new User(['name' => 'Administrator', 'email' => $email, 'password' => Hash::make($password)]);
    $u->role = 'admin';
    $u->email_verified_at = now();
    $u->save();
    $this->info('Administrator created.');
});
Artisan::command('formaku:demo', function () {
    $s = app(RecordService::class);
    $actor = User::where('role', 'admin')->firstOrFail()->id;
    $pages = Collection::firstOrCreate(['key' => 'pages'], ['name' => 'Strony i oferta', 'kind' => 'content', 'definition' => ['public' => true, 'fields' => [['key' => 'title', 'label' => 'Tytuł', 'type' => 'text', 'required' => true], ['key' => 'slug', 'label' => 'Adres URL', 'type' => 'text', 'required' => true], ['key' => 'blocks', 'label' => 'Treść strony', 'type' => 'richtext']]]]);
    Collection::firstOrCreate(['key' => 'requests'], ['name' => 'Zgłoszenia klientów', 'kind' => 'data', 'definition' => ['fields' => [['key' => 'title', 'label' => 'Temat', 'type' => 'text', 'required' => true], ['key' => 'description', 'label' => 'Opis', 'type' => 'text', 'required' => true], ['key' => 'internal_note', 'label' => 'Notatka wewnętrzna', 'type' => 'text', 'private' => true]], 'states' => ['new', 'in_progress', 'closed'], 'transitions' => [['from' => 'new', 'to' => 'in_progress'], ['from' => 'in_progress', 'to' => 'closed']]]]);
    if (! Record::where('collection_id', $pages->id)->exists()) {
        $rec = $s->save($pages, null, ['title' => 'Zbuduj coś swojego', 'slug' => 'start', 'blocks' => [['type' => 'hero', 'props' => ['eyebrow' => 'FORMAKU / OPEN SOURCE', 'title' => 'Od pomysłu do własnej aplikacji.', 'text' => 'Treści, dane i portal klienta. W jednym miejscu.']], ['type' => 'cta', 'props' => ['title' => 'Porozmawiajmy o Twoim projekcie', 'label' => 'Otwórz portal', 'url' => '/portal']]]], 0, $actor);
        $s->publish($rec, $rec->version, $actor);
    }$this->info('Demo ready. No accounts or passwords were seeded.');
});
Schedule::command('formaku:publish-scheduled')
    ->everyMinute()
    ->name('formaku:publish-scheduled')
    ->withoutOverlapping();

Schedule::call(function () {
    foreach (DB::table('formaku_events')->whereNull('notified_at')->whereIn('type', ['saved', 'transitioned'])->limit(100)->get() as $event) {
        NotifyRecordOwner::dispatch($event->id);
    }
})->everyMinute()->name('formaku:notifications')->withoutOverlapping();
