<?php

namespace NimblePHP\Storagebox\Event;

/**
 * Dispatchowany tuż przed usunięciem pliku (storage + rekord) w deleteFile().
 * Pozwala aplikacji dopiąć sprzątanie (np. rozliczenie miejsca na dysku,
 * usunięcie powiązanych rekordów) zanim rekord zniknie z bazy.
 */
class BeforeFileDeleteEvent extends AbstractStorageFileEvent
{
}
