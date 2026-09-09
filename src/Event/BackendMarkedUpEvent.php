<?php

namespace NimblePHP\Storagebox\Event;

/**
 * Dispatchowany, gdy backend mirrored storage wraca ze statusu 'down' na 'up'
 * (ModuleStorageBackendModel::healthCheckCron() albo udany zapis/synchronizacja
 * po wczesniejszej awarii).
 */
class BackendMarkedUpEvent extends AbstractStorageBackendEvent
{
}
