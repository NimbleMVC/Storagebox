<?php

namespace NimblePHP\Storagebox\Event;

/**
 * Dispatchowany, gdy backend mirrored storage przechodzi ze statusu 'up' na 'down'
 * (ModuleStorageBackendModel::healthCheckCron() albo nieudana proba zapisu/synchronizacji
 * w MirroredStorage/reconcileCron()/drainCron()). NIE jest dispatchowany ponownie przy
 * kolejnych nieudanych probach, dopoki backend nie wroci najpierw do statusu 'up'.
 */
class BackendMarkedDownEvent extends AbstractStorageBackendEvent
{
}
