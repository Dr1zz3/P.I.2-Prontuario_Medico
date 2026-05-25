# Apresentação — Sistema de Prontuário Médico (MED+CLIN)

**Projeto Integrador 2 — CEUB**

---

## 🌐 Acessos

| Recurso | URL |
|---|---|
| **Sistema online (produção)** | https://medclin-pi.page.gd/frontend/login.html |
| **Código-fonte (GitHub)** | https://github.com/Dr1zz3/P.I.2-Prontuario_Medico |
| **Banco de dados (phpMyAdmin)** | https://dash.infinityfree.com (via painel) |

---

## 🔑 Credenciais de teste

**Todos os usuários usam a senha `123456`** (acadêmico — em produção real seriam senhas únicas).

| E-mail | Perfil | O que pode fazer |
|---|---|---|
| `admin@clinica.com` | Administrador | Gerencia usuários e catálogo de medicamentos |
| `bruna@clinica.com` | Médico(a) | Prontuário, evoluções, receitas, agenda própria |
| `carla@clinica.com` | Recepção | Cadastra pacientes, agenda da clínica |
| `tania@clinica.com` | Téc. Enfermagem | Registra sinais vitais |

---

## 🎬 Roteiro de demonstração sugerido (~12 min)

### 1. Abertura — Login e perfis (~1 min)

> "O sistema tem 4 perfis com permissões diferentes, baseados no que a médica descreveu na entrevista. Vou mostrar cada um."

- Mostrar tela de login limpa
- Falar: "O perfil define quais telas e ações o usuário tem acesso. Isso é validado **tanto no frontend quanto no backend** — não dá pra burlar trocando a URL."

---

### 2. Perfil Recepção (~2 min)

**Login**: `carla@clinica.com` / `123456`

**Demonstrar**:
1. Dashboard da recepção → 3 cards (Agenda, Pacientes, Cadastrar Paciente)
2. Cadastrar Paciente:
   - Nome: `Aluno Teste`
   - CPF: `12345678901` (qualquer)
   - Data nascimento, sexo
   - **CEP**: `70150900` → endereço preenche sozinho (integração ViaCEP)
   - Marcar **LGPD** (consentimento)
   - Salvar
3. Agenda → "Novo agendamento":
   - Paciente: o que acabou de criar
   - Médico: Dra. Bruna
   - Data/hora: amanhã 10h
   - Tipo: Primeira consulta
   - Salvar

**Pontos a destacar**:
- ✅ Integração com **ViaCEP** (API real dos Correios)
- ✅ **LGPD**: campo obrigatório de consentimento + registro de data/hora
- ✅ Validação de CPF único
- ✅ Conflito de horário detectado se já existir consulta no mesmo médico

---

### 3. Perfil Técnica de Enfermagem (~2 min)

**Login**: `tania@clinica.com` / `123456`

**Demonstrar**:
1. Dashboard mostra só **2 cards** (não vê dados sigilosos do médico)
2. Sinais Vitais → buscar pelo paciente "Aluno Teste"
3. Registrar:
   - PA 120/80, FC 72, FR 18, SatO2 98, Temp 36.5
   - **Peso 75 + Altura 175** → **IMC aparece automaticamente** (24.5 — peso normal)
   - Observações
   - **Salvar** (vira rascunho 🟡)
4. Aba Histórico → vê o rascunho
5. Volta na aba → **Assinar** (vira verde 🟢, imutável)
6. Tentar suspender → modal pede justificativa

**Pontos a destacar**:
- ✅ **Cálculo automático de IMC** com classificação (peso normal/sobrepeso/obesidade I-III)
- ✅ **Imutabilidade após assinar**: depois disso o registro fica **bloqueado** — só pode ser suspenso (com justificativa)
- ✅ Histórico imutável (mesmo suspensão preserva o original, só marca como inválido)

---

### 4. Perfil Médico — Tela central (~5 min) ⭐ DESTAQUE

**Login**: `bruna@clinica.com` / `123456`

**Demonstrar**:

#### 4.1 Pacientes → Prontuário
1. Pacientes → busca/clica em "Aluno Teste" → botão **"Prontuário"**
2. Mostra 4 abas: **Nova Evolução / Histórico / Sinais Vitais / Receitas**

#### 4.2 Sinais Vitais (read-only)
- Mostra os sinais que a **técnica** registrou (com IMC calculado)
- Comentar: *"O médico vê tudo que a enfermagem anotou, mas não pode editar. Sigilo profissional respeitado."*

#### 4.3 Nova Evolução (SOAP)
- Preencher:
  - **Queixa**: "Dor de cabeça há 3 dias"
  - **Anamnese**: "Cefaleia frontal pulsátil, piora com esforço"
  - **Exame físico**: "PA 120x80, BEG, ausculta pulmonar limpa"
  - **Hipótese**: "Cefaleia tensional"
  - **Conduta**: "Repouso, analgesia, retorno em 7 dias"
