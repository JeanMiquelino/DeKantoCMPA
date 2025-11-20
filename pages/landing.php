<?php
require_once __DIR__ . '/../includes/branding.php';

$logo = $branding['logo'] ?? null;
$app_name = $branding['app_name'] ?? 'Dekanto';
$primary_color = $branding['primary_color'] ?? '#8072ff';

// Normalização do caminho do logo
$logo_src = null;
if ($logo) {
    $logo_src = $logo;
    if (!preg_match('#^https?://#', $logo_src)) {
        $logo_src = preg_replace('#^\./#','', $logo_src);
        if (strpos($logo_src, '../') === 0) {
            // já relativo, mantem
        } elseif (strpos($logo_src, '/') === 0) {
            $logo_src = '..' . $logo_src;
        } else {
            $logo_src = '../' . $logo_src;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($app_name); ?> - Plataforma de Gestão Inteligente</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/nexus.css">
    <style>
        :root {
            --landing-gradient-primary: linear-gradient(135deg, #8072ff 0%, #5a47ff 50%, #3e2ccc 100%);
            --landing-gradient-secondary: linear-gradient(135deg, #00f5c9 0%, #00d4aa 100%);
            --landing-glow-primary: rgba(128, 114, 255, 0.3);
            --landing-glow-secondary: rgba(0, 245, 201, 0.2);
        }

        /* Hero Section */
        .hero-section {
            min-height: 100vh;
            background: 
                radial-gradient(circle at 20% 20%, rgba(128, 114, 255, 0.15) 0%, transparent 50%),
                radial-gradient(circle at 80% 80%, rgba(0, 245, 201, 0.1) 0%, transparent 50%),
                var(--matrix-bg);
            position: relative;
            overflow: hidden;
        }

        .hero-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-image:
                linear-gradient(rgba(128, 114, 255, 0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(128, 114, 255, 0.03) 1px, transparent 1px);
            background-size: 3rem 3rem;
            animation: matrix-grid 20s linear infinite;
        }

        @keyframes matrix-grid {
            0% { transform: translate(0, 0); }
            100% { transform: translate(3rem, 3rem); }
        }

        .hero-content {
            position: relative;
            z-index: 2;
        }

        .hero-title {
            font-size: 4.5rem;
            font-weight: 700;
            background: var(--landing-gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            line-height: 1.1;
            margin-bottom: 1.5rem;
        }

        .hero-subtitle {
            font-size: 1.4rem;
            color: var(--matrix-text-secondary);
            margin-bottom: 2.5rem;
            max-width: 600px;
        }

        .btn-hero {
            padding: 1rem 2.5rem;
            font-size: 1.1rem;
            font-weight: 600;
            background: var(--landing-gradient-primary);
            border: none;
            border-radius: 12px;
            color: white;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            box-shadow: 0 8px 25px var(--landing-glow-primary);
            position: relative;
            overflow: hidden;
        }

        .btn-hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }

        .btn-hero:hover::before {
            left: 100%;
        }

        .btn-hero:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 35px var(--landing-glow-primary);
            color: white;
        }

        .btn-secondary-hero {
            padding: 1rem 2rem;
            font-size: 1.1rem;
            background: transparent;
            border: 2px solid var(--matrix-primary);
            color: var(--matrix-primary);
            text-decoration: none;
            border-radius: 12px;
            transition: all 0.3s ease;
        }

        .btn-secondary-hero:hover {
            background: var(--matrix-primary);
            color: white;
            transform: translateY(-2px);
        }

        /* Features Section */
        .features-section {
            padding: 8rem 0;
            background: 
                radial-gradient(circle at 50% 0%, rgba(128, 114, 255, 0.08) 0%, transparent 70%),
                var(--matrix-bg);
        }

        .feature-card {
            background: var(--matrix-surface-transparent);
            border: 1px solid var(--matrix-border);
            border-radius: 16px;
            padding: 3rem 2rem;
            text-align: center;
            transition: all 0.3s ease;
            backdrop-filter: blur(12px);
            height: 100%;
            position: relative;
            overflow: hidden;
        }

        .feature-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(circle at 50% 0%, var(--landing-glow-primary) 0%, transparent 70%);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .feature-card:hover::before {
            opacity: 1;
        }

        .feature-card:hover {
            transform: translateY(-10px);
            border-color: var(--matrix-primary);
            box-shadow: 0 20px 40px rgba(128, 114, 255, 0.2);
        }

        .feature-icon {
            width: 80px;
            height: 80px;
            background: var(--landing-gradient-primary);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 2rem;
            font-size: 2rem;
            color: white;
            position: relative;
            z-index: 2;
        }

        .feature-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1rem;
            position: relative;
            z-index: 2;
        }

        .feature-description {
            color: var(--matrix-text-secondary);
            line-height: 1.6;
            position: relative;
            z-index: 2;
        }

        /* Stats Section */
        .stats-section {
            padding: 6rem 0;
            background: var(--matrix-surface);
            position: relative;
        }

        .stat-item {
            text-align: center;
            padding: 2rem 1rem;
        }

        .stat-number {
            font-size: 3.5rem;
            font-weight: 700;
            background: var(--landing-gradient-secondary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            display: block;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 1.1rem;
            color: var(--matrix-text-secondary);
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* CTA Section */
        .cta-section {
            padding: 8rem 0;
            background: 
                radial-gradient(circle at 50% 50%, rgba(128, 114, 255, 0.1) 0%, transparent 70%),
                var(--matrix-bg);
            text-align: center;
        }

        .cta-title {
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
        }

        .cta-subtitle {
            font-size: 1.2rem;
            color: var(--matrix-text-secondary);
            margin-bottom: 3rem;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }

        /* Navbar customizations */
        .navbar-landing {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            background: rgba(13, 12, 29, 0.95) !important;
            backdrop-filter: blur(12px);
            transition: all 0.3s ease;
        }

        .navbar-landing.scrolled {
            background: rgba(13, 12, 29, 0.98) !important;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .hero-title {
                font-size: 3rem;
            }
            
            .hero-subtitle {
                font-size: 1.2rem;
            }
            
            .feature-card {
                padding: 2rem 1.5rem;
            }
            
            .cta-title {
                font-size: 2.2rem;
            }
        }

        /* Floating elements animation */
        .floating-element {
            position: absolute;
            opacity: 0.1;
            animation: floating 6s ease-in-out infinite;
        }

        .floating-element:nth-child(1) { top: 20%; left: 10%; animation-delay: 0s; }
        .floating-element:nth-child(2) { top: 60%; right: 15%; animation-delay: 2s; }
        .floating-element:nth-child(3) { bottom: 30%; left: 20%; animation-delay: 4s; }

        @keyframes floating {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(180deg); }
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-landing">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="#" style="font-size: 1.5rem;">
                <?php if ($logo_src): ?>
                    <img src="<?php echo htmlspecialchars($logo_src); ?>" alt="Logo" style="height: 40px; margin-right: 12px;">
                <?php endif; ?>
                <span style="background: var(--landing-gradient-primary); -webkit-background-clip: text; -webkit-text-fill-color: transparent; font-weight: 700;">
                    <?php echo htmlspecialchars($app_name); ?>
                </span>
            </a>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item">
                        <a class="nav-link" href="#features">Recursos</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#about">Sobre</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#contact">Contato</a>
                    </li>
                    <li class="nav-item ms-3">
                        <a href="login.php" class="btn btn-outline-primary btn-sm px-3">
                            <i class="bi bi-box-arrow-in-right me-1"></i>
                            Entrar
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero-section d-flex align-items-center">
        <!-- Floating elements -->
        <div class="floating-element">
            <i class="bi bi-cpu" style="font-size: 4rem; color: var(--matrix-primary);"></i>
        </div>
        <div class="floating-element">
            <i class="bi bi-diagram-3" style="font-size: 3rem; color: var(--matrix-success);"></i>
        </div>
        <div class="floating-element">
            <i class="bi bi-graph-up-arrow" style="font-size: 3.5rem; color: var(--matrix-primary);"></i>
        </div>

        <div class="container hero-content">
            <div class="row align-items-center">
                <div class="col-lg-6">
                    <h1 class="hero-title">
                        Gestão Inteligente
                        <br>de Suprimentos
                    </h1>
                    <p class="hero-subtitle">
                        Transforme sua operação com o <?php echo htmlspecialchars($app_name); ?>. 
                        Automação, inteligência e controle total sobre requisições, cotações, 
                        pedidos e fornecedores em uma plataforma única.
                    </p>
                    <div class="d-flex flex-wrap gap-3">
                        <a href="login.php" class="btn-hero">
                            <i class="bi bi-rocket-takeoff"></i>
                            Começar Agora
                        </a>
                        <a href="#features" class="btn-secondary-hero">
                            Conhecer Recursos
                        </a>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="position-relative">
                        <!-- Dashboard Preview -->
                        <div class="card-matrix p-4" style="transform: perspective(1000px) rotateY(-5deg) rotateX(5deg);">
                            <div class="d-flex justify-content-between align-items-center mb-4">
                                <h5 class="mb-0">Dashboard Executivo</h5>
                                <span class="badge bg-success">Tempo Real</span>
                            </div>
                            
                            <div class="row g-3 mb-4">
                                <div class="col-6">
                                    <div class="bg-dark p-3 rounded">
                                        <div class="text-success small">Pedidos (Mês)</div>
                                        <div class="h4 mb-0">R$ 2.847.320</div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="bg-dark p-3 rounded">
                                        <div class="text-info small">Fornecedores</div>
                                        <div class="h4 mb-0">1.247</div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Mini Chart -->
                            <div class="bg-dark p-3 rounded">
                                <div class="text-secondary small mb-2">Evolução de Pedidos</div>
                                <div class="d-flex align-items-end gap-1" style="height: 60px;">
                                    <div class="bg-primary" style="width: 8px; height: 40%; border-radius: 2px;"></div>
                                    <div class="bg-primary" style="width: 8px; height: 60%; border-radius: 2px;"></div>
                                    <div class="bg-primary" style="width: 8px; height: 35%; border-radius: 2px;"></div>
                                    <div class="bg-primary" style="width: 8px; height: 80%; border-radius: 2px;"></div>
                                    <div class="bg-primary" style="width: 8px; height: 100%; border-radius: 2px;"></div>
                                    <div class="bg-primary" style="width: 8px; height: 70%; border-radius: 2px;"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section id="features" class="features-section">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="display-4 fw-bold mb-4">Recursos Poderosos</h2>
                <p class="lead text-secondary">Descubra as funcionalidades que tornam o <?php echo htmlspecialchars($app_name); ?> a escolha ideal para sua empresa</p>
            </div>
            
            <div class="row g-4">
                <div class="col-lg-4 col-md-6">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-lightning-charge"></i>
                        </div>
                        <h3 class="feature-title">Automação Inteligente</h3>
                        <p class="feature-description">
                            Automatize processos de cotação, aprovação e emissão de pedidos. 
                            Reduza tempo operacional em até 70% com fluxos inteligentes.
                        </p>
                    </div>
                </div>
                
                <div class="col-lg-4 col-md-6">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-graph-up-arrow"></i>
                        </div>
                        <h3 class="feature-title">Analytics Avançado</h3>
                        <p class="feature-description">
                            Dashboards em tempo real, KPIs inteligentes e relatórios customizáveis. 
                            Tome decisões baseadas em dados precisos.
                        </p>
                    </div>
                </div>
                
                <div class="col-lg-4 col-md-6">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-shield-check"></i>
                        </div>
                        <h3 class="feature-title">Controle Total</h3>
                        <p class="feature-description">
                            Rastreabilidade completa, auditoria automática e controle de acesso granular. 
                            Segurança e governança em cada processo.
                        </p>
                    </div>
                </div>
                
                <div class="col-lg-4 col-md-6">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-people"></i>
                        </div>
                        <h3 class="feature-title">Gestão de Fornecedores</h3>
                        <p class="feature-description">
                            Cadastro inteligente, avaliação de performance e integração com sistemas externos. 
                            Construa parcerias estratégicas.
                        </p>
                    </div>
                </div>
                
                <div class="col-lg-4 col-md-6">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-currency-exchange"></i>
                        </div>
                        <h3 class="feature-title">Cotações Dinâmicas</h3>
                        <p class="feature-description">
                            Sistema de cotações multi-fornecedor com comparação automática, 
                            histórico de preços e alertas de variação.
                        </p>
                    </div>
                </div>
                
                <div class="col-lg-4 col-md-6">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="bi bi-phone"></i>
                        </div>
                        <h3 class="feature-title">Mobile First</h3>
                        <p class="feature-description">
                            Interface responsiva e aplicativo mobile. Aprove, acompanhe e gerencie 
                            de qualquer lugar, a qualquer momento.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Stats Section -->
    <section id="about" class="stats-section">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="display-4 fw-bold mb-4">Resultados Comprovados</h2>
                <p class="lead text-secondary">Números que demonstram o impacto do <?php echo htmlspecialchars($app_name); ?> nas organizações</p>
            </div>
            
            <div class="row">
                <div class="col-lg-3 col-md-6">
                    <div class="stat-item">
                        <span class="stat-number" data-count="70">0</span>
                        <div class="stat-label">Redução de Tempo</div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <div class="stat-item">
                        <span class="stat-number" data-count="95">0</span>
                        <div class="stat-label">Satisfação Cliente</div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <div class="stat-item">
                        <span class="stat-number" data-count="500">0</span>
                        <div class="stat-label">Empresas Ativas</div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <div class="stat-item">
                        <span class="stat-number" data-count="99">0</span>
                        <div class="stat-label">Uptime Garantido</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section id="contact" class="cta-section">
        <div class="container">
            <h2 class="cta-title">Pronto para Transformar sua Gestão?</h2>
            <p class="cta-subtitle">
                Junte-se a centenas de empresas que já revolucionaram seus processos de suprimentos 
                com o <?php echo htmlspecialchars($app_name); ?>. Comece sua jornada hoje mesmo.
            </p>
            
            <div class="d-flex flex-wrap justify-content-center gap-3">
                <a href="login.php" class="btn-hero">
                    <i class="bi bi-rocket-takeoff"></i>
                    Iniciar Teste Gratuito
                </a>
                <a href="mailto:contato@dekanto.com" class="btn-secondary-hero">
                    <i class="bi bi-envelope me-2"></i>
                    Falar com Especialista
                </a>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-dark py-5">
        <div class="container">
            <div class="row">
                <div class="col-lg-6">
                    <div class="d-flex align-items-center mb-3">
                        <?php if ($logo_src): ?>
                            <img src="<?php echo htmlspecialchars($logo_src); ?>" alt="Logo" style="height: 32px; margin-right: 12px;">
                        <?php endif; ?>
                        <span class="h4 mb-0" style="background: var(--landing-gradient-primary); -webkit-background-clip: text; -webkit-text-fill-color: transparent; font-weight: 700;">
                            <?php echo htmlspecialchars($app_name); ?>
                        </span>
                    </div>
                    <p class="text-secondary">
                        Plataforma inteligente de gestão de suprimentos que transforma 
                        a forma como sua empresa compra, controla e otimiza processos.
                    </p>
                </div>
                <div class="col-lg-6">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="text-white mb-3">Produto</h6>
                            <ul class="list-unstyled">
                                <li><a href="#features" class="text-secondary text-decoration-none">Recursos</a></li>
                                <li><a href="#" class="text-secondary text-decoration-none">Preços</a></li>
                                <li><a href="#" class="text-secondary text-decoration-none">Integrações</a></li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-white mb-3">Suporte</h6>
                            <ul class="list-unstyled">
                                <li><a href="#" class="text-secondary text-decoration-none">Documentação</a></li>
                                <li><a href="#" class="text-secondary text-decoration-none">Contato</a></li>
                                <li><a href="#" class="text-secondary text-decoration-none">Status</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            <hr class="my-4" style="border-color: var(--matrix-border);">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <p class="text-secondary mb-0">&copy; 2025 <?php echo htmlspecialchars($app_name); ?>. Todos os direitos reservados.</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <a href="login.php" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-box-arrow-in-right me-1"></i>
                        Acessar Plataforma
                    </a>
                </div>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Navbar scroll effect
        window.addEventListener('scroll', function() {
            const navbar = document.querySelector('.navbar-landing');
            if (window.scrollY > 50) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
        });

        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Stats counter animation
        function animateValue(element, start, end, duration) {
            let startTimestamp = null;
            const step = (timestamp) => {
                if (!startTimestamp) startTimestamp = timestamp;
                const progress = Math.min((timestamp - startTimestamp) / duration, 1);
                element.innerHTML = Math.floor(progress * (end - start) + start) + (element.getAttribute('data-count') > 90 ? '%' : '');
                if (progress < 1) {
                    window.requestAnimationFrame(step);
                }
            };
            window.requestAnimationFrame(step);
        }

        // Intersection Observer for stats animation
        const statsObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const statNumbers = entry.target.querySelectorAll('.stat-number');
                    statNumbers.forEach(stat => {
                        const endValue = parseInt(stat.getAttribute('data-count'));
                        animateValue(stat, 0, endValue, 2000);
                    });
                    statsObserver.unobserve(entry.target);
                }
            });
        }, { threshold: 0.5 });

        const statsSection = document.querySelector('.stats-section');
        if (statsSection) {
            statsObserver.observe(statsSection);
        }

        // Parallax effect for floating elements
        window.addEventListener('scroll', function() {
            const scrolled = window.pageYOffset;
            const parallaxElements = document.querySelectorAll('.floating-element');
            
            parallaxElements.forEach((element, index) => {
                const speed = 0.5 + (index * 0.1);
                const yPos = -(scrolled * speed);
                element.style.transform = `translateY(${yPos}px) rotate(${scrolled * 0.1}deg)`;
            });
        });
    </script>
</body>
</html>
