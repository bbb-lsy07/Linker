    </div> <!-- .container -->
    
    <!-- 将PHP变量传递给JS -->
    <script>
        const serversData = <?php echo json_encode($servers ?? []); ?>;
        const csrfToken = '<?php echo htmlspecialchars($csrf_token ?? ''); ?>';
    </script>
    <!-- 引入外部 JS 文件 -->
    <script src="assets/js/dashboard.js"></script>
</body>
</html>
