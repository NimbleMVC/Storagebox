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

### Rola backendu: `primary` vs `failover`

Każdy backend ma `role`: `primary` (domyślna) albo `failover`.

- **`primary`** — normalny cel mirroringu: zapis idzie na najwyżej priorytetowy zdrowy `primary`, a pozostałe `primary` backendy dostają kopię w tle (`reconcileCron()`).
- **`failover`** — backend awaryjny: **nigdy** nie dostaje proaktywnej kopii przy zwykłym zapisie. Jest używany tylko wtedy, gdy *żaden* backend `primary` nie zadziałał. Gdy jakiś `primary` wróci do zdrowia, `ModuleStorageFileMirrorModel::drainCron()` przenosi plik z backendu `failover` z powrotem na `primary` i usuwa go z backendu `failover`.

To odpowiada na scenariusz "MinIO + S3 jako primary, lokalny dysk tylko jako awaryjny tryb, bez ciągłego trzymania tam kopii wszystkiego" — bez roli `failover`, lokalny dysk (jak każdy inny aktywny backend) dostawałby kopię każdego zapisanego pliku.

### Dodanie backendu do już działającego systemu (backfill)

Nowy backend **nie** dostaje automatycznie kopii plików zapisanych przed jego dodaniem —
`reconcileCron()`/`drainCron()` przetwarzają tylko wiersze już istniejące w
`module_storage_file_mirror`. Żeby dociągnąć istniejące pliki na nowo dodany backend:

```php
use NimblePHP\Storagebox\ModuleStorageFileMirrorModel;

$newBackendId = $backends->createBackend(name: 's3-new', type: 'minio', priority: 750, ...)
    ? $backends->getId()
    : null;

$mirrors = $this->loadModel(ModuleStorageFileMirrorModel::class);
$queued = $mirrors->backfillToBackend($newBackendId); // kolejkuje wszystkie znane pliki jako "pending"
// reconcileCron() dosynchronizuje je w tle, tak jak każdy inny "pending" wpis
```

Wywołanie jest idempotentne — pliki już zakolejkowane/zsynchronizowane na tym backendzie
są pomijane, więc można je bezpiecznie odpalić wielokrotnie.

Wrażliwe dane logowania (`credentials`) są szyfrowane w bazie przez
`nimblephp/crypto` (`Crypto::encryptArray()`, AES-256-GCM) i nigdy nie są
zapisywane jawnie — wymaga to skonfigurowanego `ENCRYPTION_KEY_CURRENT` /
`ENCRYPTION_KEY_N` oraz zarejestrowanego modułu Crypto w aplikacji, ale tylko
w momencie faktycznego użycia tej funkcji (instalacja paczki sama w sobie
niczego nie szyfruje ani nie wymaga kluczy).
