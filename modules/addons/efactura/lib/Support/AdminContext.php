<?php
/**
 * WHMCS-eFactura - RO e-Factura (ANAF) addon for WHMCS
 *
 * Copyright (C) 2026 S.C. CROCKY S.R.L.
 * SPDX-License-Identifier: GPL-3.0-only
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License version 3, with the
 * additional permission and additional terms in ADDITIONAL-TERMS.md.
 * See the LICENSE and ADDITIONAL-TERMS.md files for details.
 */

declare(strict_types=1);

namespace WHMCS\Module\Addon\Efactura\Support;

use WHMCS\Database\Capsule;

/**
 * The WHMCS administrator of the current request, if any.
 */
final class AdminContext
{
    public static function id(): ?int
    {
        $id = (int) ($_SESSION['adminid'] ?? 0);

        return $id > 0 ? $id : null;
    }

    /**
     * Whether the current admin may use the addon: the role is in its access
     * list (Configuration > Addon Modules), as WHMCS checks for its pages.
     */
    public static function canUseAddon(): bool
    {
        $adminId = self::id();
        if ($adminId === null) {
            return false;
        }
        $role = (int) Capsule::table('tbladmins')->where('id', $adminId)->value('roleid');
        $access = (string) Capsule::table('tbladdonmodules')->where('module', 'efactura')->where('setting', 'access')->value('value');

        return $role > 0 && in_array((string) $role, array_map('trim', explode(',', $access)), true);
    }

    public static function name(?int $adminId): string
    {
        if ($adminId === null || $adminId <= 0) {
            return '';
        }
        $admin = Capsule::table('tbladmins')->where('id', $adminId)->first(['firstname', 'lastname', 'username']);
        if ($admin === null) {
            return '#' . $adminId;
        }
        $name = trim($admin->firstname . ' ' . $admin->lastname);

        return $name !== '' ? $name . ' (' . $admin->username . ')' : (string) $admin->username;
    }

    /**
     * URL of a page in the WHMCS admin area, e.g. "addonmodules.php?module=efactura".
     */
    public static function adminUrl(string $page): string
    {
        return rtrim((string) \WHMCS\Config\Setting::getValue('SystemURL'), '/') . '/' . \App::get_admin_folder_name() . '/' . $page;
    }
}
