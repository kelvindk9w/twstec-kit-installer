<?php

declare(strict_types=1);

// Installer texts (php artisan tws:install) — twstec/kit-installer. The
// application wins: its own lang/<locale>/installer.php overrides these keys.

return [
    'description' => 'Chooses the optional kit modules (accounts, uploads, /admin panel) and the demo, and applies: Composer, migrations, APP_KEY and pepper',
    'options' => [
        'with' => 'Optional modules to install or keep, comma separated (accounts,uploads,admin); without the option, the TWS_KIT_WITH variable applies',
        'without' => 'Optional modules to remove, comma separated; without the option, the TWS_KIT_WITHOUT variable applies',
        'no_demo' => 'Removes the kit demo (twstec/kit-demo)',
        'force' => 'Allows running in production',
        'graceful' => 'Does not fail when the database is unreachable (migrations are left for later)',
    ],

    'intro' => 'TWS Laravel Starter Kit installer. foundation (security, audit, locale, email) and auth (login, registration, verification) always come; the modules below are optional.',
    'production_refused' => 'Refused: APP_ENV=production. The installer removes and adds packages and edits .env — in production, only with --force.',

    'modules' => [
        'foundation' => 'Foundation (twstec/kit-foundation)',
        'auth' => 'Authentication (twstec/kit-auth)',
        'accounts' => 'Accounts, API keys and projects (twstec/kit-accounts)',
        'uploads' => 'Secure uploads and profile photo (twstec/kit-uploads)',
        'admin' => '/admin panel with Filament (twstec/kit-admin)',
        'demo' => 'Kit demo (twstec/kit-demo)',
    ],

    'select_label' => 'Which optional modules do you want?',
    'select_hint' => 'Space toggles; Enter confirms. Uploads needs Accounts.',
    'keep_demo' => 'Keep the kit demo (landing pages, showcase, demo accounts — development only)?',
    'demo_must_go' => 'The demo requires every optional module (:modules). With this choice it will be removed. Continue?',
    'confirm_apply' => 'Apply these changes?',
    'aborted' => 'Nothing was changed.',

    'errors' => [
        'unknown_module' => 'Unknown module: :module. The optional ones are: :modules.',
        'required_module' => 'The :module module is required and cannot be removed.',
        'with_and_without' => 'The :module module is in both --with and --without.',
        'missing_dependency' => ':module needs :needs.',
        'demo_requires' => 'The demo requires :modules. Remove it too (--no-demo) or keep the modules.',
    ],

    'plan' => [
        'heading' => 'Plan',
        'module' => 'Module',
        'now' => 'Now',
        'after' => 'After',
        'installed' => 'installed',
        'absent' => 'absent',
        'always' => 'always',
        'nothing_to_change' => 'The packages already match your choice: nothing to install or remove.',
    ],

    'steps' => [
        'demo_uninstall' => 'Removing from the database what the demo installed (demo:uninstall)',
        'demo_remove' => 'Removing the demo (composer remove --dev)',
        'composer_remove' => 'Removing :packages',
        'composer_require' => 'Installing :packages',
        'clear_caches' => 'Clearing the Laravel caches (optimize:clear)',
        'env_created' => '.env created from .env.example',
        'app_key' => 'APP_KEY',
        'app_key_generated' => 'generated',
        'app_key_kept' => 'already set',
        'pepper' => 'API key pepper (API_KEYS_HASH_PEPPER)',
        'pepper_generated' => 'generated',
        'pepper_generated_previous' => 'generated; the current APP_KEY went to API_KEYS_PREVIOUS_HASH_PEPPERS (keys already issued keep working)',
        'pepper_kept' => 'already set',
        'migrate' => 'Migrations (migrate)',
    ],

    'failures' => [
        'demo_uninstall' => 'demo:uninstall failed (is the database reachable?). Nothing was removed.',
        'demo_uninstall_graceful' => 'demo:uninstall failed (database unreachable?); continuing without it — run `php artisan demo:uninstall --drop-tables` on a database that already ran the demo.',
        'composer' => 'Composer failed. See the output above; composer.json and composer.lock stay as Composer left them.',
        'migrate' => 'The migrations failed. Check the database in .env and run `php artisan migrate`.',
        'migrate_graceful' => 'Database unreachable: migrations were left for later (`php artisan migrate`).',
        'env_missing' => 'No .env or .env.example: APP_KEY and pepper were not generated.',
    ],

    'summary' => [
        'heading' => 'Done',
        'removed' => 'removed',
        'added' => 'installed now',
        'kept' => 'installed',
        'absent' => 'not installed',
        'next' => 'Next steps',
        'next_build' => 'Rebuild the frontend: npm install && npm run build',
        'next_fresh' => 'Development database with the demo fake data: php artisan migrate:fresh',
        'next_horizon' => 'Without /admin, /horizon stays closed outside the local environment (see app/Providers/HorizonServiceProvider.php).',
        'next_serve' => 'Start the application: composer dev (or docker compose up -d, see the README).',
    ],

    // php artisan tws:add — adding kit packages to an existing application.
    'add' => [
        'description' => 'Adds kit packages (authentication, accounts, uploads, /admin panel) to an existing Laravel application: Composer, configuration, .env and migrations',
        'arguments' => [
            'modules' => 'Modules to add, space separated (auth accounts uploads admin)',
        ],
        'intro' => 'TWS Laravel Starter Kit packages in this application:',
        'available' => 'available',
        'nothing_available' => 'Every kit package is already installed.',
        'nothing_chosen' => 'No module chosen: nothing was changed.',
        'select_label' => 'Which modules do you want to add?',
        'select_hint' => 'Space toggles; Enter confirms. Uploads needs Accounts; authentication comes along with any module.',
        'already_installed' => ':module is already installed.',
        'will_install' => 'will be installed',
        'comes_along' => 'comes along (required by the kit)',
        'next' => 'What the application does now',
        'errors' => [
            'none_given' => 'Tell which modules to add (without a terminal there is no question): php artisan tws:add :modules',
            'add_together' => 'Add both together: :command',
            'user_model' => ':modules needs the application user model (:model) with the kit authentication: it does not implement Twstec\\Kit\\Auth\\Contracts\\AuthUser yet, and without it the application does not boot. Nothing was changed. The way: :firstadjust the model as in the twstec/kit-auth README (vendor/twstec/kit-auth/README.md) and run :command.',
        ],
        'next_steps' => [
            'auth' => 'Authentication: the application user model implements Twstec\\Kit\\Auth\\Contracts\\AuthUser with the KitAuthenticatable trait (vendor/twstec/kit-auth/README.md).',
            'accounts' => 'Accounts: nothing else in the model — the personal account is created with each person; the API keys pepper was generated in .env (vendor/twstec/kit-accounts/README.md).',
            'uploads' => 'Uploads: for the profile photo, the HasAvatar trait in the model and the users.avatar_upload_id column in an application migration (vendor/twstec/kit-uploads/README.md).',
            'admin' => '/admin panel: a Filament PanelProvider with AdminPlugin::make(), the model with FilamentUser and the AccessesAdminPanel trait, the users.is_admin column; then php artisan user:make-admin email@example.com (vendor/twstec/kit-admin/README.md).',
        ],
    ],
];
