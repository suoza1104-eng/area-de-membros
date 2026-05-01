  </div><!-- /#page-content -->
</div><!-- /#main-wrapper -->
</div><!-- /#layout -->

<script>
function toggleSidebar() {
  var sidebar = document.getElementById('sidebar');
  var overlay = document.getElementById('sb-overlay');
  sidebar.classList.toggle('open');
  overlay.classList.toggle('show');
  document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
}
function closeSidebar() {
  var sidebar = document.getElementById('sidebar');
  var overlay = document.getElementById('sb-overlay');
  sidebar.classList.remove('open');
  overlay.classList.remove('show');
  document.body.style.overflow = '';
}
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeSidebar();
});
</script>
</body>
</html>
