# nimblephp/storage-box

Uniwersalny moduł przechowywania plików dla NimblePHP. Zapisuje pliki przez wybrany
driver (lokalny system plików lub MinIO/S3) i przechowuje ich metadane w bazie danych
(tabela `module_storage_file`).

## Zawartość

- `Module` — rejestracja modułu i uruchamianie migracji (`onUpdate`)
- `ModuleStorageFileModel` — manager plików (zapis, kopiowanie, pobieranie, duplikacja, usuwanie, auto-usuwanie)
- `MinioStorage` — driver MinIO / AWS S3 (rozszerza `NimblePHP\Framework\Storage`)
- `MirroredStorage` — driver wielo-backendowy z failoverem i tłem synchronizacji (patrz niżej)
- `ModuleStorageBackendModel` / `ModuleStorageFileMirrorModel` — konfiguracja backendów i stan mirroringu dla `MirroredStorage`
- `StorageProvider` — enum providerów (`storage`, `minio`, `mirrored`)
- `StorageBoxException` — wyjątek modułu
- `src/Migrations/` — migracje tworzące tabele `module_storage_file`, `module_storage_backend`, `module_storage_file_mirror`
- `storage:add-backend` — komenda CLI (`php vendor/bin/nimble storage:add-backend ...`) dodająca backend bez wpisywania kluczy w kodzie (patrz niżej)
- `Event\BackendMarkedDownEvent` / `Event\BackendMarkedUpEvent` / `Event\MirrorSyncFailedEvent` — eventy obserwowalności mirroringu (patrz niżej)

## Instalacja

```bash
composer require nimblephp/storage-box
```

## Migracja

Migracje uruchamiają się automatycznie przy aktualizacji aplikacji
(`Module::onUpdate()`, grupa `module_storage_file`). Można też uruchomić ręcznie:

```bash
php vendor/bin/nimble migration:run --dir=vendor/nimblephp/storage-box/src/Migrations
```

## Użycie

```php
use NimblePHP\Storagebox\ModuleStorageFileModel;
use NimblePHP\Storagebox\StorageProvider;
use NimblePHP\Storagebox\TrustedLocalFile;
use NimblePHP\Storagebox\UploadedFile;

$model = $this->loadModel(ModuleStorageFileModel::class);
$model->provider = StorageProvider::minio; // domyślnie StorageProvider::storage

// zapis z zawartości
$id = $model->write($content, type: 'avatar', fileName: 'photo.jpg');

// upload HTTP — fabryka sprawdza UPLOAD_ERR_OK, is_uploaded_file i katalog tymczasowy PHP
$upload = UploadedFile::fromArray($_FILES['file']);
$id = $model->copyUploadedFile($upload, type: 'attachment');

// jawnie zaufany import lokalny — plik musi pozostać we wskazanym katalogu
$source = TrustedLocalFile::fromPath('/srv/app-import/report.pdf', '/srv/app-import');
$id = $model->importTrustedLocalFile($source, type: 'report');

// pobranie zawartości
$content = $model->getFileContent($id);

// usunięcie
$model->setId($id)->deleteFile();
```

Metoda `copy(string $path, ...)` jest zachowana przejściowo, ale akceptuje wyłącznie
ścieżki rozpoznane przez `is_uploaded_file()`. Nie należy przekazywać do niej ścieżek
z requestu. Import zwykłych plików lokalnych wymaga `TrustedLocalFile` i katalogu
dozwolonego kontrolowanego przez aplikację. Dowiązania symboliczne, urządzenia,
katalogi oraz ścieżki wychodzące poza ten katalog są odrzucane.

## Konfiguracja MinIO / S3 (zmienne środowiskowe)

- `MINIO_HOST` — endpoint MinIO; puste = tryb AWS S3
- `MINIO_BUCKET`
- `MINIO_REGION`
- `MINIO_USERNAME`
- `MINIO_PASSWORD`

## Mirrored storage — wiele backendów z failoverem (opcjonalne)

`StorageProvider::mirrored` to opcjonalna alternatywa dla pojedynczego providera:
lista backendów (MinIO, S3, lokalny filesystem) konfigurowana w bazie danych,
z priorytetami i automatycznym failoverem. Zapis idzie synchronicznie na
najwyżej priorytetowy zdrowy backend, pozostałe backendy są dosynchronizowywane
w tle przez cron (`ModuleStorageFileMirrorModel::reconcileCron()`), a zdrowie
backendów jest cyklicznie sprawdzane (`ModuleStorageBackendModel::healthCheckCron()`).
Istniejące pliki zapisane przez `storage`/`minio` nie są tym w żaden sposób
dotknięte — to w pełni opcjonalna ścieżka, włączana per plik przez ustawienie
`$model->provider = StorageProvider::mirrored`.

