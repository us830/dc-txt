<?php
/**
 * 草叶集 · 文本网盘
 * 纯 PHP 单文件版，无需数据库，数据存储于 /data 目录
 * 后台管理位于根路径，密码：Asd123456!
 */

// 关闭错误显示（生产环境可开启调试）
error_reporting(0);
ini_set('display_errors', 0);
session_start();

// 常量定义
define('DATA_DIR', __DIR__ . '/data');
define('PASSWORD', 'Asd123456!');
define('SESSION_DAYS', 7);

// 确保数据目录存在且可写
if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0755, true);
}

/**
 * 读取 JSON 文件
 */
function readJson($file) {
    $path = DATA_DIR . '/' . $file;
    if (!file_exists($path)) return [];
    $content = file_get_contents($path);
    return json_decode($content, true) ?: [];
}

/**
 * 写入 JSON 文件
 */
function writeJson($file, $data) {
    $path = DATA_DIR . '/' . $file;
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/**
 * 生成随机 6 位字母数字
 */
function generateRandomSlug() {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    return substr(str_shuffle($chars), 0, 6);
}

/**
 * 获取唯一 slug
 */
function getUniqueSlug($customSlug = null) {
    $items = readJson('items.json');
    if ($customSlug && trim($customSlug) !== '') {
        $slug = trim($customSlug);
        foreach ($items as $item) {
            if ($item['slug'] === $slug) {
                throw new Exception('自定义链接后缀已存在');
            }
        }
        return $slug;
    }
    do {
        $slug = generateRandomSlug();
        $exists = false;
        foreach ($items as $item) {
            if ($item['slug'] === $slug) { $exists = true; break; }
        }
    } while ($exists);
    return $slug;
}

/**
 * 获取内容前10个字
 */
function getPreviewText($content) {
    $text = strip_tags($content);
    $preview = mb_substr($text, 0, 10, 'UTF-8');
    if (mb_strlen($text, 'UTF-8') > 10) $preview .= '...';
    return $preview;
}

/**
 * 检查登录状态（Session + Cookie 自动登录）
 */
function checkAuth() {
    if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
        return true;
    }
    if (isset($_COOKIE['admin_token'])) {
        $sessions = readJson('sessions.json');
        $token = $_COOKIE['admin_token'];
        if (isset($sessions[$token]) && $sessions[$token] > time()) {
            $_SESSION['admin_logged_in'] = true;
            return true;
        }
    }
    return false;
}

/**
 * 设置登录状态（记住密码）
 */
function setAuth() {
    $token = bin2hex(random_bytes(32));
    $expires = time() + SESSION_DAYS * 86400;
    $sessions = readJson('sessions.json');
    $sessions[$token] = $expires;
    writeJson('sessions.json', $sessions);
    setcookie('admin_token', $token, $expires, '/', '', false, true);
    $_SESSION['admin_logged_in'] = true;
}

/**
 * 清除登录
 */
function clearAuth() {
    if (isset($_COOKIE['admin_token'])) {
        $sessions = readJson('sessions.json');
        unset($sessions[$_COOKIE['admin_token']]);
        writeJson('sessions.json', $sessions);
        setcookie('admin_token', '', time() - 3600, '/');
    }
    unset($_SESSION['admin_logged_in']);
    session_destroy();
}

// ===================== 路由与请求处理 =====================
$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// ---------- 1. 纯文本分享 /raw/xxxxxx ----------
if (preg_match('#^/raw/([a-zA-Z0-9]+)$#', $path, $matches)) {
    $slug = $matches[1];
    $items = readJson('items.json');
    $target = null;
    foreach ($items as $item) {
        if ($item['slug'] === $slug) { $target = $item; break; }
    }
    if (!$target) { http_response_code(404); die('分享不存在或已失效'); }
    header('Content-Type: text/plain; charset=utf-8');
    echo $target['content'];
    exit;
}

