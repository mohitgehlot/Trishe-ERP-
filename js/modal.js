// Central Modal Manager
const ModalManager = {
    // Open modal
    open: function(modalId, options = {}) {
        const modal = document.getElementById(modalId);
        if (!modal) return;
        
        modal.style.display = 'block';
        
        // Load content if URL provided
        if (options.url) {
            this.loadContent(modalId, options.url, options.data);
        }
        
        // Set custom title if provided
        if (options.title) {
            modal.querySelector('.modal-header h3').innerHTML = 
                `<i class="${options.icon || 'fas fa-plus-circle'}"></i> ${options.title}`;
        }
        
        // Trigger callback
        if (options.onOpen) options.onOpen(modal);
    },
    
    // Close modal
    close: function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) modal.style.display = 'none';
    },
    
    // Load content via AJAX
    loadContent: function(modalId, url, data = {}) {
        const body = document.getElementById(`${modalId}_body`);
        const loader = body.querySelector('.modal-loader');
        
        loader.style.display = 'block';
        
        fetch(url, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(data)
        })
        .then(response => response.text())
        .then(html => {
            loader.style.display = 'none';
            body.innerHTML = html;
        })
        .catch(error => {
            loader.style.display = 'none';
            body.innerHTML = '<p class="error">Error loading content</p>';
        });
    },
    
    // Set form data
    setFormData: function(modalId, data) {
        const modal = document.getElementById(modalId);
        Object.keys(data).forEach(key => {
            const input = modal.querySelector(`[name="${key}"]`);
            if (input) input.value = data[key];
        });
    }
};

// Initialize all modals
document.addEventListener('DOMContentLoaded', function() {
    // Close buttons
    document.querySelectorAll('[data-close]').forEach(btn => {
        btn.addEventListener('click', function() {
            ModalManager.close(this.dataset.close);
        });
    });
    
    // Close on outside click
    window.addEventListener('click', function(event) {
        if (event.target.classList.contains('modal')) {
            ModalManager.close(event.target.id);
        }
    });
});