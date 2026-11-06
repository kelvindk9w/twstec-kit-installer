# {{name}}

Projeto Laravel criado com o [TWS Laravel Starter Kit]({{repository}}){{#kit_version}}
{{kit_version}}{{/kit_version}}, com a interface em {{interface}}. Este README é do projeto:
reescreva à vontade (o `tws:upgrade --check` não o compara com o do kit).

## Docker de desenvolvimento

Tudo roda no Docker; a máquina só precisa dele (no Windows, pelo WSL). O
nome, as portas e as senhas do projeto estão no `.env` (o instalador gravou na
criação).

```bash
docker compose up -d       # sobe tudo; na primeira vez, as dependências e as migrations
docker compose ps          # o que está no ar
docker compose stop        # para (os dados ficam)
```

- Aplicação: <{{url}}>
- E-mails enviados (Mailpit): <{{mail}}>

Sobem o site (nginx + PHP-FPM), {{#pgsql}}o PostgreSQL, {{/pgsql}}{{#redis}}o Redis, {{/redis}}o
Mailpit, a fila, o agendador e o Vite (recarga ao vivo do front). Tudo é
publicado só em `127.0.0.1`.{{#pgsql}} O banco só sai do Docker com
`COMPOSE_PROFILES=db-port` no `.env`.{{/pgsql}}
{{#sqlite}}

**Modo protótipo:** o banco é SQLite (`database/database.sqlite`). Para
produção, migre para o PostgreSQL — ver
[docs/instalacao.md]({{docs}}/instalacao.md#banco-de-dados).
{{/sqlite}}

Trocar uma porta ou o nome: edite o `.env` e rode `docker compose up -d`. No
VS Code: extensão **Dev Containers** → "Reopen in Container" (entra no
container `app`).
{{#admin}}

O painel `/admin` (<{{url}}/admin>) é para quem for promovido:

```bash
docker compose exec app php artisan user:make-admin email@exemplo.com
```
{{/admin}}

## Comandos do dia a dia

```bash
docker compose exec app php artisan test             # a suíte (Pest)
docker compose exec app composer require vendor/pacote   # dependência PHP (o Composer do container)
docker compose exec vite npm install pacote           # dependência do front

scripts/verificar          # o mesmo conjunto do CI: segredos, Pint, Larastan, auditorias, build e a suíte
scripts/verificar --e2e    # e também o E2E
scripts/e2e                # só o E2E (Playwright), com o projeto no ar
```

O CI (`.github/workflows/ci.yml`) roda à mão e uma vez por semana; o
`scripts/verificar` roda os mesmos passos na máquina.

## Personalizar

- **Textos, e-mails, configuração e telas:** crie no projeto só o arquivo (ou
  as chaves) que muda; o resto continua vindo dos pacotes `twstec/kit-*`.
  {{#react}}Para mudar uma tela do kit, copie só aquele arquivo de
  `vendor/twstec/kit-foundation/resources/react/` para o mesmo caminho em
  `resources/js/`.{{/react}}{{#livewire}}Para mudar uma tela do kit, copie só aquele arquivo de
  `vendor/twstec/kit-foundation/resources/livewire/` para o mesmo caminho em
  `resources/views/`.{{/livewire}} Ver
  [docs/personalizacao.md]({{docs}}/personalizacao.md).
- **O que o projeto sobrescreve do kit:**
  `docker compose exec app php artisan kit:overrides`.
- **Atualizar o kit:**
  `docker compose exec app composer update 'twstec/*' --with-all-dependencies`
  e, para os arquivos que ficam no projeto (Docker, CI, scripts, `.env.example`…),
  `docker compose exec app php artisan tws:upgrade --check` — ver
  [docs/personalizacao.md]({{docs}}/personalizacao.md#o-que-fica-no-projeto-twsupgrade---check).

## Documentação do kit

- [Instalação e o Docker de desenvolvimento]({{docs}}/instalacao.md)
- [Personalização]({{docs}}/personalizacao.md)
- [Testes]({{docs}}/testes.md)
- [Segurança]({{docs}}/seguranca.md)
- [Produção]({{docs}}/producao.md)
- [Todas as páginas]({{docs}})

A licença do kit (MIT) está em `NOTICE-KIT-MIT.txt`; a do projeto, no
`composer.json`.
