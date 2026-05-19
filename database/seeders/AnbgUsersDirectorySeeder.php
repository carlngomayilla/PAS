<?php

namespace Database\Seeders;

/**
 * Compatibility alias for the historical manual command:
 *   php artisan db:seed --class=AnbgUsersDirectorySeeder
 *
 * The user directory is now sourced from
 * docs/base_utilisateurs_pas_anbg_refaite_nouvelle_logique.xlsx through
 * AnbgOrganizationSeeder, so this class must not carry a second static map.
 */
class AnbgUsersDirectorySeeder extends AnbgOrganizationSeeder
{
}
