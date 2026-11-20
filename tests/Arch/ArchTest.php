<?php

declare(strict_types=1);

arch('Core never depends on the framework')
    ->expect('Padosoft\MobilePush\Core')
    ->not->toUse(['Illuminate', 'Laravel', 'Orchestra', 'Carbon']);

arch('strict types everywhere')
    ->expect('Padosoft\MobilePush')
    ->toUseStrictTypes();

arch('no debugging leftovers')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'env'])
    ->not->toBeUsedIn('Padosoft\MobilePush');

arch('exceptions extend the package base exception')
    ->expect('Padosoft\MobilePush\Core\Exceptions')
    ->toExtend(RuntimeException::class);
