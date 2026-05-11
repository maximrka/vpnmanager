<?php

declare(strict_types=1);

namespace App\Adapters;

interface VpnAdapterInterface
{
    public function serviceName(): string;
    public function status(): string;
}
