<?php
require_once __DIR__ . '/./includes/branding.php';
require_once __DIR__ . '/includes/auth.php';

$logo = $branding['logo'] ?? null;
$app_name = $branding['app_name'] ?? 'Dekanto';
$primary_color = $branding['primary_color'] ?? '#8072ff';
 
// Detecta usuário atual e papel de admin
$__usr = function_exists('auth_usuario') ? auth_usuario() : null;
$__isAdmin = $__usr && is_array($__usr) && in_array('admin', ($__usr['roles'] ?? []), true);

// Normalização do caminho do logo
$logo_src = $branding['logo_url'] ?? null;
?>
<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($app_name) ?> — Plataforma Inteligente</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="stylesheet" href="./assets/css/nexus.css">
  <style>
    :root{
      --neo-primary: var(--matrix-primary, #B5A886);
      --neo-secondary: var(--matrix-tertiary, #931621);
      /* paleta clara */
      --neo-bg: #f7f8fc;
      --neo-surface: color-mix(in oklab, var(--matrix-surface, #ffffff) 92%, #f2f4f8 8%);
      --neo-text: var(--matrix-text-primary, #1c1f2e);
      --neo-muted: var(--matrix-text-secondary, #5c6273);
      --neo-border: var(--matrix-border, #e5e7ef);
      /* sombras suaves para claro */
      --shadow-soft: 0 8px 20px rgba(16,24,40,.08);
      --shadow-softer: 0 2px 8px rgba(16,24,40,.06);
    }
    html, body { background: var(--neo-bg); color: var(--neo-text); }

    /* Aurora background (suave no claro) */
    .aurora {
      position: fixed; inset: -20vmax; z-index: -1; pointer-events: none; filter: saturate(110%);
      background:
        radial-gradient(45vmax 45vmax at 15% 20%, color-mix(in oklab, var(--neo-primary), #00ffd0 20%) 0%, transparent 65%),
        radial-gradient(35vmax 35vmax at 85% 25%, color-mix(in oklab, var(--neo-secondary), #6a00ff 18%) 0%, transparent 60%),
        radial-gradient(55vmax 55vmax at 60% 80%, color-mix(in oklab, var(--neo-primary), #ff3399 18%) 0%, transparent 70%);
      opacity: .35;
      animation: aurora-pan 18s ease-in-out infinite alternate;
      mask-image: radial-gradient(120vmax 120vmax at 50% 50%, #000 55%, transparent 100%);
    }
    @keyframes aurora-pan {
      0% { transform: translate3d(0,0,0) rotate(0deg) scale(1); }
      100% { transform: translate3d(0,-4%,0) rotate(3deg) scale(1.05); }
    }

    /* Glass navbar */
    .nav-neo {
      background: color-mix(in oklab, var(--neo-surface) 85%, transparent 15%);
      backdrop-filter: blur(14px);
      border-bottom: 1px solid var(--neo-border);
    }
    .nav-neo .navbar-brand span { font-weight: 800; letter-spacing: .4px; background: linear-gradient(135deg, var(--neo-primary), var(--neo-secondary)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
    .nav-neo .nav-link { color: var(--neo-muted) !important; font-weight: 600; font-size: .95rem; border-radius: 999px; padding: .5rem .9rem; }
    .nav-neo .nav-link:hover { color: var(--neo-text) !important; background: rgba(0,0,0,.04); }

    /* Hero */
    .hero { padding-top: 9rem; padding-bottom: 6rem; }
    .badge-chip { border: 1px solid var(--neo-border); color: var(--neo-muted); background: color-mix(in oklab, var(--neo-surface) 85%, transparent 15%); border-radius: 999px; padding: .4rem .8rem; font-size: .8rem; }
    .display-gradient {
      font-size: clamp(2.6rem, 6vw, 5rem);
      line-height: 1.05; font-weight: 900; letter-spacing: -.5px;
      background: conic-gradient(from 180deg at 50% 50%, var(--neo-primary), var(--neo-secondary), var(--neo-primary));
      -webkit-background-clip: text; -webkit-text-fill-color: transparent;
      filter: drop-shadow(0 4px 12px rgba(16,24,40,.08));
    }
    .lead-neo { color: var(--neo-muted); font-size: clamp(1rem, 1.9vw, 1.3rem); }

    .btn-neo-primary{
      --glow: color-mix(in oklab, var(--neo-primary), #fff 10%);
      background: linear-gradient(135deg, var(--neo-primary), color-mix(in oklab, var(--neo-secondary), var(--neo-primary) 30%));
      color: #1b1b1f; border: none; font-weight: 800; letter-spacing: .2px; padding: .9rem 1.2rem; border-radius: 14px;
      box-shadow: var(--shadow-soft);
    }
    .btn-neo-primary:hover{ transform: translateY(-2px); color: #111; }
    .btn-neo-ghost{ border: 1px solid var(--neo-border); color: var(--neo-text); padding: .9rem 1.2rem; border-radius: 14px; background: transparent; }
    .btn-neo-ghost:hover{ background: rgba(0,0,0,.04); }

    /* Feature cards */
    .feature-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1.2rem; }
    .feature-card {
      position: relative; border: 1px solid var(--neo-border); border-radius: 16px; overflow: hidden;
      background: color-mix(in oklab, var(--neo-surface) 90%, transparent 10%);
      backdrop-filter: blur(10px);
      padding: 1.4rem; transition: transform .25s ease, box-shadow .25s ease, border-color .25s ease;
    }
    .feature-card:hover { transform: translateY(-2px); border-color: color-mix(in oklab, var(--neo-primary), var(--neo-border) 70%); box-shadow: var(--shadow-soft); }
    .feature-icon { width: 44px; height: 44px; border-radius: 10px; display: grid; place-items:center; color:#111; font-size: 1.2rem;
      background: linear-gradient(135deg, var(--neo-primary), color-mix(in oklab, var(--neo-secondary), var(--neo-primary) 35%)); box-shadow: var(--shadow-softer);
    }

    /* Showcase panel */
    .showcase { border: 1px solid var(--neo-border); border-radius: 18px; padding: 1.5rem; background: color-mix(in oklab, var(--neo-surface) 90%, transparent 10%); }
    .mock {
      aspect-ratio: 16/10; border: 1px dashed var(--neo-border); border-radius: 12px; display: grid; place-items:center; color: var(--neo-muted);
      background: repeating-linear-gradient(45deg, rgba(16,24,40,.03) 0 10px, transparent 10px 20px);
      cursor: pointer;
    }

    /* Stats ribbon */
    .stats { background: linear-gradient(180deg, rgba(16,24,40,.03), transparent); border-top: 1px solid var(--neo-border); border-bottom: 1px solid var(--neo-border); }
    .stat { text-align:center; padding: 1.4rem 0; }
    .stat .num { font-weight: 900; font-size: clamp(2rem, 4.5vw, 3.2rem); background: linear-gradient(135deg, var(--neo-primary), var(--neo-secondary)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
    .stat .label { color: var(--neo-muted); font-weight: 600; letter-spacing: .2px; }

    /* Reveal animation */
    .reveal { opacity: 0; transform: translateY(14px); transition: all .6s ease; }
    .reveal.in { opacity: 1; transform: none; }

    /* Footer */
    footer { border-top: 1px solid var(--neo-border); background: color-mix(in oklab, var(--neo-surface) 90%, transparent 10%); }

    /* Preview shell */
    .preview-shell .preview-fallback{ display:none; }
    .preview-shell.error .preview-fallback{ display:block; }
    .preview-shell.error iframe{ display:none; }

    /* Wizard (Configuração guiada) */
    .wizard-modal .modal-content{ border:1px solid var(--neo-border); background: color-mix(in oklab, var(--neo-surface) 92%, transparent 8%); }
    .wizard-progress{ display:flex; gap:1rem; align-items:center; margin-bottom: .75rem; }
    .wizard-progress .step{ display:flex; align-items:center; gap:.5rem; color: var(--neo-muted); font-weight:600; }
    .wizard-progress .dot{ width:10px; height:10px; border-radius:50%; background: var(--neo-border); }
    .wizard-progress .step.active{ color: var(--neo-text); }
    .wizard-progress .step.active .dot{ background: var(--neo-primary); }
    .wizard-step{ display:none; }
    .wizard-step.active{ display:block; }
    .wizard-log{ border:1px dashed var(--neo-border); background: rgba(16,24,40,.03); border-radius: 10px; padding:.75rem; max-height: 240px; overflow:auto; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size:.85rem; }
    .form-text{ color: var(--neo-muted); }
  </style>
</head>
<body>
  <div class="aurora" aria-hidden="true"></div>

  <!-- Navbar -->
  <nav class="navbar navbar-expand-lg nav-neo sticky-top">
    <div class="container py-2">
      <a class="navbar-brand d-flex align-items-center" href="#">
        <?php if ($logo_src): ?><img src="<?= htmlspecialchars($logo_src) ?>" alt="Logo" style="height:34px" class="me-2"><?php endif; ?>
        <span><?= htmlspecialchars($app_name) ?></span>
      </a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navNeo"><span class="navbar-toggler-icon"></span></button>
      <div class="collapse navbar-collapse" id="navNeo">
        <ul class="navbar-nav ms-auto align-items-center gap-lg-1">
          <li class="nav-item"><a class="nav-link" href="#recursos">Recursos</a></li>
          <li class="nav-item"><a class="nav-link" href="#como-funciona">Como funciona</a></li>
          <li class="nav-item"><a class="nav-link" href="#resultados">Resultados</a></li>
          <?php if (!empty($__isAdmin)): ?>
          <li class="nav-item ms-lg-2"><a class="nav-link text-warning" href="./setup.php"><i class="bi bi-sliders me-1"></i>Setup</a></li>
          <?php endif; ?>
          <li class="nav-item ms-lg-2 mt-2 mt-lg-0"><a href="./pages/login.php" class="btn btn-neo-ghost"><i class="bi bi-box-arrow-in-right me-2"></i>Entrar</a></li>
          <li class="nav-item ms-lg-2 mt-2 mt-lg-0"><a href="./pages/login.php" class="btn btn-neo-primary">Começar</a></li>
        </ul>
      </div>
    </div>
  </nav>

  <!-- Hero -->
  <header class="hero">
    <div class="container">
      <div class="row align-items-center g-4">
        <div class="col-lg-6">
          <div class="badge-chip mb-3 d-inline-flex align-items-center gap-2"><i class="bi bi-stars text-warning"></i> Nova geração em gestão</div>
          <h1 class="display-gradient mb-3">Operações mais rápidas, decisões mais inteligentes.</h1>
          <p class="lead-neo mb-4">Um cockpit unificado para requisitar, cotar, comprar e acompanhar — com automações, insights e uma experiência impecável do início ao fim.</p>
          <div class="d-flex flex-wrap gap-3">
            <a href="./pages/login.php" class="btn btn-neo-primary"><i class="bi bi-rocket-takeoff me-2"></i>Experimentar agora</a>
            <a href="#recursos" class="btn btn-neo-ghost"><i class="bi bi-play-circle me-2"></i>Ver recursos</a>
          </div>
          <div class="d-flex align-items-center gap-4 mt-4 text-muted small">
            <span><i class="bi bi-shield-lock me-1"></i> SSO & Logs de auditoria</span>
            <span><i class="bi bi-gear-wide-connected me-1"></i> APIs e integrações</span>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="showcase reveal">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <span class="text-muted">Prévia do sistema</span>
              <span class="badge bg-success-subtle text-success border border-success-subtle"><i class="bi bi-box-arrow-in-right me-1"></i>Login</span>
            </div>
            <div class="preview-shell">
              <div class="ratio ratio-16x9 rounded-3 overflow-hidden" style="border:1px solid var(--neo-border);">
                <iframe src="./pages/login.php" class="w-100 h-100 border-0" title="Prévia: Login" loading="lazy"></iframe>
              </div>
              <div class="preview-fallback small text-muted text-center py-3">
                Não foi possível carregar a prévia. <a href="./pages/login.php">Acesse o login</a>.
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </header>

  <!-- Feature grid -->
  <section id="recursos" class="py-5">
    <div class="container">
      <div class="row mb-4 align-items-end">
        <div class="col-lg-8">
          <h2 class="h1 fw-bold">Tudo o que você precisa — em um só lugar</h2>
          <p class="lead-neo mb-0">Do pedido à compra, do fornecedor ao cliente. Orquestração, colaboração e performance sem fricção.</p>
        </div>
      </div>
      <div class="feature-grid">
        <article class="feature-card reveal" data-tilt>
          <div class="feature-icon mb-3"><i class="bi bi-kanban"></i></div>
          <h3 class="h5 fw-bold mb-2">Kanban operacional</h3>
          <p class="text-secondary mb-0">Acompanhe status, prazos e bloqueios em painéis fluídos e colaborativos.</p>
        </article>
        <article class="feature-card reveal" data-tilt>
          <div class="feature-icon mb-3"><i class="bi bi-bell"></i></div>
          <h3 class="h5 fw-bold mb-2">Timeline e notificações</h3>
          <p class="text-secondary mb-0">Registro de eventos e alertas por requisição, com histórico consultável.</p>
        </article>
        <article class="feature-card reveal" data-tilt>
          <div class="feature-icon mb-3"><i class="bi bi-paperclip"></i></div>
          <h3 class="h5 fw-bold mb-2">Gestão de anexos</h3>
          <p class="text-secondary mb-0">Upload e download de documentos com controle e rastreabilidade.</p>
        </article>
        <article class="feature-card reveal" data-tilt>
          <div class="feature-icon mb-3"><i class="bi bi-people"></i></div>
          <h3 class="h5 fw-bold mb-2">Portal do cliente</h3>
          <p class="text-secondary mb-0">Acesso dedicado para consulta de status, propostas e interações.</p>
        </article>
        <article class="feature-card reveal" data-tilt>
          <div class="feature-icon mb-3"><i class="bi bi-graph-up"></i></div>
          <h3 class="h5 fw-bold mb-2">KPIs básicos</h3>
          <p class="text-secondary mb-0">Indicadores essenciais para acompanhar volume e tempos médios.</p>
        </article>
        <article class="feature-card reveal" data-tilt>
          <div class="feature-icon mb-3"><i class="bi bi-plug"></i></div>
          <h3 class="h5 fw-bold mb-2">APIs e integrações</h3>
          <p class="text-secondary mb-0">Conexão com ERPs/CRMs e fornecedores via endpoints REST.</p>
        </article>
      </div>
    </div>
  </section>

  <!-- How it works -->
  <section id="como-funciona" class="py-5">
    <div class="container">
      <div class="row g-4 align-items-center">
        <div class="col-lg-6 order-lg-2">
          <div class="showcase reveal">
            <?php if (!empty($__isAdmin)): ?>
            <div id="wizardInline" class="wizard-inline">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="text-muted">Configuração guiada</span>
                <span class="badge bg-warning-subtle text-warning border border-warning-subtle"><i class="bi bi-magic me-1"></i>Wizard</span>
              </div>
              <div class="wizard-progress small">
                <div class="step" data-wizard-step><span class="dot"></span><span>Estado</span></div>
                <div class="step" data-wizard-step><span class="dot"></span><span>Configuração</span></div>
                <div class="step" data-wizard-step><span class="dot"></span><span>Executar</span></div>
              </div>

              <div class="wizard-step" id="wizardStep0">
                <p class="mb-3 text-secondary">Verifique o estado atual antes de iniciar. Isso checa conexão com o banco, migrações e usuário admin.</p>
                <div class="row g-3">
                  <div class="col-md-4">
                    <div class="p-3 rounded border" id="stateDbBox">
                      <div class="small text-muted">Banco de dados</div>
                      <div class="fw-bold" id="state-db">—</div>
                    </div>
                  </div>
                  <div class="col-md-4">
                    <div class="p-3 rounded border" id="stateMigBox">
                      <div class="small text-muted">Migrações</div>
                      <div class="fw-bold" id="state-migrations">—</div>
                    </div>
                  </div>
                  <div class="col-md-4">
                    <div class="p-3 rounded border" id="stateAdminBox">
                      <div class="small text-muted">Usuário admin</div>
                      <div class="fw-bold" id="state-admin">—</div>
                    </div>
                  </div>
                </div>
                <div class="d-flex align-items-center gap-2 mt-3">
                  <button class="btn btn-neo-ghost" id="wizardCheckBtn"><i class="bi bi-arrow-repeat me-1"></i>Checar estado</button>
                  <button class="btn btn-neo-primary" id="wizardContinueBtn1" disabled>Continuar</button>
                </div>
              </div>

              <div class="wizard-step" id="wizardStep1">
                <div class="row g-3">
                  <div class="col-md-6">
                    <label class="form-label">Host do BD</label>
                    <input type="text" class="form-control" id="cfg-db-host" placeholder="localhost" value="localhost">
                    <div class="form-text">Ex.: localhost, 127.0.0.1 ou hostname do servidor</div>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Nome do BD</label>
                    <input type="text" class="form-control" id="cfg-db-name" placeholder="atlas">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Usuário do BD</label>
                    <input type="text" class="form-control" id="cfg-db-user" placeholder="root" value="root">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Senha do BD</label>
                    <input type="password" class="form-control" id="cfg-db-pass" placeholder="">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Nome do App (opcional)</label>
                    <input type="text" class="form-control" id="cfg-app-name" placeholder="<?= htmlspecialchars($app_name) ?>">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Cor primária (opcional)</label>
                    <input type="text" class="form-control" id="cfg-primary-color" placeholder="#8072ff">
                  </div>
                </div>
                <div class="d-flex align-items-center gap-2 mt-3">
                  <button class="btn btn-neo-ghost" id="wizardPrev2">Anterior</button>
                  <button class="btn btn-neo-primary" id="wizardSaveBtn"><i class="bi bi-save me-1"></i>Salvar configuração</button>
                </div>
                <div class="mt-2 small text-muted" id="wizardSaveMsg"></div>
              </div>

              <div class="wizard-step" id="wizardStep2">
                <p class="mb-2 text-secondary">Executa migrações, cria estruturas necessárias e, se aplicável, dados básicos.</p>
                <div class="d-flex align-items-center gap-2 mb-3">
                  <button class="btn btn-neo-ghost" id="wizardPrev3">Anterior</button>
                  <button class="btn btn-neo-primary" id="wizardRunBtn"><i class="bi bi-cpu me-1"></i>Executar setup</button>
                  <a href="./pages/login.php" class="btn btn-neo-ghost d-none" id="wizardGoLogin"><i class="bi bi-box-arrow-in-right me-1"></i>Ir para login</a>
                </div>
                <div class="wizard-log" id="wizardLog" aria-live="polite"></div>
              </div>
            </div>
            <?php else: ?>
            <a class="mock rounded-3 d-block text-decoration-none" href="./pages/login.php">
              <div class="text-center"><i class="bi bi-toggles2 me-2"></i>Configuração guiada</div>
            </a>
            <?php endif; ?>
          </div>
        </div>
        <div class="col-lg-6">
          <h2 class="h1 fw-bold mb-3">Comece em minutos</h2>
          <ul class="list-unstyled lead-neo">
            <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i>Conecte dados e cadastros</li>
            <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i>Defina papéis e regras</li>
            <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i>Ative automações por evento</li>
          </ul>
          <a href="./pages/login.php" class="btn btn-neo-primary"><i class="bi bi-box-arrow-in-right me-2"></i>Acessar plataforma</a>
          <?php if (!empty($__isAdmin)): ?>
            <a class="btn btn-neo-ghost ms-2" href="#wizardInline"><i class="bi bi-magic me-2"></i>Configuração guiada</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <!-- Resultados (sem métricas infladas) -->
  <section id="resultados" class="py-5">
    <div class="container">
      <div class="p-4 p-lg-5 rounded-4" style="border:1px solid var(--neo-border); background: color-mix(in oklab, var(--neo-surface) 85%, transparent 15%);">
        <h2 class="h3 fw-bold mb-2">Resultados sem exageros</h2>
        <p class="text-secondary mb-0">Nada de números inflados. Avalie com seus dados, acompanhe no módulo de KPIs e evolua conforme o uso real.</p>
      </div>
    </div>
  </section>

  <!-- Final CTA -->
  <section class="py-6 py-lg-7">
    <div class="container">
      <div class="p-5 p-lg-6 rounded-4" style="border:1px solid var(--neo-border); background: color-mix(in oklab, var(--neo-surface) 85%, transparent 15%);">
        <div class="row align-items-center g-4">
          <div class="col-lg-8">
            <h2 class="display-6 fw-900 mb-2">Pronto para elevar sua operação?</h2>
            <p class="lead-neo mb-0">Experimente a nova experiência do <?= htmlspecialchars($app_name) ?> e coloque sua equipe no próximo nível.</p>
          </div>
          <div class="col-lg-4 text-lg-end">
            <a href="./pages/login.php" class="btn btn-neo-primary me-2">Começar agora</a>
            <a href="mailto:contato@dekanto.com" class="btn btn-neo-ghost">Falar com especialista</a>
          </div>
        </div>
      </div>
    </div>
  </section>

  <?php if (!empty($__isAdmin)): ?>
  <!-- Wizard Modal (Configuração guiada) REMOVIDO: agora o wizard é inline na seção "Como funciona" -->
  <?php endif; ?>

  <footer class="py-5 mt-4">
    <div class="container">
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-2">
          <?php if ($logo_src): ?><img src="<?= htmlspecialchars($logo_src) ?>" alt="Logo" style="height:28px"><?php endif; ?>
          <strong><?= htmlspecialchars($app_name) ?></strong>
          <span class="text-muted">© <?= date('Y') ?>. Todos os direitos reservados.</span>
        </div>
        <div class="d-flex align-items-center gap-3">
          <a href="#recursos" class="link-secondary text-decoration-none">Recursos</a>
          <a href="#como-funciona" class="link-secondary text-decoration-none">Como funciona</a>
          <a href="#resultados" class="link-secondary text-decoration-none">Resultados</a>
          <a href="./pages/login.php" class="btn btn-sm btn-neo-ghost">Entrar</a>
        </div>
      </div>
    </div>
  </footer>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // Reveal on scroll
    const io = new IntersectionObserver(entries => {
      entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } });
    }, { threshold: 0.12 });
    document.querySelectorAll('.reveal').forEach(el => io.observe(el));

    // Preview fallback (iframe)
    document.querySelectorAll('.preview-shell iframe').forEach(ifr => {
      let done = false; const shell = ifr.closest('.preview-shell');
      const markLoaded = () => { if(!done){ done = true; shell?.classList.add('loaded'); shell?.classList.remove('error'); } };
      ifr.addEventListener('load', markLoaded);
      setTimeout(() => { if(!done){ shell?.classList.add('error'); } }, 4000);
    });

    // Simple tilt effect
    document.querySelectorAll('[data-tilt]').forEach(card=>{
      let rAF; const onMove = e=>{
        const r = card.getBoundingClientRect(); const mx = (e.clientX - r.left) / r.width; const my = (e.clientY - r.top) / r.height;
        cancelAnimationFrame(rAF); rAF = requestAnimationFrame(()=>{ card.style.transform = `perspective(700px) rotateX(${(0.5-my)*6}deg) rotateY(${(mx-0.5)*6}deg) translateY(-4px)`; });
      };
      const reset = ()=>{ card.style.transform=''; };
      card.addEventListener('mousemove', onMove);
      card.addEventListener('mouseleave', reset);
    });
  </script>
  <?php if (!empty($__isAdmin)): ?>
  <script>
    // Wizard logic (Configuração guiada) - Inline
    (function(){
      const root = document.getElementById('wizardInline');
      if(!root) return;

      const steps = [
        document.getElementById('wizardStep0'),
        document.getElementById('wizardStep1'),
        document.getElementById('wizardStep2'),
      ];
      const progress = Array.from(root.querySelectorAll('[data-wizard-step]'));

      const btnCheck = document.getElementById('wizardCheckBtn');
      const btnCont1 = document.getElementById('wizardContinueBtn1');
      const btnPrev2 = document.getElementById('wizardPrev2');
      const btnSave = document.getElementById('wizardSaveBtn');
      const saveMsg = document.getElementById('wizardSaveMsg');
      const btnPrev3 = document.getElementById('wizardPrev3');
      const btnRun = document.getElementById('wizardRunBtn');
      const goLogin = document.getElementById('wizardGoLogin');
      const logEl = document.getElementById('wizardLog');

      const stateDb = document.getElementById('state-db');
      const stateMig = document.getElementById('state-migrations');
      const stateAdmin = document.getElementById('state-admin');

      let current = 0;

      function go(n){
        current = Math.max(0, Math.min(steps.length-1, n));
        steps.forEach((s,i)=> s.classList.toggle('active', i===current));
        progress.forEach((p,i)=> p.classList.toggle('active', i===current));
      }
      function reset(){
        stateDb.textContent = '—';
        stateMig.textContent = '—';
        stateAdmin.textContent = '—';
        btnCont1.disabled = true;
        saveMsg.textContent = '';
        logEl.textContent = '';
        go(0);
      }
      async function api(action, payload){
        const url = `./setup.php?action=${encodeURIComponent(action)}`;
        const opts = payload ? { method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json'}, body: JSON.stringify(payload) } : { headers:{'Accept':'application/json'} };
        try{
          const res = await fetch(url, opts);
          const ct = res.headers.get('content-type')||'';
          let data = null, text = null;
          if(ct.includes('application/json')) data = await res.json(); else text = await res.text();
          if(!res.ok) throw new Error((data && (data.error||data.message)) || text || `HTTP ${res.status}`);
          return data ?? { ok:true, message: text };
        }catch(err){
          return { ok:false, error: err.message || String(err) };
        }
      }
      function setBadge(el, ok, msgIfAny){
        el.textContent = ok ? 'OK' : (msgIfAny || 'Pendente');
        el.className = 'fw-bold ' + (ok ? 'text-success' : 'text-warning');
      }

      btnCheck?.addEventListener('click', async ()=>{
        btnCheck.disabled = true; btnCheck.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Checando...';
        const out = await api('check_state');
        const dbOk = !!(out?.db_ok ?? out?.db ?? out?.database_ok ?? out?.database);
        const migOk = !!(out?.migrations_ok ?? out?.migrated ?? out?.migrations);
        const admOk = !!(out?.admin_ok ?? out?.admin ?? out?.admin_exists);
        setBadge(stateDb, dbOk);
        setBadge(stateMig, migOk);
        setBadge(stateAdmin, admOk);
        const ready = dbOk && migOk && admOk;
        btnCont1.disabled = ready ? false : false; // pode continuar para ajustar
        btnCheck.disabled = false; btnCheck.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i>Checar estado';
      });
      btnCont1?.addEventListener('click', ()=> go(1));

      btnPrev2?.addEventListener('click', ()=> go(0));
      btnPrev3?.addEventListener('click', ()=> go(1));

      btnSave?.addEventListener('click', async ()=>{
        const payload = {
          db_host: document.getElementById('cfg-db-host').value.trim(),
          db_name: document.getElementById('cfg-db-name').value.trim(),
          db_user: document.getElementById('cfg-db-user').value.trim(),
          db_pass: document.getElementById('cfg-db-pass').value,
          app_name: document.getElementById('cfg-app-name').value.trim() || undefined,
          primary_color: document.getElementById('cfg-primary-color').value.trim() || undefined,
        };
        saveMsg.textContent = '';
        btnSave.disabled = true; btnSave.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Salvando...';
        const res = await api('save_config', payload);
        if(res?.ok !== false){
          saveMsg.className = 'mt-2 small text-success';
          saveMsg.textContent = (res?.message || 'Configuração salva.');
          setTimeout(()=>{ go(2); }, 400);
        }else{
          saveMsg.className = 'mt-2 small text-danger';
          saveMsg.textContent = 'Falha ao salvar: ' + (res?.error || 'erro desconhecido');
        }
        btnSave.disabled = false; btnSave.innerHTML = '<i class="bi bi-save me-1"></i>Salvar configuração';
      });

      btnRun?.addEventListener('click', async ()=>{
        btnRun.disabled = true; btnRun.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Executando...';
        logEl.textContent = '';
        const res = await api('run_setup', {});
        if(res?.ok === false){
          logEl.innerHTML = '<span class="text-danger">Erro:</span> ' + (res?.error || 'erro desconhecido');
        }else{
          const logs = res?.logs || res?.output || res?.message || '';
          logEl.textContent = logs || 'Setup concluído.';
          goLogin.classList.remove('d-none');
        }
        btnRun.disabled = false; btnRun.innerHTML = '<i class="bi bi-cpu me-1"></i>Executar setup';
      });

      // Inicializa automaticamente ao carregar a página
      reset();
      btnCheck?.click();
    })();
  </script>
  <?php endif; ?>
</body>
</html>
