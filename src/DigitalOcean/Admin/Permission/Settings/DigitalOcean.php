<?php

namespace Nails\Cdn\Driver\DigitalOcean\Admin\Permission\Settings;

use Nails\Admin\Interfaces\Permission;

class DigitalOcean implements Permission
{
    public function label(): string
    {
        return 'Can manage CDN: Digital Ocean Spaces settings';
    }

    public function group(): string
    {
        return 'Settings';
    }
}
