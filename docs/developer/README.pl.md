# Rozszerzanie Formaku w Laravelu

[English](README.md)

Przykłady korzystają ze startera na Laravelu 13. Integracja z panelem wymaga Formaku alpha.32 lub nowszej wersji. Użyj lokalnej instalacji z osobną bazą testową. W samodzielnej aplikacji najpierw ustaw guard i autoryzację Formaku zgodnie z [instrukcją integracji pakietu](../../README.md#package-integration).

## Instalacja w istniejącej aplikacji Laravel

W aplikacji Laravel 13 z PHP 8.4 lub nowszym dodaj [repozytorium Composer Formaku](https://formaku.eu/composer/packages.json) i zainstaluj wydanie.

```sh
composer config repositories.formaku composer https://formaku.eu/composer
composer require 'formaku/cms:0.1.0-alpha.82' --prefer-dist
php artisan formaku:install
php artisan migrate
```

W `config/formaku.php` ustaw w `guard` mechanizm sesji używany do logowania w aplikacji. W `authorize` wskaż funkcję, która otrzymuje zalogowanego użytkownika i nazwę uprawnienia, a zwraca wartość logiczną. Jeśli aplikacja definiuje publiczną metodę statyczną `allows` w klasie `App\Support\FormakuAccess`, użyj takiej konfiguracji.

```php
'guard' => 'web',
'authorize' => [\App\Support\FormakuAccess::class, 'allows'],
```

W metodzie sprawdzaj uprawnienia aplikacji. Nadaj `work` osobom z dostępem do panelu, `build` osobom zarządzającym modelami, a `publish` osobom uprawnionym do publikacji. Dodaj trasę o nazwie `login` dla domyślnego przekierowania gości w Laravel lub wskaż istniejący ekran logowania przez `redirectGuestsTo` w `bootstrap/app.php`. Zaloguj się w aplikacji gospodarza i otwórz `/formaku`. Jeśli potrzebujesz aplikacji z gotowym logowaniem, użyj startera Formaku. Włącz publiczne trasy Formaku, gdy zechcesz przez nie publikować treści.

### Sprawdź instalację Formaku

Po instalacji lub aktualizacji uruchom polecenie diagnostyczne w katalogu aplikacji Laravel. Polecenie działa w trybie odczytu.

```sh
php artisan formaku:doctor
```

Polecenie sprawdza ustawienia mechanizmu sesji i funkcji autoryzacji, historię migracji Formaku oraz zgodność wszystkich plików panelu z zainstalowanym pakietem, w tym modułów edytora i fontów. Wyświetla też stan tras publicznych. Gdy któraś kontrola się nie powiedzie, polecenie zwraca niezerowy kod wyjścia. Po sprawdzeniu historii migracji polecenie sprawdza też, czy istnieją wszystkie wymagane tabele Formaku. Jeśli historia jest kompletna, a tabel brakuje, sprawdź bazę wybraną w Laravel i zawartość odtworzonej kopii.

Jeśli brakuje plików panelu w kopii źródeł, zbuduj je w głównym katalogu repozytorium Formaku. Brakujące lub nieaktualne pliki publiczne odśwież przez `php artisan formaku:install`. Przy zaległych migracjach postępuj zgodnie z [instrukcją aktualizacji](../DEPLOYMENT.md#upgrade-an-existing-alpha-installation). Aby sprawdzić uprawnienia konkretnej roli, zaloguj się w aplikacji jako użytkownik tej roli i otwórz panel.

Od alpha.82 panel rysuje menu i ekrany dopiero wtedy, gdy `GET /formaku/api/v1/bootstrap` zwróci kolekcje i uprawnienia użytkownika. Jeśli to żądanie się nie powiedzie, panel pokazuje komunikat **Nie udało się wczytać panelu**, treść odpowiedzi API i przycisk **Spróbuj ponownie**. Żądanie wywołuje funkcje `authorize` i `staff` z `config/formaku.php` oraz funkcje `authorize` zarejestrowanych rozszerzeń panelu, dlatego przyczyny szukaj w logu Laravel aplikacji gospodarza.

W CI i skryptach wdrożeniowych uruchom `php artisan formaku:doctor --json` w wersji alpha.81 lub nowszej. Polecenie zwraca jeden obiekt JSON z polem `ok` i wynikami logicznymi `authentication`, `database` i `assets` w polu `checks`. Pole `errors` zawiera komunikat każdej nieudanej kontroli w kolejności zwykłego wyniku. Gdy wszystkie kontrole przeszły, lista jest pusta. Komunikaty podają nazwy tabel i plików, bez danych połączenia. Pole `public_routes` podaje stan konfiguracji tras publicznych. Kod wyjścia to 0, jeśli wszystkie kontrole przeszły, albo 1, jeśli któraś wykryła problem. Błąd uruchamiania Laravel może wystąpić przed wypisaniem JSON.

### Aktualizacja istniejącej instalacji Composer

Po aktualizacji do alpha.82 wykonaj te polecenia w aplikacji Laravel przed przywróceniem ruchu.

```sh
php artisan formaku:install
php artisan migrate --force
```

Jeśli migracja indeksu historii rewizji oczekuje na wykonanie, uwzględnij czas budowy indeksu PostgreSQL przed wznowieniem zapisów. SQLite również tworzy ten indeks. Istniejące rewizje pozostają w bazie.

Od alpha.31 `formaku:install` usuwa skompilowane kopie szablonów Blade pakietu. Widoki aplikacji gospodarza pozostają w cache. Jeśli wdrożenie kompiluje widoki z wyprzedzeniem, uruchom następnie `php artisan view:cache`. Przy instalacji alpha.30 po aktualizacji wykonaj `php artisan view:clear`.

### Włącz zaplanowaną publikację w aplikacji Laravel

Po instalacji Formaku dodaj poniższy wpis w pliku `routes/console.php` aplikacji.

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('formaku:publish-scheduled')->everyMinute()->withoutOverlapping(5);
Schedule::command('formaku:notify')->everyMinute()->withoutOverlapping(5);
```

Sprawdź wpisy poleceniem `php artisan schedule:list` i uruchom scheduler Laravel w swoim środowisku. Pierwsza komenda publikuje zapisane treści, których termin już nadszedł. Jeśli aplikacja ma własny wpis publikujący treści Formaku, zastąp go tą komendą. Starter Formaku ma oba wpisy.

Podaj w `withoutOverlapping` czas blokady w minutach. Bez niego Laravel utrzymuje blokadę przez dobę. Jeśli uruchomienie zostanie przerwane przed jej zwolnieniem, na przykład przy zatrzymaniu kontenera, zadanie nie ruszy do wygaśnięcia blokady. Obie komendy można bezpiecznie powtórzyć po pięciu minutach.

### Włącz powiadomienia portalu w aplikacji Laravel

Komenda `formaku:notify`, dostępna od alpha.81, kolejkuje e-mail do właściciela rekordu po zapisie, zmianie stanu i udostępnieniu odpowiedzi w kolekcjach danych. Wymaga skonfigurowanej poczty i działającej kolejki. Jedno uruchomienie kolejkuje 100 najstarszych oczekujących zdarzeń. Opcja `--limit` przyjmuje wartość od 1 do 1000. Zdarzenie, którego rekord albo właściciel już nie istnieje, zostaje zamknięte bez wysyłki. Jeśli aplikacja skopiowała wpis powiadomień z wcześniejszego startera, zastąp go tą komendą.

Uruchom ręcznie `php artisan formaku:publish-scheduled`, aby zobaczyć podsumowanie. Po błędzie szkic zachowuje termin, a komenda zwraca niezerowy kod po przetworzeniu pozostałych szkiców. Użyj identyfikatora z wyniku i logu aplikacji, aby ustalić przyczynę. Popraw szkic i ustaw nowy termin albo anuluj publikację. Zmiana wersji w trakcie pracy powoduje pominięcie rekordu.

### Instalacja z lokalnego ZIP-u

Dla Laravel 13 i PHP 8.4 lub nowszego pobierz `formaku-cms-v0.1.0-alpha.82.zip` i plik `.sha256` z [załączników wydania na GitHubie](https://github.com/formaku/formaku/releases/tag/v0.1.0-alpha.82). Umieść oba w katalogu `formaku-packages` w głównym katalogu aplikacji. Sprawdź sumę kontrolną i zarejestruj katalog jako [repozytorium artefaktów Composer](https://getcomposer.org/doc/05-repositories.md#artifact).

```sh
(cd formaku-packages && sha256sum -c formaku-cms-v0.1.0-alpha.82.zip.sha256)
composer config repositories.formaku-artifacts artifact ./formaku-packages
composer require 'formaku/cms:0.1.0-alpha.82' --prefer-dist
php artisan formaku:install
php artisan migrate
```

Na macOS w poleceniu sprawdzającym sumę użyj `shasum -a 256 -c`. Zachowaj ZIP pod tą samą ścieżką względną w środowiskach, w których uruchamiasz `composer install`. Archiwum zawiera zbudowane zasoby, fonty panelu i informacje o ich licencjach.


## Udostępnij katalog ról w edytorze modelu

W pliku `config/formaku.php` aplikacji gospodarza ustaw `roles` jako tablicę kluczy ról i wyświetlanych nazw.

```php
'roles' => [
    'case_manager' => 'Opiekun sprawy',
    'reviewer' => 'Weryfikator',
],
```

Użyj kluczy zwracanych przez funkcję `role` dla użytkowników. Bez tej funkcji Formaku odczytuje właściwość `role` użytkownika. Katalog podpowiada role w edytorze modelu osobom z uprawnieniem `build`. Aplikacja Laravel przypisuje role, a funkcja autoryzacji Formaku i reguły kolekcji lub pól określają dostęp. W edytorze można też podać klucz spoza katalogu.

## Skonfiguruj uprawnienia pól

Definicja pola może zawierać `access.read` i `access.write`, czyli listy kluczy ról rozpoznawanych przez Formaku w aplikacji gospodarza. Pominięta lista dziedziczy dostęp z kolekcji. Pusta lista ogranicza operację do osób z uprawnieniem `build`. Zapis wymaga też odczytu pola i prawa do edycji rekordu. Lista odczytu wymaga `private: true`. Pole adresu `slug` nie przyjmuje listy odczytu.

Przy aktualizacji modelu pominięcie `access` zachowuje dotychczasowe reguły pola. Wyślij jawnie `access: {}`, aby je usunąć. Bieżący model określa dostęp do bieżących danych i starszych rewizji. Przy bezpośrednim wywołaniu `RecordService::save()` dla kolekcji z regułami pól przekaż zalogowanego użytkownika jako `actorUser` i odpowiadający mu identyfikator wykonawcy.

Przed powrotem do wersji sprzed uprawnień pól ogranicz dostęp do każdej objętej kolekcji do osób, które mogą zobaczyć wszystkie jej pola. Sprawdź reguły kolekcji przed przywróceniem ruchu na starszej wersji.

## Zbuduj arkusz stylów CMS

Uruchom `npm run build` w głównym katalogu repozytorium Formaku. Arkusz skanuje źródła panelu, widoki Blade i kod renderowania zadeklarowane w `packages/cms/resources/admin/style.css`. Jeśli rozszerzenie potrzebuje klas spoza zestawu Formaku, skompiluj jego style w aplikacji gospodarza. Polecenie `bash tests/css-sources.sh` sprawdza, czy pliki spoza wskazanych źródeł pozostawiają CSS identyczny.

## Wyszukaj rekordy kolekcji

W uwierzytelnionej sesji panelu wyślij `GET /formaku/api/v1/collections/{collection}/records?label=Przyklad`, aby wyszukać ostatnio zapisany tytuł lub nazwę w kolekcji. Endpoint uwzględnia zakres dostępu do kolekcji i uprawnienia odczytu pól. Fraza może mieć do 200 znaków. Zakoduj ją jako wartość w adresie URL. Znaki takie jak `%` i `_` są traktowane dosłownie. Rozróżnianie wielkości liter i znaków diakrytycznych zależy od bazy danych.

Filtr `label` można łączyć z `state`, a w kolekcjach treści także z `scheduled=1`. Odnośniki do kolejnych stron w odpowiedzi zachowują filtry. Dotychczasowy parametr `search` nadal przeszukuje opublikowane tytuły. W kolekcjach treści dodaj `publication=unpublished`, `publication=published` lub `publication=changed`, aby filtrować stan publikacji. `published` obejmuje też rekordy z nowszym szkicem. `changed` zwraca opublikowane rekordy z innym zapisanym szkicem.

## Odczytaj historię wersji stronami

W uwierzytelnionej sesji panelu wyślij `GET /formaku/api/v1/records/{record}/revisions?page=2`. Otrzymasz do 25 identyfikatorów i dat rewizji, od najnowszej. Obowiązują uprawnienia odczytu rekordu. Użyj `GET /formaku/api/v1/records/{record}?history=0`, aby pobrać bieżący rekord bez pełnej historii. Żądania bez tego parametru zachowują dotychczasową odpowiedź.

## Obejrzyj zapisaną rewizję

W uwierzytelnionej sesji panelu użyj `GET /formaku/api/v1/records/{record}/revisions/{revision}/preview` dla zapisanej rewizji rekordu treści. Odpowiedź HTML zawiera nagłówki `Cache-Control: no-store` i `X-Robots-Tag: noindex`. Podgląd korzysta z aktualnej definicji kolekcji i uprawnień do odczytu pól. Żądanie zachowuje bieżący szkic, opublikowaną rewizję i termin.

## Wycofaj publikację przez API

Wyślij `DELETE /formaku/api/v1/records/{record}/publish` z aktualną wartością `version` w treści JSON. Użyj uwierzytelnionej sesji WWW z tokenem CSRF, uprawnieniem do publikowania i prawem zapisu rekordu. Nieaktualna wersja zwraca `409`.

Wycofanie opublikowanej treści usuwa wskazanie publicznej rewizji i zaplanowany termin w jednej operacji. Zapisany szkic i historia pozostają dostępne. Rekord bez bieżącej publikacji zachowuje wersję oraz ewentualny termin pierwszej publikacji.

## Skonfiguruj markę witryny przez API

Wersjonowane ustawienia menu przyjmują `brandName` (do 80 znaków) i `brandLogoId` (identyfikator publicznego pliku JPEG, PNG lub WebP). Do zapisu dołącz aktualne `version`. Pominięcie pola zachowuje zapisaną wartość. Wyślij `null`, aby wyczyścić ustawienie. Marka korzysta z istniejącej tabeli ustawień witryny.

## Skonfiguruj formularz zgłoszeń klienta

W kolekcji danych `requests` ustaw `definition.portal_fields` jako listę kluczy dodatkowych pól. Na przykład `"portal_fields": ["preferred_date", "contact_method"]` dodaje pola o tych kluczach, jeśli występują w `definition.fields` i nie mają ustawionej prywatności. Obsługiwane typy to `text`, `number`, `date`, `boolean` i `select`. Dla list wyboru podaj różne, niepuste wartości tekstowe. Przecinek służy jako separator wartości. Domyślnie lista jest pusta, a portal zawsze zawiera istniejące pola `title` i `description`.

Formularz pokazuje dodatkowe pola, które zalogowany klient może zapisać. W szczegółach zgłoszenia właściciel widzi zapisane wartości, które może odczytać. Obowiązują reguły kolekcji i pól Formaku.

### Włącz załączniki do zgłoszeń

W kolekcji danych `requests` ustaw `definition.portal_attachments` na `true`. Opcjonalne `definition.portal_attachment_limits` przyjmuje `max_files` (1–3), `max_mib` (1–5 na plik) i `types` (niepustą listę unikalnych wartości spośród `pdf`, `png` i `jpeg`). Domyślne wartości to trzy pliki, 5 MiB na plik i wszystkie trzy typy. Limity obowiązują przy składaniu zgłoszenia.

Pliki trafiają na dysk `local` w Laravelu. Zachowaj jego prywatność i twórz kopie razem z bazą danych. Aby obsłużyć domyślne limity w aplikacji instalowanej przez Composer, ustaw w PHP co najmniej `upload_max_filesize=5M`, `post_max_size=20M` i `max_file_uploads=4`. Sprawdź też limit żądań w reverse proxy. Obraz Docker ma skonfigurowane limity PHP.

Jeśli migracja tabeli załączników wprowadzona w alpha.37 jest nadal oczekująca, zastosuj ją przed uruchomieniem nowej wersji. Przy powrocie do kodu alpha.36 zachowaj tabelę załączników i pliki, aby odzyskać dostęp po ponownej aktualizacji.

Alpha.65 stosuje pierwotne limity przesyłania. Przed powrotem do tej wersji wyłącz załączniki, jeśli przyjmowanie plików według tych limitów naruszałoby zasady przesyłania w Twojej instalacji.

## Zarejestruj rozszerzenia

1. Skopiuj pliki PHP z [examples/app](examples/app) do odpowiadających im ścieżek w katalogu `app/` aplikacji.
2. Dodaj `App\Providers\FormakuExamplesServiceProvider::class` do tablicy zwracanej w pliku `bootstrap/providers.php`.
3. [Przykładowy provider](examples/app/Providers/FormakuExamplesServiceProvider.php) rejestruje rozszerzenia w metodzie `boot(Registry $registry)`. Formaku udostępnia współdzielony rejestr podczas wywołania `register()` swojego providera. Wybierz prefiks kluczy właściwy dla projektu. Ponowna rejestracja klucza rozszerzenia zastępuje wpis w danej mapie rejestru. Klucze wbudowanych typów pól i bloków są zastrzeżone.

## Opublikuj stronę z własnym blokiem

[Blok komunikatu](examples/app/Formaku/NoticeBlock.php) waliduje krótki tekst i koduje znaki specjalne przed zwróceniem HTML. Formaku wywołuje `render()` zarejestrowanego bloku przy weryfikacji publikacji oraz przy renderowaniu stron publicznych i podglądu szkicu. Kod renderowania powinien działać bez zapisu i wywołań zewnętrznych. Blok sam wywołuje `schema()` do sprawdzania właściwości. Zapis szkicu waliduje pola kolekcji, a wbudowany edytor bloków definiuje własne formularze.

Użyj publicznej kolekcji `content` z polami tekstowymi `title` i `slug` oraz polem `richtext` o kluczu `blocks`. Zaloguj się na konto z prawem zapisu w kolekcji i wyślij poniższy JSON na `POST /formaku/api/v1/collections/{collection}/records`. Adres uwzględnia skonfigurowany prefiks Formaku. Trasy korzystają z sesji WWW i ochrony CSRF Laravela.

```json
{
  "version": 0,
  "data": {
    "title": "Przykład rozszerzenia",
    "slug": "extension-example",
    "blocks": [
      {"type": "example_notice", "props": {"message": "Notatka dla redakcji"}}
    ]
  }
}
```

Użyj zwróconego `id` i otwórz podgląd przez `GET /formaku/api/v1/records/{record}/preview` w tej samej sesji. Aby opublikować stronę, użyj sesji z prawem publikacji i wyślij zwróconą wartość `version` w żądaniu `POST /formaku/api/v1/records/{record}/publish`. Otwórz `/p/extension-example`, aby sprawdzić opublikowany blok. Publiczny adres wymaga włączonego `formaku.public_routes`, tak jak w starterze.

Lista bloków w edytorze jest stała. Przekaż przykładowy blok przez API lub własny importer. Zachowaj rejestrację bloku, dopóki jego kod jest potrzebny do odczytu bieżących lub historycznych rewizji.

## Pokaż zasoby i akcje Laravel w panelu

Od alpha.32 metody `Registry::resource()` i `Registry::action()` przyjmują opcjonalną konfigurację panelu. [Przykładowy provider](examples/app/Providers/FormakuExamplesServiceProvider.php) rejestruje **My profile** i **Preview notice**. Dodaj provider, zaloguj się z dostępem do Formaku i otwórz **Rozszerzenia**. Możesz zobaczyć własny profil i wysłać tekst do podglądu. Panel korzysta z istniejących tras sesyjnych Formaku.

Dla zasobu podaj etykietę, listę kolumn z `key` i `label` oraz funkcję `authorize`. Adapter otrzymuje zalogowanego użytkownika. W aplikacji gospodarza określ zakres i kolejność zapytania oraz wybierz surowe atrybuty lub aliasy SQL do wyświetlenia. Panel pokazuje wartości skalarne i puste komórki, do 25 wierszy na stronę. Uprawnienia do wierszy uwzględnij w zapytaniu SQL. Filtrowanie po odczycie może zmniejszyć liczbę wierszy na stronie. Zapytanie zasobu powinno działać bez limitu Laravela nakładanego na każdą grupę.

Dla akcji podaj etykietę, listę pól z `key`, `label`, `type` i opcjonalnym `required` oraz funkcję `authorize`. Dostępne typy to `text`, `integer` i `boolean`. Handler otrzymuje zalogowanego użytkownika i zwalidowane pola. Dodaj w handlerze walidację biznesową i uprawnienia do konkretnych rekordów. Zwracaj wyłącznie dane, które użytkownik może zobaczyć. Panel wyświetla wynik jako tekst.

Formaku sprawdza funkcję autoryzacji przy pobieraniu listy i przy wywołaniu rozszerzenia. Panel udostępnia rozszerzenia z jawną konfiguracją. Istniejące wpisy w rejestrze i trasy aplikacji nadal działają.

### Uruchom akcję na jednym wierszu zasobu

Od alpha.81 zasób może udostępniać akcje dla pojedynczego wiersza. Dodaj `row_key` i `row_actions` do konfiguracji zasobu, a akcję, która ma sens tylko dla wiersza, oznacz przez `row_only`.

```php
$registry->resource(new OrdersResource, [
    'label' => 'Orders',
    'columns' => [['key' => 'id', 'label' => 'ID'], ['key' => 'status', 'label' => 'Status']],
    'authorize' => fn (object $user): bool => $user->can('orders.view'),
    'row_key' => 'id',
    'row_actions' => [['action' => 'cancel_order', 'label' => 'Cancel', 'input_key' => 'order_id']],
]);
$registry->action(new CancelOrderAction, [
    'label' => 'Cancel order',
    'fields' => [['key' => 'order_id', 'label' => 'Order', 'type' => 'integer', 'required' => true]],
    'authorize' => fn (object $user): bool => $user->can('orders.cancel'),
    'row_only' => true,
]);
```

`row_key` wskazuje jedną z kolumn zasobu. Każda akcja wiersza wskazuje zarejestrowaną akcję oraz jej pole typu `text` albo `integer`, do którego trafia identyfikator wiersza. Wyślij `POST /formaku/api/v1/extensions/resources/{resource}/actions/{action}` z JSON-em zawierającym `row` i pozostałe pola akcji. Formaku wyszukuje wiersz przez zapytanie samego zasobu, więc wiersz niewidoczny dla zalogowanego użytkownika zwraca 404. Następnie przekazuje handlerowi zapisany identyfikator w polu `input_key`. Żądanie musi wskazywać dokładnie jeden wiersz.

Akcja `row_only` nie pojawia się na liście samodzielnych akcji i nie można jej wywołać bez wiersza. API bootstrap zwraca `row_key` oraz akcje wiersza dostępne dla użytkownika. Zasób, którego zapytanie ustawia limit, przesunięcie albo filtruje wiersze po odczycie, zwraca 422 dla akcji wiersza.

W wydaniach po alpha.82 panel pokazuje przycisk każdej akcji wiersza w kolumnie **Akcje** zasobu. Przycisk otwiera okno z wartościami wiersza i polami akcji innymi niż `input_key`. Akcja uruchamia się, gdy użytkownik wybierze **Uruchom akcję**. Okno pokazuje wtedy zwrócone dane, a panel ponownie wczytuje listę. Wiersz ma przyciski, gdy wartość `row_key` jest liczbą całkowitą albo niepustym tekstem o długości do 255 znaków. Jeśli handler odrzuci żądanie odpowiedzią 4xx, okno pokazuje `message` z tej odpowiedzi, dlatego zgłaszaj `ValidationException` z komunikatem, na podstawie którego użytkownik może działać. Przy odpowiedzi 404 okno informuje, że wiersz albo akcja nie są już dostępne. W tych wydaniach każda akcja wiersza w API bootstrap ma też `fields`, czyli pola, o które pyta panel. Panel w alpha.81 i alpha.82 nie pokazuje przycisków akcji wiersza. W tych wydaniach wywołuj ten adres z własnego interfejsu.

## Wywołaj zasób i akcję z aplikacji

Rejestr przechowuje [OwnProfileResource](examples/app/Formaku/OwnProfileResource.php) i [PreviewNoticeAction](examples/app/Formaku/PreviewNoticeAction.php). Opcjonalne trasy aplikacji udostępniają te same przykłady poza panelem. Skopiuj [przykładowe trasy](examples/routes/formaku-extension-examples.php) do katalogu `routes/` aplikacji i dodaj poniższy wiersz na końcu `routes/web.php`.

```php
require __DIR__.'/formaku-extension-examples.php';
```

| Trasa aplikacji | Działanie |
| --- | --- |
| `GET /extension-examples/profile` | Pobiera użytkownika z sesji, ogranicza zapytanie do jego konta i zwraca wyłącznie ID i nazwę. |
| `POST /extension-examples/preview-notice` | Przyjmuje JSON z tekstowym polem `message`, waliduje dane i zwraca podgląd po usunięciu skrajnych spacji. |

Trasy korzystają z sesji i ochrony CSRF Laravela. Korzystają ze stałych kluczy rejestru i ustalają wykonawcę na podstawie sesji. Zastosuj polityki aplikacji przed udostępnieniem zasobu biznesowego lub wywołaniem akcji ze skutkiem ubocznym. Umieść ograniczenie do właściciela lub tenanta w zapytaniu i jawnie wybierz zwracane pola. Po stronie klienta traktuj wynik akcji jak dane tekstowe.

## Zarejestruj własne typy pól i metadane bloków

Wywołaj `Registry::field()`, zanim utworzysz kolekcję z polem tego typu. Podczas walidacji rekordu Formaku przekazuje definicję pola do `FieldType::rules()`. Definicja kolekcji określa, czy pole jest wymagane. Jeśli typ potrzebuje reguł walidacji i metadanych, użyj `Formaku\Cms\Fields\Field`.

```php
$registry->field(new \Formaku\Cms\Fields\Field(
    'example_price_cents', 'Cena w groszach', 'number', ['integer', 'min:0'],
));
```

W sekcji **Buduj → Edytuj model** wybierz **Cena w groszach** jako typ pola. API kolekcji używa klucza `example_price_cents`. Zainstaluj rozszerzenie w każdej aplikacji, która zapisuje lub publikuje takie rekordy. Wbudowaną obsługę plików i relacji zapewniają klucze `media` i `relation`.

API startowe zwraca `fieldTypes` dla pól wbudowanych i rozszerzeń implementujących `PanelFieldType` oraz `blockTypes` dla bloków wbudowanych i rozszerzeń implementujących `PanelBlockType`. Lista `blocks` nadal zawiera klucze bloków wbudowanych. Panel pokazuje etykietę rozszerzenia i używa wbudowanych kontrolek `text`, `richtext`, `number`, `date`, `boolean` i `select` dla własnych typów pól. Dla kontrolki `select` ustaw opcje w modelu kolekcji. Pola bez obsługiwanej kontrolki są w panelu tylko do odczytu.

### Edytuj własne bloki w Formaku

Zarejestruj `PanelBlockType` metodą `Registry::block()`. Blok pojawi się w edytorze strony pod etykietą rozszerzenia. W `panelSchema()` opisz ustawienia za pomocą `key`, `label` i `input`. Panel obsługuje `text`, `textarea`, `number`, `boolean` i `select`. Każda opcja listy ma etykietę `label` i wartość `value` w postaci liczby, wartości logicznej albo niepustego tekstu bez białych znaków na początku lub końcu.

```php
public function panelSchema(): array
{
    return [
        ['key' => 'heading', 'label' => 'Nagłówek', 'input' => 'text'],
        ['key' => 'tone', 'label' => 'Akcent', 'input' => 'select', 'options' => [
            ['label' => 'Neutralny', 'value' => 0],
            ['label' => 'Wyróżniony', 'value' => 1],
        ]],
    ];
}
```

Do wartości tekstowych Laravel stosuje skonfigurowaną normalizację żądań. Identyfikatory większe niż zakres bezpiecznych liczb całkowitych JavaScript przekazuj jako tekst.

Jeśli blok przyjmuje dzieci, w edytorze można dodawać i przenosić do niego inne bloki. Zapisane ustawienia o innych typach kontrolek oraz ustawienia bloków bez metadanych panelu pozostają w szkicu. Do ich edycji użyj API lub edytora w aplikacji Laravel.

Od alpha.81 zapis szkicu sprawdza ustawienia bloków rozszerzeń. Wartość musi odpowiadać polu `input` z `panelSchema()`: tekst dla `text` i `textarea`, liczba dla `number`, wartość logiczna dla `boolean`, a dla `select` jedna z wartości opcji. Brak ustawienia albo wartość `null` są akceptowane. Aby dodać reguły walidacji Laravela, zaimplementuj `Formaku\Cms\Contracts\ValidatedBlockType` i zwróć je z metody `rules()`. Błędy są zgłaszane pod ścieżką bloku, na przykład `blocks.0.props.heading`.

Opublikowana strona pomija blok, którego typ nie jest już zarejestrowany, na przykład po usunięciu rozszerzenia, i renderuje pozostałe. Formaku zgłasza brakujący typ do obsługi wyjątków aplikacji najwyżej raz na godzinę dla danego typu bloku i rekordu. Zapis szkicu z niezarejestrowanym typem bloku jest nadal odrzucany.

`BlockType::schema()` pozostaje dostępna do walidacji w rozszerzeniu. Gdy `acceptsChildren()` zwraca `true`, Formaku przekazuje wyrenderowaną zawartość zagnieżdżoną jako drugi argument metody `render()`. Rozszerzenia służące do samego renderowania lub walidacji mogą nadal implementować `BlockType` lub `FieldType`.

Interfejsy `AiProvider` i `PipelineDriver` nie mają metod rejestracji. Starter korzysta z konkretnych usług integrujących AI i GitHuba.

## Sprawdź działanie w starterze

Po skopiowaniu plików i dodaniu providera oraz tras skopiuj [FormakuExtensionsExampleTest.php](examples/tests/Feature/FormakuExtensionsExampleTest.php) do `tests/Feature/` startera. Uruchom test na bazie SQLite w pamięci skonfigurowanej przez starter i z testowym kluczem aplikacji.

```sh
APP_KEY="base64:$(openssl rand -base64 32)" php artisan test --compact --filter=FormakuExtensionsExampleTest
```

Test obejmuje zapis szkicu, podgląd, publikację, kodowanie znaków HTML oraz uwierzytelnienie, zakres zasobu i walidację akcji w API panelu i na trasach gospodarza.

## Monitoruj instalację

`php artisan formaku:doctor` sprawdza instalację w chwili uruchomienia. Aby obserwować działające usługi, zainstaluj w aplikacji opcjonalny pakiet [Formaku Monitor](https://github.com/formaku/monitor). Jego repozytorium jest prywatne, więc Composer potrzebuje dostępu SSH do GitHuba.

```sh
composer config repositories.formaku-monitor vcs git@github.com:formaku/monitor.git
composer require formaku/monitor:dev-main
php artisan migrate
```

Pakiet dodaje adresy `GET /health/live`, `/health/ready` i `/health/status`, polecenie `php artisan formaku:monitor` oraz alerty przez e-mail albo webhook. Sprawdza bazę danych, migracje, cache, katalogi zapisu, scheduler i workera kolejki oraz odczytuje `formaku:doctor --json` razem z przyczynami z pola `errors`. Gdy zainstalowano Formaku Commerce, odczytuje także diagnostykę sklepu. Kontrole schedulera i kolejki opierają się na sygnale, który pakiet dodaje do harmonogramu co minutę, dlatego scheduler i worker kolejki muszą działać. Konfigurację dla Docker Compose i sondę hosta opisuje [instrukcja wdrożenia](../DEPLOYMENT.md#monitor-the-running-services).

W zarządzanej instalacji sprzedawcy wysyłaj w nagłówku `Host` żądań kontrolnych nazwę hosta sprzedawcy. Starter odpowiada innym hostom kodem HTTP 421, zanim uruchomi jakąkolwiek trasę.

## Uruchom odizolowaną instalację sprzedawcy

Ustaw `FORMAKU_MERCHANT_ID` i `FORMAKU_MERCHANT_HOST`, aby włączyć tryb zarządzany startera Laravel. Uruchom osobną grupę procesów dla każdego sprzedawcy. Aplikacja HTTP, worker kolejki i harmonogram muszą używać tej samej konfiguracji sprzedawcy. Oddziel katalogi zapisu i cache startowy Laravel. Użyj osobnego konta systemowego lub kontenera z dostępem wyłącznie do woluminu tej instalacji.

Użyj [rejestru Formaku Platform](https://github.com/formaku/platform), aby uzyskać UUID sprzedawcy, zweryfikowaną nazwę hosta oraz nazwę bazy i roli PostgreSQL. Osobno podaj adres serwera bazy, hasło roli i unikalny `APP_KEY`. Właścicielem bazy powinna być osobna rola logowania sprzedawcy. Odbierz uprawnienie `CONNECT` roli `PUBLIC`. Role sprzedawców nie powinny mieć uprawnień superużytkownika ani członkostwa w rolach innych sprzedawców. Uruchom migracje CMS i Commerce na bazie tej instalacji.

Skonfiguruj HTTPS na reverse proxy i kieruj każdą zatwierdzoną domenę do właściwego procesu HTTP. Przekazuj kanoniczną nazwę hosta w nagłówku `Host`, również w zapytaniach sprawdzających stan usługi. Przy niezgodnym hoście starter zwraca HTTP 421 przed uruchomieniem sesji. Sesje, cache, limity żądań i kolejki korzystają z bazy danego sprzedawcy. Udostępniaj przesłane pliki przez trasy CMS.

Aby włączyć opcjonalny [pakiet Commerce](https://github.com/formaku/commerce), zainstaluj go w starterze i ustaw `FORMAKU_COMMERCE_ENABLED=true`. W starterze dostęp do katalogu Commerce, stanów magazynowych, obsługi zamówień i potwierdzania płatności wymaga roli administratora.

Po zainstalowaniu zależności Composer startera uruchom test z repozytorium CMS. Wskaż katalogi robocze Commerce 0.2 i Platform 0.2. Skrypt uruchamia tymczasowy PostgreSQL na adresie lokalnym, tworzy dwie bazy z osobnymi rolami logowania, sprawdza rejestr Platform i izolację CMS, a następnie zamyka serwer.

```sh
scripts/test-merchant-isolation.sh /sciezka/do/commerce /sciezka/do/platform
```

Jeśli `pg_config --bindir` nie wskazuje katalogu serwera PostgreSQL, ustaw `FORMAKU_TEST_PG_BIN` na katalog z `initdb`, `pg_ctl` i `psql`. PHP CLI wymaga sterownika PDO PostgreSQL.

Aby wymagać sprawdzenia katalogu panelu, dodaj `--require-panel-catalog` przed ścieżkami i wskaż checkout Commerce zawierający katalog. Test zakończy się błędem, jeśli katalogu zabraknie.