// ---------- 2. 网页浏览 /web/xxxxxx ----------
if (preg_match('#^/web/([a-zA-Z0-9]+)$#', $path, $matches)) {
    $slug = $matches[1];
    $items = readJson('items.json');
    $target = null;
    foreach ($items as $item) {
        if ($item['slug'] === $slug) { $target = $item; break; }
    }
    if (!$target) { http_response_code(404); die('分享不存在或已失效'); }
    $title = htmlspecialchars($target['title']);
    $content = nl2br(htmlspecialchars($target['content']));
    $category = htmlspecialchars($target['category']);
    echo <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>$title - 草叶集</title>
<style>body{background:#f4fce8;font-family:system-ui;padding:2rem;max-width:900px;margin:0 auto;}.paper{background:white;border-radius:2rem;padding:2rem;box-shadow:0 8px 20px rgba(0,0,0,0.05);}h1{color:#3f7822;}</style>
</head>
<body><div class="paper"><h1>📄 $title</h1><div style="white-space:pre-wrap; line-height:1.6;">$content</div><hr/><small>分类: $category · 草叶集</small></div></body>
</html>
HTML;
    exit;
}

// ---------- 3. API 路由 (/api/...) ----------
if (strpos($path, '/api/') === 0) {
    if ($path !== '/api/login' && !checkAuth()) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    if ($path === '/api/items' && $method === 'GET') {
        echo json_encode(readJson('items.json'));
        exit;
    }

    if ($path === '/api/items' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $title = trim($input['title'] ?? '');
        $content = trim($input['content'] ?? '');
        $category = trim($input['category'] ?? '');
        $customSlug = trim($input['customSlug'] ?? '');

        if (!$title || !$content || !$category) {
            http_response_code(400);
            echo json_encode(['error' => '缺少必填字段']);
            exit;
        }
        try {
            $slug = getUniqueSlug($customSlug);
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }
        $id = uniqid();
        $preview = getPreviewText($content);
        $newItem = [
            'id' => $id,
            'title' => $title,
            'content' => $content,
            'category' => $category,
            'slug' => $slug,
            'createdAt' => time(),
            'updatedAt' => time(),
            'preview' => $preview
        ];
        $items = readJson('items.json');
        array_unshift($items, $newItem);
        writeJson('items.json', $items);

        $categories = readJson('categories.json');
        if (!in_array($category, $categories)) {
            $categories[] = $category;
            writeJson('categories.json', $categories);
        }
        echo json_encode(['success' => true, 'slug' => $slug]);
        exit;
    }

    if (preg_match('#^/api/items/(.+)$#', $path, $matches) && $method === 'PUT') {
        $id = $matches[1];
        $input = json_decode(file_get_contents('php://input'), true);
        $title = trim($input['title'] ?? '');
        $content = trim($input['content'] ?? '');
        $category = trim($input['category'] ?? '');

        $items = readJson('items.json');
        $found = false;
        foreach ($items as &$item) {
            if ($item['id'] === $id) {
                $item['title'] = $title;
                $item['content'] = $content;
                $item['category'] = $category;
                $item['updatedAt'] = time();
                $item['preview'] = getPreviewText($content);
                $found = true;
                break;
            }
        }
        if (!$found) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }
        writeJson('items.json', $items);

        $categories = readJson('categories.json');
        if (!in_array($category, $categories)) {
            $categories[] = $category;
            writeJson('categories.json', $categories);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if (preg_match('#^/api/items/(.+)$#', $path, $matches) && $method === 'DELETE') {
        $id = $matches[1];
        $items = readJson('items.json');
        $newItems = [];
        foreach ($items as $item) {
            if ($item['id'] !== $id) $newItems[] = $item;
        }
        writeJson('items.json', $newItems);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($path === '/api/categories' && $method === 'GET') {
        echo json_encode(readJson('categories.json'));
        exit;
    }

    if ($path === '/api/categories' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $name = trim($input['name'] ?? '');
        if (!$name) { http_response_code(400); echo json_encode(['error' => 'Name required']); exit; }
        $categories = readJson('categories.json');
        if (!in_array($name, $categories)) {
            $categories[] = $name;
            writeJson('categories.json', $categories);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($path === '/api/login' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $password = $input['password'] ?? '';
        if ($password === PASSWORD) {
            setAuth();
            echo json_encode(['success' => true]);
        } else {
            http_response_code(401);
            echo json_encode(['error' => '密码错误']);
        }
        exit;
    }

    if ($path === '/api/logout' && $method === 'POST') {
        clearAuth();
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(404);
    echo json_encode(['error' => 'API not found']);
    exit;
}

// ---------- 4. 根路径：后台管理界面（包含登录页） ----------
if ($path === '/' || $path === '') {
    if (!checkAuth()) {
        echo <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>草叶后台 - 登录</title>
<style>body{background:#eaf4e2;display:flex;align-items:center;justify-content:center;min-height:100vh;font-family:sans-serif;}.login-box{background:white;border-radius:2rem;padding:2rem;width:90%;max-width:360px;box-shadow:0 20px 30px rgba(0,0,0,0.05);}input,button{width:100%;margin-top:1rem;padding:0.7rem;border-radius:2rem;border:1px solid #c2dcb0;}button{background:#6f9e3f;color:white;border:none;cursor:pointer;}</style>
</head>
<body>
<div class="login-box"><h2>🔐 管理员登录</h2><input type="password" id="pwd" placeholder="请输入密码"><button id="loginBtn">登录</button><p id="err" style="color:red;margin-top:1rem;"></p></div>
<script>
document.getElementById('loginBtn').onclick=async()=>{
    const pwd=document.getElementById('pwd').value;
    const res=await fetch('/api/login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({password:pwd})});
    if(res.ok){ window.location.href='/'; }
    else{ document.getElementById('err').innerText='密码错误'; }
};
</script>
</body>
</html>
HTML;
        exit;
    }

    // 已登录：显示完整后台管理界面（带折叠开关）
    echo <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>草叶后台 · 文本网盘</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box;}
        body{background:#f0f7ea;font-family:system-ui,sans-serif;padding:1rem;padding-bottom:3rem;color:#1f3b0e;}
        .container{max-width:1280px;margin:0 auto;}
        .btn{background:#eef3e8;border:1px solid #c2dcb0;padding:0.5rem 1rem;border-radius:2rem;font-weight:500;cursor:pointer;color:#2a5512;transition:0.2s;}
        .btn-primary{background:#6f9e3f;border-color:#4f742a;color:white;}
        .btn-primary:hover{background:#5c852f;}
        .btn-outline{background:transparent;border:1px solid #8bb56a;color:#3a6b1f;}
        .card{background:white;border-radius:1.5rem;box-shadow:0 2px 4px rgba(0,0,0,0.05);margin-bottom:1.5rem;border:1px solid #dbeacf;overflow:hidden;}
        .card-header{display:flex;justify-content:space-between;align-items:center;padding:1rem 1.5rem;background:#fdfdf5;cursor:pointer;user-select:none;}
        .card-header h3{margin:0;font-size:1.2rem;color:#3a6b1f;}
        .toggle-icon{font-size:1.2rem;color:#6f9e3f;transition:transform 0.2s;}
        .card-content{padding:0 1.5rem 1.2rem 1.5rem;border-top:1px solid #e2efd6;}
        .card-content.collapsed{display:none;}
        .form-group{margin-bottom:1.2rem;}
        label{font-weight:600;display:block;margin-bottom:0.4rem;color:#2b4b12;}
        input,textarea,select{width:100%;padding:0.7rem 1rem;border-radius:1.2rem;border:1px solid #cfe2c0;background:#fefef7;font-family:inherit;}
        input:focus,textarea:focus,select:focus{outline:none;border-color:#6f9e3f;box-shadow:0 0 0 2px rgba(111,158,63,0.2);}
        .flex-row{display:flex;flex-wrap:wrap;gap:1rem;align-items:center;justify-content:space-between;}
        .category-chips{display:flex;flex-wrap:wrap;gap:0.6rem;margin:0.8rem 0 1.2rem;}
        .chip{background:#eef3e8;border-radius:2rem;padding:0.3rem 1rem;font-size:0.85rem;cursor:pointer;border:1px solid transparent;}
        .chip.active{background:#6f9e3f;color:white;}
        .item-list{display:flex;flex-direction:column;gap:0.8rem;}
        .accordion-item{background:white;border-radius:1.2rem;overflow:hidden;border:1px solid #e2efd6;}
        .accordion-header{padding:1rem 1.2rem;background:#fdfdf5;cursor:pointer;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.5rem;font-weight:600;}
        .item-title{font-size:1.05rem;color:#2f5a14;}
        .item-preview{font-size:0.8rem;color:#6f8460;background:#f6fbf1;padding:0.2rem 0.6rem;border-radius:2rem;}
        .action-buttons{display:flex;gap:0.8rem;margin-top:0.6rem;flex-wrap:wrap;}
        .icon-btn{background:none;border:none;font-size:1.4rem;cursor:pointer;color:#557c34;padding:0 0.2rem;}
        .accordion-body{padding:0 1.2rem 1rem 1.2rem;border-top:1px solid #e2efd6;background:#fff;display:none;}
        .accordion-body.open{display:block;}
        .modal{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:1000;}
        .modal-content{background:white;max-width:550px;width:90%;border-radius:2rem;padding:1.5rem;max-height:85vh;overflow-y:auto;}
        .hidden{display:none;}
        .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:1rem;}
        @media (max-width:640px){.grid-2{grid-template-columns:1fr;}}
        .toast{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#2b5219;color:white;padding:0.5rem 1.2rem;border-radius:2rem;z-index:1100;}
        .qr-center{display:flex;justify-content:center;margin:10px 0;}
        .qr-item-container{margin-bottom:20px; border-bottom:1px solid #e0e0e0; padding-bottom:15px;}
        .qr-item-container:last-child{border-bottom:none;}
        .qr-label{font-weight:bold; margin-bottom:5px; color:#3a6b1f;}
    </style>
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs2@0.0.2/qrcode.min.js"></script>
</head>
<body>
<div class="container">
    <div class="flex-row" style="margin-bottom:0.8rem;"><h2 style="color:#497e24;">📄 草叶后台</h2><button id="logoutBtn" class="btn logout-btn">🚪 退出</button></div>

    <!-- 分类筛选卡片（不可折叠） -->
    <div class="card">
        <div class="card-header" style="cursor:default; background:#f0f7ea;"><strong>📂 分类筛选</strong><button id="showNewCatModalBtn" class="btn btn-outline" style="margin-left:auto;">+ 新建分类</button></div>
        <div class="card-content" style="padding-top:0;"><div id="categoryChips" class="category-chips"></div></div>
    </div>

    <!-- 创建新分享卡片（可折叠） -->
    <div class="card">
        <div class="card-header" id="createHeader">
            <h3>✨ 创建新分享</h3>
            <span class="toggle-icon" id="createToggle">▼</span>
        </div>
        <div class="card-content" id="createContent">
            <div class="grid-2">
                <div class="form-group" style="margin-top: 15px;"><label>标题 *</label><input id="newTitle" placeholder="标题"></div>
                <div class="form-group" style="margin-top: 15px;"><label>分类 *</label><select id="newCategorySelect"></select></div>
            </div>
            <div class="form-group"><label>内容 *</label><textarea id="newContent" rows="12" placeholder="填写文本内容..."></textarea></div>
            <div class="form-group"><label>自定义链接后缀 (选填)</label><input id="newSlug" placeholder="例如 mynote2024"></div>
            <button id="createBtn" class="btn btn-primary">📎 创建分享链接</button>
        </div>
    </div>

    <!-- 所有内容卡片（可折叠） -->
    <div class="card">
        <div class="card-header" id="listHeader">
            <h3>📋 所有内容</h3>
            <span class="toggle-icon" id="listToggle">▼</span>
        </div>
        <div class="card-content" id="listContent">
            <div id="itemsList" class="item-list" style="margin-top:1rem;">加载中...</div>
        </div>
    </div>
</div>

<!-- 编辑模态框 -->
<div id="editModal" class="modal hidden"><div class="modal-content"><h3>✏️ 编辑内容</h3><input type="hidden" id="editId"><div class="form-group"><label>标题</label><input id="editTitle"></div><div class="form-group"><label>分类</label><select id="editCategory"></select></div><div class="form-group"><label>内容</label><textarea id="editContent" rows="5"></textarea></div><div class="flex-row"><button id="saveEditBtn" class="btn btn-primary">保存修改</button><button id="closeModalBtn" class="btn">取消</button></div></div></div>

<!-- 二维码模态框 -->
<div id="qrModal" class="modal hidden"><div class="modal-content" style="text-align:center;"><h4>📱 分享二维码（竖排）</h4><div id="qrWebContainer" class="qr-item-container"><div class="qr-label">🌐 网页链接</div><div id="qrcodeWeb" class="qr-center"></div><p id="webLinkText" style="word-break:break-all; font-size:0.8rem; margin-top:5px;"></p></div><div id="qrRawContainer" class="qr-item-container"><div class="qr-label">📄 原始文本链接</div><div id="qrcodeRaw" class="qr-center"></div><p id="rawLinkText" style="word-break:break-all; font-size:0.8rem; margin-top:5px;"></p></div><button id="closeQrBtn" class="btn">关闭</button></div></div>

<!-- 复制链接选择模态框 -->
<div id="copySelectModal" class="modal hidden"><div class="modal-content" style="text-align:center;"><h4>📋 选择要复制的链接类型</h4><div style="display:flex; gap:1rem; justify-content:center; margin-top:1rem;"><button id="copyWebBtn" class="btn btn-primary">🌐 网页链接</button><button id="copyRawBtn" class="btn btn-outline">📄 原始文本链接</button></div><button id="closeCopyModalBtn" class="btn" style="margin-top:1rem;">取消</button></div></div>

<script>
    let allItems = [], categories = [], currentFilter = "all";
    let pendingCopyWebUrl = "", pendingCopyRawUrl = "";

    // 折叠功能初始化
    function initCollapse() {
        const createHeader = document.getElementById('createHeader');
        const createContent = document.getElementById('createContent');
        const createToggle = document.getElementById('createToggle');
        const listHeader = document.getElementById('listHeader');
        const listContent = document.getElementById('listContent');
        const listToggle = document.getElementById('listToggle');

        let createCollapsed = false;
        let listCollapsed = false;

        createHeader.addEventListener('click', () => {
            createCollapsed = !createCollapsed;
            if (createCollapsed) {
                createContent.classList.add('collapsed');
                createToggle.textContent = '▶';
            } else {
                createContent.classList.remove('collapsed');
                createToggle.textContent = '▼';
            }
        });
        listHeader.addEventListener('click', () => {
            listCollapsed = !listCollapsed;
            if (listCollapsed) {
                listContent.classList.add('collapsed');
                listToggle.textContent = '▶';
            } else {
                listContent.classList.remove('collapsed');
                listToggle.textContent = '▼';
            }
        });
    }

    async function apiFetch(path, options={}) {
        const resp = await fetch(path, {...options, headers:{'Content-Type':'application/json', ...options.headers}});
        if(resp.status===401){ alert("认证失效，请重新登录"); window.location.href="/?logout=1"; return null; }
        return resp;
    }
    async function loadData() {
        try{
            const [itemsRes, catsRes] = await Promise.all([apiFetch("/api/items"), apiFetch("/api/categories")]);
            if(itemsRes && itemsRes.ok) allItems = await itemsRes.json();
            if(catsRes && catsRes.ok) categories = await catsRes.json();
            renderCategoryChips(); renderItemsList(); updateCategorySelects();
        }catch(e){ console.error(e); }
    }
    function updateCategorySelects() {
        ['newCategorySelect','editCategory'].forEach(id=>{
            const sel = document.getElementById(id);
            if(sel){ sel.innerHTML = categories.map(c=>'<option value="'+c+'">'+c+'</option>').join(''); if(id==='newCategorySelect' && categories.length) sel.value = categories[0]; }
        });
    }
    function renderCategoryChips() {
        const container = document.getElementById('categoryChips');
        let html = '<div class="chip '+(currentFilter==='all'?'active':'')+'" data-cat="all">📌 全部</div>';
        categories.forEach(cat=>{ html += '<div class="chip '+(currentFilter===cat?'active':'')+'" data-cat="'+cat+'">🌿 '+cat+'</div>'; });
        container.innerHTML = html;
        document.querySelectorAll('.chip').forEach(chip=>{ chip.addEventListener('click',()=>{ currentFilter = chip.dataset.cat; renderCategoryChips(); renderItemsList(); }); });
    }
    function escapeHtml(str){ if(!str) return ''; return str.replace(/[&<>]/g, function(m){ if(m==='&') return '&amp;'; if(m==='<') return '&lt;'; if(m==='>') return '&gt;'; return m;}); }
    function renderItemsList() {
        const container = document.getElementById('itemsList');
        let filtered = currentFilter==='all' ? allItems : allItems.filter(i=>i.category===currentFilter);
        if(filtered.length===0){ container.innerHTML='<div style="padding:1rem;text-align:center;">暂无内容，创建一条吧~</div>'; return; }
        let html = '';
        filtered.forEach(item=>{
            const webUrl = window.location.origin + '/web/' + item.slug;
            const rawUrl = window.location.origin + '/raw/' + item.slug;
            const previewText = item.preview || "无内容";
            html += '<div class="accordion-item" data-id="'+item.id+'">'+
                '<div class="accordion-header"><span class="item-title">📄 '+escapeHtml(item.title)+'</span><span class="item-preview">'+escapeHtml(previewText)+'</span></div>'+
                '<div class="accordion-body"><div class="action-buttons">'+
                '<button class="icon-btn edit-item" data-id="'+item.id+'" title="编辑">✏️</button>'+
                '<button class="icon-btn open-web" data-url="'+webUrl+'" title="网页浏览">🔗</button>'+
                '<button class="icon-btn open-raw" data-url="'+rawUrl+'" title="原始文本">📄</button>'+
                '<button class="icon-btn copy-link" data-web="'+webUrl+'" data-raw="'+rawUrl+'" title="复制链接">📋</button>'+
                '<button class="icon-btn qr-item" data-web="'+webUrl+'" data-raw="'+rawUrl+'" title="二维码">📱</button>'+
                '<button class="icon-btn delete-item" data-id="'+item.id+'" title="删除">🗑️</button>'+
                '</div><div style="font-size:0.75rem; margin-top:0.5rem;">🔗 /web/'+item.slug+'</div></div></div>';
        });
        container.innerHTML = html;
        document.querySelectorAll('.accordion-item').forEach(el=>{ const header=el.querySelector('.accordion-header'), body=el.querySelector('.accordion-body'); header.addEventListener('click',(e)=>{ e.stopPropagation(); body.classList.toggle('open'); }); });
        document.querySelectorAll('.edit-item').forEach(btn=>{ btn.addEventListener('click',(e)=>{ e.stopPropagation(); openEditModal(btn.dataset.id); }); });
        document.querySelectorAll('.open-web').forEach(btn=>{ btn.addEventListener('click',(e)=>{ e.stopPropagation(); window.open(btn.dataset.url,'_blank'); }); });
        document.querySelectorAll('.open-raw').forEach(btn=>{ btn.addEventListener('click',(e)=>{ e.stopPropagation(); window.open(btn.dataset.url,'_blank'); }); });
        document.querySelectorAll('.copy-link').forEach(btn=>{ btn.addEventListener('click',(e)=>{ e.stopPropagation(); showCopyChoice(btn.dataset.web, btn.dataset.raw); }); });
        document.querySelectorAll('.qr-item').forEach(btn=>{ btn.addEventListener('click',(e)=>{ e.stopPropagation(); showQR(btn.dataset.web, btn.dataset.raw); }); });
        document.querySelectorAll('.delete-item').forEach(btn=>{ btn.addEventListener('click',async(e)=>{ e.stopPropagation(); if(confirm('确定删除？')) await deleteItem(btn.dataset.id); }); });
    }
    function showCopyChoice(webUrl, rawUrl) {
        pendingCopyWebUrl = webUrl; pendingCopyRawUrl = rawUrl;
        document.getElementById('copySelectModal').classList.remove('hidden');
    }
    async function copyToClipboard(text, typeMsg) {
        try { await navigator.clipboard.writeText(text); showToast("✅ "+typeMsg+" 已复制"); } catch(err) { showToast("❌ 复制失败"); }
    }
    function handleCopyWeb() { copyToClipboard(pendingCopyWebUrl, "网页链接"); closeCopyModal(); }
    function handleCopyRaw() { copyToClipboard(pendingCopyRawUrl, "原始文本链接"); closeCopyModal(); }
    function closeCopyModal() { document.getElementById('copySelectModal').classList.add('hidden'); pendingCopyWebUrl=""; pendingCopyRawUrl=""; }
    function showQR(webUrl, rawUrl) {
        document.getElementById('qrcodeWeb').innerHTML = ""; document.getElementById('qrcodeRaw').innerHTML = "";
        new QRCode(document.getElementById('qrcodeWeb'), { text: webUrl, width: 180, height: 180, colorDark: '#2a5512' });
        document.getElementById('webLinkText').innerText = webUrl;
        new QRCode(document.getElementById('qrcodeRaw'), { text: rawUrl, width: 180, height: 180, colorDark: '#2a5512' });
        document.getElementById('rawLinkText').innerText = rawUrl;
        document.getElementById('qrModal').classList.remove('hidden');
    }
    async function openEditModal(id){ const item=allItems.find(i=>i.id===id); if(!item) return; document.getElementById('editId').value=item.id; document.getElementById('editTitle').value=item.title; document.getElementById('editContent').value=item.content; const catSelect=document.getElementById('editCategory'); catSelect.innerHTML=categories.map(c=>'<option value="'+c+'" '+(c===item.category?'selected':'')+'>'+c+'</option>').join(''); document.getElementById('editModal').classList.remove('hidden'); }
    async function saveEdit(){ const id=document.getElementById('editId').value, title=document.getElementById('editTitle').value, content=document.getElementById('editContent').value, category=document.getElementById('editCategory').value; if(!title.trim()||!content.trim()){ alert("标题和内容不能为空"); return; } const resp=await apiFetch('/api/items/'+id,{method:'PUT',body:JSON.stringify({title,content,category})}); if(resp&&resp.ok){ closeModal(); loadData(); showToast("更新成功"); }else showToast("更新失败"); }
    async function deleteItem(id){ const resp=await apiFetch('/api/items/'+id,{method:'DELETE'}); if(resp&&resp.ok){ loadData(); showToast("已删除"); } }
    async function createItem(){ const title=document.getElementById('newTitle').value, content=document.getElementById('newContent').value, category=document.getElementById('newCategorySelect').value, customSlug=document.getElementById('newSlug').value; if(!title.trim()||!content.trim()){ alert("标题和内容不能为空"); return; } const resp=await apiFetch("/api/items",{method:'POST',body:JSON.stringify({title,content,category,customSlug})}); if(resp&&resp.ok){ const newItem=await resp.json(); document.getElementById('newTitle').value=''; document.getElementById('newContent').value=''; document.getElementById('newSlug').value=''; loadData(); showToast('创建成功！链接: /web/'+newItem.slug); }else showToast("创建失败，slug可能重复"); }
    function closeModal(){ document.getElementById('editModal').classList.add('hidden'); document.getElementById('qrModal').classList.add('hidden'); }
    function showToast(msg){ let t=document.createElement('div'); t.className='toast'; t.innerText=msg; document.body.appendChild(t); setTimeout(()=>t.remove(),2000); }
    async function newCategory(){ let catName=prompt("请输入分类名称"); if(catName&&catName.trim()){ const resp=await apiFetch("/api/categories",{method:'POST',body:JSON.stringify({name:catName.trim()})}); if(resp&&resp.ok){ loadData(); showToast("分类添加成功"); }else showToast("分类已存在或无效"); } }
    async function logout(){ await apiFetch("/api/logout",{method:'POST'}); window.location.href="/"; }

    document.addEventListener('DOMContentLoaded',()=>{
        initCollapse();
        loadData();
        document.getElementById('createBtn').addEventListener('click',createItem);
        document.getElementById('logoutBtn').addEventListener('click',logout);
        document.getElementById('saveEditBtn').addEventListener('click',saveEdit);
        document.getElementById('closeModalBtn').addEventListener('click',closeModal);
        document.getElementById('closeQrBtn').addEventListener('click',closeModal);
        document.getElementById('showNewCatModalBtn').addEventListener('click',newCategory);
        document.getElementById('copyWebBtn').addEventListener('click',handleCopyWeb);
        document.getElementById('copyRawBtn').addEventListener('click',handleCopyRaw);
        document.getElementById('closeCopyModalBtn').addEventListener('click',closeCopyModal);
    });
</script>
</body>
</html>
HTML;
    exit;
}

http_response_code(404);
echo 'Not Found';
