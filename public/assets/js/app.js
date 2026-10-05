// WireGuard VPN Manager - Modern Vanilla JS
document.addEventListener('DOMContentLoaded', function () {
    // -------------------------------------------------------------
    // Helper: Retrieve active CSRF token from page meta or input
    // -------------------------------------------------------------
    function getCsrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta && meta.getAttribute('content')) {
            return meta.getAttribute('content');
        }
        var input = document.querySelector('input[name="csrf_token"]');
        if (input && input.value) {
            return input.value;
        }
        return '';
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }

    // -------------------------------------------------------------
    // Real-Time Client Search Filter
    // -------------------------------------------------------------
    var searchInput = document.getElementById('clientSearch');
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            var q = this.value.toLowerCase().trim();
            var rows = document.querySelectorAll('.client-card-row');
            var matchCount = 0;

            rows.forEach(function (row) {
                var name = row.getAttribute('data-name') || '';
                var ip = row.getAttribute('data-ip') || '';
                var desc = row.getAttribute('data-desc') || '';

                if (q === '' || name.indexOf(q) !== -1 || ip.indexOf(q) !== -1 || desc.indexOf(q) !== -1) {
                    row.style.display = 'flex';
                    matchCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            var noResults = document.getElementById('noSearchResults');
            if (noResults) {
                noResults.style.display = (matchCount === 0 && rows.length > 0) ? 'block' : 'none';
            }
        });
    }

    // -------------------------------------------------------------
    // Sort Dropdown & Sorting Logic
    // -------------------------------------------------------------
    var btnSort = document.getElementById('btnSort');
    var sortMenu = document.getElementById('sortMenu');
    if (btnSort && sortMenu) {
        btnSort.addEventListener('click', function (e) {
            e.stopPropagation();
            var isOpen = sortMenu.style.display === 'block';
            sortMenu.style.display = isOpen ? 'none' : 'block';
            btnSort.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
        });

        document.addEventListener('click', function (e) {
            if (!sortMenu.contains(e.target) && !btnSort.contains(e.target)) {
                sortMenu.style.display = 'none';
                btnSort.setAttribute('aria-expanded', 'false');
            }
        });

        document.querySelectorAll('.sort-option').forEach(function (opt) {
            opt.addEventListener('click', function () {
                var sortType = this.getAttribute('data-sort');
                document.querySelectorAll('.sort-option').forEach(function (o) {
                    o.classList.remove('active');
                });
                this.classList.add('active');
                sortMenu.style.display = 'none';
                btnSort.setAttribute('aria-expanded', 'false');
                sortClients(sortType);
            });
        });
    }

    function parseIp(ipStr) {
        if (!ipStr) return 0;
        var parts = ipStr.split('.');
        if (parts.length !== 4) return 0;
        return ((+parts[0]) * 16777216) + ((+parts[1]) * 65536) + ((+parts[2]) * 256) + (+parts[3]);
    }

    function sortClients(sortType) {
        var list = document.getElementById('clientsList');
        if (!list) return;
        var rows = Array.from(list.querySelectorAll('.client-card-row'));

        rows.sort(function (a, b) {
            if (sortType === 'name-asc') {
                return (a.getAttribute('data-name') || '').localeCompare(b.getAttribute('data-name') || '');
            } else if (sortType === 'name-desc') {
                return (b.getAttribute('data-name') || '').localeCompare(a.getAttribute('data-name') || '');
            } else if (sortType === 'ip-asc') {
                return parseIp(a.getAttribute('data-ip')) - parseIp(b.getAttribute('data-ip'));
            } else if (sortType === 'ip-desc') {
                return parseIp(b.getAttribute('data-ip')) - parseIp(a.getAttribute('data-ip'));
            } else if (sortType === 'traffic-desc') {
                var tA = parseInt(a.getAttribute('data-traffic') || 0, 10);
                var tB = parseInt(b.getAttribute('data-traffic') || 0, 10);
                return tB - tA;
            } else if (sortType === 'date-desc') {
                return (b.getAttribute('data-date') || '').localeCompare(a.getAttribute('data-date') || '');
            } else if (sortType === 'date-asc') {
                return (a.getAttribute('data-date') || '').localeCompare(b.getAttribute('data-date') || '');
            } else if (sortType === 'status') {
                var aActive = a.getAttribute('data-state') === 'active' ? 1 : 0;
                var bActive = b.getAttribute('data-state') === 'active' ? 1 : 0;
                return bActive - aActive;
            }
            return 0;
        });

        rows.forEach(function (row) {
            list.appendChild(row);
        });
    }

    // -------------------------------------------------------------
    // Action Dropdown Menu Toggle for each record
    // -------------------------------------------------------------
    document.querySelectorAll('.btn-action-dropdown').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var wrapper = this.closest('.action-dropdown-wrapper');
            var menu = wrapper ? wrapper.querySelector('.action-dropdown-menu') : null;
            if (!menu) return;

            var isOpen = menu.style.display === 'block';

            // Close all other action dropdown menus
            document.querySelectorAll('.action-dropdown-menu').forEach(function (m) {
                m.style.display = 'none';
            });
            document.querySelectorAll('.btn-action-dropdown').forEach(function (b) {
                b.setAttribute('aria-expanded', 'false');
            });

            if (!isOpen) {
                menu.style.display = 'block';
                btn.setAttribute('aria-expanded', 'true');
            }
        });
    });

    // Close action dropdowns when clicking outside
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.action-dropdown-wrapper')) {
            document.querySelectorAll('.action-dropdown-menu').forEach(function (m) {
                m.style.display = 'none';
            });
            document.querySelectorAll('.btn-action-dropdown').forEach(function (b) {
                b.setAttribute('aria-expanded', 'false');
            });
        }
    });

    // Close dropdown when any item inside is clicked
    document.querySelectorAll('.action-dropdown-menu .dropdown-item').forEach(function (item) {
        item.addEventListener('click', function () {
            var menu = this.closest('.action-dropdown-menu');
            if (menu) menu.style.display = 'none';
        });
    });

    // -------------------------------------------------------------
    // Menu Toggle Action (Instant AJAX Connect / Disconnect)
    // -------------------------------------------------------------
    document.querySelectorAll('.btn-menu-toggle').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var clientId = this.getAttribute('data-client-id');
            var toggleBtn = this;
            var csrfToken = getCsrfToken();
            var row = document.getElementById('client-row-' + clientId);

            var formData = new FormData();
            formData.append('action', 'toggle');
            formData.append('client_id', clientId);
            formData.append('csrf_token', csrfToken);
            formData.append('ajax', '1');

            fetch('/clients.php', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data && data.success) {
                    var badgeSpan = document.getElementById('client-badge-' + clientId);
                    var textSpan = toggleBtn.querySelector('.toggle-text');
                    var iconSvg = toggleBtn.querySelector('svg');

                    if (data.state === 'active') {
                        if (row) {
                            row.classList.remove('client-inactive');
                            row.setAttribute('data-state', 'active');
                        }
                        if (badgeSpan) {
                            badgeSpan.innerHTML = '<span class="badge badge-success"><span class="dot dot-online"></span> Active</span>';
                        }
                        if (textSpan) textSpan.textContent = 'Disconnect Client';
                        if (iconSvg) {
                            iconSvg.innerHTML = '<path d="M18.36 6.64a9 9 0 1 1-12.73 0"></path><line x1="12" y1="2" x2="12" y2="12"></line>';
                        }
                    } else {
                        if (row) {
                            row.classList.add('client-inactive');
                            row.setAttribute('data-state', 'disabled');
                        }
                        if (badgeSpan) {
                            badgeSpan.innerHTML = '<span class="badge badge-warning">Disconnected</span>';
                        }
                        if (textSpan) textSpan.textContent = 'Connect Client';
                        if (iconSvg) {
                            iconSvg.innerHTML = '<polyline points="20 6 9 17 4 12"></polyline>';
                        }
                    }
                } else {
                    alert('Could not toggle client: ' + (data.error || 'Server error'));
                }
            })
            .catch(function (err) {
                alert('Connection error: ' + err.message);
            });
        });
    });

    // -------------------------------------------------------------
    // Edit Client Modal & Submission
    // -------------------------------------------------------------
    document.querySelectorAll('.btn-edit-trigger').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var clientId = this.getAttribute('data-client-id');
            var clientName = this.getAttribute('data-client-name');
            var clientDesc = this.getAttribute('data-client-desc') || '';

            var idInput = document.getElementById('editClientId');
            var nameInput = document.getElementById('editClientName');
            var descInput = document.getElementById('editClientDesc');

            if (idInput) idInput.value = clientId;
            if (nameInput) nameInput.value = clientName;
            if (descInput) descInput.value = clientDesc;

            openModal('editModal');
        });
    });

    var editForm = document.getElementById('editClientForm');
    if (editForm) {
        editForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var idInput = document.getElementById('editClientId');
            var nameInput = document.getElementById('editClientName');
            var descInput = document.getElementById('editClientDesc');
            if (!idInput) return;

            var clientId = idInput.value;
            var newName = nameInput ? nameInput.value.trim() : '';
            var newDesc = descInput ? descInput.value.trim() : '';
            var saveBtn = document.getElementById('saveEditBtn');

            if (saveBtn) {
                saveBtn.disabled = true;
                saveBtn.textContent = 'Saving...';
            }

            var formData = new FormData(editForm);
            formData.append('ajax', '1');

            fetch('/clients.php', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                closeModal('editModal');
                if (saveBtn) {
                    saveBtn.disabled = false;
                    saveBtn.textContent = 'Save Changes';
                }

                if (data && data.success) {
                    var titleLink = document.getElementById('client-title-' + clientId);
                    var subtitleDiv = document.getElementById('client-subtitle-' + clientId);
                    var row = document.getElementById('client-row-' + clientId);

                    if (titleLink) titleLink.textContent = newName;
                    if (subtitleDiv) {
                        subtitleDiv.innerHTML = newDesc ? '<span>' + escapeHtml(newDesc) + '</span>' : '<span>Permanent</span>';
                    }
                    if (row) {
                        row.setAttribute('data-name', newName.toLowerCase());
                        row.setAttribute('data-display-name', newName);
                        row.setAttribute('data-desc', newDesc.toLowerCase());
                    }

                    var editBtn = document.querySelector('.btn-edit-trigger[data-client-id="' + clientId + '"]');
                    if (editBtn) {
                        editBtn.setAttribute('data-client-name', newName);
                        editBtn.setAttribute('data-client-desc', newDesc);
                    }
                } else {
                    alert('Could not update client: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(function (err) {
                closeModal('editModal');
                if (saveBtn) {
                    saveBtn.disabled = false;
                    saveBtn.textContent = 'Save Changes';
                }
                alert('Connection error: ' + err.message);
            });
        });
    }

    // -------------------------------------------------------------
    // Delete Client Modal & Permanent Deletion (DB + WireGuard)
    // -------------------------------------------------------------
    document.querySelectorAll('.btn-delete-trigger, .btn-delete-client').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var clientId = this.getAttribute('data-client-id');
            var clientName = this.getAttribute('data-client-name');
            var clientIp = this.getAttribute('data-client-ip') || '';

            var idInput = document.getElementById('deleteClientId');
            var nameSpan = document.getElementById('deleteClientName');
            var ipSpan = document.getElementById('deleteClientIp');

            if (idInput) idInput.value = clientId;
            if (nameSpan) nameSpan.textContent = clientName;
            if (ipSpan) ipSpan.textContent = clientIp;

            openModal('deleteModal');
        });
    });

    var deleteForm = document.getElementById('deleteClientForm');
    if (deleteForm) {
        deleteForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var idInput = document.getElementById('deleteClientId');
            if (!idInput) return;

            var clientId = idInput.value;
            var confirmBtn = document.getElementById('confirmDeleteBtn');

            if (confirmBtn) {
                confirmBtn.disabled = true;
                confirmBtn.textContent = 'Deleting...';
            }

            var formData = new FormData(deleteForm);
            formData.append('ajax', '1');

            fetch('/clients.php', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                closeModal('deleteModal');
                if (confirmBtn) {
                    confirmBtn.disabled = false;
                    confirmBtn.textContent = 'Delete Client';
                }

                if (data && data.success) {
                    var row = document.getElementById('client-row-' + clientId);
                    if (row) {
                        row.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                        row.style.opacity = '0';
                        row.style.transform = 'translateX(25px)';
                        setTimeout(function () {
                            if (row.parentNode) row.parentNode.removeChild(row);

                            var remainingRows = document.querySelectorAll('.client-card-row');
                            if (remainingRows.length === 0) {
                                var list = document.getElementById('clientsList');
                                if (list) {
                                    list.innerHTML = '<div class="empty-state"><p>No clients configured yet.</p><a href="/add-client.php" class="btn-new-client mt-3" style="display: inline-flex;">+ Add Your First Client</a></div>';
                                }
                            }
                        }, 300);
                    }
                } else {
                    alert('Could not delete client: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(function (err) {
                closeModal('deleteModal');
                if (confirmBtn) {
                    confirmBtn.disabled = false;
                    confirmBtn.textContent = 'Delete Client';
                }
                alert('Connection error: ' + err.message);
            });
        });
    }

    // -------------------------------------------------------------
    // QR Code Modal Triggers & Loading
    // -------------------------------------------------------------
    document.querySelectorAll('.btn-qr, .btn-qr-trigger').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var clientId = this.getAttribute('data-client-id');
            var clientName = this.getAttribute('data-client-name');
            openQrModal(clientId, clientName);
        });
    });

    // -------------------------------------------------------------
    // Revoke Modal Triggers
    // -------------------------------------------------------------
    document.querySelectorAll('.btn-revoke').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var clientId = this.getAttribute('data-client-id');
            var clientName = this.getAttribute('data-client-name');
            openRevokeModal(clientId, clientName);
        });
    });

    // -------------------------------------------------------------
    // Modal Close Triggers via [data-close-modal]
    // -------------------------------------------------------------
    document.querySelectorAll('[data-close-modal]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var modalId = btn.getAttribute('data-close-modal');
            if (modalId) {
                closeModal(modalId);
            }
        });
    });

    // -------------------------------------------------------------
    // Form Submit Confirmation via data-confirm
    // -------------------------------------------------------------
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            var msg = form.getAttribute('data-confirm');
            if (msg && !confirm(msg)) {
                e.preventDefault();
            }
        });
    });

    // -------------------------------------------------------------
    // Copy Configuration to Clipboard
    // -------------------------------------------------------------
    var copyBtn = document.getElementById('btnCopyConfig');
    if (copyBtn) {
        copyBtn.addEventListener('click', function () {
            var box = document.getElementById('configBox');
            if (!box) return;
            var text = box.textContent;
            navigator.clipboard.writeText(text).then(function () {
                var orig = copyBtn.textContent;
                copyBtn.textContent = 'Copied!';
                copyBtn.classList.add('btn-success');
                setTimeout(function () {
                    copyBtn.textContent = orig;
                    copyBtn.classList.remove('btn-success');
                }, 2000);
            }).catch(function (err) {
                alert('Could not copy to clipboard: ' + err);
            });
        });
    }

    // -------------------------------------------------------------
    // Mobile Navigation Toggle
    // -------------------------------------------------------------
    var navToggle = document.getElementById('navToggle');
    var appNav = document.getElementById('appNav');
    if (navToggle && appNav) {
        navToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            var isOpen = appNav.classList.toggle('nav-open');
            navToggle.classList.toggle('active');
            navToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });

        document.addEventListener('click', function (e) {
            if (appNav.classList.contains('nav-open') && !appNav.contains(e.target) && !navToggle.contains(e.target)) {
                appNav.classList.remove('nav-open');
                navToggle.classList.remove('active');
                navToggle.setAttribute('aria-expanded', 'false');
            }
        });
    }

    // -------------------------------------------------------------
    // Auto-dismiss Alert Messages
    // -------------------------------------------------------------
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

// -------------------------------------------------------------
// Global Modal Helpers
// -------------------------------------------------------------
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
    var dlBtn = document.getElementById('qrDownloadBtn');

    if (nameSpan) nameSpan.textContent = clientName;
    if (dlBtn) dlBtn.href = '/client.php?id=' + encodeURIComponent(clientId) + '&download=1';
    if (container) {
        container.innerHTML = '<div class="spinner" style="padding: 2rem;">Loading QR Code...</div>';
    }
    openModal('qrModal');

    fetch('/client.php?id=' + encodeURIComponent(clientId) + '&qr=1')
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data && data.qr) {
                container.innerHTML = '<img src="' + data.qr + '" alt="WireGuard QR Code" class="qr-img">';
            } else {
                container.innerHTML = '<div class="alert alert-danger" style="text-align: left;">Could not generate QR code: ' + (data.error || 'Unknown error') + '</div>';
            }
        })
        .catch(function (err) {
            container.innerHTML = '<div class="alert alert-danger">Failed to fetch QR code: ' + err.message + '</div>';
        });
}

// Close modals when clicking outside modal dialog
window.addEventListener('click', function (e) {
    if (e.target && e.target.classList.contains('modal')) {
        e.target.style.display = 'none';
    }
});
