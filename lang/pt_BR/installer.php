<?php

declare(strict_types=1);

// Textos do instalador (php artisan tws:install) — twstec/kit-installer.
// O aplicativo vence: um lang/<idioma>/installer.php dele sobrescreve estas
// chaves.

return [
    'description' => 'Escolhe os módulos opcionais do kit (contas, uploads, painel /admin) e a demonstração, e aplica: Composer, migrations, APP_KEY e pepper',
    'options' => [
        'with' => 'Módulos opcionais a instalar ou manter, separados por vírgula (accounts,uploads,admin)',
        'without' => 'Módulos opcionais a remover, separados por vírgula',
        'no_demo' => 'Remove a demonstração do kit (twstec/kit-demo)',
        'force' => 'Permite rodar em produção',
        'graceful' => 'Não falha se o banco não estiver acessível (as migrations ficam para depois)',
    ],

    'intro' => 'Instalador do TWS Laravel Starter Kit. foundation (segurança, auditoria, idioma, e-mail) e auth (login, cadastro, verificação) vêm sempre; os módulos abaixo são opcionais.',
    'production_refused' => 'Recusado: APP_ENV=production. O instalador tira e acrescenta pacotes e mexe no .env — em produção, só com --force.',

    'modules' => [
        'foundation' => 'Base (twstec/kit-foundation)',
        'auth' => 'Autenticação (twstec/kit-auth)',
        'accounts' => 'Contas, chaves de API e projetos (twstec/kit-accounts)',
        'uploads' => 'Uploads seguros e foto de perfil (twstec/kit-uploads)',
        'admin' => 'Painel /admin com Filament (twstec/kit-admin)',
        'demo' => 'Demonstração do kit (twstec/kit-demo)',
    ],

    'select_label' => 'Quais módulos opcionais você quer?',
    'select_hint' => 'Espaço marca e desmarca; Enter confirma. Uploads precisa de Contas.',
    'keep_demo' => 'Manter a demonstração do kit (landings, vitrine, contas demo — só para desenvolvimento)?',
    'demo_must_go' => 'A demonstração exige todos os módulos opcionais (:modules). Com esta escolha ela será removida. Continuar?',
    'confirm_apply' => 'Aplicar estas mudanças?',
    'aborted' => 'Nada foi alterado.',

    'errors' => [
        'unknown_module' => 'Módulo desconhecido: :module. Os opcionais são: :modules.',
        'required_module' => 'O módulo :module é obrigatório e não pode ser removido.',
        'with_and_without' => 'O módulo :module está em --with e em --without.',
        'missing_dependency' => ':module precisa de :needs.',
        'demo_requires' => 'A demonstração exige :modules. Remova-a junto (--no-demo) ou mantenha os módulos.',
    ],

    'plan' => [
        'heading' => 'Plano',
        'module' => 'Módulo',
        'now' => 'Agora',
        'after' => 'Depois',
        'installed' => 'instalado',
        'absent' => 'ausente',
        'always' => 'sempre',
        'nothing_to_change' => 'Os pacotes já estão como escolhido: nada a instalar ou remover.',
    ],

    'steps' => [
        'demo_uninstall' => 'Tirando do banco o que a demonstração instalou (demo:uninstall)',
        'demo_remove' => 'Removendo a demonstração (composer remove --dev)',
        'composer_remove' => 'Removendo :packages',
        'composer_require' => 'Instalando :packages',
        'clear_caches' => 'Limpando os caches do Laravel (optimize:clear)',
        'env_created' => '.env criado a partir do .env.example',
        'app_key' => 'APP_KEY',
        'app_key_generated' => 'gerada',
        'app_key_kept' => 'já definida',
        'pepper' => 'Pepper das chaves de API (API_KEYS_HASH_PEPPER)',
        'pepper_generated' => 'gerado',
        'pepper_generated_previous' => 'gerado; a APP_KEY atual foi para API_KEYS_PREVIOUS_HASH_PEPPERS (chaves já emitidas continuam valendo)',
        'pepper_kept' => 'já definido',
        'migrate' => 'Migrations (migrate)',
    ],

    'failures' => [
        'demo_uninstall' => 'demo:uninstall falhou (o banco está acessível?). Nada foi removido.',
        'demo_uninstall_graceful' => 'demo:uninstall falhou (banco inacessível?); seguindo sem ele — rode `php artisan demo:uninstall --drop-tables` num banco que já rodou a demo.',
        'composer' => 'O Composer falhou. Veja a saída acima; o composer.json e o composer.lock ficam como o Composer os deixou.',
        'migrate' => 'As migrations falharam. Confira o banco no .env e rode `php artisan migrate`.',
        'migrate_graceful' => 'Banco inacessível: as migrations ficaram para depois (`php artisan migrate`).',
        'env_missing' => 'Sem .env nem .env.example: APP_KEY e pepper não foram gerados.',
    ],

    'summary' => [
        'heading' => 'Pronto',
        'removed' => 'removido',
        'added' => 'instalado agora',
        'kept' => 'instalado',
        'absent' => 'não instalado',
        'next' => 'Próximos passos',
        'next_build' => 'Recompile o front: npm install && npm run build',
        'next_fresh' => 'Banco de desenvolvimento com a massa fictícia da demo: php artisan migrate:fresh',
        'next_horizon' => 'Sem o /admin, o /horizon fica fechado fora do ambiente local (ver app/Providers/HorizonServiceProvider.php).',
        'next_serve' => 'Suba o aplicativo: composer dev (ou docker compose up -d, ver o README).',
    ],
];
