# twstec/kit-installer

> **Parte do [TWS Laravel Starter Kit](https://github.com/kelvindk9w/tws-laravel-starter-kit).** O código, as issues e os
> pull requests ficam no monorepo
> [kelvindk9w/tws-laravel-starter-kit](https://github.com/kelvindk9w/tws-laravel-starter-kit) (pasta `packages/installer`); este
> repositório é o espelho só-leitura publicado a cada versão.
> Documentação: [docs/](https://github.com/kelvindk9w/tws-laravel-starter-kit/tree/desenvolvimento/docs) · Segurança:
> [SECURITY.md](SECURITY.md) · Licença: MIT ([LICENSE](LICENSE)).

O instalador do **TWS Laravel Starter Kit**: `php artisan tws:install`. Você
escolhe os módulos opcionais do kit — **contas e API** (`twstec/kit-accounts`),
**uploads** (`twstec/kit-uploads`) e o **painel `/admin`** (`twstec/kit-admin`)
— e se a **demonstração** (`twstec/kit-demo`) fica; ele aplica.
`twstec/kit-foundation` e `twstec/kit-auth` vêm sempre.

- **Requisitos:** PHP 8.4+, Laravel 13, `twstec/kit-foundation` 2.x.
- **Licença:** MIT.
- **Onde fica:** em `require-dev` dos dois starters (`twstec/starter-livewire`
  e `twstec/starter-react`). É ferramenta de desenvolvimento: roda o Composer
  e escreve no `.env`, então não vai para a imagem de produção
  (`composer install --no-dev`).

## Instalação

Vem com os starters (o `post-create-project-cmd` o chama). Num aplicativo que
usa os pacotes do kit sem starter:

```bash
composer require --dev "twstec/kit-installer:^2.0@beta"   # durante o beta; na 2.0.0 estável, ^2.0
```

## Por que um pacote à parte

O `twstec/kit-foundation` é a base de segurança que vai para **toda**
aplicação — inclusive as que instalam só os pacotes, sem starter. Um comando
que tira pacotes do projeto não tem lugar ali. Aqui ele serve aos dois
starters, fica fora da produção e pode ser removido depois da instalação.

## Uso

```bash
php artisan tws:install                          # pergunta (Laravel Prompts)
php artisan tws:install --no-interaction --without=admin --no-demo
php artisan tws:install --no-interaction --with=accounts,uploads --without=admin
```

| Opção | O que faz |
| --- | --- |
| `--with=a,b` | Instala (ou mantém) esses módulos opcionais |
| `--without=a,b` | Remove esses módulos opcionais |
| `--no-demo` | Remove a demonstração |
| `--force` | Permite rodar com `APP_ENV=production` (sem ela, recusa) |
| `--graceful` | Banco inacessível não reprova: as migrations ficam para depois (é o que o `post-create-project-cmd` do starter usa) |

Aplica, nesta ordem: `demo:uninstall --drop-tables` e a remoção da demo (se
ela sai), `composer remove` dos módulos não escolhidos, `composer require` dos
que faltam (com a restrição de versão do foundation no `composer.json` do
projeto), `optimize:clear`, o `.env` (do `.env.example` se faltar), a
`APP_KEY` e o pepper dedicado das chaves de API quando faltam, e `migrate`.
Idempotente. Não apaga arquivo do aplicativo — o starter esconde sozinho o que
é de um módulo ausente (`Kit::has`).

As regras: `uploads` exige `accounts`; a demonstração exige todos os módulos
opcionais (tirar um deles exige `--no-demo`); `foundation` e `auth` não saem.

Tudo em [docs/instalacao.md](https://github.com/kelvindk9w/tws-laravel-starter-kit/blob/desenvolvimento/docs/instalacao.md).

## Testes

```bash
docker compose exec -w /var/packages/installer app ./vendor/bin/pest
```

A suíte sobe uma aplicação limpa (Testbench) com um projeto descartável numa
pasta temporária; o Composer é de mentira (`Contracts\Composer`), o que está
instalado também (`Contracts\Inventory`), e os comandos do artisan que o
instalador roda em processo novo passam pelo `Process::fake`. Prova as
escolhas (opções e perguntas), a ordem dos passos, as recusas (produção sem
`--force`, uploads sem contas, a demo com módulo faltando), o `.env` (APP_KEY,
pepper e os peppers anteriores) e a idempotência.
