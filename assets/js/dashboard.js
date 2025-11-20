// Theme Toggle Logic
function toggleTheme() {
    const html = document.documentElement;
    const icon = document.querySelector('.theme-toggle i');

    if (html.getAttribute('data-bs-theme') === 'dark') {
        html.setAttribute('data-bs-theme', 'light');
        icon.className = 'bi bi-moon-stars';
        localStorage.setItem('theme', 'light');
        showToast('Tema claro ativado', 'success');
    } else {
        html.setAttribute('data-bs-theme', 'dark');
        icon.className = 'bi bi-sun';
        localStorage.setItem('theme', 'dark');
        showToast('Tema escuro ativado', 'success');
    }
}

// Toast Notifications
function showToast(message, type = 'info') {
    const toastContainer = document.querySelector('.toast-container');
    if (!toastContainer) return;

    const toast = document.createElement('div');
    toast.className = `toast align-items-center text-white bg-${type} border-0`;
    toast.setAttribute('role', 'alert');
    toast.innerHTML = `
        <div class="d-flex">
            <div class="toast-body">${message}</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    `;
    toastContainer.appendChild(toast);

    const bsToast = new bootstrap.Toast(toast);
    bsToast.show();

    toast.addEventListener('hidden.bs.toast', () => {
        toast.remove();
    });
}

// Animate KPIs with loading state
function animateKPI(element, value, prefix = '', suffix = '', duration = 1500) {
    if (!element) return;
    
    element.parentElement.classList.add('loading');
    
    let start = 0;
    let end = parseFloat(value.toString().replace(/[^0-9.]/g, '')) || 0;
    let stepTime = Math.ceil(duration / 50);
    let current = 0;
    let increment = end / (duration / stepTime);

    function update() {
        current += increment;
        if (current >= end) {
            current = end;
        }

        if (prefix === 'R$ ') {
            element.textContent = prefix + current.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        } else {
            element.textContent = prefix + Math.round(current) + suffix;
        }
        
        if (current < end) {
            setTimeout(update, stepTime);
        } else {
            // Ensure the final value is displayed correctly formatted
             if (prefix === 'R$ ') {
                element.textContent = prefix + end.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
             }
            element.parentElement.classList.remove('loading');
        }
    }
    
    setTimeout(update, 300);
}


// Event Listener for DOMContentLoaded
document.addEventListener('DOMContentLoaded', function() {
    // Load saved theme
    const savedTheme = localStorage.getItem('theme') || 'light';
    document.documentElement.setAttribute('data-bs-theme', savedTheme);
    const icon = document.querySelector('.theme-toggle i');
    if(icon) {
        icon.className = savedTheme === 'dark' ? 'bi bi-sun' : 'bi bi-moon-stars';
    }

    // Initialize tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Animate KPIs by reading data-value attributes
    const kpiElements = document.querySelectorAll('.kpi-anim');
    kpiElements.forEach(el => {
        const value = el.dataset.value;
        const prefix = el.dataset.prefix || '';
        animateKPI(el, value, prefix);
    });
    
    // Welcome message
    setTimeout(() => {
        showToast(`Bem-vindo ao ${document.title.split(' - ')[0]}! 🎉`, 'success');
    }, 1500);

    // Global Search event
    const globalSearch = document.getElementById('globalSearch');
    if(globalSearch){
        globalSearch.addEventListener('input', function(e) {
            const query = e.target.value.toLowerCase();
            if (query.length > 2) {
                showToast(`Buscando por: ${query}`, 'info');
            }
        });
    }

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'k') {
            e.preventDefault();
            if(globalSearch) globalSearch.focus();
        }
        if (e.key === 'Escape') {
             if(globalSearch) globalSearch.blur();
        }
    });
});