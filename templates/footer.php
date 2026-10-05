<?php
$footerPort = \WireGuardManager\Security::isHttps()
    ? \WireGuardManager\Security::TLS_PORT
    : \WireGuardManager\Security::PLAIN_PORT;
$footerScheme = \WireGuardManager\Security::isHttps() ? 'HTTPS' : 'HTTP';
?>
        </div>
    </main>

    <footer class="app-footer">
        <div class="footer-container">
            <div class="footer-info">
                <span>WireGuard Manager &bull; Standalone Edition</span>
                <span class="separator">|</span>
                <span>Bind: <code>10.50.0.1:<?= h($footerPort) ?></code> (<?= h($footerScheme) ?> &bull; VPN Interface Only)</span>
            </div>
            <div class="footer-note">
                <span class="security-tag">&#x1F512; WireGuard cryptographic isolation &amp; bcrypt authentication active.</span>
            </div>
        </div>
    </footer>

    <script src="/assets/js/app.js"></script>
</body>
</html>
