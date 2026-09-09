<?php

namespace NimblePHP\Storagebox\Event;

use NimblePHP\Framework\Event\AbstractEvent;

/**
 * Dispatchowany za kazdym razem, gdy ModuleStorageFileMirrorModel::markFailed() oznacza
 * wpis mirrora jako 'failed' (reconcileCron() nie znalazlo zywej kopii pliku albo zapis
 * na docelowym backendzie rzucil wyjatek). Dispatchowany przy KAZDEJ nieudanej probie,
 * bez wbudowanego throttlingu ani limitu prob - jesli aplikacja hosta chce alarmowac
 * dopiero po N kolejnych niepowodzeniach tego samego pliku, musi to policzyc sama.
 */
class MirrorSyncFailedEvent extends AbstractEvent
{

    /**
     * @param array $mirror Rekord z module_storage_file_mirror (po oznaczeniu 'failed').
     * @param string $error Komunikat bledu ostatniej proby.
     */
    public function __construct(public array $mirror, public string $error)
    {
    }

}
