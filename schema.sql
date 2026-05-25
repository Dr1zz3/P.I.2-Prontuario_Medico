-- ==============================================================
-- PROJETO ACADÊMICO: Prontuário Médico (consultório único)
-- SGBD: MySQL 8.0+
-- Baseado nos requisitos da reunião com a médica-cliente.
--
-- REGRA CENTRAL — IMUTABILIDADE PÓS-ASSINATURA
-- Evoluções, sinais vitais, receitas e solicitações de exame têm
-- ciclo: RASCUNHO -> ASSINADO -> (opcional) SUSPENSO.
--   - RASCUNHO: editável pelo autor (botão "Salvar").
--   - ASSINADO: imutável (botão "Assinar"). Triggers bloqueiam UPDATE/DELETE.
--   - SUSPENSO: marcado como suspenso (não apagado), exige justificativa.
--     Só o próprio autor pode suspender (validação na aplicação).
-- ==============================================================

CREATE DATABASE IF NOT EXISTS prontuario_medico
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE prontuario_medico;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS resultados_exame;
DROP TABLE IF EXISTS solicitacoes_exame;
DROP TABLE IF EXISTS tipos_exame;
DROP TABLE IF EXISTS receita_itens;
DROP TABLE IF EXISTS receitas;
DROP TABLE IF EXISTS medicamentos;
DROP TABLE IF EXISTS sinais_vitais;
DROP TABLE IF EXISTS evolucao_cid;
DROP TABLE IF EXISTS evolucoes;
DROP TABLE IF EXISTS agendamentos;
DROP TABLE IF EXISTS templates_texto;
DROP TABLE IF EXISTS medicos;
DROP TABLE IF EXISTS pacientes;
DROP TABLE IF EXISTS convenios;
DROP TABLE IF EXISTS cid10;
DROP TABLE IF EXISTS especialidades;
DROP TABLE IF EXISTS usuarios;
DROP TABLE IF EXISTS clinica;
SET FOREIGN_KEY_CHECKS = 1;

-- ==============================================================
-- 1. CLÍNICA (singleton — dados do timbre/cabeçalho de receitas)
-- ==============================================================

CREATE TABLE clinica (
  id              TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
  nome            VARCHAR(120) NOT NULL,
  cnpj            CHAR(14),
  telefone        VARCHAR(20),
  email           VARCHAR(120),
  cep             CHAR(8),
  logradouro      VARCHAR(150),
  numero          VARCHAR(10),
  complemento     VARCHAR(50),
  bairro          VARCHAR(80),
  cidade          VARCHAR(80),
  uf              CHAR(2),
  logo_url        VARCHAR(500),                    -- caminho da imagem do logo
  CONSTRAINT chk_clinica_unica CHECK (id = 1)
) ENGINE=InnoDB;

-- ==============================================================
-- 2. CATÁLOGOS BASE
-- ==============================================================

CREATE TABLE especialidades (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome         VARCHAR(80) NOT NULL UNIQUE,
  descricao    VARCHAR(255),
  ativo        BOOLEAN NOT NULL DEFAULT TRUE
) ENGINE=InnoDB;

CREATE TABLE cid10 (
  codigo       VARCHAR(10) PRIMARY KEY,           -- ex.: J45.0
  descricao    VARCHAR(255) NOT NULL,
  categoria    VARCHAR(80),
  INDEX idx_cid10_descricao (descricao)
) ENGINE=InnoDB;

CREATE TABLE convenios (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome          VARCHAR(100) NOT NULL,
  registro_ans  VARCHAR(20),
  ativo         BOOLEAN NOT NULL DEFAULT TRUE
) ENGINE=InnoDB;

-- ==============================================================
-- 3. USUÁRIOS DO SISTEMA (login)
-- ==============================================================

