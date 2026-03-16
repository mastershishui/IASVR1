// BCP University Management System - Main JS

document.addEventListener('DOMContentLoaded', function() {

    // ===== TABS =====
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const target = this.dataset.tab;
            const container = this.closest('.tabs-container') || document;
            container.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            container.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
            this.classList.add('active');
            const panel = container.querySelector(`#tab-${target}`);
            if (panel) panel.classList.add('active');
        });
    });

    // ===== LOGIN TABS =====
    document.querySelectorAll('.login-tab').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.login-tab').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
        });
    });

    // ===== MODALS =====
    document.querySelectorAll('[data-modal]').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.modal;
            const modal = document.getElementById(id);
            if (modal) modal.classList.add('open');
        });
    });

    document.querySelectorAll('.modal-close, .modal-cancel').forEach(btn => {
        btn.addEventListener('click', function() {
            this.closest('.modal-overlay').classList.remove('open');
        });
    });

    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', function(e) {
            if (e.target === this) this.classList.remove('open');
        });
    });

    // ===== SIDEBAR COLLAPSE =====
    const collapseBtn = document.querySelector('.collapse-btn');
    const sidebar = document.querySelector('.sidebar');
    if (collapseBtn && sidebar) {
        collapseBtn.addEventListener('click', function() {
            sidebar.classList.toggle('collapsed');
        });
    }

    // ===== ALERTS AUTO-DISMISS =====
    document.querySelectorAll('.alert[data-dismiss]').forEach(alert => {
        setTimeout(() => alert.remove(), parseInt(alert.dataset.dismiss) || 4000);
    });

    // ===== SEARCH FILTER =====
    const searchInput = document.getElementById('tableSearch');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const val = this.value.toLowerCase();
            document.querySelectorAll('.searchable-row').forEach(row => {
                row.style.display = row.textContent.toLowerCase().includes(val) ? '' : 'none';
            });
        });
    }

    // ===== ROLE SWITCHER =====
    document.querySelectorAll('.role-tab').forEach(tab => {
        tab.addEventListener('click', function() {
            document.querySelectorAll('.role-tab').forEach(t => t.classList.remove('active'));
            this.classList.add('active');
        });
    });

    // ===== CONFIRM DIALOGS =====
    document.querySelectorAll('[data-confirm]').forEach(el => {
        el.addEventListener('click', function(e) {
            if (!confirm(this.dataset.confirm)) e.preventDefault();
        });
    });

    // ===== NOTIFICATION BELL =====
    const notifBtn = document.querySelector('.notif-bell');
    if (notifBtn) {
        notifBtn.addEventListener('click', function() {
            fetch('ajax/notifications.php')
                .then(r => r.json())
                .then(data => {
                    // Update notification count
                    const dot = this.querySelector('.notif-dot');
                    if (data.count === 0 && dot) dot.style.display = 'none';
                })
                .catch(() => {});
        });
    }

    // ===== LIVE CHART BARS =====
    initCharts();
    
    // ===== FORM VALIDATION =====
    document.querySelectorAll('form[data-validate]').forEach(form => {
        form.addEventListener('submit', function(e) {
            let valid = true;
            this.querySelectorAll('[required]').forEach(field => {
                if (!field.value.trim()) {
                    valid = false;
                    field.classList.add('error');
                    field.style.borderColor = 'var(--danger)';
                } else {
                    field.classList.remove('error');
                    field.style.borderColor = '';
                }
            });
            if (!valid) {
                e.preventDefault();
                showToast('Please fill in all required fields.', 'danger');
            }
        });
    });
});

// ===== CHARTS =====
function initCharts() {
    const chartBars = document.querySelectorAll('.chart-bar');
    chartBars.forEach(bar => {
        const height = bar.dataset.value || '50';
        bar.style.height = height + '%';
    });
}

// ===== TOAST =====
function showToast(message, type = 'info', duration = 3500) {
    const container = document.getElementById('toast-container') || createToastContainer();
    const toast = document.createElement('div');
    const colors = {
        success: '#10B981',
        danger: '#EF4444',
        warning: '#F59E0B',
        info: '#2563EB'
    };
    toast.style.cssText = `
        background: white;
        border-left: 4px solid ${colors[type] || colors.info};
        padding: 12px 18px;
        border-radius: 8px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.12);
        font-size: 13.5px;
        color: #0F172A;
        font-family: 'DM Sans', sans-serif;
        animation: slideIn 0.3s ease;
        max-width: 320px;
    `;
    toast.textContent = message;
    container.appendChild(toast);
    setTimeout(() => {
        toast.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, duration);
}

function createToastContainer() {
    const container = document.createElement('div');
    container.id = 'toast-container';
    container.style.cssText = `
        position: fixed;
        bottom: 24px;
        right: 24px;
        z-index: 9999;
        display: flex;
        flex-direction: column;
        gap: 10px;
    `;
    document.body.appendChild(container);
    
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideIn { from { transform: translateX(100%); opacity:0; } to { transform: translateX(0); opacity:1; } }
        @keyframes slideOut { from { transform: translateX(0); opacity:1; } to { transform: translateX(100%); opacity:0; } }
    `;
    document.head.appendChild(style);
    return container;
}

// ===== AJAX HELPER =====
function ajaxPost(url, data, callback) {
    const form = new FormData();
    Object.keys(data).forEach(k => form.append(k, data[k]));
    fetch(url, { method: 'POST', body: form })
        .then(r => r.json())
        .then(callback)
        .catch(e => showToast('Network error.', 'danger'));
}

// ===== PRINT =====
function printSection(id) {
    const el = document.getElementById(id);
    if (!el) return;
    const win = window.open('', '_blank');
    win.document.write(`
        <html><head>
        <title>BCP-UMS Print</title>
        <link rel="stylesheet" href="css/style.css">
        <style>body{padding:20px;} .no-print{display:none;}</style>
        </head><body>${el.innerHTML}</body></html>
    `);
    win.document.close();
    win.print();
}

// ===== PROCESS FLOW ANIMATION =====
function animateFlow() {
    document.querySelectorAll('.flow-step').forEach((step, i) => {
        setTimeout(() => {
            step.querySelector('.flow-step-circle')?.classList.add('animated');
        }, i * 200);
    });
}