- **Salvar como rascunho** (fica laranja 🟡)
- Aba Histórico → mostra o rascunho
- Botão "Continuar editando" → volta pra aba Nova Evolução
- **Assinar** → vira verde 🟢, "Agora é imutável"
- Tentar editar → impossível (sem botões)

#### 4.4 Receita médica (a feature visual mais impressionante)
- Aba **Receitas** → "Nova Receita"
- Vincular à evolução assinada
- Tipo: **Comum**
- Adicionar medicamento → **digitar "amox"** no campo → aparecem sugestões do catálogo
- Escolher "Amoxil (Amoxicilina)" → **modal pergunta**: "Este medicamento é antibiótico. Mudar tipo da receita?" → **Sim**
- Adicionar posologia: "1 cápsula 8/8h por 7 dias"
- Quantidade: "21 cápsulas"
- Antes de assinar, **mostrar que o tipo virou ANTIBIÓTICO** → apareceu o campo de dados do comprador (CPF/RG obrigatórios)
- Preencher dados do comprador
- Salvar e assinar

#### 4.5 Impressão do receituário
- Na lista, clicar em **"🖨️ Imprimir"**
- **ABRIR EM NOVA ABA**: layout de receituário real, **em duas vias paisagem** (1ª via FARMÁCIA, 2ª via PACIENTE), com cabeçalho da clínica, dados do paciente, prescrição, assinatura
- Ctrl+P pra mostrar como sai impresso (PDF/papel)

#### 4.6 Suspender (a regra de ouro)
- Voltar pra aba Receitas
- Clicar em **"Suspender"** na receita assinada
- Modal pede **justificativa obrigatória**: "Errei a posologia, paciente alérgico"
- Confirmar → receita aparece como 🔴 SUSPENSA com texto **riscado** e a justificativa em vermelho

**Pontos a destacar nesta seção**:
- ⭐ **Autocomplete inteligente**: catálogo de 37 medicamentos brasileiros (Tylenol, Amoxil, Bactrim, etc.) + detecção automática de antibiótico
- ⭐ **Templates diferenciados**: receita comum vs especial (antibiótico em 2 vias, paisagem, dados do comprador) — exatamente como a médica descreveu
- ⭐ **Salvar ≠ Assinar**: rascunho (editável) vs assinado (imutável)
- ⭐ **Suspensão com auditoria**: nunca apaga, só marca + justificativa do autor

---

### 5. Perfil Admin (~1 min)

**Login**: `admin@clinica.com` / `123456`

**Demonstrar**:
1. Dashboard admin → "Gerenciar Usuários"
2. Tabela com todos os usuários (4 ativos)
3. Tentar **"Desativar"** o próprio Admin → **bloqueado** (mensagem: "Você não pode desativar seu próprio usuário")
4. "Novo usuário" → mostrar campos dinâmicos:
   - Se perfil = **Médico**, aparecem campos de **CPF, CRM, Especialidade**
   - Se perfil = Recepção/Técnica, esses campos somem
5. Voltar → "Medicamentos"
6. Mostrar catálogo (37 medicamentos) com tags ANTIBIÓTICO vs COMUM
7. Demonstrar **"Importar CSV"** (clicar em "baixar modelo" → mostra estrutura esperada)

**Pontos a destacar**:
- ✅ **Anti-lockout**: ADMIN não pode se desativar nem deixar sistema sem nenhum admin
- ✅ Validação de CPF/CRM duplicados ao cadastrar médico
- ✅ Import em lote de medicamentos via CSV (caso clínica queira carregar uma planilha de farmácia)

---

### 6. Arquitetura — abrir o GitHub (~1 min)

Abrir https://github.com/Dr1zz3/P.I.2-Prontuario_Medico

**Comentar sobre**:
- `README.md` com documentação completa
- `schema.sql`: 18 tabelas, modelo relacional bem normalizado
- `schema_deploy_no_triggers.sql`: versão sem triggers pra hospedagens compartilhadas
- Pasta `backend/`: 12 APIs PHP separadas por módulo
- Pasta `frontend/`: 13 telas em HTML5 + CSS3 + JS vanilla (sem frameworks pesados)

---

## 🛡️ Segurança implementada (pra mostrar consciência)

| Item | Implementação |
|---|---|
| **SQL Injection** | 100% prepared statements (PDO) |
| **Senhas** | bcrypt via `password_hash` (nunca texto puro) |
| **Sessão** | PHP `$_SESSION` com `session_regenerate_id` (anti-fixation) |
| **Autorização** | `exigirLogin()` + `exigirPerfil()` em cada endpoint |
| **LGPD** | Consentimento obrigatório + registro de data/hora |
| **Imutabilidade** | Camada PHP (autor + status + justificativa) — em XAMPP, triggers extras no banco |
| **Anti-lockout** | ADMIN não pode se autodesativar nem deixar sistema sem admin |
| **Soft delete** | Pacientes/usuários/medicamentos: marca inativo, não apaga (histórico médico precisa ser preservado) |

---

## 📊 Métricas do projeto

