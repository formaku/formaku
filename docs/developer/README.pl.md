# Rozszerzanie Formaku w Laravelu

[English](README.md)

Przykłady dotyczą Formaku v0.1.0-alpha.14 i startera na Laravelu 13. Użyj lokalnej instalacji z osobną bazą testową. W samodzielnej aplikacji najpierw ustaw guard i autoryzację Formaku zgodnie z [instrukcją integracji pakietu](../../README.md#package-integration).

## Instalacja w istniejącej aplikacji Laravel

Dla Laravel 13 i PHP 8.4 lub nowszego pobierz `formaku-cms-v0.1.0-alpha.23.zip` i plik `.sha256` z [załączników wydania na GitHubie](https://github.com/formaku/formaku/releases/tag/v0.1.0-alpha.23). Umieść oba w katalogu `formaku-packages` w głównym katalogu aplikacji. Sprawdź sumę kontrolną i zarejestruj katalog jako [repozytorium artefaktów Composer](https://getcomposer.org/doc/05-repositories.md#artifact).

```sh
(cd formaku-packages && sha256sum -c formaku-cms-v0.1.0-alpha.23.zip.sha256)
composer repo add formaku-artifacts artifact ./formaku-packages
composer require 'formaku/cms:0.1.0-alpha.23' --prefer-dist
php artisan formaku:install
php artisan migrate
```

Na macOS w poleceniu sprawdzającym sumę użyj `shasum -a 256 -c`. Zachowaj ZIP pod tą samą ścieżką względną w środowiskach, w których uruchamiasz `composer install`. Archiwum zawiera zbudowane zasoby, fonty panelu i informacje o ich licencjach.

Skonfiguruj guard aplikacji i funkcję `authorize` w `config/formaku.php` zgodnie z politykami aplikacji. Otwórz `/formaku` jako uprawniony użytkownik. Włącz publiczne trasy Formaku, gdy chcesz przez nie publikować treści.

## Zarejestruj rozszerzenia

1. Skopiuj pliki PHP z [examples/app](examples/app) do odpowiadających im ścieżek w katalogu `app/` aplikacji.
2. Dodaj `App\Providers\FormakuExamplesServiceProvider::class` do tablicy zwracanej w pliku `bootstrap/providers.php`.
3. [Przykładowy provider](examples/app/Providers/FormakuExamplesServiceProvider.php) rejestruje rozszerzenia w metodzie `boot(Registry $registry)`. Formaku udostępnia współdzielony rejestr podczas wywołania `register()` swojego providera. Wybierz prefiks kluczy właściwy dla projektu. Ponowna rejestracja tego samego klucza zastępuje wpis w danej mapie rejestru.

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

## Wywołaj zasób i akcję z aplikacji

Rejestr przechowuje [OwnProfileResource](examples/app/Formaku/OwnProfileResource.php) i [PreviewNoticeAction](examples/app/Formaku/PreviewNoticeAction.php). Aplikacja zapewnia interfejs i trasy dla obu rozszerzeń. Skopiuj [przykładowe trasy](examples/routes/formaku-extension-examples.php) do katalogu `routes/` aplikacji i dodaj poniższy wiersz na końcu `routes/web.php`.

```php
require __DIR__.'/formaku-extension-examples.php';
```

| Trasa aplikacji | Działanie |
| --- | --- |
| `GET /extension-examples/profile` | Pobiera użytkownika z sesji, ogranicza zapytanie do jego konta i zwraca wyłącznie ID i nazwę. |
| `POST /extension-examples/preview-notice` | Przyjmuje JSON z tekstowym polem `message`, waliduje dane i zwraca podgląd po usunięciu skrajnych spacji. |

Trasy korzystają z sesji i ochrony CSRF Laravela. Korzystają ze stałych kluczy rejestru i ustalają wykonawcę na podstawie sesji. Zastosuj polityki aplikacji przed udostępnieniem zasobu biznesowego lub wywołaniem akcji ze skutkiem ubocznym. Umieść ograniczenie do właściciela lub tenanta w zapytaniu i jawnie wybierz zwracane pola. Po stronie klienta traktuj wynik akcji jak dane tekstowe.

Rejestr przechowuje też wpisy `FieldType`. Walidator kolekcji i edytor obecnie korzystają z własnych wbudowanych list typów. Interfejsy `AiProvider` i `PipelineDriver` nie mają metod rejestracji. Starter korzysta z konkretnych usług integrujących AI i GitHuba.

## Sprawdź działanie w starterze

Po skopiowaniu plików i dodaniu providera oraz tras skopiuj [FormakuExtensionsExampleTest.php](examples/tests/Feature/FormakuExtensionsExampleTest.php) do `tests/Feature/` startera. Uruchom test na bazie SQLite w pamięci skonfigurowanej przez starter i z testowym kluczem aplikacji.

```sh
APP_KEY="base64:$(openssl rand -base64 32)" php artisan test --compact --filter=FormakuExtensionsExampleTest
```

Test obejmuje zapis szkicu, podgląd, publikację, kodowanie znaków HTML, uwierzytelnienie, zakres zasobu i walidację akcji.
