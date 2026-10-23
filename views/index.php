<?php
/**
 * 页面模板：引用外置样式与脚本，输出 CSRF Token
 */
if (!defined('AI_CREATOR_APP')) {
    http_response_code(403);
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
    <title>AI创作平台 - 智能写作助手</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Noto+Sans+SC:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css?v=20261024">
</head>
<body>
<?php require __DIR__ . '/index.body.php'; ?>
    <script src="assets/js/app.js?v=20261024"></script>
</body>
</html>
