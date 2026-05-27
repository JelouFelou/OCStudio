# OCStudio

OCStudio to aplikacja webowa do tworzenia, organizowania i opisywania postaci. Projekt pozwala budować własne szablony postaci, przypisywać postacie do folderów, zarządzać statusami oraz korzystać z panelu administracyjnego. Aplikacja została przygotowana jako projekt PHP + PostgreSQL uruchamiany w Dockerze.

## Spis Treści

- [Funkcje](#funkcje)
- [Screeny Aplikacji](#screeny-aplikacji)
- [Technologie](#technologie)
- [Architektura](#architektura)
- [Struktura Projektu](#struktura-projektu)
- [Uruchomienie](#uruchomienie)
- [Konta i Role](#konta-i-role)
- [Baza Danych](#baza-danych)
- [Backup Bazy](#backup-bazy)
- [Flow Aplikacji](#flow-aplikacji)
- [Najważniejsze Widoki](#najważniejsze-widoki)
- [Bezpieczeństwo i Prywatność](#bezpieczeństwo-i-prywatność)
- [Responsywność](#responsywność)
- [Dalszy Rozwój](#dalszy-rozwój)

## Funkcje

- Rejestracja i logowanie użytkowników.
- Sesja użytkownika oraz zabezpieczenie widoków wymagających logowania.
- Dashboard z podsumowaniem konta.
- Tworzenie, edycja, duplikowanie i usuwanie postaci.
- Organizowanie postaci w folderach.
- Zmiana nazwy folderu i usuwanie folderu po potwierdzeniu nazwą.
- Przenoszenie postaci między folderami.
- Wyszukiwanie postaci i folderów.
- Statusy postaci: `Do zrobienia`, `W trakcie`, `Gotowa`.
- Filtrowanie i przypisywanie filtrów do postaci.
- Tworzenie własnych szablonów postaci.
- Obsługa różnych typów pól w szablonach postaci:
  - tekst,
  - długi tekst,
  - lista,
  - wybór z listy,
  - zdjęcie,
  - galeria zdjęć,
  - tabela,
  - data.
- Podgląd postaci w formie strony opisowej.
- Warianty postaci.
- Upload grafik postaci.
- Domyślna grafika dla trybu jasnego i ciemnego.
- Ustawienia wyglądu:
  - light mode,
  - dark mode,
  - wybór koloru akcentu,
  - liczba kolumn w widoku postaci.
- Panel admina:
  - lista użytkowników,
  - informacje o liczbie postaci,
  - informacja o zajętym miejscu,
  - blokowanie konta,
  - planowanie usunięcia konta,
  - cofanie zaplanowanego usunięcia.
- System liczenia zajętości plików użytkownika.
- Responsywny interfejs z trybem mobilnym i menu burger.

## Screeny Aplikacji

### Logowanie

![Logowanie](docs/screenshots/01-login.png)

### Rejestracja

![Rejestracja](docs/screenshots/02-register.png)

### Dashboard

![Dashboard](docs/screenshots/03-dashboard.png)

### Lista Postaci + Folderów

![Lista postaci](docs/screenshots/04-characters.png)

### Tworzenie Postaci

![Tworzenie postaci](docs/screenshots/05-create-character.png)

### Podgląd Postaci

![Podgląd postaci](docs/screenshots/06-preview-character.png)

### Szablony postaci

![Szablony postaci](docs/screenshots/07-templates.png)

### Kreator Szablonu postaci

![Kreator szablonu postaci](docs/screenshots/08-template-creator.png)

### Ustawienia

![Ustawienia](docs/screenshots/09-settings.png)

### Panel Admina

![Panel admina](docs/screenshots/10-admin.png)

### Widok Mobilny

![Widok mobilny](docs/screenshots/11-mobile.png)

## Technologie

- PHP
- PostgreSQL
- Docker
- Docker Compose
- Nginx
- HTML
- CSS
- JavaScript
- Fetch API / AJAX
- Font Awesome
- pgAdmin

## Architektura

Projekt jest zorganizowany w stylu MVC:

- `controllers` odpowiadają za obsługę żądań HTTP i wybór odpowiednich widoków,
- `models` reprezentują dane używane w aplikacji,
- `repositories` odpowiadają za komunikację z bazą danych,
- `views` zawierają szablony HTML,
- `public/scripts` zawiera logikę JavaScript,
- `public/styles` zawiera style aplikacji,
- `docker` zawiera konfigurację kontenerów.

Routing aplikacji znajduje się w pliku `Routing.php`. Po otrzymaniu ścieżki aplikacja wybiera odpowiedni kontroler i akcję.

## Struktura Projektu

```text
.
├── docker/
│   ├── db/
│   │   ├── Dockerfile
│   │   └── init/
│   │       └── init.sql
│   ├── nginx/
│   └── php/
├── public/
│   ├── scripts/
│   ├── styles/
│   ├── uploads/
│   └── views/
├── scripts/
│   └── backup_database.ps1
├── src/
│   ├── controllers/
│   ├── models/
│   ├── repositories/
│   └── services/
├── config.php
├── Database.php
├── docker-compose.yaml
├── index.php
├── README.md
└── Routing.php
```

## Uruchomienie

### Wymagania

- Docker
- Docker Compose

### Start Aplikacji

W głównym folderze projektu uruchom:

```powershell
docker compose up --build
```

Po uruchomieniu aplikacja jest dostępna pod adresem:

```text
http://localhost:8080
```

pgAdmin jest dostępny pod adresem:

```text
http://localhost:5050
```

Dane logowania do pgAdmin:

```text
Email: admin@example.com
Hasło: admin
```

### Dane Połączenia z Bazą

```text
Host w Dockerze: db
Port w kontenerze: 5432
Port lokalny: 5433
Baza: db
Użytkownik: docker
Hasło: docker
```

## Konta i Role

Aplikacja obsługuje typ konta zapisany w tabeli `users` w kolumnie `account_type`.

```text
0 - User
1 - Admin
```

Domyślnie użytkownik ma typ konta `0`. Konto administratora można nadać ręcznie w bazie danych:

```sql
UPDATE users
SET account_type = 1
WHERE email = 'adres@email.pl';
```

Panel admina jest dostępny tylko dla użytkowników z `account_type = 1`.

## Baza Danych

Schemat bazy danych znajduje się w pliku:

```text
docker/db/init/init.sql
```

Najważniejsze tabele:

- `users` - konta użytkowników,
- `templates` - szablony postaci,
- `template_fields` - pola w szablonach postaci,
- `characters` - postacie,
- `character_field_values` - wartości pól postaci,
- `character_variants` - warianty postaci,
- `character_variant_field_values` - wartości pól wariantów,
- `worlds` - foldery użytkownika,
- `character_statuses` - statusy postaci,
- `filters` - filtry,
- `character_filters` - przypisanie filtrów do postaci,
- `world_filters` - przypisanie filtrów do folderów,
- `user_blocked_filters` - filtry ukryte przez użytkownika.

### Widoki, Funkcje i Wyzwalacze SQL

W pliku `docker/db/init/init.sql` znajdują się dodatkowe elementy bazy danych:

- `user_account_summary` - widok raportowy podsumowujący konto użytkownika. Zwraca dane użytkownika oraz liczbę jego postaci, szablonów postaci i folderów.
- `is_account_currently_banned(banned_until TIMESTAMP WITH TIME ZONE)` - funkcja SQL sprawdzająca, czy konto jest aktualnie zablokowane.
- `set_default_username_from_email()` - funkcja wyzwalacza ustawiająca domyślną nazwę użytkownika na podstawie części emaila przed `@`, jeżeli `username` jest pusty.
- `trg_set_default_username_from_email` - wyzwalacz uruchamiany przed dodaniem lub aktualizacją użytkownika, korzystający z funkcji `set_default_username_from_email()`.

Przykładowe użycie widoku i funkcji:

```sql
SELECT * FROM user_account_summary;

SELECT email, is_account_currently_banned(banned_until) AS is_banned
FROM users;
```

### Diagram ERD

![Diagram ERD](docs/erd.png)

## Backup Bazy

Backup bazy danych nie jest wykonywany z panelu aplikacji, ponieważ pełny eksport SQL zawiera dane użytkowników. Bezpieczniej wykonywać go jako operację administracyjną z terminala.

### Backup Manualny

W głównym folderze projektu:

```powershell
mkdir backups
$stamp = Get-Date -Format "yyyyMMdd_HHmmss"
docker compose exec -T db pg_dump -U docker db > "backups\ocstudio_db_$stamp.sql"
```

Folder `backups/` jest dodany do `.gitignore`, więc pliki backupu nie trafiają do repozytorium.

### Backup Skryptem

Można użyć gotowego skryptu:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/backup_database.ps1
```

Skrypt zapisuje plik SQL w folderze `backups/`.

### Przywracanie Backupu

Przykład przywrócenia backupu:

```powershell
docker compose exec -T db psql -U docker db < "backups\ocstudio_db_backup.sql"
```

Przywracanie należy wykonywać ostrożnie, ponieważ może zmienić lub zdublować dane w aktualnej bazie.

## Flow Aplikacji

1. Użytkownik trafia na ekran logowania. Może zalogować się, przejść do rejestracji albo użyć uproszczonego resetu hasła.
2. Po rejestracji dane konta są walidowane, hasło jest hashowane, a użytkownik wraca do logowania.
3. Po poprawnym logowaniu aplikacja zapisuje dane użytkownika w sesji i przekierowuje go do dashboardu.
4. Dashboard pokazuje podsumowanie konta: liczbę postaci, folderów, szablonów postaci oraz losowe postacie wymagające uwagi.
5. Użytkownik tworzy szablon postaci, czyli zestaw pól opisujących dany typ postaci. Szablon może zawierać pola tekstowe, listy, galerie, tabele, daty i pola wyboru.
6. Podczas tworzenia postaci użytkownik wybiera szablon postaci, uzupełnia pola, dodaje obraz, warianty oraz opcjonalnie przypisuje postać do folderu.
7. Lista postaci pozwala filtrować, wyszukiwać, przypisywać statusy, przenosić postacie między folderami, duplikować je i usuwać po potwierdzeniu nazwą.
8. Widok podglądu prezentuje gotową kartę postaci na podstawie wybranego szablonu oraz zapisanych wartości pól.
9. Ustawienia pozwalają zmienić motyw jasny/ciemny, kolor akcentu i liczbę kolumn w widoku postaci.
10. Administrator po zalogowaniu ma dostęp do panelu admina, gdzie może blokować konta użytkowników i planować lub cofać usunięcie konta.
11. Wylogowanie kończy pracę z aplikacją i usuwa aktywną sesję użytkownika.

## Najważniejsze Widoki

### Logowanie i Rejestracja

Użytkownik może założyć konto i zalogować się do aplikacji. Hasło jest zapisywane w bazie w formie zahashowanej.

### Dashboard

Widok startowy po zalogowaniu. Pokazuje podstawowe informacje o koncie i szybki dostęp do najważniejszych sekcji.

### Postacie

Główna sekcja pracy z postaciami. Użytkownik może:

- tworzyć postacie,
- edytować postacie,
- duplikować postacie,
- usuwać postacie po potwierdzeniu nazwą,
- przypisywać statusy,
- przypisywać filtry,
- przenosić postacie do folderów,
- wyszukiwać postacie.

### Foldery

Foldery służą do porządkowania postaci. Usunięcie folderu przenosi postacie do głównego widoku, zamiast usuwać je razem z folderem.

### Szablony postaci

Szablony postaci pozwalają zdefiniować strukturę danych dla postaci. Dzięki temu różne typy postaci mogą mieć różne pola.

### Kreator Szablonu postaci

Kreator pozwala dodawać pola po lewej stronie opisu lub w infoboxie. Na urządzeniach mobilnych pola można przesuwać przyciskami góra/dół oraz przenosić między sekcjami.

### Podgląd Postaci

Widok prezentuje postać na podstawie wybranego szablonu postaci oraz zapisanych danych. Prywatne postacie są dostępne tylko dla właściciela konta.

### Ustawienia

Użytkownik może zmienić wygląd aplikacji, kolor akcentu oraz domyślną liczbę kolumn w widoku postaci.

### Panel Admina

Administrator może zarządzać kontami użytkowników. Panel nie daje adminowi pełnego podglądu prywatnych treści postaci, żeby zachować prywatność użytkowników.

## Bezpieczeństwo i Prywatność

W projekcie zastosowano kilka mechanizmów bezpieczeństwa:

- hasła są hashowane,
- widoki aplikacji wymagają zalogowania,
- panel admina wymaga roli administratora,
- zwykły użytkownik nie może zarządzać cudzymi danymi,
- admin nie ma edycji prywatnych postaci użytkownika,
- usuwanie konta może zostać zaplanowane i cofnięte,
- blokada konta może zawierać powód oraz czas trwania,
- backup SQL jest traktowany jako operacja techniczna poza panelem aplikacji,
- folder z backupami jest ignorowany przez Git.

## Responsywność

Aplikacja została dostosowana do różnych szerokości ekranu:

- na małych ekranach pojawia się menu burger,
- siatka postaci zmniejsza liczbę kolumn,
- suwak liczby kolumn jest ukrywany tam, gdzie układ ma stałe limity,
- formularze i kreator szablonów postaci dopasowują się do ekranu,
- złożone pola w kreatorze nie powinny wychodzić poza szerokość strony.

## Eksport Bazy do Pliku SQL

Eksport bazy do pliku `.sql` można wykonać komendą:

```powershell
$stamp = Get-Date -Format "yyyyMMdd_HHmmss"
docker compose exec -T db pg_dump -U docker db > "backups\ocstudio_db_$stamp.sql"
```

Taki plik można dołączyć do dokumentacji projektu tylko wtedy, gdy nie zawiera prywatnych danych użytkowników.

## Dalszy Rozwój

Pomysły na dalszy rozwój:

- przygotowanie diagramu ERD,
- rozbudowanie panelu admina o raporty,
- system kont premium,
- część społecznościowa z publicznym udostępnianiem wybranych postaci,
- eksport wybranej postaci do PDF,
- import i eksport szablonów postaci,
- automatyczne backupy wykonywane przez harmonogram,
- testy automatyczne.

## Autor

Projekt wykonany w ramach zajęć WdPAI.
Adrian Bober 152685
adrian.bober@student.pk.edu.pl
