# Rozszerzanie Formaku w Laravelu

[English](README.md)

Przykłady korzystają ze startera na Laravelu 13. Integracja z panelem wymaga Formaku alpha.32 lub nowszej wersji. Użyj lokalnej instalacji z osobną bazą testową. W samodzielnej aplikacji najpierw ustaw guard i autoryzację Formaku zgodnie z [instrukcją integracji pakietu](../../README.md#package-integration).

## Instalacja w istniejącej aplikacji Laravel

W aplikacji Laravel 13 z PHP 8.4 lub nowszym dodaj [repozytorium Composer Formaku](https://formaku.eu/composer/packages.json) i zainstaluj wydanie.

```sh
composer config repositories.formaku composer https://formaku.eu/composer
composer require 'formaku/cms:0.1.0-alpha.60' --prefer-dist
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

Polecenie sprawdza ustawienia mechanizmu sesji i funkcji autoryzacji, historię migracji Formaku oraz zgodność opublikowanych plików `app.js` i `main.css` z zainstalowanym pakietem. Wyświetla też stan tras publicznych. Gdy któraś kontrola się nie powiedzie, polecenie zwraca niezerowy kod wyjścia.

Jeśli brakuje plików panelu w kopii źródeł, zbuduj je w głównym katalogu repozytorium Formaku. Brakujące lub nieaktualne pliki publiczne odśwież przez `php artisan formaku:install`. Przy zaległych migracjach postępuj zgodnie z [instrukcją aktualizacji](../DEPLOYMENT.md#upgrade-an-existing-alpha-installation). Aby sprawdzić uprawnienia konkretnej roli, zaloguj się w aplikacji jako użytkownik tej roli i otwórz panel.

### Aktualizacja istniejącej instalacji Composer

Po aktualizacji do alpha.60 wykonaj te polecenia w aplikacji Laravel przed przywróceniem ruchu.

```sh
php artisan formaku:install
php artisan migrate --force
```

Od alpha.31 `formaku:install` usuwa skompilowane kopie szablonów Blade pakietu. Widoki aplikacji gospodarza pozostają w cache. Jeśli wdrożenie kompiluje widoki z wyprzedzeniem, uruchom następnie `php artisan view:cache`. Przy instalacji alpha.30 po aktualizacji wykonaj `php artisan view:clear`.

### Włącz zaplanowaną publikację w aplikacji Laravel

Po instalacji Formaku dodaj poniższy wpis w pliku `routes/console.php` aplikacji.

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('formaku:publish-scheduled')->everyMinute()->withoutOverlapping();
```

Sprawdź wpis poleceniem `php artisan schedule:list` i uruchom scheduler Laravel w swoim środowisku. Komenda publikuje zapisane treści, których termin już nadszedł. Jeśli aplikacja ma własny wpis publikujący treści Formaku, zastąp go tą komendą. Starter Formaku ma gotowy wpis.

Uruchom ręcznie `php artisan formaku:publish-scheduled`, aby zobaczyć podsumowanie. Po błędzie szkic zachowuje termin, a komenda zwraca niezerowy kod po przetworzeniu pozostałych szkiców. Użyj identyfikatora z wyniku i logu aplikacji, aby ustalić przyczynę. Popraw szkic i ustaw nowy termin albo anuluj publikację. Zmiana wersji w trakcie pracy powoduje pominięcie rekordu.

### Instalacja z lokalnego ZIP-u

Dla Laravel 13 i PHP 8.4 lub nowszego pobierz `formaku-cms-v0.1.0-alpha.60.zip` i plik `.sha256` z [załączników wydania na GitHubie](https://github.com/formaku/formaku/releases/tag/v0.1.0-alpha.60). Umieść oba w katalogu `formaku-packages` w głównym katalogu aplikacji. Sprawdź sumę kontrolną i zarejestruj katalog jako [repozytorium artefaktów Composer](https://getcomposer.org/doc/05-repositories.md#artifact).

```sh
(cd formaku-packages && sha256sum -c formaku-cms-v0.1.0-alpha.60.zip.sha256)
composer config repositories.formaku-artifacts artifact ./formaku-packages
composer require 'formaku/cms:0.1.0-alpha.60' --prefer-dist
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

W kolekcji danych `requests` ustaw `definition.portal_attachments` na `true`. Zgłoszenie może zawierać do trzech plików PDF, JPEG lub PNG o rozmiarze do 5 MiB każdy. Pliki trafiają na dysk `local` w Laravelu. Zachowaj jego prywatność i twórz kopie razem z bazą danych. W aplikacji instalowanej przez Composer ustaw w PHP co najmniej `upload_max_filesize=5M`, `post_max_size=20M` i `max_file_uploads=4`. Sprawdź też limit żądań w reverse proxy. Obraz Docker ma skonfigurowane limity PHP.

Jeśli migracja tabeli załączników wprowadzona w alpha.37 jest nadal oczekująca, zastosuj ją przed uruchomieniem nowej wersji. Przy powrocie do kodu alpha.36 zachowaj tabelę załączników i pliki, aby odzyskać dostęp po ponownej aktualizacji.

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

`BlockType::schema()` pozostaje dostępna do walidacji w rozszerzeniu. Gdy `acceptsChildren()` zwraca `true`, Formaku przekazuje wyrenderowaną zawartość zagnieżdżoną jako drugi argument metody `render()`. Rozszerzenia służące do samego renderowania lub walidacji mogą nadal implementować `BlockType` lub `FieldType`.

Interfejsy `AiProvider` i `PipelineDriver` nie mają metod rejestracji. Starter korzysta z konkretnych usług integrujących AI i GitHuba.

## Sprawdź działanie w starterze

Po skopiowaniu plików i dodaniu providera oraz tras skopiuj [FormakuExtensionsExampleTest.php](examples/tests/Feature/FormakuExtensionsExampleTest.php) do `tests/Feature/` startera. Uruchom test na bazie SQLite w pamięci skonfigurowanej przez starter i z testowym kluczem aplikacji.

```sh
APP_KEY="base64:$(openssl rand -base64 32)" php artisan test --compact --filter=FormakuExtensionsExampleTest
```

Test obejmuje zapis szkicu, podgląd, publikację, kodowanie znaków HTML oraz uwierzytelnienie, zakres zasobu i walidację akcji w API panelu i na trasach gospodarza.
