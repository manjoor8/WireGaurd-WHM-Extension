// WireGuard VPN Manager - Vanilla JS
document.addEventListener('DOMContentLoaded', function () {
    // QR Code modal triggers
    document.querySelectorAll('.btn-qr').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var clientId = this.getAttribute('data-client-id');
            var clientName = this.getAttribute('data-client-name');
            openQrModal(clientId, clientName);
        });
    });

    // Revoke modal triggers
    document.querySelectorAll('.btn-revoke').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var clientId = this.getAttribute('data-client-id');
            var clientName = this.getAttribute('data-client-name');
            openRevokeModal(clientId, clientName);
        });
    });

    // Mobile navigation toggle
    var navToggle = document.getElementById('navToggle');
    var appNav = document.getElementById('appNav');
    if (navToggle && appNav) {
        navToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            var isOpen = appNav.classList.toggle('nav-open');
            navToggle.classList.toggle('active');
            navToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });

        // Close mobile nav when clicking outside
        document.addEventListener('click', function (e) {
            if (appNav.classList.contains('nav-open') && !appNav.contains(e.target) && !navToggle.contains(e.target)) {
                appNav.classList.remove('nav-open');
                navToggle.classList.remove('active');
                navToggle.setAttribute('aria-expanded', 'false');
            }
        });
    }

    // Auto dismiss alerts after 5 seconds
    setTimeout(function () {
        var alerts = document.querySelectorAll('.alert');
        alerts.forEach(function (el) {
            el.style.transition = 'opacity 0.5s ease-out';
            el.style.opacity = '0';
            setTimeout(function () {
                if (el.parentNode) el.parentNode.removeChild(el);
            }, 500);
        });
    }, 5000);
});

function openModal(modalId) {
    var modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'flex';
    }
}

function closeModal(modalId) {
    var modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'none';
    }
}

function openRevokeModal(clientId, clientName) {
    var idInput = document.getElementById('revokeClientId');
    var nameSpan = document.getElementById('revokeClientName');
    if (idInput) idInput.value = clientId;
    if (nameSpan) nameSpan.textContent = clientName;
    openModal('revokeModal');
}

function openQrModal(clientId, clientName) {
    var nameSpan = document.getElementById('qrClientName');
    var container = document.getElementById('qrImageContainer');
    if (nameSpan) nameSpan.textContent = clientName;
    if (container) {
        container.innerHTML = '<div class="spinner">Loading QR Code...</div>';
    }
    openModal('qrModal');

    // Fetch dynamic QR code data URI
    fetch('/client.php?id=' + encodeURIComponent(clientId) + '&qr=1')
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data && data.qr) {
                container.innerHTML = '<img src="' + data.qr + '" alt="WireGuard QR Code" class="qr-img">';
            } else {
                container.innerHTML = '<div class="alert alert-danger">Could not generate QR code: ' + (data.error || 'Unknown error') + '</div>';
            }
        })
        .catch(function (err) {
            container.innerHTML = '<div class="alert alert-danger">Failed to fetch QR code: ' + err.message + '</div>';
        });
}

function copyConfig() {
    var box = document.getElementById('configBox');
    if (!box) return;

    var text = box.textContent;
    navigator.clipboard.writeText(text).then(function () {
        var btn = event.target;
        var orig = btn.textContent;
        btn.textContent = 'Copied!';
        btn.classList.add('btn-success');
        setTimeout(function () {
            btn.textContent = orig;
            btn.classList.remove('btn-success');
        }, 2000);
    }).catch(function (err) {
        alert('Could not copy to clipboard: ' + err);
    });
}

// Close modals when clicking outside modal dialog
window.addEventListener('click', function (e) {
    if (e.target && e.target.classList.contains('modal')) {
        e.target.style.display = 'none';
    }
});