```php
use NimblePHP\Storagebox\ModuleStorageBackendModel;

$backends = $this->loadModel(ModuleStorageBackendModel::class);

// MinIO, najwyższy priorytet
$backends->createBackend(
    name: 'minio-primary',
    type: 'minio',
    priority: 1000,
    config: ['host' => 'https://minio.local:9000', 'bucket' => 'files'],
    credentials: ['username' => 'access-key', 'password' => 'secret-key']
);

// AWS S3 jako backup (puste "host" = tryb AWS S3, region wykrywany automatycznie)
$backends->createBackend(
    name: 's3-backup',
    type: 'minio',
    priority: 500,
    config: ['bucket' => 'files-backup'],
    credentials: ['username' => 'AKIA...', 'password' => '...']
);

// lokalny filesystem jako tryb awaryjny, WYŁĄCZNIE na wypadek gdy oba powyższe padną
// (role: 'failover' - nigdy nie dostaje proaktywnej kopii, patrz niżej)
$backends->createBackend(name: 'local-fallback', type: 'storage', priority: 0, role: 'failover');
```

### Dodawanie backendu bez wpisywania kluczy w kodzie (CLI)

Wołanie `createBackend()` z poziomu kodu aplikacji oznacza, że dane logowania muszą
gdzieś w tym kodzie (albo w migracji/seederze) się fizycznie znaleźć — a to zwykle
ląduje w repo. Zamiast tego można użyć komendy CLI, która **nigdy nie przyjmuje
sekretu jako zwykłej wartości** — tylko z już ustawionej zmiennej środowiskowej
(`--username-env`/`--password-env`) albo pytając interaktywnie z ukrytym echem:

```bash
php vendor/bin/nimble storage:add-backend minio-primary \
    --priority=1000 \
    --host=https://minio.local:9000 \
    --bucket=files \
    --username-env=MINIO_USERNAME \
    --password-env=MINIO_PASSWORD

php vendor/bin/nimble storage:add-backend local-emergency --type=storage --role=failover --priority=0
```

`--username-env`/`--password-env` wskazują **nazwę** zmiennej środowiskowej (np. już
załadowanej z `.env`), nie sam sekret — więc żaden klucz nie trafia do argumentów
procesu ani do historii powłoki. Bez tych opcji komenda zapyta o dane logowania
interaktywnie (bez wyświetlania wpisywanych znaków).

### Rola backendu: `primary` vs `failover`

Każdy backend ma `role`: `primary` (domyślna) albo `failover`.

- **`primary`** — normalny cel mirroringu: zapis idzie na najwyżej priorytetowy zdrowy `primary`, a pozostałe `primary` backendy dostają kopię w tle (`reconcileCron()`).
- **`failover`** — backend awaryjny: **nigdy** nie dostaje proaktywnej kopii przy zwykłym zapisie. Jest używany tylko wtedy, gdy *żaden* backend `primary` nie zadziałał. Gdy jakiś `primary` wróci do zdrowia, `ModuleStorageFileMirrorModel::drainCron()` przenosi plik z backendu `failover` z powrotem na `primary` i usuwa go z backendu `failover`.

To odpowiada na scenariusz "MinIO + S3 jako primary, lokalny dysk tylko jako awaryjny tryb, bez ciągłego trzymania tam kopii wszystkiego" — bez roli `failover`, lokalny dysk (jak każdy inny aktywny backend) dostawałby kopię każdego zapisanego pliku.

### Dodanie backendu do już działającego systemu (backfill)

Dodanie nowego (albo ponowne włączenie istniejącego) backendu `primary` jest w pełni
automatyczne — `ModuleStorageFileMirrorModel::backfillCron()` (co minutę) sam wykrywa
każdy włączony backend `primary` i dogania go o pliki, których jeszcze nie ma, kolejkując
je jako `pending`; `reconcileCron()` (ten sam przebieg albo kolejny) faktycznie je kopiuje.
Nie trzeba nic wywoływać ręcznie — wystarczy `createBackend(...)` albo ustawienie `enabled = 1`.

```php
$backends->createBackend(name: 's3-new', type: 'minio', priority: 750, config: [...], credentials: [...]);
// nic więcej - w ciągu ~minuty backfillCron() + reconcileCron() dogonią ten backend same
```

Backendy `role: 'failover'` są świadomie pomijane przez `backfillCron()` (mają trzymać
tylko to, czego naprawdę nigdzie indziej nie ma, nie pełną kopię wszystkiego) — jeśli
mimo to chcesz z góry zasilić backend `failover`, zrób to jawnie:

```php
use NimblePHP\Storagebox\ModuleStorageFileMirrorModel;

$mirrors = $this->loadModel(ModuleStorageFileMirrorModel::class);
$queued = $mirrors->backfillToBackend($failoverBackendId); // kolejkuje wszystkie znane pliki jako "pending"
```