| | |
|---|---|
| **Tabelas no banco** | 18 |
| **APIs PHP** | 12 módulos |
| **Telas frontend** | 13 |
| **Perfis com permissões diferenciadas** | 4 |
| **Linhas de código** | ~9 mil |
| **Padrões brasileiros usados** | ViaCEP, CRM, CID-10, TUSS, LGPD, SUS |
| **Tempo de desenvolvimento** | ~2 semanas |

---

## 🧠 Decisões técnicas (caso o professor pergunte)

### Por que PHP + MySQL puro, sem framework?
- Hospedagens gratuitas (InfinityFree, etc.) suportam universalmente
- Foco do PI é na arquitetura/banco, não no framework
- Stack tradicional, fácil de avaliar pelo professor

### Por que separar `evolucoes`, `sinais_vitais`, `receitas`?
- A médica descreveu fluxos diferentes pra cada um (perfis distintos preenchem)
- Permite o sistema de assinatura independente
- Normalização: cada tabela tem responsabilidade única

### Por que tem `usuarios` E `medicos`?
- Nem todo usuário é médico (recepção, técnica, admin)
- Médico tem dados específicos (CRM, especialidade, CPF profissional) que outros perfis não têm
- Relação 1:1 — só o usuário com perfil MEDICO tem registro em `medicos`

### Como garante a imutabilidade após assinatura?
1. **Backend valida**: status precisa ser RASCUNHO pra editar
2. **Backend valida**: só autor pode assinar/suspender
3. **Banco bloqueia** (em XAMPP via triggers): UPDATE/DELETE em registros assinados
4. **Frontend esconde** botões de edição (UX, não segurança)

### Por que receita tem dois tipos?
- **SIMPLES** (medicamentos comuns): receituário em 1 via, formato livre
- **ANTIBIOTICO**: exige identificação do comprador (CPF/RG) e emissão em **2 vias** (1 fica na farmácia, 1 com o paciente) — exigência legal da ANVISA
- **Tarja preta** NÃO é emitida — exige receituário físico da Vigilância Sanitária

---

## ❓ Perguntas previstas + respostas

**P: Por que não usou Vue/React/Angular?**
> Frontend vanilla atende totalmente as necessidades aqui — não há SPA complexa, são telas CRUD bem definidas. Frameworks adicionariam build step + curva de aprendizado sem benefício real no escopo. Em projetos maiores, sim, faria sentido.

**P: A senha "123456" é segura?**
> Não, é só pra demonstração acadêmica. Em produção, o admin geraria senhas únicas pra cada usuário (e o sistema já suporta troca de senha pelo painel).

**P: Se o servidor for hackeado, os dados clínicos vazam?**
> No banco, sim — não temos criptografia em camada de aplicação ainda. Em produção real, dados sensíveis (CPF, CID, evolução) seriam criptografados. Está no roadmap.

**P: Como funcionaria com vários médicos no mesmo paciente?**
> Cada médico tem seu próprio histórico de evoluções. Médico B abre o paciente do Médico A, **vê tudo, mas não edita** — botões de edição só aparecem se `autor_usuario_id = usuário logado`. Validado no backend também.

**P: E se a internet cair?**
> Aplicação web depende de conexão. Pra clínicas pequenas seria recomendado um plano de contingência (papel impresso de emergência). Sistema offline-first está fora do escopo do PI.

**P: Como vocês testaram?**
> Testes manuais end-to-end (todos os fluxos por perfil) + testes via API (`curl`/PowerShell) durante o desenvolvimento. Testes automatizados não foram escopo do PI.

---

## ⚠️ Limitações conhecidas (assumir antes que perguntem)

- ❌ Sem aba de **Exames** (estrutura no banco existe, faltou tempo pra UI)
- ❌ Sem **PDF nativo** do receituário (usa `window.print()` do navegador)
- ❌ Sem **2FA** (escopo acadêmico)
- ❌ Triggers desabilitados na versão deployada (InfinityFree não permite — mas a regra está garantida no PHP)
- ❌ Tarja preta não emitida (exige receituário físico da Vigilância Sanitária)
- ❌ Sem logo personalizada da clínica no receituário (usa só letras "MED+CLIN")

---

## 🚀 Roadmap futuro

- [ ] Módulo de Exames com solicitação + resultado
- [ ] PDF nativo do receituário (TCPDF ou similar)
- [ ] Upload de logo da clínica (com `clinica.logo_url`)
- [ ] Indicadores no dashboard admin (consultas/mês, médicos mais ativos)
- [ ] Notificação por e-mail (lembrete de consulta)
- [ ] App mobile (PWA)

---

## 🆘 Backup caso o site online não funcione no dia

Se a internet falhar ou InfinityFree estiver fora:

1. Abrir XAMPP local
2. Iniciar Apache + MySQL
3. Acessar: http://localhost/prontuario_medico/frontend/login.html
4. Mesmas credenciais funcionam

Garante uma demonstração mesmo offline.

---

**Boa apresentação! 🎓**
