<?php

declare(strict_types=1);

namespace Padosoft\MobilePush\Laravel\Testing;

/**
 * A fake was activated in an environment that is not "testing". Fakes change what a send does (nothing reaches a
 * provider, the environment reads as production for the send pipeline), so they must never be switched on by
 * accident in a real deployment.
 */
final class FakeNotAllowedException extends \RuntimeException {}
