# Sistema de Prontuário Médico — MED+CLIN

Sistema de prontuário eletrônico para consultório médico, desenvolvido como
Projeto Integrador 2 do curso (CEUB).

Atende requisitos coletados em entrevista com médica-cliente real, incluindo
imutabilidade pós-assinatura, controle multi-perfil, emissão de receituário
(comum e antibiótico) e adequação à LGPD.

## Stack

- **Backend**: PHP 8 com PDO (prepared statements)
- **Banco**: MySQL 8 / MariaDB 10.4+ (compatível com XAMPP)
- **Frontend**: HTML5 + CSS3 + JavaScript vanilla (sem frameworks)
- **Padrões BR**: ViaCEP, CRM, CID-10, TUSS, LGPD

## Funcionalidades

### Por perfil
| Perfil | O que faz |
|---|---|
| **Administrador** | Gerencia usuários (médicos com CRM, recepção, técnicas), catálogo de medicamentos (import CSV) |
| **Recepção** | Cadastra pacientes (com ViaCEP + LGPD), gerencia agenda completa da clínica |
| **Médico** | Acessa prontuário (evoluções SOAP, sinais vitais, receitas, histórico), agenda própria |
| **Téc. enfermagem** | Registra sinais vitais com IMC automático |

### Regras críticas
- **Imutabilidade**: ao assinar uma evolução/sinais/receita, o registro fica imutável (validação no PHP + triggers no banco)
- **Suspensão com justificativa**: só o autor pode suspender o próprio registro; histórico preservado
- **Visualização cross-médico**: médico vê evoluções de outros médicos sem editar
- **Conflito de horário**: agenda bloqueia 2 consultas no mesmo médico/horário

## Estrutura

```
.
├── schema.sql               # script completo do banco (18 tabelas + 8 triggers)
├── backend/                 # APIs PHP
│   ├── config.php           # conexão PDO
│   ├── auth.php             # login/logout/sessão
│   ├── auth_helpers.php     # exigirLogin() / exigirPerfil()
│   ├── instalar.php         # cria 4 usuários + clínica + paciente exemplo (rodar 1×)
│   ├── seed_medicamentos.php# popula 37 medicamentos comuns (rodar 1×)
│   ├── usuarios.php         # CRUD de usuários (ADMIN)
│   ├── pacientes.php        # CRUD de pacientes
│   ├── evolucoes.php        # SOAP + assinar/suspender
│   ├── sinais_vitais.php    # aferições
│   ├── receitas.php         # comum + antibiótico
│   ├── medicamentos.php     # catálogo + CSV
│   └── agendamentos.php     # agenda
└── frontend/                # telas HTML
    ├── login.html
    ├── dashboard_*.html     # 1 por perfil
    ├── pacientes.html / paciente_form.html
    ├── prontuario.html      # tela central do médico (4 abas)
    ├── sinais_vitais.html
    ├── agenda.html
    ├── medicamentos.html
    ├── usuarios.html
    ├── receituario_imprimir.html  # impressão A4 com 2 vias p/ antibiótico
    └── assets/{css,js}/
```

## Como rodar localmente (XAMPP)

1. **Instalar XAMPP** (PHP 8 + MariaDB)
2. **Clonar** este repositório:
   ```
   git clone https://github.com/Dr1zz3/P.I.2-Prontuario_Medico.git
   ```
3. **Copiar pasta** para `C:\xampp\htdocs\prontuario_medico\` (nome exato)
4. **Iniciar Apache + MySQL** no painel do XAMPP
5. **Criar o banco**:
   - Abrir http://localhost/phpmyadmin
   - Aba **SQL** → colar conteúdo de `schema.sql` → Executar
6. **Popular dados de teste**:
   - http://localhost/prontuario_medico/backend/instalar.php
   - http://localhost/prontuario_medico/backend/seed_medicamentos.php
7. **Acessar**:
   - http://localhost/prontuario_medico/frontend/login.html

### Credenciais de teste (senha de todas: `123456`)

| E-mail | Perfil |
|---|---|
| `admin@clinica.com` | Administrador |
| `bruna@clinica.com` | Médico(a) |
| `carla@clinica.com` | Recepção |
| `tania@clinica.com` | Téc. enfermagem |

### Configuração do banco

Por padrão `backend/config.php` usa as configurações padrão do XAMPP:

```php
const DB_HOST = '127.0.0.1';
const DB_USER = 'root';
const DB_PASS = '';   // XAMPP vem sem senha
const DB_NAME = 'prontuario_medico';
```

Se seu MySQL tem senha, ajuste essa constante.

## Segurança implementada

- ✅ Senhas armazenadas com `password_hash` (bcrypt) — nunca em texto puro
- ✅ Todas as queries usam **prepared statements** (imune a SQL injection)
- ✅ Sessão PHP com `session_regenerate_id` no login (anti-fixation)
- ✅ Autorização por perfil no backend (frontend é só UX)
- ✅ Triggers no banco bloqueiam UPDATE/DELETE em registros assinados
- ✅ Soft delete em pacientes/usuários/medicamentos (preserva histórico)
- ✅ Anti-lockout: ADMIN não pode desativar a si mesmo nem ao último ADMIN

## Limitações conhecidas

- Tarja preta NÃO é emitida (requer receituário físico da Vigilância Sanitária)
- Sem 2FA (escopo acadêmico)
- Sem PDF nativo (impressão usa `window.print()` do navegador)

## Roadmap

- [ ] Aba de exames com mesma estrutura (assinar/suspender)
- [ ] Geração de PDF nativo do receituário
- [ ] Indicadores no dashboard admin (consultas/mês, etc.)
- [ ] Logout automático por inatividade
- [ ] Logo personalizada da clínica no receituário

## Equipe

Projeto Integrador 2 — CEUB.