CREATE TABLE usuarios (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome           VARCHAR(120) NOT NULL,
  email          VARCHAR(120) NOT NULL UNIQUE,
  senha_hash     VARCHAR(255) NOT NULL,
  perfil         ENUM('ADMIN','MEDICO','RECEPCAO','TECNICO_ENFERMAGEM') NOT NULL,
  ativo          BOOLEAN NOT NULL DEFAULT TRUE,
  ultimo_acesso  DATETIME,
  criado_em      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ==============================================================
-- 4. PESSOAS
-- ==============================================================

CREATE TABLE pacientes (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cpf                 CHAR(11) NOT NULL UNIQUE,
  nome                VARCHAR(120) NOT NULL,
  nome_social         VARCHAR(120),
  data_nascimento     DATE NOT NULL,
  sexo                ENUM('M','F','O') NOT NULL,
  rg                  VARCHAR(20),
  cartao_sus          VARCHAR(15),
  email               VARCHAR(120),
  telefone            VARCHAR(20),
  cep                 CHAR(8),
  logradouro          VARCHAR(150),
  numero              VARCHAR(10),
  complemento         VARCHAR(50),
  bairro              VARCHAR(80),
  cidade              VARCHAR(80),
  uf                  CHAR(2),
  tipo_sanguineo      ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-'),
  alergias            TEXT,
  convenio_id         INT UNSIGNED,
  numero_convenio     VARCHAR(40),
  consentimento_lgpd  BOOLEAN NOT NULL DEFAULT FALSE,
  data_consentimento  DATETIME,
  ativo               BOOLEAN NOT NULL DEFAULT TRUE,
  criado_em           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_pac_convenio FOREIGN KEY (convenio_id) REFERENCES convenios(id),
  CONSTRAINT chk_pac_cpf CHECK (cpf REGEXP '^[0-9]{11}$'),
  INDEX idx_pac_nome (nome)
) ENGINE=InnoDB;

CREATE TABLE medicos (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id        INT UNSIGNED NOT NULL UNIQUE,
  cpf               CHAR(11) NOT NULL UNIQUE,
  nome              VARCHAR(120) NOT NULL,
  crm_numero        VARCHAR(15) NOT NULL,
  crm_uf            CHAR(2) NOT NULL,
  especialidade_id  INT UNSIGNED NOT NULL,
  telefone          VARCHAR(20),
  ativo             BOOLEAN NOT NULL DEFAULT TRUE,

  CONSTRAINT uk_med_crm UNIQUE (crm_numero, crm_uf),
  CONSTRAINT fk_med_usuario       FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  CONSTRAINT fk_med_especialidade FOREIGN KEY (especialidade_id) REFERENCES especialidades(id)
) ENGINE=InnoDB;

-- ==============================================================
-- 5. AGENDA
-- ==============================================================

CREATE TABLE agendamentos (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  paciente_id    INT UNSIGNED NOT NULL,
  medico_id      INT UNSIGNED NOT NULL,
  data_hora      DATETIME NOT NULL,
  duracao_min    SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  tipo           ENUM('PRIMEIRA_CONSULTA','RETORNO','URGENCIA') NOT NULL DEFAULT 'PRIMEIRA_CONSULTA',
  status         ENUM('AGENDADO','CONFIRMADO','EM_ATENDIMENTO','REALIZADO','CANCELADO','FALTOU') NOT NULL DEFAULT 'AGENDADO',
  observacoes    VARCHAR(500),
  criado_por     INT UNSIGNED,
  criado_em      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_ag_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(id),
  CONSTRAINT fk_ag_medico   FOREIGN KEY (medico_id) REFERENCES medicos(id),
  CONSTRAINT fk_ag_criador  FOREIGN KEY (criado_por) REFERENCES usuarios(id),
  INDEX idx_ag_data (data_hora),
  INDEX idx_ag_medico_data (medico_id, data_hora)
) ENGINE=InnoDB;

-- ==============================================================
-- 6. EVOLUÇÕES (história clínica — cada consulta = 1 evolução nova)
-- Modelo SOAP. Ciclo: RASCUNHO -> ASSINADO -> SUSPENSO.
-- ==============================================================

CREATE TABLE evolucoes (
  id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  agendamento_id          INT UNSIGNED UNIQUE,    -- 1:1 (NULL p/ encaixe)
  paciente_id             INT UNSIGNED NOT NULL,
  medico_id               INT UNSIGNED NOT NULL,  -- autor (médico responsável)
  autor_usuario_id        INT UNSIGNED NOT NULL,  -- usuário logado que criou
  data_atendimento        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  -- SOAP
  queixa_principal        TEXT,                   -- S: subjetivo
  anamnese                TEXT,                   -- S: HDA
  exame_fisico            TEXT,                   -- O: objetivo
  hipotese_diagnostica    TEXT,                   -- A: assessment
  conduta                 TEXT,                   -- P: plano

  -- ciclo de assinatura/imutabilidade
  status                  ENUM('RASCUNHO','ASSINADO','SUSPENSO') NOT NULL DEFAULT 'RASCUNHO',
  assinado_em             DATETIME,
  suspenso_em             DATETIME,
  suspenso_por            INT UNSIGNED,           -- deve ser igual a autor_usuario_id (validar na aplicação)
  justificativa_suspensao TEXT,

  criado_em               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_evo_agendamento FOREIGN KEY (agendamento_id) REFERENCES agendamentos(id),
  CONSTRAINT fk_evo_paciente    FOREIGN KEY (paciente_id) REFERENCES pacientes(id),
  CONSTRAINT fk_evo_medico      FOREIGN KEY (medico_id) REFERENCES medicos(id),
  CONSTRAINT fk_evo_autor       FOREIGN KEY (autor_usuario_id) REFERENCES usuarios(id),
  CONSTRAINT fk_evo_suspensor   FOREIGN KEY (suspenso_por) REFERENCES usuarios(id),
  INDEX idx_evo_paciente_data (paciente_id, data_atendimento),
  INDEX idx_evo_status (status)
) ENGINE=InnoDB;

-- N:N evolução x CID-10
CREATE TABLE evolucao_cid (
  evolucao_id   INT UNSIGNED NOT NULL,
  cid10_codigo  VARCHAR(10) NOT NULL,
  principal     BOOLEAN NOT NULL DEFAULT FALSE,
  PRIMARY KEY (evolucao_id, cid10_codigo),
  CONSTRAINT fk_ec_evolucao FOREIGN KEY (evolucao_id) REFERENCES evolucoes(id) ON DELETE CASCADE,
  CONSTRAINT fk_ec_cid      FOREIGN KEY (cid10_codigo) REFERENCES cid10(codigo)
) ENGINE=InnoDB;

-- ==============================================================
-- 7. SINAIS VITAIS (preenchido pela TÉCNICA DE ENFERMAGEM)
-- Tabela separada da evolução: o médico vê (read-only), mas não cria
-- nem edita. Mesmo ciclo de assinatura.
-- ==============================================================

CREATE TABLE sinais_vitais (
  id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  paciente_id             INT UNSIGNED NOT NULL,
  agendamento_id          INT UNSIGNED,            -- opcional: liga a um agendamento
  autor_usuario_id        INT UNSIGNED NOT NULL,   -- técnica de enfermagem que mediu
  data_afericao           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  pressao_sistolica       SMALLINT UNSIGNED,       -- mmHg
  pressao_diastolica      SMALLINT UNSIGNED,
  frequencia_cardiaca     SMALLINT UNSIGNED,       -- bpm
  frequencia_respiratoria SMALLINT UNSIGNED,       -- irpm
  saturacao_o2            TINYINT UNSIGNED,        -- %
  temperatura             DECIMAL(4,1),            -- °C
  peso_kg                 DECIMAL(5,2),
  altura_cm               SMALLINT UNSIGNED,
  observacoes             TEXT,

  status                  ENUM('RASCUNHO','ASSINADO','SUSPENSO') NOT NULL DEFAULT 'RASCUNHO',
  assinado_em             DATETIME,
  suspenso_em             DATETIME,
  suspenso_por            INT UNSIGNED,
  justificativa_suspensao TEXT,

  criado_em               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_sv_paciente    FOREIGN KEY (paciente_id) REFERENCES pacientes(id),
  CONSTRAINT fk_sv_agendamento FOREIGN KEY (agendamento_id) REFERENCES agendamentos(id),
  CONSTRAINT fk_sv_autor       FOREIGN KEY (autor_usuario_id) REFERENCES usuarios(id),
  CONSTRAINT fk_sv_suspensor   FOREIGN KEY (suspenso_por) REFERENCES usuarios(id),
  INDEX idx_sv_paciente_data (paciente_id, data_afericao)
) ENGINE=InnoDB;

-- ==============================================================
-- 8. RECEITAS
-- Apenas dois tipos: SIMPLES (medicamentos comuns/antialérgicos/dor)
-- e ANTIBIOTICO (modelo padronizado em duas vias).
-- Tarja preta NÃO é emitida pelo sistema (exige receituário físico
-- da Vigilância Sanitária).
-- ==============================================================

CREATE TABLE medicamentos (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome_comercial   VARCHAR(120) NOT NULL,
  principio_ativo  VARCHAR(120) NOT NULL,
  apresentacao     VARCHAR(80),                  -- ex.: "comprimido 500mg"
  fabricante       VARCHAR(80),
  registro_anvisa  VARCHAR(20),
  e_antibiotico    BOOLEAN NOT NULL DEFAULT FALSE,
  ativo            BOOLEAN NOT NULL DEFAULT TRUE,
  INDEX idx_med_nome (nome_comercial),
  INDEX idx_med_principio (principio_ativo)
) ENGINE=InnoDB;

CREATE TABLE receitas (
  id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  evolucao_id             INT UNSIGNED NOT NULL,
  paciente_id             INT UNSIGNED NOT NULL,
  medico_id               INT UNSIGNED NOT NULL,
  autor_usuario_id        INT UNSIGNED NOT NULL,
  tipo                    ENUM('SIMPLES','ANTIBIOTICO') NOT NULL DEFAULT 'SIMPLES',
  data_emissao            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  validade_dias           SMALLINT UNSIGNED NOT NULL DEFAULT 30,

  -- campos extras p/ receita de antibiótico (modelo especial em 2 vias)
  comprador_nome          VARCHAR(120),
  comprador_cpf           CHAR(11),
  comprador_rg            VARCHAR(20),

  observacoes             TEXT,

  -- ciclo de assinatura
  status                  ENUM('RASCUNHO','ASSINADO','SUSPENSO') NOT NULL DEFAULT 'RASCUNHO',
  assinado_em             DATETIME,
  suspenso_em             DATETIME,
  suspenso_por            INT UNSIGNED,
  justificativa_suspensao TEXT,

  criado_em               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_rec_evolucao  FOREIGN KEY (evolucao_id) REFERENCES evolucoes(id),
  CONSTRAINT fk_rec_paciente  FOREIGN KEY (paciente_id) REFERENCES pacientes(id),
  CONSTRAINT fk_rec_medico    FOREIGN KEY (medico_id) REFERENCES medicos(id),
  CONSTRAINT fk_rec_autor     FOREIGN KEY (autor_usuario_id) REFERENCES usuarios(id),
  CONSTRAINT fk_rec_suspensor FOREIGN KEY (suspenso_por) REFERENCES usuarios(id)
) ENGINE=InnoDB;

CREATE TABLE receita_itens (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  receita_id      INT UNSIGNED NOT NULL,
  medicamento_id  INT UNSIGNED,                  -- FK opcional: pode ser texto livre
  descricao_livre VARCHAR(255),                  -- usado quando o médico digita sem catálogo
  posologia       VARCHAR(255) NOT NULL,         -- ex.: "1 cp 8/8h por 7 dias"
  quantidade      VARCHAR(80),                   -- texto p/ flexibilidade ("1 caixa", "30 cp")
  uso_continuo    BOOLEAN NOT NULL DEFAULT FALSE,

  CONSTRAINT fk_ri_receita FOREIGN KEY (receita_id) REFERENCES receitas(id) ON DELETE CASCADE,
  CONSTRAINT fk_ri_med     FOREIGN KEY (medicamento_id) REFERENCES medicamentos(id),
  CONSTRAINT chk_ri_med_ou_texto CHECK (medicamento_id IS NOT NULL OR descricao_livre IS NOT NULL)
) ENGINE=InnoDB;

-- ==============================================================
-- 9. EXAMES (solicitação na consulta + resultado posterior)
-- ==============================================================

CREATE TABLE tipos_exame (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo_tuss  VARCHAR(10),
  nome         VARCHAR(120) NOT NULL,
  categoria    ENUM('LABORATORIAL','IMAGEM','CARDIOLOGICO','OUTROS') NOT NULL DEFAULT 'LABORATORIAL',
  preparo      TEXT,
  ativo        BOOLEAN NOT NULL DEFAULT TRUE,
  INDEX idx_tex_nome (nome)
) ENGINE=InnoDB;

CREATE TABLE solicitacoes_exame (
  id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  evolucao_id             INT UNSIGNED NOT NULL,
  paciente_id             INT UNSIGNED NOT NULL,
  medico_id               INT UNSIGNED NOT NULL,
  autor_usuario_id        INT UNSIGNED NOT NULL,
  tipo_exame_id           INT UNSIGNED NOT NULL,
  data_solicitacao        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  justificativa           TEXT,

  status                  ENUM('RASCUNHO','ASSINADO','SUSPENSO') NOT NULL DEFAULT 'RASCUNHO',
  assinado_em             DATETIME,
  suspenso_em             DATETIME,
  suspenso_por            INT UNSIGNED,
  justificativa_suspensao TEXT,

  CONSTRAINT fk_se_evolucao  FOREIGN KEY (evolucao_id) REFERENCES evolucoes(id),
  CONSTRAINT fk_se_paciente  FOREIGN KEY (paciente_id) REFERENCES pacientes(id),
  CONSTRAINT fk_se_medico    FOREIGN KEY (medico_id) REFERENCES medicos(id),
  CONSTRAINT fk_se_autor     FOREIGN KEY (autor_usuario_id) REFERENCES usuarios(id),
  CONSTRAINT fk_se_tipo      FOREIGN KEY (tipo_exame_id) REFERENCES tipos_exame(id),
  CONSTRAINT fk_se_suspensor FOREIGN KEY (suspenso_por) REFERENCES usuarios(id)
) ENGINE=InnoDB;

CREATE TABLE resultados_exame (
  id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  solicitacao_id       INT UNSIGNED NOT NULL UNIQUE,
  data_resultado       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  laudo                TEXT,
  arquivo_url          VARCHAR(500),
  responsavel_tecnico  VARCHAR(120),

  CONSTRAINT fk_re_solicitacao FOREIGN KEY (solicitacao_id) REFERENCES solicitacoes_exame(id)
) ENGINE=InnoDB;

-- ==============================================================
-- 10. TEMPLATES PESSOAIS (nice-to-have)
-- Cada profissional salva modelos de texto reutilizáveis
-- (ex.: modelo de evolução de retorno, modelo de receita).
-- ==============================================================

CREATE TABLE templates_texto (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id    INT UNSIGNED NOT NULL,
  contexto      ENUM('EVOLUCAO','RECEITA','EXAME') NOT NULL,
  titulo        VARCHAR(120) NOT NULL,
  conteudo      TEXT NOT NULL,
  criado_em     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_tpl_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  INDEX idx_tpl_usuario_ctx (usuario_id, contexto)
) ENGINE=InnoDB;

-- ==============================================================
-- 11. TRIGGERS DE IMUTABILIDADE
-- Bloqueiam UPDATE/DELETE em registros já assinados, exceto a
-- transição ASSINADO -> SUSPENSO (que exige justificativa).
-- A regra "só o autor pode suspender" deve ser validada na aplicação
-- (o trigger não tem contexto do usuário logado).
-- ==============================================================

DELIMITER $$

-- ----- evolucoes -----
CREATE TRIGGER trg_evolucoes_bu BEFORE UPDATE ON evolucoes
FOR EACH ROW
BEGIN
  IF OLD.status = 'SUSPENSO' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Evolução suspensa é imutável.';
  END IF;
  IF OLD.status = 'ASSINADO' THEN
    IF NEW.status <> 'SUSPENSO' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Evolução assinada só pode ser suspensa.';
    END IF;
    IF NEW.justificativa_suspensao IS NULL OR TRIM(NEW.justificativa_suspensao) = '' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Justificativa obrigatória para suspender.';
    END IF;
    IF NOT (NEW.queixa_principal     <=> OLD.queixa_principal)
    OR NOT (NEW.anamnese             <=> OLD.anamnese)
    OR NOT (NEW.exame_fisico         <=> OLD.exame_fisico)
    OR NOT (NEW.hipotese_diagnostica <=> OLD.hipotese_diagnostica)
    OR NOT (NEW.conduta              <=> OLD.conduta) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Conteúdo clínico de evolução assinada não pode ser alterado.';
    END IF;
  END IF;
END$$

CREATE TRIGGER trg_evolucoes_bd BEFORE DELETE ON evolucoes
FOR EACH ROW
BEGIN
  IF OLD.status IN ('ASSINADO','SUSPENSO') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Não é permitido excluir evolução assinada/suspensa.';
  END IF;
END$$

-- ----- sinais_vitais -----
CREATE TRIGGER trg_sinais_vitais_bu BEFORE UPDATE ON sinais_vitais
FOR EACH ROW
BEGIN
  IF OLD.status = 'SUSPENSO' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Registro de sinais vitais suspenso é imutável.';
  END IF;
  IF OLD.status = 'ASSINADO' THEN
    IF NEW.status <> 'SUSPENSO' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sinais vitais assinados só podem ser suspensos.';
    END IF;
    IF NEW.justificativa_suspensao IS NULL OR TRIM(NEW.justificativa_suspensao) = '' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Justificativa obrigatória para suspender.';
    END IF;
    IF NOT (NEW.pressao_sistolica       <=> OLD.pressao_sistolica)
    OR NOT (NEW.pressao_diastolica      <=> OLD.pressao_diastolica)
    OR NOT (NEW.frequencia_cardiaca     <=> OLD.frequencia_cardiaca)
    OR NOT (NEW.frequencia_respiratoria <=> OLD.frequencia_respiratoria)
    OR NOT (NEW.saturacao_o2            <=> OLD.saturacao_o2)
    OR NOT (NEW.temperatura             <=> OLD.temperatura)
    OR NOT (NEW.peso_kg                 <=> OLD.peso_kg)
    OR NOT (NEW.altura_cm               <=> OLD.altura_cm)
    OR NOT (NEW.observacoes             <=> OLD.observacoes)
    OR NOT (NEW.data_afericao           <=> OLD.data_afericao)
    OR NOT (NEW.paciente_id             <=> OLD.paciente_id)
    OR NOT (NEW.autor_usuario_id        <=> OLD.autor_usuario_id) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Conteúdo de sinais vitais assinados não pode ser alterado.';
    END IF;
  END IF;
END$$

CREATE TRIGGER trg_sinais_vitais_bd BEFORE DELETE ON sinais_vitais
FOR EACH ROW
BEGIN
  IF OLD.status IN ('ASSINADO','SUSPENSO') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Não é permitido excluir sinais vitais assinados/suspensos.';
  END IF;
END$$

-- ----- receitas -----
CREATE TRIGGER trg_receitas_bu BEFORE UPDATE ON receitas
FOR EACH ROW
BEGIN
  IF OLD.status = 'SUSPENSO' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Receita suspensa é imutável.';
  END IF;
  IF OLD.status = 'ASSINADO' THEN
    IF NEW.status <> 'SUSPENSO' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Receita assinada só pode ser suspensa.';
    END IF;
    IF NEW.justificativa_suspensao IS NULL OR TRIM(NEW.justificativa_suspensao) = '' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Justificativa obrigatória para suspender.';
    END IF;
    IF NOT (NEW.tipo            <=> OLD.tipo)
    OR NOT (NEW.validade_dias   <=> OLD.validade_dias)
    OR NOT (NEW.comprador_nome  <=> OLD.comprador_nome)
    OR NOT (NEW.comprador_cpf   <=> OLD.comprador_cpf)
    OR NOT (NEW.comprador_rg    <=> OLD.comprador_rg)
    OR NOT (NEW.observacoes     <=> OLD.observacoes)
    OR NOT (NEW.data_emissao    <=> OLD.data_emissao)
    OR NOT (NEW.evolucao_id     <=> OLD.evolucao_id)
    OR NOT (NEW.paciente_id     <=> OLD.paciente_id)
    OR NOT (NEW.medico_id       <=> OLD.medico_id)
    OR NOT (NEW.autor_usuario_id <=> OLD.autor_usuario_id) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Conteúdo de receita assinada não pode ser alterado.';
    END IF;
  END IF;
END$$

CREATE TRIGGER trg_receitas_bd BEFORE DELETE ON receitas
FOR EACH ROW
BEGIN
  IF OLD.status IN ('ASSINADO','SUSPENSO') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Não é permitido excluir receita assinada/suspensa.';
  END IF;
END$$

-- ----- solicitacoes_exame -----
CREATE TRIGGER trg_solic_exame_bu BEFORE UPDATE ON solicitacoes_exame
FOR EACH ROW
BEGIN
  IF OLD.status = 'SUSPENSO' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Solicitação suspensa é imutável.';
  END IF;
  IF OLD.status = 'ASSINADO' THEN
    IF NEW.status <> 'SUSPENSO' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Solicitação assinada só pode ser suspensa.';
    END IF;
    IF NEW.justificativa_suspensao IS NULL OR TRIM(NEW.justificativa_suspensao) = '' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Justificativa obrigatória para suspender.';
    END IF;
    IF NOT (NEW.tipo_exame_id     <=> OLD.tipo_exame_id)
    OR NOT (NEW.justificativa     <=> OLD.justificativa)
    OR NOT (NEW.data_solicitacao  <=> OLD.data_solicitacao)
    OR NOT (NEW.evolucao_id       <=> OLD.evolucao_id)
    OR NOT (NEW.paciente_id       <=> OLD.paciente_id)
    OR NOT (NEW.medico_id         <=> OLD.medico_id)
    OR NOT (NEW.autor_usuario_id  <=> OLD.autor_usuario_id) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Conteúdo de solicitação de exame assinada não pode ser alterado.';
    END IF;
  END IF;
END$$

CREATE TRIGGER trg_solic_exame_bd BEFORE DELETE ON solicitacoes_exame
FOR EACH ROW
BEGIN
  IF OLD.status IN ('ASSINADO','SUSPENSO') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Não é permitido excluir solicitação assinada/suspensa.';
  END IF;
END$$

DELIMITER ;

-- ==============================================================
-- FIM DO SCHEMA
-- ==============================================================
