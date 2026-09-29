<?php

declare(strict_types=1);

// Textos del instalador (php artisan tws:install) — twstec/kit-installer. La
// aplicación gana: su propio lang/<idioma>/installer.php sobrescribe estas
// claves.

return [
    'description' => 'Elige los módulos opcionales del kit (cuentas, uploads, panel /admin) y la demostración, y aplica: Composer, migraciones, APP_KEY y pepper',
    'options' => [
        'with' => 'Módulos opcionales a instalar o mantener, separados por coma (accounts,uploads,admin); sin la opción, vale la variable TWS_KIT_WITH',
        'without' => 'Módulos opcionales a quitar, separados por coma; sin la opción, vale la variable TWS_KIT_WITHOUT',
        'no_demo' => 'Quita la demostración del kit (twstec/kit-demo)',
        'force' => 'Permite ejecutar en producción',
        'graceful' => 'No falla si la base de datos no está accesible (las migraciones quedan para después)',
    ],

    'intro' => 'Instalador del TWS Laravel Starter Kit. foundation (seguridad, auditoría, idioma, correo) y auth (login, registro, verificación) vienen siempre; los módulos de abajo son opcionales.',
    'production_refused' => 'Rechazado: APP_ENV=production. El instalador quita y agrega paquetes y modifica el .env — en producción, solo con --force.',

    'modules' => [
        'foundation' => 'Base (twstec/kit-foundation)',
        'auth' => 'Autenticación (twstec/kit-auth)',
        'accounts' => 'Cuentas, claves de API y proyectos (twstec/kit-accounts)',
        'uploads' => 'Uploads seguros y foto de perfil (twstec/kit-uploads)',
        'admin' => 'Panel /admin con Filament (twstec/kit-admin)',
        'demo' => 'Demostración del kit (twstec/kit-demo)',
    ],

    'select_label' => '¿Qué módulos opcionales quieres?',
    'select_hint' => 'Espacio marca y desmarca; Enter confirma. Uploads necesita Cuentas.',
    'keep_demo' => '¿Mantener la demostración del kit (landings, vitrina, cuentas demo — solo para desarrollo)?',
    'demo_must_go' => 'La demostración exige todos los módulos opcionales (:modules). Con esta elección se quitará. ¿Continuar?',
    'confirm_apply' => '¿Aplicar estos cambios?',
    'aborted' => 'No se cambió nada.',

    'errors' => [
        'unknown_module' => 'Módulo desconocido: :module. Los opcionales son: :modules.',
        'required_module' => 'El módulo :module es obligatorio y no se puede quitar.',
        'with_and_without' => 'El módulo :module está en --with y en --without.',
        'missing_dependency' => ':module necesita :needs.',
        'demo_requires' => 'La demostración exige :modules. Quítala también (--no-demo) o mantén los módulos.',
    ],

    'plan' => [
        'heading' => 'Plan',
        'module' => 'Módulo',
        'now' => 'Ahora',
        'after' => 'Después',
        'installed' => 'instalado',
        'absent' => 'ausente',
        'always' => 'siempre',
        'nothing_to_change' => 'Los paquetes ya están como elegiste: nada que instalar o quitar.',
    ],

    'steps' => [
        'demo_uninstall' => 'Quitando de la base de datos lo que instaló la demostración (demo:uninstall)',
        'demo_remove' => 'Quitando la demostración (composer remove --dev)',
        'composer_remove' => 'Quitando :packages',
        'composer_require' => 'Instalando :packages',
        'clear_caches' => 'Limpiando las cachés de Laravel (optimize:clear)',
        'env_created' => '.env creado a partir de .env.example',
        'app_key' => 'APP_KEY',
        'app_key_generated' => 'generada',
        'app_key_kept' => 'ya definida',
        'pepper' => 'Pepper de las claves de API (API_KEYS_HASH_PEPPER)',
        'pepper_generated' => 'generado',
        'pepper_generated_previous' => 'generado; la APP_KEY actual pasó a API_KEYS_PREVIOUS_HASH_PEPPERS (las claves ya emitidas siguen valiendo)',
        'pepper_kept' => 'ya definido',
        'migrate' => 'Migraciones (migrate)',
    ],

    'failures' => [
        'demo_uninstall' => 'demo:uninstall falló (¿la base de datos está accesible?). No se quitó nada.',
        'demo_uninstall_graceful' => 'demo:uninstall falló (¿base de datos inaccesible?); se sigue sin él — ejecuta `php artisan demo:uninstall --drop-tables` en una base que ya ejecutó la demo.',
        'composer' => 'Composer falló. Mira la salida de arriba; composer.json y composer.lock quedan como Composer los dejó.',
        'migrate' => 'Las migraciones fallaron. Revisa la base de datos en el .env y ejecuta `php artisan migrate`.',
        'migrate_graceful' => 'Base de datos inaccesible: las migraciones quedaron para después (`php artisan migrate`).',
        'env_missing' => 'Sin .env ni .env.example: no se generaron APP_KEY ni pepper.',
    ],

    'summary' => [
        'heading' => 'Listo',
        'removed' => 'quitado',
        'added' => 'instalado ahora',
        'kept' => 'instalado',
        'absent' => 'no instalado',
        'next' => 'Próximos pasos',
        'next_build' => 'Recompila el frontend: npm install && npm run build',
        'next_fresh' => 'Base de desarrollo con los datos ficticios de la demo: php artisan migrate:fresh',
        'next_horizon' => 'Sin /admin, /horizon queda cerrado fuera del entorno local (ver app/Providers/HorizonServiceProvider.php).',
        'next_serve' => 'Levanta la aplicación: composer dev (o docker compose up -d, ver el README).',
    ],

    // php artisan tws:add — añadir paquetes del kit a una aplicación que ya existe.
    'add' => [
        'description' => 'Añade paquetes del kit (autenticación, cuentas, uploads, panel /admin) a una aplicación Laravel que ya existe: Composer, configuración, .env y migraciones',
        'arguments' => [
            'modules' => 'Módulos a añadir, separados por espacio (auth accounts uploads admin)',
        ],
        'intro' => 'Paquetes del TWS Laravel Starter Kit en esta aplicación:',
        'available' => 'disponible',
        'nothing_available' => 'Todos los paquetes del kit ya están instalados.',
        'nothing_chosen' => 'Ningún módulo elegido: no se cambió nada.',
        'select_label' => '¿Qué módulos añadir?',
        'select_hint' => 'Espacio marca y desmarca; Enter confirma. Uploads necesita Cuentas; la autenticación viene con cualquier módulo.',
        'already_installed' => ':module ya está instalado.',
        'will_install' => 'se instalará',
        'comes_along' => 'viene junto (obligatorio en el kit)',
        'next' => 'Lo que la aplicación hace ahora',
        'errors' => [
            'none_given' => 'Indica qué módulos añadir (sin terminal no hay pregunta): php artisan tws:add :modules',
            'add_together' => 'Añade los dos juntos: :command',
            'user_model' => ':modules necesita el modelo de usuario de la aplicación (:model) con la autenticación del kit: todavía no implementa Twstec\\Kit\\Auth\\Contracts\\AuthUser, y sin eso la aplicación no arranca. No se cambió nada. El camino: :firstajuste el modelo como en el README de twstec/kit-auth (vendor/twstec/kit-auth/README.md) y ejecute :command.',
        ],
        'next_steps' => [
            'auth' => 'Autenticación: el modelo de usuario de la aplicación implementa Twstec\\Kit\\Auth\\Contracts\\AuthUser con el trait KitAuthenticatable (vendor/twstec/kit-auth/README.md).',
            'accounts' => 'Cuentas: nada más en el modelo — la cuenta personal nace con cada persona; el pepper de las claves de API se generó en el .env (vendor/twstec/kit-accounts/README.md).',
            'uploads' => 'Uploads: para la foto de perfil, el trait HasAvatar en el modelo y la columna users.avatar_upload_id en una migración de la aplicación (vendor/twstec/kit-uploads/README.md).',
            'admin' => 'Panel /admin: un PanelProvider de Filament con AdminPlugin::make(), el modelo con FilamentUser y el trait AccessesAdminPanel, la columna users.is_admin; después, php artisan user:make-admin email@ejemplo.com (vendor/twstec/kit-admin/README.md).',
        ],
    ],
];