`backfillToBackend()` jest idempotentne — pliki już zakolejkowane/zsynchronizowane na danym
backendzie są pomijane, więc zarówno cron, jak i ręczne wywołanie można bezpiecznie powtarzać.

### Migracja istniejących plików na mirrored (`storage`/`minio` → `mirrored`)

Pliki zapisane wcześniej przez zwykły `storage`/`minio` (przed włączeniem mirrored storage)
można "przełączyć" na `mirrored` bez przenoszenia czy ponownego wgrywania — wystarczy,
że backend w `module_storage_backend` opisuje **to samo** miejsce, w którym plik już
fizycznie jest (te same dane logowania/bucket dla MinIO):

```php
use NimblePHP\Storagebox\ModuleStorageBackendModel;
use NimblePHP\Storagebox\ModuleStorageFileModel;
use NimblePHP\Storagebox\StorageProvider;

$backends = $this->loadModel(ModuleStorageBackendModel::class);
$backends->createBackend(
    name: 'existing-minio',
    type: 'minio',
    priority: 1000,
    config: ['host' => $_ENV['MINIO_HOST'], 'bucket' => $_ENV['MINIO_BUCKET'], 'region' => $_ENV['MINIO_REGION']],
    credentials: ['username' => $_ENV['MINIO_USERNAME'], 'password' => $_ENV['MINIO_PASSWORD']]
);

$model = $this->loadModel(ModuleStorageFileModel::class);
$migrated = $model->migrateToMirrored($backends->getId(), StorageProvider::minio);
// $migrated = liczba zmigrowanych rekordów; wywołaj ponownie, jeśli masz ich więcej niż limit (domyślnie 500)
```

Metoda tylko rejestruje wpis mirrora ze statusem `synced` i przełącza kolumnę `provider`
na `mirrored` — nie dotyka samego pliku ani nie weryfikuje, że backend faktycznie go widzi
(brak sprawdzenia analogicznie do reszty pakietu, który też nie wymusza kluczy obcych).
Po migracji dodanie kolejnych backendów `primary` działa już w pełni automatycznie
(`backfillCron()`, patrz wyżej).

Wrażliwe dane logowania (`credentials`) są szyfrowane w bazie przez
`nimblephp/crypto` (`Crypto::encryptArray()`, AES-256-GCM) i nigdy nie są
zapisywane jawnie — wymaga to skonfigurowanego `ENCRYPTION_KEY_CURRENT` /
`ENCRYPTION_KEY_N` oraz zarejestrowanego modułu Crypto w aplikacji, ale tylko
w momencie faktycznego użycia tej funkcji (instalacja paczki sama w sobie
niczego nie szyfruje ani nie wymaga kluczy).

### Eventy mirroringu (obserwowalność)

Backend padający/wracający i trwałe niepowodzenia synchronizacji dispatchują eventy
przez `Kernel::dispatchEvent(...)` — bez żadnej wbudowanej logiki alarmowania; to,
co z nimi zrobisz (log, powiadomienie, metryka), zależy w całości od Ciebie:

```php
use NimblePHP\Storagebox\Event\BackendMarkedDownEvent;
use NimblePHP\Storagebox\Event\BackendMarkedUpEvent;
use NimblePHP\Storagebox\Event\MirrorSyncFailedEvent;

Kernel::getEventDispatcher()->addListener(BackendMarkedDownEvent::class, function (BackendMarkedDownEvent $event) {
    // $event->backend - pełny rekord z module_storage_backend (już ze statusem 'down')
    Slack::alert("Backend storage \"{$event->backend['name']}\" padł");
});

Kernel::getEventDispatcher()->addListener(MirrorSyncFailedEvent::class, function (MirrorSyncFailedEvent $event) {
    // $event->mirror - rekord z module_storage_file_mirror, $event->error - komunikat błędu
    // Dispatchowany przy KAŻDEJ nieudanej próbie (reconcileCron/drainCron) - jeśli chcesz
    // throttling (np. alarm dopiero po 5 kolejnych niepowodzeniach tego samego pliku),
    // policz to sam w swoim listenerze.
});
```

- `BackendMarkedDownEvent`/`BackendMarkedUpEvent` — dispatchowane tylko przy **faktycznej
  zmianie** statusu backendu (`markUp()`/`markDown()` w `ModuleStorageBackendModel`) —
  powtarzające się nieudane zapisy na już martwy backend nie zasypią Cię eventami.
- `MirrorSyncFailedEvent` — dispatchowany przy każdym `markFailed()` w `ModuleStorageFileMirrorModel`.

Błąd rzucony w listenerze jest tylko logowany i nie wpływa na resztę operacji
(analogicznie do `AfterFileWriteEvent`/`BeforeFileDeleteEvent`).
