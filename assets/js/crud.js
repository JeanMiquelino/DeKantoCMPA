// assets/js/crud.js

/**
 * Script genérico para operações CRUD (Create, Read, Update, Delete)
 * Utiliza data-attributes de um container principal para configuração.
 * * Requer:
 * - Um elemento container com id="page-container" e os seguintes data-attributes:
 * - data-endpoint: A URL da API (ex: ../api/fornecedores.php)
 * - data-singular: O nome do item no singular (ex: Fornecedor)
 * - data-plural: O nome do item no plural (ex: Fornecedores)
 * - Um formulário com id="form-main"
 * - Um tbody com id="tabela-main-body" para a listagem
 * - Uma função global `criarLinhaTabela(item)` definida na página HTML específica.
 */
document.addEventListener('DOMContentLoaded', function() {
    const pageContainer = document.getElementById('page-container');
    if (!pageContainer) {
        console.error('Erro Crítico: Elemento #page-container não encontrado. O script não pode ser inicializado.');
        return;
    }

    // Configuração lida a partir dos atributos data-* do container da página
    const config = {
        endpoint: pageContainer.dataset.endpoint,
        singular: pageContainer.dataset.singular,
        plural: pageContainer.dataset.plural,
        form: document.getElementById('form-main'),
        tableBody: document.getElementById('tabela-main-body'),
        submitButton: document.querySelector('#form-main button[type="submit"]'),
        editandoId: null
    };

    if (!config.endpoint || !config.form || !config.tableBody || !config.submitButton) {
        console.error('Erro de configuração: Um ou mais elementos essenciais (form, tabela, botão) ou data-attributes não foram encontrados.');
        return;
    }
    
    const originalButtonHtml = config.submitButton.innerHTML;

    // Referências do modal de confirmação (se existir na página)
    const confirmationModalEl = document.getElementById('confirmationModal');
    const confirmationModal = confirmationModalEl ? new bootstrap.Modal(confirmationModalEl) : null;
    const confirmDeleteBtn = confirmationModalEl ? confirmationModalEl.querySelector('#confirm-delete-btn') : null;
    const modalItemName = confirmationModalEl ? confirmationModalEl.querySelector('#modal-item-name') : null;
    let pendingDeleteId = null;

    function askConfirmDelete(label, onConfirm){
        // Preferir modal global caso disponível
        if (window.confirmDialog) {
            window.confirmDialog({
                title: 'Remover',
                message: 'Tem a certeza que deseja remover '+(label||('este '+(config.singular||'item')))+'?',
                variant: 'danger',
                confirmText: 'Remover',
                cancelText: 'Cancelar'
            }).then(ok=>{ if(ok){ onConfirm && onConfirm(); } });
            return;
        }
        if(confirmationModal && confirmDeleteBtn){
            // Atualiza mensagem do modal, quando houver placeholder
            if(modalItemName){ modalItemName.textContent = label || ''; }
            // Garante listener único
            const handler = ()=>{ try { onConfirm && onConfirm(); } finally { confirmationModal.hide(); } };
            confirmDeleteBtn.replaceWith(confirmDeleteBtn.cloneNode(true));
            const newBtn = confirmationModalEl.querySelector('#confirm-delete-btn');
            newBtn.addEventListener('click', handler, { once: true });
            confirmationModal.show();
        } else {
            // Fallback para confirm nativo
            if(window.confirm('Tem a certeza que deseja remover '+(label||('este '+(config.singular||'item')))+'?')){
                onConfirm && onConfirm();
            }
        }
    }

    // Função para mostrar notificações (toasts)
    function showToast(message, type = 'info') {
        const toastContainer = document.querySelector('.toast-container');
        if (!toastContainer) return;

        const toastId = 'toast-' + Date.now();
        const toastHtml = `
            <div id="${toastId}" class="toast align-items-center text-white bg-${type} border-0" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body">${message}</div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Fechar"></button>
                </div>
            </div>`;
        toastContainer.insertAdjacentHTML('beforeend', toastHtml);
        const toastEl = document.getElementById(toastId);
        const bsToast = new bootstrap.Toast(toastEl, { delay: 3000 });
        bsToast.show();
        toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
    }

    // Carrega os dados da API e renderiza a tabela
    function carregarDados() {
        fetch(config.endpoint)
            .then(res => {
                if (!res.ok) throw new Error(`Erro na rede ou servidor: ${res.statusText}`);
                return res.json();
            })
            .then(dados => {
                config.tableBody.innerHTML = '';
                if (dados.length === 0) {
                    const cols = config.tableBody.parentElement.querySelector('thead tr').childElementCount;
                    config.tableBody.innerHTML = `<tr><td colspan="${cols}" class="text-center text-secondary py-4">Nenhum ${config.plural.toLowerCase()} encontrado.</td></tr>`;
                } else {
                    dados.forEach(item => {
                        // A função criarLinhaTabela DEVE ser definida na página HTML específica
                        if (typeof window.criarLinhaTabela === 'function') {
                            config.tableBody.innerHTML += window.criarLinhaTabela(item);
                        }
                    });
                }
            })
            .catch(err => {
                console.error(err);
                showToast(`Erro ao carregar ${config.plural.toLowerCase()}. Verifique a consola.`, 'danger');
            });
    }

    // Limpa o formulário e redefine o estado de edição
    function limparForm() {
        config.editandoId = null;
        config.form.reset();
        config.submitButton.innerHTML = originalButtonHtml;
        config.form.querySelector('input').focus();
    }

    // Preenche o formulário para edição
    window.editarItem = function(item) {
        config.editandoId = item.id;
        for (const key in item) {
            if (config.form.elements[key]) {
                config.form.elements[key].value = item[key];
            }
        }
        config.submitButton.innerHTML = `<i class="bi bi-check-circle me-2"></i>Atualizar ${config.singular}`;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // Remove um item (com modal)
    window.removerItem = function(id, labelOpt) {
        const label = labelOpt || `${(config.singular||'Item')} #${id}`;
        pendingDeleteId = id;
        askConfirmDelete(label, () => {
            fetch(config.endpoint, {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: pendingDeleteId })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast(`${config.singular} removido com sucesso!`, 'success');
                    carregarDados();
                } else {
                    showToast(data.erro || `Erro ao remover ${config.singular.toLowerCase()}.`, 'danger');
                }
            })
            .catch(err => {
                console.error(err);
                showToast('Erro de comunicação ao remover.', 'danger');
            })
            .finally(() => { pendingDeleteId = null; });
        });
    }

    // Lida com o envio do formulário (criação ou atualização)
    config.form.addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(config.form);
        const data = Object.fromEntries(formData.entries());
        
        const method = config.editandoId ? 'PUT' : 'POST';
        if (config.editandoId) {
            data.id = config.editandoId;
        }

        fetch(config.endpoint, {
            method: method,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        })
        .then(res => res.json())
        .then(resData => {
            if (resData.success) {
                showToast(`${config.singular} ${config.editandoId ? 'atualizado' : 'adicionado'} com sucesso!`, 'success');
                limparForm();
                carregarDados();
            } else {
                showToast(resData.erro || 'Ocorreu um erro ao salvar.', 'danger');
            }
        })
        .catch(err => {
            console.error(err);
            showToast('Erro de comunicação com o servidor.', 'danger');
        });
    });

    // Associa a função de limpar ao botão "Limpar"
    const btnLimpar = document.getElementById('btn-limpar');
    if (btnLimpar) {
        btnLimpar.addEventListener('click', limparForm);
    }
    
    // Carga inicial dos dados
    carregarDados();
});