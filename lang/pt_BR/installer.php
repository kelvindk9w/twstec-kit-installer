<?php

declare(strict_types=1);

// Textos do instalador (php artisan tws:install) — twstec/kit-installer.
// O aplicativo vence: um lang/<idioma>/installer.php dele sobrescreve estas
// chaves.

return [
    'description' => 'Escolhe os módulos opcionais do kit (contas, uploads, painel /admin, webhooks) e a demonstração, e aplica: Composer, migrations, APP_KEY e pepper',
    'options' => [
        'with' => 'Módulos opcionais a instalar ou manter, separados por vírgula (accounts,uploads,admin,webhooks); sem a opção, vale a variável TWS_KIT_WITH',
        'without' => 'Módulos opcionais a remover, separados por vírgula; sem a opção, vale a variável TWS_KIT_WITHOUT',
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
        'webhooks' => 'Webhooks de saída assinados (twstec/kit-webhooks)',
        'demo' => 'Demonstração do kit (twstec/kit-demo)',
    ],

    'select_label' => 'Quais módulos opcionais você quer?',
    'select_hint' => 'Espaço marca e desmarca; Enter confirma. Uploads e Webhooks precisam de Contas.',
    'keep_demo' => 'Manter a demonstração do kit (landings, vitrine, contas demo — só para desenvolvimento)?',
    'demo_must_go' => 'A demonstração exige :modules. Com esta escolha ela será removida. Continuar?',
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
        'uploads_key' => 'Chave dos uploads confidenciais (UPLOADS_ENCRYPTION_KEY)',
        'uploads_key_generated' => 'gerada (guarde uma cópia no cofre de segredos: sem ela, os arquivos confidenciais não abrem)',
        'uploads_key_kept' => 'já definida',
        'migrate' => 'Migrations (migrate)',
    ],

    'failures' => [
        'demo_uninstall' => 'demo:uninstall falhou (o banco está acessível?). Nada foi removido.',
        'demo_uninstall_graceful' => 'demo:uninstall falhou (banco inacessível?); seguindo sem ele — rode `php artisan demo:uninstall --drop-tables` num banco que já rodou a demo.',
        'composer' => 'O Composer falhou. Veja a saída acima; o composer.json e o composer.lock ficam como o Composer os deixou.',
        'migrate' => 'As migrations falharam. Confira o banco no .env e rode `php artisan migrate`.',
        'migrate_graceful' => 'Banco inacessível: as migrations ficaram para depois (`php artisan migrate`).',
        'env_missing' => 'Sem .env nem .env.example: APP_KEY, pepper e a chave dos uploads confidenciais não foram gerados. Crie o .env e rode `php artisan key:generate` e, com uploads, `php artisan uploads:encryption-key`.',
    ],

    // Docker de desenvolvimento do projeto criado (compose.yaml na raiz).
    'dev' => [
        'step' => 'Docker de desenvolvimento',
        'configured' => 'projeto :name, número :slot — :url',
        'kept' => 'já configurado (COMPOSE_PROJECT_NAME=:name)',
        'name_invalid' => 'Nome de projeto inválido em TWS_KIT_NAME: :name. Use letras minúsculas, números e hífen, começando por letra, de 2 a 40 caracteres (sugestão: :suggestion). Nada foi alterado.',
        'name_taken' => 'Já existe um projeto Docker chamado :name nesta máquina (containers ou volumes, mesmo parado). Use outro nome em TWS_KIT_NAME — sugestão: :suggestion. Nada foi alterado.',
        'slot_invalid' => 'Número de projeto inválido em TWS_KIT_SLOT: :slot. Use de 0 a 99. Nada foi alterado.',
        'slot_busy' => 'O número :slot (TWS_KIT_SLOT) está em uso — portas :occupants. O primeiro número com as quatro portas livres é :suggestion. Nada foi alterado.',
        'no_free_slot' => 'Nenhum número de 0 a 99 tem as quatro portas livres (site 808N, e-mails 802N, Vite 803N, banco 804N). Pare projetos que não está usando e rode de novo. Nada foi alterado.',
        'expose_invalid' => 'Valor inválido em TWS_KIT_EXPOSE_DB: :value. Use 1 (publicar o banco) ou 0. Nada foi alterado.',
        'migrate_on_up' => 'no primeiro docker compose up -d (o banco do projeto roda no Docker)',
        'other_program' => 'outro programa',
        'vendor_invalid' => 'Vendor inválido em TWS_KIT_VENDOR: :vendor. Use letras minúsculas, números e hífen (o nome do pacote no composer.json fica <vendor>/<nome do projeto>). Nada foi alterado.',
        'license_invalid' => 'Licença inválida em TWS_KIT_LICENSE: :license. Use um identificador SPDX (MIT, Apache-2.0…) ou proprietary. Nada foi alterado.',
        'identity_step' => 'Identidade do projeto',
        'identity' => 'composer.json: :package, licença :license',
        'identity_notice' => 'composer.json: :package, licença :license — a licença MIT do kit ficou em :notice',
        'notice_header' => "Este projeto foi criado a partir do TWS Laravel Starter Kit, distribuído sob a\nlicença MIT abaixo. A MIT exige manter este aviso junto das partes do kit que\no projeto usa. Ela NÃO é a licença do projeto: a do projeto está no\ncomposer.json (\"license\": \":license\") — troque-a lá, e acrescente um LICENSE\npróprio, se for o caso.\n\n----------------------------------------------------------------------------",
        'tests_step' => 'Banco de teste (PostgreSQL)',
        'tests' => ':database (phpunit.pgsql.xml e o db-init do compose.yaml)',
        'ci_step' => 'CI',
        'ci' => ':workflow (manual e semanal; ver o README) e scripts/verificar',
        'next' => 'Suba o projeto: docker compose up -d — depois abra :url (e-mails em :mail).',
    ],

    'extensions' => [
        'missing' => 'Faltam extensões do PHP nesta máquina: :extensions. Há duas saídas:',
        'install' => '1) Instalar as extensões no PHP desta máquina (confira com `php -m`):',
        'windows' => '   • Windows: no php.ini (`php --ini` mostra onde está), tire o ";" do começo das linhas :lines (o PHP de windows.php.net já traz os arquivos).',
        'linux' => '   • Ubuntu/Debian: sudo apt install :packages',
        'mac' => '   • macOS (Homebrew): o PHP do `brew install php` já traz essas extensões; confira qual PHP o terminal usa (`which php`).',
        'docker' => '2) Ou usar o caminho só com o Docker, que já traz tudo: apague esta pasta, baixe o twstec-kit (Code → Download ZIP) e, dentro da pasta dele, rode `docker compose run --rm instalar`.',
        'windows_horizon' => 'Windows: o Horizon (o painel das filas) precisa das extensões pcntl e posix, que o PHP do Windows não tem — elas foram ignoradas na instalação, e só o Horizon fica de fora. O resto roda normalmente; para processar a fila, use `php artisan queue:work`. Nos próximos comandos do Composer neste projeto, defina antes `$env:COMPOSER_IGNORE_PLATFORM_REQ = "ext-pcntl,ext-posix"`. O caminho só com o Docker (docker compose run --rm instalar) roda tudo, inclusive o Horizon.',
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

    // php artisan tws:add — acrescentar pacotes do kit a um aplicativo que já existe.
    'add' => [
        'description' => 'Acrescenta pacotes do kit (autenticação, contas, uploads, painel /admin, webhooks) a um aplicativo Laravel que já existe: Composer, configuração, .env e migrations',
        'arguments' => [
            'modules' => 'Módulos a acrescentar, separados por espaço (auth accounts uploads admin webhooks)',
        ],
        'intro' => 'Pacotes do TWS Laravel Starter Kit neste aplicativo:',
        'available' => 'disponível',
        'nothing_available' => 'Todos os pacotes do kit já estão instalados.',
        'nothing_chosen' => 'Nenhum módulo escolhido: nada foi alterado.',
        'select_label' => 'Quais módulos acrescentar?',
        'select_hint' => 'Espaço marca e desmarca; Enter confirma. Uploads e Webhooks precisam de Contas; a autenticação vem junto de qualquer módulo.',
        'already_installed' => ':module já está instalado.',
        'will_install' => 'será instalado',
        'comes_along' => 'vem junto (obrigatório no kit)',
        'next' => 'O que o aplicativo faz agora',
        'errors' => [
            'none_given' => 'Diga quais módulos acrescentar (sem terminal não há pergunta): php artisan tws:add :modules',
            'add_together' => 'Adicione os dois juntos: :command',
            'user_model' => ':modules precisa do model de usuário do aplicativo (:model) com a autenticação do kit: ele ainda não implementa Twstec\\Kit\\Auth\\Contracts\\AuthUser, e sem isso o aplicativo não sobe. Nada foi alterado. O caminho: :firstajuste o model como no README do twstec/kit-auth (vendor/twstec/kit-auth/README.md, "O model de usuário é do aplicativo") e rode :command.',
        ],
        'next_steps' => [
            'auth' => 'Autenticação: o model de usuário do aplicativo implementa Twstec\\Kit\\Auth\\Contracts\\AuthUser com a trait KitAuthenticatable (vendor/twstec/kit-auth/README.md, "O model de usuário é do aplicativo").',
            'accounts' => 'Contas: nada a mais no model — a conta pessoal nasce com cada pessoa; o pepper das chaves de API foi gerado no .env (vendor/twstec/kit-accounts/README.md).',
            'uploads' => 'Uploads: para a foto de perfil, a trait HasAvatar no model e a coluna users.avatar_upload_id numa migration do aplicativo (vendor/twstec/kit-uploads/README.md).',
            'webhooks' => 'Webhooks: declare os eventos do aplicativo em webhooks.events (WEBHOOKS_EVENTS), dispare com Webhooks::dispatch($conta, \'order.created\', [...]) dentro da transação da mudança e deixe a fila e o agendador rodando; as telas de endpoints são do aplicativo (vendor/twstec/kit-webhooks/README.md).',
            'admin' => 'Painel /admin: um PanelProvider do Filament com AdminPlugin::make(), o model com FilamentUser e a trait AccessesAdminPanel, a coluna users.is_admin; depois, php artisan user:make-admin email@exemplo.com (vendor/twstec/kit-admin/README.md).',
        ],
    ],
];
