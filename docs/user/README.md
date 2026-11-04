# Instrukcja Formaku / User guide

## Uruchomienie

Zainstaluj starter według README. Utwórz administratora komendą `formaku:admin`, a przykładowe kolekcje przez `formaku:demo`. Konto demonstracyjne nie powstaje automatycznie.

## Praca

Wybierz kolekcję i dodaj rekord. Zapis tworzy nową rewizję. W treściach poprzednia publikacja pozostaje widoczna do użycia „Opublikuj treść”. Podgląd wymaga uprawnień panelu. Historia pozwala przywrócić rewizję do szkicu. Konflikt zapisu wymaga ponownego pobrania i porównania zmian.

## Buduj

Utwórz kolekcję danych lub treści, nadaj stabilne klucze i dodaj pola. Dla procesu wskaż stany i dozwolone przejścia. Aktywacja tworzy panel listy i edycji. Usunięcie pola lub zmiana jego typu wymaga migracji przygotowanej w Laravel. Definicje można eksportować i importować w ustawieniach.

## Portal

Po włączeniu rejestracji użytkownik tworzy konto klienta i potwierdza e-mail. Klient widzi własne zgłoszenia; personel obsługuje je z panelu. Notatki prywatne nie trafiają do odpowiedzi klienta. Poczta wymaga skonfigurowanego transportu i działającej kolejki oraz schedulera.

## AI

Administrator ustawia klucz i model na serwerze. W zakładce AI wybierz dostawcę i opisz stronę. Odśwież stan zadania po wykonaniu przez worker. Gotową propozycję można zastosować jako nowy szkic, poprawić i osobno opublikować. Klucze nie są widoczne w panelu ani w eksporcie.

## Wydania

Wskaż pełny SHA zatwierdzonego commita. „Zbuduj wydanie” uruchamia workflow w skonfigurowanym repozytorium GitHub. Link prowadzi do rzeczywistego przebiegu Actions. Samo przyjęcie żądania nie oznacza zbudowania ani wdrożenia obrazu. Wdrożenie istniejącego obrazu jest osobnym workflow opisanym w docs/DEPLOYMENT.md.

## English quick guide

Work: create and edit collection records; save a draft, inspect its preview and publish explicitly. Build: define stable field keys, relations and workflow transitions, then activate the configuration. AI produces proposals which can be applied as a new page draft. Releases trigger the configured GitHub workflow; actual build/deployment state must be verified in Actions. Customer portal access is scoped to the signed-in owner.

## Dostęp do kolekcji

W edycji modelu wybierz „Skonfiguruj dostęp ról”. Dla redaktora i publikującego ustaw zakres rekordów: wszystkie, własne, przypisane albo brak dostępu oraz osobno prawo edycji. Po włączeniu reguł role bez wpisu nie mają dostępu. Administrator buildera zachowuje pełny dostęp. Te same ograniczenia obejmują API, listy, podgląd, historię i relacje.
