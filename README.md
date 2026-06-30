# nimblephp/storage-box

Uniwersalny moduł przechowywania plików dla NimblePHP. Zapisuje pliki przez wybrany
driver (lokalny system plików lub MinIO/S3) i przechowuje ich metadane w bazie danych
(tabela `module_storage_file`).

## Zawartość

- `Module` — rejestracja modułu i uruchamianie migracji (`onUpdate`)
- `ModuleStorageFileModel` — manager plików (zapis, kopiowanie, pobieranie, duplikacja, usuwanie, auto-usuwanie)
- `MinioStorage` — driver MinIO / AWS S3 (rozszerza `NimblePHP\Framework\Storage`)
- `StorageProvider` — enum providerów (`storage`, `minio`)
- `StorageBoxException` — wyjątek modułu
- `src/Migrations/` — migracja tworząca tabelę `module_storage_file`

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

$model = $this->loadModel(ModuleStorageFileModel::class);
$model->provider = StorageProvider::minio; // domyślnie StorageProvider::storage

// zapis z zawartości
$id = $model->write($content, type: 'avatar', fileName: 'photo.jpg');

// kopiowanie z lokalnej ścieżki
$id = $model->copy('/tmp/upload.jpg', type: 'attachment', fileName: 'upload.jpg');

// pobranie zawartości
$content = $model->getFileContent($id);

// usunięcie
$model->setId($id)->deleteFile();
```

## Konfiguracja MinIO / S3 (zmienne środowiskowe)

- `MINIO_HOST` — endpoint MinIO; puste = tryb AWS S3
- `MINIO_BUCKET`
- `MINIO_REGION`
- `MINIO_USERNAME`
- `MINIO_PASSWORD`
