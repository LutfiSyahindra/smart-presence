<?php

namespace Tests\Unit;

use App\Support\AccessPermission;
use PHPUnit\Framework\TestCase;

class AccessPermissionTest extends TestCase
{
    public function test_mobile_permissions_are_registered_as_system_permissions_in_the_mobile_group(): void
    {
        $this->assertSame('mobile.access', AccessPermission::MOBILE_ACCESS);
        $this->assertSame('mobile.account', AccessPermission::MOBILE_ACCOUNT);

        foreach ([AccessPermission::MOBILE_ACCESS, AccessPermission::MOBILE_ACCOUNT] as $permission) {
            $this->assertContains($permission, AccessPermission::all());
            $this->assertTrue(AccessPermission::isSystem($permission));
        }
    }
}
