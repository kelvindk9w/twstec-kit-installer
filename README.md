# twstec/kit-installer

> **Parte do [TWS Laravel Starter Kit](https://github.com/kelvindk9w/tws-laravel-starter-kit).** O código, as issues e os
> pull requests ficam no monorepo
> [kelvindk9w/tws-laravel-starter-kit](https://github.com/kelvindk9w/tws-laravel-starter-kit) (pasta `packages/installer`); este
> repositório é o espelho só-leitura publicado a cada versão.
> Documentação: [docs/](https://github.com/kelvindk9w/tws-laravel-starter-kit/tree/desenvolvimento/docs) · Segurança:
> [SECURITY.md](SECURITY.md) · Licença: MIT ([LICENSE](LICENSE)).

O instalador do **TWS Laravel Starter Kit**, com dois comandos:

- `php artisan tws:install` — num projeto criado de um starter: você escolhe
  os módulos opcionais do kit — **contas e API** (`twstec/kit-accounts`),
  **uploads** (`twstec/kit-uploads`) e o **painel `/admin`**
  (`twstec/kit-admin`) — e se a **demonstração** (`twstec/kit-demo`) fica;
  ele aplica. `twstec/kit-foundation` e `twstec/kit-auth` vêm sempre;
- `php artisan tws:add` — num **aplicativo Laravel que já existe**: mostra os
  pacotes do kit que faltam e instala os escolhidos (ver
  [abaixo](#acrescentar-pacotes-a-um-aplicativo-que-já-existe-twsadd)).

- **Requisitos:** PHP 8.4+, Laravel 13, `twstec/kit-foundation` 2.x.
- **Licença:** MIT.
- **Onde fica:** em `require-dev` dos dois starters (`twstec/starter-livewire`
  e `twstec/starter-react`). É ferramenta de desenvolvimento: roda o Composer
  e escreve no `.env`, então não vai para a imagem de produção
  (`composer install --no-dev`).

## Instalação

Vem com os starters (o `post-create-project-cmd` o chama — e o comando único,
`composer create-project twstec/kit`, também, com a escolha do menu). Num
aplicativo que usa os pacotes do kit sem starter:

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

**Pelo ambiente:** sem a opção, `TWS_KIT_WITH` e `TWS_KIT_WITHOUT` valem como
`--with` e `--without` (a opção vence) e também desligam as perguntas. É por
onde a escolha chega ao `post-create-project-cmd` do starter, porque o
Composer não repassa opções ao script:
`TWS_KIT_WITHOUT=admin composer create-project "twstec/starter-livewire:^2.0@beta" app`.
O comando único (`twstec/kit`) usa o mesmo caminho para passar o que o menu
dele perguntou, sem perguntar de novo.

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

## Acrescentar pacotes a um aplicativo que já existe (`tws:add`)

```bash
# Durante o beta, o foundation também leva o @beta (a estabilidade só vale no
# projeto raiz); na 2.0.0 estável, basta a segunda linha, sem o @beta.
composer require "twstec/kit-foundation:^2.0@beta"
composer require --dev "twstec/kit-installer:^2.0@beta"
php artisan tws:add                                        # pergunta (Laravel Prompts)
php artisan tws:add accounts admin                         # sem perguntas
```

Mostra os módulos do kit instalados e os disponíveis; instala os escolhidos:

- a **autenticação** vem junto de qualquer módulo, se faltar;
- **recusa** o módulo cujo pré-requisito não está instalado nem escolhido,
  com a explicação e o comando certo (`tws:add uploads` sem contas → "adicione
  os dois juntos: `php artisan tws:add accounts uploads`"); no menu, a mesma
  regra não deixa a escolha passar;
- **recusa contas (e uploads) enquanto o model de usuário do aplicativo não
  estiver pronto para a autenticação do kit** (`auth.providers.users.model`
  implementando `Twstec\Kit\Auth\Contracts\AuthUser`): o pacote de contas
  liga a conta pessoal aos eventos desse model já no boot, e o `twstec/kit-auth`
  falha alto com um model que não serve — num aplicativo recém-criado (o
  `User` do esqueleto), o aplicativo deixaria de subir. A mensagem dá o
  caminho: `php artisan tws:add auth`, ajustar o model como no
  [README do twstec/kit-auth](https://github.com/kelvindk9w/tws-laravel-starter-kit/blob/desenvolvimento/packages/auth/README.md#o-model-de-usu%C3%A1rio-%C3%A9-do-aplicativo)
  e `php artisan tws:add accounts`. A autenticação e o `/admin` não dependem
  disso (o `kit-auth` confere o model só no primeiro uso);
- `composer require` dos escolhidos (e do foundation/auth como requisito
  **direto** do projeto, se hoje só vêm por dependência), com a restrição de
  versão do kit que está no `composer.json`;
- `optimize:clear`, `vendor:publish --tag=<módulo>-config` (sem
  sobrescrever), `APP_KEY` se faltar, o pepper das chaves de API com contas,
  `migrate`;
- o resumo diz o que só o aplicativo faz (model de usuário, foto, painel do
  Filament, primeiro admin).

| Opção | O que faz |
| --- | --- |
| `--force` | Permite rodar com `APP_ENV=production` (sem ela, recusa) |
| `--graceful` | Banco inacessível não reprova: as migrations ficam para depois |

## Testes

```bash
docker compose exec -w /var/packages/installer app ./vendor/bin/pest
```

A suíte sobe uma aplicação limpa (Testbench) com um projeto descartável numa
pasta temporária; o Composer é de mentira (`Contracts\Composer`), o que está
instalado também (`Contracts\Inventory`), e os comandos do artisan que o
instalador roda em processo novo passam pelo `Process::fake`. Prova as
escolhas (opções, variáveis de ambiente e perguntas), a ordem dos passos, as
recusas (produção sem `--force`, uploads sem contas, a demo com módulo
faltando), o `.env` (APP_KEY, pepper e os peppers anteriores) e a
idempotência; e, no `tws:add`, o que está disponível, a autenticação que vem
junto, o foundation virando requisito direto, a recusa de uploads sem contas
(argumentos e menu) e o resumo por módulo.
