# Instrukcja Formaku / User guide

## Uruchomienie

Zainstaluj starter według [instrukcji uruchomienia](../../README.md#run-locally). Utwórz administratora komendą `formaku:admin` i ustaw hasło o długości co najmniej 12 znaków. Na świeżej instalacji `formaku:demo` tworzy przykładowe kolekcje i publikuje stronę `/p/start`. Do panelu `/formaku` zaloguj się na własne konto administratora.

## Praca

Od wersji `0.1.0-alpha.16` wybierz **Polski** lub **English** w polu języka w nagłówku panelu. Przeglądarka zapamięta wybór. Treści, nazwy kolekcji i własne etykiety pól zachowają swój język.

Wybierz kolekcję i dodaj rekord. Zapis tworzy nową rewizję. W treściach poprzednia publikacja pozostaje widoczna do użycia „Opublikuj treść”. Podgląd wymaga uprawnień panelu. Historia pozwala przywrócić rewizję do szkicu. Konflikt zapisu wymaga ponownego pobrania i porównania zmian.

Od wersji `0.1.0-alpha.5` po zapisaniu szkicu wybierz **Pokaż podgląd**, aby zobaczyć stronę w panelu. Przełącz szerokość między komputerem (1280 px), tabletem (768 px) i telefonem (390 px). Podgląd korzysta z ostatniego zapisanego szkicu, w tym z jego metadanych SEO. Możesz go też otworzyć w nowej karcie.

### Kontenery i kolumny

Od wersji `0.1.0-alpha.6` dodaj blok kontenera lub kolumn, a następnie wybierz typ bloku wewnątrz układu. Przyciski przy bloku zmieniają jego kolejność w tym samym układzie albo go usuwają. Kontener może układać treść pionowo, a kolumny mieszczą dwa lub trzy bloki obok siebie przy odpowiedniej szerokości. Na węższej przestrzeni liczba kolumn maleje. Starsze układy bez ustawienia zachowują układ automatyczny.

Kliknij **Powiel blok**, aby dodać kopię bezpośrednio za wybranym blokiem. Możesz powielać bloki na głównym poziomie i wewnątrz układu. Kopia kontenera lub kolumn zawiera wszystkie bloki wewnętrzne. Zmiany w kopii nie modyfikują oryginału, a oba bloki obrazu korzystają z tego samego pliku w bibliotece. Zapisz szkic i sprawdź podgląd przed publikacją.

Kliknij **Przenieś blok** i wybierz **Poziom strony**, kontener albo kolumny. Blok trafia na koniec wybranej listy razem z zawartością. Okno pokazuje tylko miejsca, które mogą go przyjąć. Po przeniesieniu możesz zmienić kolejność w nowej liście, zapisać szkic i sprawdzić podgląd.

Aby przywrócić ostatni usunięty blok, kliknij **Cofnij usunięcie bloku** obok nagłówka **Sekcje strony**. Blok wróci z zawartością i odwołaniami do mediów. Polecenie działa także po zapisie automatycznym, dopóki edytujesz ten szkic. Zapisz i opublikuj szkic, aby przywrócony blok był widoczny publicznie.

Od wersji `0.1.0-alpha.27` jeśli zmienisz zapisany szkic w trakcie odświeżania panelu po publikacji, zapis automatyczny poczeka na zakończenie odświeżania. Nowa zmiana pozostanie w szkicu do kolejnej publikacji.

Zaplanowane publikacje wymagają działającego schedulera. Lokalnie uruchom go poleceniem `php artisan schedule:work` w katalogu startera.

Od wersji `0.1.0-alpha.4` publikacja publicznej strony pod nowym adresem zapisuje przekierowanie 301 ze starego adresu. Przy kolejnej zmianie wcześniejsze adresy prowadzą bezpośrednio do bieżącej publikacji. Jeśli inna strona opublikuje się pod dawnym adresem, będzie tam wyświetlana jej treść. Ukrycie kolekcji wyłącza także przekierowania do jej stron.

### Wersje językowe stron

Od alpha.30 zapisz każdą wersję językową jako osobną stronę w tej samej publicznej kolekcji treści. W sekcji **Wersje językowe** wpisz **Język strony** (np. `pl` lub `en-GB`) i ten sam **Klucz grupy tłumaczeń** na obu stronach (np. `o-nas`). Wybierz **Zapisz powiązanie**. Potrzebujesz uprawnień do zapisu rekordu i publikowania.

Każda strona zachowuje osobny szkic i adres. Po opublikowaniu co najmniej dwóch stron z grupy pojawi się przełącznik języków, a kod HTML otrzyma odnośniki `hreflang`. Zmiana powiązania jest widoczna od razu na opublikowanych stronach. Aby odłączyć stronę, wyczyść oba pola i zapisz powiązanie.

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

Od wersji `0.1.0-alpha.14` w tym samym miejscu wybierz **Jasny** lub **Ciemny** w polu **Motyw witryny** i zapisz ustawienia. Motyw stosuje się do publicznych stron i podglądu szkicu. Od alpha.28 opcja **Zgodny z systemem** dopasowuje motyw do preferencji odwiedzającego.

Od alpha.29 w polu **Akcent kolorystyczny** wybierz **Formaku**, **Niebieski** albo **Śliwkowy**. Ustawienie zmienia kolory wyróżnień i przycisków na stronach publicznych oraz w podglądzie.

### Metadane SEO

Od wersji `0.1.0-alpha.3` otwórz **Buduj**, wybierz **Edytuj model** przy publicznej kolekcji treści i w sekcji **Metadane SEO** przypisz pola tekstowe do tytułu oraz opisu. Zapisz konfigurację. Redaktor może uzupełnić te pola w rekordzie, a publikacja udostępni wartości z opublikowanej rewizji. Pola prywatne są wykluczone z listy. Pusty tytuł korzysta z publicznego tytułu strony albo jej slugu, a pusty opis pomija znacznik opisu.

### Mapa witryny

Od wersji `0.1.0-alpha.3` starter udostępnia `/sitemap.xml` ze stroną główną i opublikowanymi stronami z publicznych kolekcji treści. Ustaw `APP_URL` na publiczny adres aplikacji, łącznie z podkatalogiem, jeśli go używasz. Przy większej liczbie stron mapa zwraca indeks części. Ustaw `FORMAKU_SITEMAP=false`, jeśli aplikacja ma własną mapę. W integracji samej paczki włącz `public_routes` i `sitemap` w konfiguracji Formaku.

## Rozszerzenia aplikacji

Od alpha.32 aplikacja Laravel może udostępnić w panelu własne zasoby i akcje. Otwórz **Rozszerzenia**, wybierz zasób, aby przejrzeć rekordy, lub wybierz akcję, uzupełnij pola i wybierz **Uruchom akcję**. Dostęp do każdej pozycji określa aplikacja gospodarza. Konfigurację opisuje [przewodnik integracji](../developer/README.pl.md#pokaż-zasoby-i-akcje-laravel-w-panelu).

## Portal

Po włączeniu rejestracji użytkownik tworzy konto klienta i potwierdza e-mail. Klient składa zgłoszenie w `/portal` i widzi własne zgłoszenia. Personel obsługuje je w panelu i może przypisać zgłoszenie pracownikowi w trakcie zmiany statusu. Notatki prywatne nie trafiają do odpowiedzi klienta. Poczta wymaga skonfigurowanego transportu oraz działającej kolejki i schedulera.

## AI

Administrator ustawia klucz i model na serwerze. W zakładce AI wybierz dostawcę i opisz stronę. Odśwież stan zadania po wykonaniu przez worker. Gotową propozycję można zastosować jako nowy szkic, poprawić i osobno opublikować. Klucze nie są widoczne w panelu ani w eksporcie.

## Asystent witryny od wersji 0.1.0-alpha.2

W istniejącej instalacji uruchom migracje przed użyciem asystenta. W menu panelu wybierz „Asystent witryny”, wskaż publiczną kolekcję treści bez pól prywatnych i relacji, a następnie pobierz adapter i wygeneruj kod. W katalogu pobranego adaptera uruchom polecenie pokazane w panelu i wpisz kod w terminalu. Po ponownym uruchomieniu Codexa możesz poprosić go o utworzenie lub edycję szkicu w tej kolekcji.

Kod parowania jest jednorazowy i wygasa po pięciu minutach. Dostęp asystenta wygasa po 30 dniach. Prawo do publikacji włączasz osobno podczas parowania. Asystent zgłasza konflikt przy próbie edycji strony z zaplanowaną publikacją. Cofnij połączenie w panelu, gdy przestajesz go używać lub przed zmianą roli użytkownika.

Od wersji `0.1.0-alpha.17` w innym kliencie MCP obsługującym stdio dopisz `--print-config` do polecenia parowania z panelu. Wpisz jednorazowy kod w terminalu i przenieś wygenerowany wpis `mcpServers` do konfiguracji klienta. Jeśli klient używa innego formatu, skopiuj z wpisu wartości `command` i `args`. Uruchom ponownie klienta. Token pozostaje w lokalnym pliku połączenia, a wygenerowany wpis zawiera ścieżkę do tego pliku.

Od wersji `0.1.0-alpha.17` z aktualnym adapterem możesz poprosić agenta o sprawdzenie pól kolekcji i walidację szkicu z wbudowanych bloków przed zapisem. Po zapisaniu otwórz podany przez agenta link do podglądu w przeglądarce, w której masz aktywną sesję Formaku.

Od wersji `0.1.0-alpha.20` pobierz nową kopię adaptera z panelu i uruchom `node formaku-assistant.mjs update ADRES_Z_PANELU` w katalogu pobranego pliku. Użyj adresu dotychczasowego połączenia. Uruchom ponownie klienta MCP. Jeśli połączenie wygasło lub zostało cofnięte, sparuj je ponownie poleceniem `setup`.

Jeśli kolekcja ma zapisane szablony stron, agent może wybrać jeden z nich i użyć jego sekcji w nowym szkicu. Sekcje edytujesz dalej w szkicu.

Od wersji `0.1.0-alpha.18` agent może odczytać i ponownie użyć publiczne obrazy wskazane w aktualnie opublikowanych wersjach stron sparowanej kolekcji. Do bloku obrazu wstawia identyfikator wybranej grafiki. Walidacja i zapis odrzucają zbyt długie lub zbyt głęboko zagnieżdżone listy bloków.

> Przygotuj szkic strony oferty z szablonu „Oferta”. Dostosuj nagłówek, opis i przycisk kontaktu. Sprawdź dane przed zapisem, a potem podaj link do podglądu szkicu.

Jeżeli instalacja udostępnia publiczny katalog Commerce, agent może oprzeć treść strony na opublikowanych produktach. Katalog zwraca aktualne ceny i dostępność do odczytu. Sprawdzenie szkicu i zapis strony odbywają się w sparowanej kolekcji CMS.

Od wersji `0.1.0-alpha.19` agent odczytuje też warianty produktów, jeśli instalacja udostępnia katalog Commerce v2. Wynik obejmuje ich etykiety, SKU, ceny i dostępność. W poleceniu możesz wskazać etykietę lub SKU, żeby dopasować treść oferty do właściwego wariantu.

Od wersji `0.1.0-alpha.20`, jeśli sklep udostępnia publiczne kategorie, możesz poprosić o szkic oferty dla wybranej kategorii. Agent pobierze listę opublikowanych kategorii i zawęzi katalog wariantów do wskazanej pozycji.

Jeśli katalog Commerce zwraca publiczny adres karty produktu, poproś agenta o użycie go w przycisku na stronie ofertowej. Karta produktu otwiera się w tej samej witrynie.

## Wydania

Wskaż pełny SHA zatwierdzonego commita z gałęzi `main`. „Zbuduj wydanie” wysyła żądanie uruchomienia workflow w skonfigurowanym repozytorium GitHub. W wierszu wybranego wydania kliknij **Odśwież stan**, żeby odczytać stan jego budowy z GitHub Actions. Zarejestrowanie przebiegu może potrwać chwilę. Po udanej budowie otwórz konkretny przebieg i skopiuj digest obrazu z podsumowania. Wdrożenie obrazu po digescie odbywa się w osobnym workflow opisanym w [instrukcji wdrożenia](../DEPLOYMENT.md).

## English quick guide

Work: create and edit collection records; save a draft, inspect its preview and publish explicitly. Build: define stable field keys, relations and workflow transitions, then activate the configuration. AI produces proposals which can be applied as a new page draft. Under **Releases**, request a build, click **Refresh status** and open the matching GitHub Actions run for the image digest. Deployment is a separate workflow. Customer portal access is scoped to the signed-in owner.

Starting with `0.1.0-alpha.17`, for a stdio MCP client, append `--print-config` to the assistant pairing command shown in Formaku. Enter the one-time code in the terminal, copy the generated `mcpServers` entry or its `command` and `args` into the client configuration, and restart the client. The token stays in the local connection file.

Starting with `0.1.0-alpha.20`, download the new adapter from the panel and run `node formaku-assistant.mjs update ENDPOINT_FROM_PANEL` with the address of the existing connection. Restart the MCP client after running it. Pair again if the connection has expired or was revoked.

Starting with `0.1.0-alpha.9`, choose **Wybierz rekordy** (Choose records) for a relation field. Select records across pages, apply the selection, then save the draft. Cancel or press Escape to keep the previous selection. The picker lists records you can read and omits private titles and names. If you lose access to a linked record, remove the link or regain access before saving.

Starting with `0.1.0-alpha.5`, the editor shows the last saved draft, including its SEO metadata, in an embedded preview at desktop, tablet and phone widths. You can also open the preview in a new tab.

Starting with `0.1.0-alpha.6`, the editor supports adding, reordering and removing blocks within containers and columns. Containers can stack their children, and two- or three-column layouts adjust to available space. Existing layouts without a setting keep their automatic grid.

Starting with `0.1.0-alpha.7`, open **Media** to browse the library by page. Choose **Edytuj metadane** (Edit metadata) to update a filename or alternative text. The filename is used in the library and when downloading the file. The URL stays the same. Changes to a public image's alternative text also appear on published pages that use it. Editing public file metadata requires publishing permission.

Choose **Duplicate block** to insert a copy after the selected block at the same level. Duplicating a container or columns copies its nested blocks. You can edit the copy independently. Image blocks keep their existing media references. Save and preview the draft before publishing.

Choose **Move block** and select **Page level**, a container, or columns. The editor appends the block and its contents to the destination. The list shows only destinations that can accept the block. Reorder it in the destination as needed, then save and preview the draft.

Select **Undo block removal** next to **Page sections** to restore the last block you removed. It retains its contents and media references. You can undo after autosave while editing the same draft. Save and publish the draft to show the restored block publicly.

Starting with `0.1.0-alpha.27`, if you edit a saved draft while the panel refreshes after publication, autosave resumes when the refresh finishes. The edit stays in the draft until the next publication.

Starting with `0.1.0-alpha.8`, add an image block, including inside a container or columns, and choose **Wybierz obraz** (Choose image). Pick a public image by filename and thumbnail. Browse up to 30 images per page. Cancel or press Escape to keep the current selection. Enter a caption, save the draft and preview the page.

Starting with `0.1.0-alpha.16`, choose **Polski** or **English** in the panel header. The browser remembers the choice. Content, collection names, and custom field labels keep their original language.

Starting with `0.1.0-alpha.17`, a paired assistant can validate a draft made with built-in blocks before saving and return a link to its preview after saving. Open the link in a browser signed in to Formaku. The assistant can copy blocks from a template in the paired collection. When the installation exposes the public Commerce catalog, it can also read product names, current prices, and availability. Download the updated adapter from the panel to use the new tools.

Starting with `0.1.0-alpha.18`, a paired assistant can reuse public images referenced by current published revisions in its collection. The selected media ID goes into an image block. Draft validation and saving reject block lists that exceed the renderer’s size or nesting limits.

Starting with `0.1.0-alpha.19`, if the installation exposes the Commerce v2 catalog, the assistant can read published variants, including their labels, SKUs, prices, and availability. Specify a variant label or SKU when drafting an offer for a particular variant. Installations with the v1 catalog continue to return products.

Starting with `0.1.0-alpha.20`, the assistant can list published categories and filter variants by category slug when the installation exposes the corresponding public Commerce endpoints.

Starting with `0.1.0-alpha.10`, use **Wybierz plik** (Choose file) in a record media field. Browse up to 30 files per page and choose an image or PDF by name and visibility. Cancel or press Escape to keep the current selection. Save the draft after selecting a file. File access follows the existing permissions.

Starting with `0.1.0-alpha.11`, choose **Użycie pliku** (File usage) next to a file in the library. The list shows records you can access that use the file in a media field or image block. Draft and Published labels refer to current revisions. Historical revisions and private titles or names are omitted.

Starting with `0.1.0-alpha.12`, image blocks can offer smaller WebP variants of public images. The original URL, caption and alternative text remain available. See the [deployment guide](../DEPLOYMENT.md#generate-variants-for-existing-public-images) to process existing files.

Administrators can save the blocks from an existing draft as a page template. Save a draft with at least one block, enter a name under **Szablony stron** (Page templates), and choose **Zapisz jako szablon** (Save as template).

In the same collection, choose **Dodaj rekord** (Add record) and **Pokaż szablony kolekcji** (Show collection templates). Select a template and choose **Wstaw sekcje szablonu** (Insert template blocks). Enter a new title and URL, adapt the copied blocks, and save the draft. Publish it with the usual publication action. Editing the copy or deleting the template leaves the other drafts and pages intact.

Starting with `0.1.0-alpha.13`, administrators can open **Ustawienia → Menu i stopka** (Settings → Menu and footer) to edit and reorder links and set plain footer text. Links accept site paths or absolute HTTP(S) URLs. Saving applies changes to the shared site layout.

Starting with `0.1.0-alpha.14`, choose **Jasny** (Light) or **Ciemny** (Dark) under **Motyw witryny** (Site theme) in the same settings. Saving applies the theme to public pages and draft previews. Starting with alpha.28, **Follow system** uses each visitor’s preferred color scheme.

Starting with alpha.29, choose **Formaku**, **Blue**, or **Plum** under **Color accent** to change the accent on public pages and in draft previews.

Starting with alpha.30, save each language as a separate page in the same public content collection. Open **Language versions**, enter the **Page language** (for example `pl` or `en-GB`) and use the same **Translation group key** on each page (for example `about`). Select **Save translation link**. You need permission to edit the record and publish pages. Once at least two linked pages are public, visitors can switch between them. Changes to the link take effect immediately on published pages. Clear both fields and save to unlink a page.

Starting with `0.1.0-alpha.3`, use **Build → Edit model → SEO metadata** on a public content collection to map optional title and description fields. Choose non-private text fields, fill them in the record and publish the revision to make the values public.

The starter serves published public pages at `/sitemap.xml`. Set `APP_URL` to the public base URL, including any subdirectory. Set `FORMAKU_SITEMAP=false` to use a host-managed sitemap. Package integrations can enable the sitemap with the `public_routes` and `sitemap` config options.

Starting with `0.1.0-alpha.4`, publishing a public page under a new URL creates a 301 redirect from its previous URL. Earlier URLs point straight to the current publication. An active publication takes precedence when an old URL is reused. Redirects are unavailable while the collection is private.

Starting with alpha.32, the **Extensions** section lists resources and actions enabled for you by the host Laravel application. Choose a resource to browse its rows or choose an action, enter its fields, and select **Run action**. See the [integration guide](../developer/README.md#show-laravel-resources-and-actions-in-the-panel) for setup.

## Dostęp do kolekcji

W edycji modelu wybierz „Skonfiguruj dostęp ról”. Dla redaktora i publikującego ustaw zakres rekordów: wszystkie, własne, przypisane albo brak dostępu oraz osobno prawo edycji. Po włączeniu reguł role bez wpisu nie mają dostępu. Administrator buildera zachowuje pełny dostęp. Te same ograniczenia obejmują API, listy, podgląd, historię i relacje.
