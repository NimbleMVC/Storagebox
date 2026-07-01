<?php

namespace NimblePHP\Storagebox\Event;

use NimblePHP\Framework\Event\AbstractEvent;

/**
 * Bazowy event cyklu życia pliku w module storagebox.
 * $record zawiera rekord z tabeli module_storage_file (m.in. id, hash, provider, size).
 */
abstract class AbstractStorageFileEvent extends AbstractEvent
{

    /**
     * @param array $record Rekord pliku z tabeli module_storage_file.
     */
    public function __construct(public array $record)
    {
    }

}
