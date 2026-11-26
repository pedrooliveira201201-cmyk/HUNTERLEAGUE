<?php
/* Tabelas do sistema. Escrito para MySQL (Hostinger); o mesmo texto roda em SQLite nos testes. */
function hl_schema(string $driver): array {
  $id   = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
  $ref  = $driver === 'sqlite' ? 'INTEGER' : 'INT UNSIGNED';
  $eng  = $driver === 'sqlite' ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
  return [
"CREATE TABLE IF NOT EXISTS config (
  chave VARCHAR(40) PRIMARY KEY,
  valor TEXT NOT NULL
)$eng",
"CREATE TABLE IF NOT EXISTS admins (
  id $id,
  usuario VARCHAR(40) NOT NULL UNIQUE,
  nome VARCHAR(80) NOT NULL,
  papel VARCHAR(12) NOT NULL DEFAULT 'atendimento',
  senha_hash VARCHAR(255) NOT NULL,
  ativo TINYINT NOT NULL DEFAULT 1,
  criado_em DATETIME NOT NULL
)$eng",
"CREATE TABLE IF NOT EXISTS parceiros (
  id $id,
  tipo VARCHAR(10) NOT NULL,
  doc VARCHAR(14) NOT NULL UNIQUE,
  nome VARCHAR(120) NOT NULL,
  loja VARCHAR(120) NULL,
  whatsapp VARCHAR(13) NOT NULL,
  email VARCHAR(120) NULL,
  cidade VARCHAR(60) NOT NULL,
  pix VARCHAR(120) NULL,
  codigo VARCHAR(16) NULL UNIQUE,
  status VARCHAR(12) NOT NULL DEFAULT 'pendente',
  nivel VARCHAR(20) NOT NULL DEFAULT 'Recruta',
  senha_hash VARCHAR(255) NOT NULL,
  termo_versao VARCHAR(10) NOT NULL,
  termo_aceito_em DATETIME NOT NULL,
  termo_ip VARCHAR(45) NOT NULL,
  criado_em DATETIME NOT NULL,
  aprovado_em DATETIME NULL,
  aprovado_por VARCHAR(40) NULL,
  obs TEXT NULL
)$eng",
"CREATE TABLE IF NOT EXISTS indicacoes (
  id $id,
  parceiro_id $ref NOT NULL,
  produto VARCHAR(10) NOT NULL DEFAULT 'nio',
  cliente_nome VARCHAR(120) NOT NULL,
  cliente_cpf VARCHAR(11) NULL,
  cliente_tel VARCHAR(11) NULL,
  cliente_email VARCHAR(120) NULL,
  cliente_cep CHAR(8) NULL,
  cliente_numero VARCHAR(10) NULL,
  origem VARCHAR(10) NOT NULL DEFAULT 'qr',
  plano VARCHAR(20) NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'Nova',
  motivo VARCHAR(60) NULL,
  instalado_em DATE NULL,
  comissao DECIMAL(10,2) NULL,
  competencia CHAR(7) NULL,
  fatura_em DATE NULL,
  saque_id $ref NULL,
  criado_em DATETIME NOT NULL,
  criado_por VARCHAR(40) NOT NULL,
  atualizado_em DATETIME NOT NULL,
  FOREIGN KEY (parceiro_id) REFERENCES parceiros(id)
)$eng",
"CREATE INDEX idx_ind_parceiro ON indicacoes (parceiro_id, criado_em)",
"CREATE INDEX idx_ind_cpf ON indicacoes (cliente_cpf)",
"CREATE INDEX idx_ind_tel ON indicacoes (cliente_tel)",
"CREATE TABLE IF NOT EXISTS leituras (
  id $id,
  parceiro_id $ref NOT NULL,
  em DATETIME NOT NULL,
  ip_hash VARCHAR(32) NOT NULL,
  agente VARCHAR(200) NULL
)$eng",
"CREATE INDEX idx_leit ON leituras (parceiro_id, em)",
"CREATE TABLE IF NOT EXISTS saques (
  id $id,
  parceiro_id $ref NOT NULL,
  valor DECIMAL(10,2) NOT NULL,
  n INT NOT NULL,
  chave_pix VARCHAR(120) NOT NULL,
  pedido_em DATETIME NOT NULL,
  pago_em DATE NULL,
  pix_id VARCHAR(160) NULL,
  arquivo VARCHAR(80) NULL,
  pago_por VARCHAR(40) NULL,
  FOREIGN KEY (parceiro_id) REFERENCES parceiros(id)
)$eng",
"CREATE INDEX idx_saque_parc ON saques (parceiro_id, pedido_em)",
"CREATE INDEX idx_ind_saque ON indicacoes (saque_id)",
"CREATE TABLE IF NOT EXISTS senha_tokens (
  id $id,
  parceiro_id $ref NOT NULL,
  hash CHAR(64) NOT NULL UNIQUE,
  expira DATETIME NOT NULL,
  usado_em DATETIME NULL
)$eng",
"CREATE TABLE IF NOT EXISTS premios (
  id $id,
  parceiro_id $ref NOT NULL,
  tipo VARCHAR(12) NOT NULL,
  alvo INT NOT NULL,
  premio VARCHAR(120) NOT NULL,
  conquistado_em DATE NOT NULL,
  entregue_em DATE NULL,
  entregue_por VARCHAR(40) NULL,
  UNIQUE (parceiro_id, tipo, alvo)
)$eng",
"CREATE TABLE IF NOT EXISTS tentativas (
  id $id,
  chave VARCHAR(80) NOT NULL,
  em DATETIME NOT NULL
)$eng",
"CREATE INDEX idx_tent ON tentativas (chave, em)",
"CREATE TABLE IF NOT EXISTS auditoria (
  id $id,
  quem VARCHAR(60) NOT NULL,
  acao VARCHAR(60) NOT NULL,
  alvo VARCHAR(80) NOT NULL DEFAULT '',
  detalhe VARCHAR(500) NOT NULL DEFAULT '',
  ip VARCHAR(45) NOT NULL,
  em DATETIME NOT NULL
)$eng",
  ];
}

const HL_CONFIG_PADRAO = [
  'site_url'        => 'https://www.hunterleague.com.br',
  'validade_dias'   => '60',
  'whatsapp'        => '553420281071',
  'mensagem_wa'     => 'Olá! Quero saber da internet Nio Fibra. Código do parceiro: {codigo}',
  'bonus_planos'    => '55|70|85|100',
  'prazo_saque'     => 'até 3 dias úteis',
  'cidades_promo'   => '',
  'cidades'         => 'todas',
  'termo_versao'    => '2.0-preliminar',
  'pixel_meta'      => '',
  'metas'           => "20|Air Fryer ou R$ 350\n60|Smart TV 50\" ou R$ 2.000\n200|iPhone 17 ou R$ 6.000",
  'smtp_host'       => 'smtp.zoho.com',
  'smtp_porta'      => '465',
  'smtp_usuario'    => '',
  'smtp_senha'      => '',
  'smtp_nome'       => 'Hunter League · CallNet',
];
