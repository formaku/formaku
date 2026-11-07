# Instrukcja Formaku / User guide

## Uruchomienie

Zainstaluj starter według [instrukcji uruchomienia](../../README.md#run-locally). Utwórz administratora komendą `formaku:admin` i ustaw hasło o długości co najmniej 12 znaków. Na świeżej instalacji `formaku:demo` tworzy przykładowe kolekcje i publikuje stronę `/p/start`. Do panelu `/formaku` zaloguj się na własne konto administratora.

## Praca

Wybierz kolekcję i dodaj rekord. Zapis tworzy nową rewizję. W treściach poprzednia publikacja pozostaje widoczna do użycia „Opublikuj treść”. Podgląd wymaga uprawnień panelu. Historia pozwala przywrócić rewizję do szkicu. Konflikt zapisu wymaga ponownego pobrania i porównania zmian.

Zaplanowane publikacje wymagają działającego schedulera. Lokalnie uruchom go poleceniem `php artisan schedule:work` w katalogu startera.

## Buduj

Utwórz kolekcję danych lub treści, nadaj stabilne klucze i dodaj pola. Dla procesu wskaż stany i dozwolone przejścia. Aktywacja tworzy panel listy i edycji. Usunięcie pola lub zmiana jego typu wymaga migracji przygotowanej w Laravel. Definicje można eksportować i importować w ustawieniach.

## Portal

Po włączeniu rejestracji użytkownik tworzy konto klienta i potwierdza e-mail. Klient składa zgłoszenie w `/portal` i widzi własne zgłoszenia. Personel obsługuje je w panelu i może przypisać zgłoszenie pracownikowi w trakcie zmiany statusu. Notatki prywatne nie trafiają do odpowiedzi klienta. Poczta wymaga skonfigurowanego transportu oraz działającej kolejki i schedulera.

## AI

Administrator ustawia klucz i model na serwerze. W zakładce AI wybierz dostawcę i opisz stronę. Odśwież stan zadania po wykonaniu przez worker. Gotową propozycję można zastosować jako nowy szkic, poprawić i osobno opublikować. Klucze nie są widoczne w panelu ani w eksporcie.

## Asystent witryny w wersji rozwojowej

W istniejącej instalacji uruchom migracje przed użyciem asystenta. W menu panelu wybierz „Asystent witryny”, wskaż publiczną kolekcję treści bez pól prywatnych i relacji, a następnie pobierz adapter i wygeneruj kod. W katalogu pobranego adaptera uruchom polecenie pokazane w panelu i wpisz kod w terminalu. Po ponownym uruchomieniu Codexa możesz poprosić go o utworzenie lub edycję szkicu w tej kolekcji.

Kod parowania jest jednorazowy i wygasa po pięciu minutach. Dostęp asystenta wygasa po 30 dniach. Prawo do publikacji włączasz osobno podczas parowania. Asystent zgłasza konflikt przy próbie edycji strony z zaplanowaną publikacją. Cofnij połączenie w panelu, gdy przestajesz go używać lub przed zmianą roli użytkownika.

## Wydania

Wskaż pełny SHA zatwierdzonego commita z gałęzi `main`. „Zbuduj wydanie” wysyła żądanie uruchomienia workflow w skonfigurowanym repozytorium GitHub. Link otwiera listę GitHub Actions. Odszukaj przebieg po identyfikatorze wydania i sprawdź jego wynik oraz digest obrazu w podsumowaniu. Wdrożenie obrazu po digescie odbywa się w osobnym workflow opisanym w [instrukcji wdrożenia](../DEPLOYMENT.md).

## English quick guide

Work: create and edit collection records; save a draft, inspect its preview and publish explicitly. Build: define stable field keys, relations and workflow transitions, then activate the configuration. AI produces proposals which can be applied as a new page draft. Releases trigger the configured GitHub workflow; actual build/deployment state must be verified in Actions. Customer portal access is scoped to the signed-in owner.

## Dostęp do kolekcji

W edycji modelu wybierz „Skonfiguruj dostęp ról”. Dla redaktora i publikującego ustaw zakres rekordów: wszystkie, własne, przypisane albo brak dostępu oraz osobno prawo edycji. Po włączeniu reguł role bez wpisu nie mają dostępu. Administrator buildera zachowuje pełny dostęp. Te same ograniczenia obejmują API, listy, podgląd, historię i relacje.
