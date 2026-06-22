</main>
<?php if(!empty($_SESSION['user'])): ?>
<nav class="bottom-nav" aria-label="Основная навигация">
  <?php foreach($navItems as $pageKey => $item): ?>
    <a class="<?php echo $currentPage === $pageKey ? 'active' : ''; ?>" href="/?page=<?php echo $pageKey; ?>">
      <span><?php echo htmlspecialchars($item[1]); ?></span>
      <?php echo htmlspecialchars($item[0]); ?>
    </a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>
<div id="pwaBanner" class="pwa-banner">
  <p>Установите наше приложение для быстрого доступа!</p>
  <div style="display: flex; gap: 0.5rem; align-items: center;">
    <button id="pwaInstallBtn">Установить</button>
    <button id="pwaCloseBtn" class="close-btn" aria-label="Закрыть">&times;</button>
  </div>
</div>
<footer class="app-footer">
  <p>Опалубка CRM</p>
</footer>
<script src="/assets/app.js"></script>
</body>
</html>
