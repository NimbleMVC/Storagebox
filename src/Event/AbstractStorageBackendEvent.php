<?php

namespace NimblePHP\Storagebox\Event;

use NimblePHP\Framework\Event\AbstractEvent;

/**
 * Bazowy event cyklu zycia backendu mirrored storage w module storagebox.
 * $backend zawiera rekord z tabeli module_storage_backend (m.in. id, name, type, role, status).
 */
abstract class AbstractStorageBackendEvent extends AbstractEvent
{

    /**
     * @param array $backend Rekord backendu z tabeli module_storage_backend.
     */
    public function __construct(public array $backend)
    {
    }

}
