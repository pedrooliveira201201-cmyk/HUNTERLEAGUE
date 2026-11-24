# Hunter League — app de parceiros CALLNET | NIO FIBRA

Produção: https://www.hunterleague.com.br (Hostinger, PHP + MySQL).

## Como funciona o deploy
- `main` = produção. Todo push na `main` publica no servidor via FTP (GitHub Actions, `.github/workflows/deploy.yml`).
- O trabalho é feito na branch `dev`; o deploy é o merge de `dev` em `main`.
- O deploy só envia arquivos versionados aqui. Ele **não** toca em `config.php`, `recrutamento-config.php` nem em `arquivos/` no servidor.

## Secrets necessários (GitHub → Settings → Secrets and variables → Actions)
- `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD` (conta FTP da Hostinger)
- `FTP_DIR` (opcional): pasta de destino, ex.: `./` ou `public_html/`, conforme a raiz da conta FTP.

## Nunca versionar
`config.php`, `recrutamento-config.php`, banco de dados, comprovantes em `arquivos/`.

Backup inicial: cópia completa do `public_html` de 24/11/2026 (`public_html.zip`, fora do repositório).
