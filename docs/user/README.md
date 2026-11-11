# Instrukcja Formaku / User guide

## Uruchomienie

Zainstaluj starter według [instrukcji uruchomienia](../../README.md#run-locally). Utwórz administratora komendą `formaku:admin` i ustaw hasło o długości co najmniej 12 znaków. Na świeżej instalacji `formaku:demo` tworzy przykładowe kolekcje i publikuje stronę `/p/start`. Do panelu `/formaku` zaloguj się na własne konto administratora.

## Praca

Wybierz kolekcję i dodaj rekord. Zapis tworzy nową rewizję. W treściach poprzednia publikacja pozostaje widoczna do użycia „Opublikuj treść”. Podgląd wymaga uprawnień panelu. Historia pozwala przywrócić rewizję do szkicu. Konflikt zapisu wymaga ponownego pobrania i porównania zmian.

Od wersji `0.1.0-alpha.5` po zapisaniu szkicu wybierz **Pokaż podgląd**, aby zobaczyć stronę w panelu. Przełącz szerokość między komputerem (1280 px), tabletem (768 px) i telefonem (390 px). Podgląd korzysta z ostatniego zapisanego szkicu, w tym z jego metadanych SEO. Możesz go też otworzyć w nowej karcie.

### Kontenery i kolumny

Od wersji `0.1.0-alpha.6` dodaj blok kontenera lub kolumn, a następnie wybierz typ bloku wewnątrz układu. Przyciski przy bloku zmieniają jego kolejność w tym samym układzie albo go usuwają. Kontener może układać treść pionowo, a kolumny mieszczą dwa lub trzy bloki obok siebie przy odpowiedniej szerokości. Na węższej przestrzeni liczba kolumn maleje. Starsze układy bez ustawienia zachowują układ automatyczny.

Zaplanowane publikacje wymagają działającego schedulera. Lokalnie uruchom go poleceniem `php artisan schedule:work` w katalogu startera.

Od wersji `0.1.0-alpha.4` publikacja publicznej strony pod nowym adresem zapisuje przekierowanie 301 ze starego adresu. Przy kolejnej zmianie wcześniejsze adresy prowadzą bezpośrednio do bieżącej publikacji. Jeśli inna strona opublikuje się pod dawnym adresem, będzie tam wyświetlana jej treść. Ukrycie kolekcji wyłącza także przekierowania do jej stron.

### Powiązane rekordy

Od wersji `0.1.0-alpha.9` w polu relacji wybierz **Wybierz rekordy**. Zaznacz rekordy na dowolnej stronie wyników i kliknij **Zastosuj wybór**, a potem zapisz szkic. **Anuluj** lub Esc przywraca poprzedni wybór. Selektor pokazuje tylko rekordy, do których masz dostęp. Zamiast prywatnego tytułu lub nazwy pokazuje dostępną etykietę albo identyfikator. Jeśli utracisz dostęp do powiązanego rekordu, przed zapisem usuń to powiązanie albo odzyskaj dostęp.

## Biblioteka mediów

Od wersji `0.1.0-alpha.7` otwórz **Media**. Użyj przycisków **Poprzednia** i **Następna**, jeśli biblioteka ma więcej niż jedną stronę. Przy pliku wybierz **Edytuj metadane**, popraw nazwę lub tekst alternatywny i zapisz. Nazwa jest widoczna w bibliotece i używana przy pobieraniu. Adres pliku pozostaje ten sam. Tekst alternatywny publicznego obrazu zmienia się także na opublikowanych stronach, które używają tego obrazu. Edycja metadanych publicznego pliku wymaga prawa do publikacji.

Od wersji `0.1.0-alpha.8` dodaj blok **Obraz**, także wewnątrz kontenera lub kolumn, i kliknij **Wybierz obraz**. Wybierz plik po nazwie i miniaturze. Lista zawiera publiczne obrazy i ma do 30 pozycji na stronę. **Anuluj** lub klawisz Esc zamyka wybór bez zmiany obrazu. Uzupełnij **Podpis obrazu**, zapisz szkic i sprawdź podgląd.

Od wersji `0.1.0-alpha.10` w polu mediów rekordu wybierz **Wybierz plik**. Wskaż obraz lub PDF po nazwie i sprawdź oznaczenie **Publiczny** albo **Prywatny**. Biblioteka pokazuje do 30 plików na stronę. **Anuluj** lub Esc zachowuje bieżący wybór. Po wybraniu pliku zapisz szkic. Dostęp do pliku zależy od jego dotychczasowych uprawnień.

Od wersji `0.1.0-alpha.11` kliknij **Użycie pliku** przy pliku w bibliotece. Lista pokazuje dostępne rekordy, które odwołują się do pliku w polu mediów lub bloku obrazu. Oznaczenia **Szkic** i **Publikacja** wskazują bieżące rewizje. Okno pomija starsze rewizje z historii oraz prywatne tytuły i nazwy.

Od wersji `0.1.0-alpha.12` blok obrazu udostępnia mniejsze warianty WebP, jeśli zostały wygenerowane dla publicznego pliku. Przeglądarka dobiera wariant do miejsca na stronie. Adres oryginału, podpis i tekst alternatywny pozostają dostępne. Aby przetworzyć wcześniejsze pliki, użyj [instrukcji wdrożenia](../DEPLOYMENT.md#generate-variants-for-existing-public-images).

## Buduj

Utwórz kolekcję danych lub treści, nadaj stabilne klucze i dodaj pola. Dla procesu wskaż stany i dozwolone przejścia. Aktywacja tworzy panel listy i edycji. Usunięcie pola lub zmiana jego typu wymaga migracji przygotowanej w Laravel. Definicje można eksportować i importować w ustawieniach.

### Szablony stron

Administrator może zapisać układ z istniejącego szkicu. Zapisz szkic z co najmniej jedną sekcją, wpisz nazwę w sekcji **Szablony stron** i wybierz **Zapisz jako szablon**.

W tej samej kolekcji wybierz **Dodaj rekord**, a następnie **Pokaż szablony kolekcji**. Wybierz szablon i kliknij **Wstaw sekcje szablonu**. Uzupełnij tytuł i adres, dostosuj sekcje i zapisz szkic. Opublikuj stronę zwykłą akcją publikacji. Zmiany w kopii i usunięcie szablonu nie zmieniają innych szkiców ani stron.

### Menu i stopka

Od wersji `0.1.0-alpha.13` jako administrator otwórz **Ustawienia → Menu i stopka**. Dodaj, edytuj lub przestaw linki nagłówka i stopki. Adres może być ścieżką witryny, np. `/p/start`, albo pełnym adresem HTTP(S). Możesz też dodać zwykły tekst stopki. Zapis od razu aktualizuje wspólny układ witryny. Pusta lista ukrywa linki w danym miejscu.

Od wersji `0.1.0-alpha.14` w tym samym miejscu wybierz **Jasny** lub **Ciemny** w polu **Motyw witryny** i zapisz ustawienia. Motyw stosuje się do publicznych stron i podglądu szkicu.

### Metadane SEO

Od wersji `0.1.0-alpha.3` otwórz **Buduj**, wybierz **Edytuj model** przy publicznej kolekcji treści i w sekcji **Metadane SEO** przypisz pola tekstowe do tytułu oraz opisu. Zapisz konfigurację. Redaktor może uzupełnić te pola w rekordzie, a publikacja udostępni wartości z opublikowanej rewizji. Pola prywatne są wykluczone z listy. Pusty tytuł korzysta z publicznego tytułu strony albo jej slugu, a pusty opis pomija znacznik opisu.

### Mapa witryny

Od wersji `0.1.0-alpha.3` starter udostępnia `/sitemap.xml` ze stroną główną i opublikowanymi stronami z publicznych kolekcji treści. Ustaw `APP_URL` na publiczny adres aplikacji, łącznie z podkatalogiem, jeśli go używasz. Przy większej liczbie stron mapa zwraca indeks części. Ustaw `FORMAKU_SITEMAP=false`, jeśli aplikacja ma własną mapę. W integracji samej paczki włącz `public_routes` i `sitemap` w konfiguracji Formaku.

## Portal

Po włączeniu rejestracji użytkownik tworzy konto klienta i potwierdza e-mail. Klient składa zgłoszenie w `/portal` i widzi własne zgłoszenia. Personel obsługuje je w panelu i może przypisać zgłoszenie pracownikowi w trakcie zmiany statusu. Notatki prywatne nie trafiają do odpowiedzi klienta. Poczta wymaga skonfigurowanego transportu oraz działającej kolejki i schedulera.

## AI

Administrator ustawia klucz i model na serwerze. W zakładce AI wybierz dostawcę i opisz stronę. Odśwież stan zadania po wykonaniu przez worker. Gotową propozycję można zastosować jako nowy szkic, poprawić i osobno opublikować. Klucze nie są widoczne w panelu ani w eksporcie.

## Asystent witryny od wersji 0.1.0-alpha.2

W istniejącej instalacji uruchom migracje przed użyciem asystenta. W menu panelu wybierz „Asystent witryny”, wskaż publiczną kolekcję treści bez pól prywatnych i relacji, a następnie pobierz adapter i wygeneruj kod. W katalogu pobranego adaptera uruchom polecenie pokazane w panelu i wpisz kod w terminalu. Po ponownym uruchomieniu Codexa możesz poprosić go o utworzenie lub edycję szkicu w tej kolekcji.

Kod parowania jest jednorazowy i wygasa po pięciu minutach. Dostęp asystenta wygasa po 30 dniach. Prawo do publikacji włączasz osobno podczas parowania. Asystent zgłasza konflikt przy próbie edycji strony z zaplanowaną publikacją. Cofnij połączenie w panelu, gdy przestajesz go używać lub przed zmianą roli użytkownika.

## Wydania

Wskaż pełny SHA zatwierdzonego commita z gałęzi `main`. „Zbuduj wydanie” wysyła żądanie uruchomienia workflow w skonfigurowanym repozytorium GitHub. Link otwiera listę GitHub Actions. Odszukaj przebieg po identyfikatorze wydania i sprawdź jego wynik oraz digest obrazu w podsumowaniu. Wdrożenie obrazu po digescie odbywa się w osobnym workflow opisanym w [instrukcji wdrożenia](../DEPLOYMENT.md).

## English quick guide

Work: create and edit collection records; save a draft, inspect its preview and publish explicitly. Build: define stable field keys, relations and workflow transitions, then activate the configuration. AI produces proposals which can be applied as a new page draft. Releases trigger the configured GitHub workflow; actual build/deployment state must be verified in Actions. Customer portal access is scoped to the signed-in owner.

Starting with `0.1.0-alpha.9`, choose **Wybierz rekordy** (Choose records) for a relation field. Select records across pages, apply the selection, then save the draft. Cancel or press Escape to keep the previous selection. The picker lists records you can read and omits private titles and names. If you lose access to a linked record, remove the link or regain access before saving.

Starting with `0.1.0-alpha.5`, the editor shows the last saved draft, including its SEO metadata, in an embedded preview at desktop, tablet and phone widths. You can also open the preview in a new tab.

Starting with `0.1.0-alpha.6`, the editor supports adding, reordering and removing blocks within containers and columns. Containers can stack their children, and two- or three-column layouts adjust to available space. Existing layouts without a setting keep their automatic grid.

Starting with `0.1.0-alpha.7`, open **Media** to browse the library by page. Choose **Edytuj metadane** (Edit metadata) to update a filename or alternative text. The filename is used in the library and when downloading the file. The URL stays the same. Changes to a public image's alternative text also appear on published pages that use it. Editing public file metadata requires publishing permission.

Starting with `0.1.0-alpha.8`, add an image block, including inside a container or columns, and choose **Wybierz obraz** (Choose image). Pick a public image by filename and thumbnail. Browse up to 30 images per page. Cancel or press Escape to keep the current selection. Enter a caption, save the draft and preview the page.

Starting with `0.1.0-alpha.10`, use **Wybierz plik** (Choose file) in a record media field. Browse up to 30 files per page and choose an image or PDF by name and visibility. Cancel or press Escape to keep the current selection. Save the draft after selecting a file. File access follows the existing permissions.

Starting with `0.1.0-alpha.11`, choose **Użycie pliku** (File usage) next to a file in the library. The list shows records you can access that use the file in a media field or image block. Draft and Published labels refer to current revisions. Historical revisions and private titles or names are omitted.

Starting with `0.1.0-alpha.12`, image blocks can offer smaller WebP variants of public images. The original URL, caption and alternative text remain available. See the [deployment guide](../DEPLOYMENT.md#generate-variants-for-existing-public-images) to process existing files.

Administrators can save the blocks from an existing draft as a page template. Save a draft with at least one block, enter a name under **Szablony stron** (Page templates), and choose **Zapisz jako szablon** (Save as template).

In the same collection, choose **Dodaj rekord** (Add record) and **Pokaż szablony kolekcji** (Show collection templates). Select a template and choose **Wstaw sekcje szablonu** (Insert template blocks). Enter a new title and URL, adapt the copied blocks, and save the draft. Publish it with the usual publication action. Editing the copy or deleting the template leaves the other drafts and pages intact.

Starting with `0.1.0-alpha.13`, administrators can open **Ustawienia → Menu i stopka** (Settings → Menu and footer) to edit and reorder links and set plain footer text. Links accept site paths or absolute HTTP(S) URLs. Saving applies changes to the shared site layout.

Starting with `0.1.0-alpha.14`, choose **Jasny** (Light) or **Ciemny** (Dark) under **Motyw witryny** (Site theme) in the same settings. Saving applies the theme to public pages and draft previews.

Starting with `0.1.0-alpha.3`, use **Build → Edit model → SEO metadata** on a public content collection to map optional title and description fields. Choose non-private text fields, fill them in the record and publish the revision to make the values public.

The starter serves published public pages at `/sitemap.xml`. Set `APP_URL` to the public base URL, including any subdirectory. Set `FORMAKU_SITEMAP=false` to use a host-managed sitemap. Package integrations can enable the sitemap with the `public_routes` and `sitemap` config options.

Starting with `0.1.0-alpha.4`, publishing a public page under a new URL creates a 301 redirect from its previous URL. Earlier URLs point straight to the current publication. An active publication takes precedence when an old URL is reused. Redirects are unavailable while the collection is private.

## Dostęp do kolekcji

W edycji modelu wybierz „Skonfiguruj dostęp ról”. Dla redaktora i publikującego ustaw zakres rekordów: wszystkie, własne, przypisane albo brak dostępu oraz osobno prawo edycji. Po włączeniu reguł role bez wpisu nie mają dostępu. Administrator buildera zachowuje pełny dostęp. Te same ograniczenia obejmują API, listy, podgląd, historię i relacje.
