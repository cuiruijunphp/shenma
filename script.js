// 预设关键词作为兜底（数据库为空或接口失败时使用）
const presetKeywords = [
    '女装','男装','连衣裙','重庆森林公园徒步旅行多少钱','意大利购物中心节假日','上海泰山500元以内租车哪家好','泰国世界遗产旅游自驾游三日游','上海古镇自由行','加拿大博物馆户外探险春季500元以内','深圳温泉冬季性价比','深圳海岛旅游最佳拍摄点','加拿大潜水点蜜月旅行春季优惠冷门','法国海滩旅游背包客接送机','美国国家公园秋季必去度假村','澳大利亚地标建筑周末游冷门民宿','法国滑雪胜地旅游冬季','上海湖泊背包客折扣','重庆博物馆旅游徒步旅行一日游','北京周庄徒步旅行500元以内','马尔代夫地标建筑旅游自驾游性价比火车票','意大利世界遗产周末游5000元以上民宿','上海古镇旅游经济型开放时间'
];

// 本地存储键名
const LS_KEYS = {
    keywords: 'shenma_keywords',
    expireAt: 'shenma_keywords_expire_at',
    index: 'shenma_keywords_index',
};

// 全局状态
let currentIndex = 0;
let countdown = null;
let isRunning = false;
let isAutoMode = true;
let currentKeywords = [];

// DOM
const searchInput = document.getElementById('searchInput');
const searchBtn = document.getElementById('searchBtn');
const currentKeywordSpan = document.getElementById('currentKeyword');
const timerSpan = document.getElementById('timer');
const progressSpan = document.getElementById('progress');
const contentSection = document.getElementById('contentSection');
const autoModeCheckbox = document.getElementById('autoMode');

// 计算今天23:59:59的时间戳（毫秒）
function getTodayEndTimestamp() {
    const d = new Date();
    d.setHours(23, 59, 59, 999);
    return d.getTime();
}

// 从本地存储加载关键词
function loadKeywordsFromLocal() {
    try {
        const expireAt = parseInt(localStorage.getItem(LS_KEYS.expireAt) || '0', 10);
        const now = Date.now();
        if (!expireAt || now > expireAt) {
            // 过期
            clearLocalKeywords();
            return null;
        }
        const raw = localStorage.getItem(LS_KEYS.keywords);
        if (!raw) return null;
        const arr = JSON.parse(raw);
        if (!Array.isArray(arr) || arr.length === 0) return null;
        const savedIndex = parseInt(localStorage.getItem(LS_KEYS.index) || '0', 10);
        return { keywords: arr, index: isNaN(savedIndex) ? 0 : savedIndex };
    } catch (e) {
        return null;
    }
}

// 保存关键词到本地：包含有效期与当前索引
function saveKeywordsToLocal(keywords, index = 0) {
    const expireAt = getTodayEndTimestamp();
    localStorage.setItem(LS_KEYS.keywords, JSON.stringify(keywords));
    localStorage.setItem(LS_KEYS.expireAt, String(expireAt));
    localStorage.setItem(LS_KEYS.index, String(index));
}

// 清空本地关键词
function clearLocalKeywords() {
    localStorage.removeItem(LS_KEYS.keywords);
    localStorage.removeItem(LS_KEYS.expireAt);
    localStorage.removeItem(LS_KEYS.index);
}

// 从后端接口拉取关键词
async function fetchKeywordsFromServer() {
    try {
        const res = await fetch('keywords.php', { cache: 'no-store' });
        const json = await res.json();
        if (json && json.code === 0 && json.data && Array.isArray(json.data.keywords) && json.data.keywords.length > 0) {
            return json.data.keywords;
        }
        return null;
    } catch (e) {
        return null;
    }
}

// 初始化关键词：优先localStorage，其次后端接口，最后fallback
async function initKeywords() {
    // 1) 先读localStorage
    const local = loadKeywordsFromLocal();
    if (local) {
        currentKeywords = local.keywords;
        currentIndex = local.index >= 0 && local.index < currentKeywords.length ? local.index : 0;
        return;
    }
    // 2) 读后端接口
    let list = await fetchKeywordsFromServer();
    if (!list || list.length === 0) {
        // 3) 兜底
        list = [...presetKeywords];
    }
    currentKeywords = list;
    currentIndex = 0;
    saveKeywordsToLocal(currentKeywords, currentIndex);
}

// 每次移动索引时保存到localStorage，维持进度
function persistIndex() {
    localStorage.setItem(LS_KEYS.index, String(currentIndex));
}

// 初始化
async function init() {
    bindEvents();
    await initKeywords();
    updateProgress();

    // 首次进来自动触发一次搜索
    autoStartFirstSearch();
}

function autoStartFirstSearch() {
    // 若输入框为空，用当前索引对应关键词自动搜索
    if (!searchInput.value.trim()) {
        const keyword = currentKeywords[currentIndex] || '';
        if (keyword) {
            searchInput.value = keyword;
            currentKeywordSpan.textContent = keyword;
            performSearch(keyword);
            // 如果自动模式开启，进入自动循环（保持原逻辑：12秒切换）
            if (autoModeCheckbox && autoModeCheckbox.checked) {
                isAutoMode = true;
                isRunning = true;
                startCountdown(12);
            }
        }
    }
}

// 绑定事件
function bindEvents() {
    // 搜索按钮
    searchBtn.addEventListener('click', () => {
        const keyword = searchInput.value.trim();
        if (keyword) {
            isRunning = false; // 手动触发一次，不立即进入自动循环
            performSearch(keyword);
        } else {
            // 空输入：触发自动
            if (autoModeCheckbox && autoModeCheckbox.checked) {
                isAutoMode = true;
                isRunning = true;
                startNextSearch();
            }
        }
    });

    // 回车搜索
    searchInput.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
            const keyword = searchInput.value.trim();
            if (keyword) {
                isRunning = false;
                performSearch(keyword);
            } else if (autoModeCheckbox && autoModeCheckbox.checked) {
                isAutoMode = true;
                isRunning = true;
                startNextSearch();
            }
        }
    });

    // 自动模式切换
    autoModeCheckbox.addEventListener('change', (e) => {
        isAutoMode = e.target.checked;
        if (!isAutoMode) {
            stopAutoSearch();
        }
    });
}

// 停止自动
function stopAutoSearch() {
    isRunning = false;
    if (countdown) {
        clearTimeout(countdown);
        countdown = null;
    }
    timerSpan.textContent = '-';
}

// 自动下一个
function startNextSearch() {
    if (!isRunning) return;
    if (!currentKeywords || currentKeywords.length === 0) return;

    const keyword = currentKeywords[currentIndex];
    currentKeywordSpan.textContent = keyword;
    searchInput.value = keyword;
    performSearch(keyword);
    startCountdown(12);
    updateProgress();
}

// 执行搜索
async function performSearch(keyword) {
    try {
        showLoading();
        const searchUrl = `https://wm.m.sm.cn/s?from=wm745640&q=${encodeURIComponent(keyword)}`;
        const iframe = document.createElement('iframe');
        iframe.src = searchUrl;
        iframe.style.width = '100%';
        iframe.style.height = '800px';
        iframe.style.border = 'none';
        iframe.style.borderRadius = '8px';
        iframe.style.boxShadow = '0 2px 10px rgba(0,0,0,0.1)';
        contentSection.innerHTML = '';
        contentSection.appendChild(iframe);
        iframe.onload = () => { /* no-op */ };
        iframe.onerror = () => { showMessage('搜索结果加载失败，请检查网络连接'); };
    } catch (error) {
        showMessage('搜索失败: ' + error.message);
    }
}

// 倒计时并切下一条
function startCountdown(seconds) {
    let remaining = seconds;
    const tick = () => {
        if (!isRunning) return;
        timerSpan.textContent = `${remaining}秒`;
        remaining--;
        if (remaining < 0) {
            // 前进索引
            currentIndex = (currentIndex + 1) % currentKeywords.length;
            persistIndex();
            startNextSearch();
        } else {
            countdown = setTimeout(tick, 1000);
        }
    };
    if (countdown) clearTimeout(countdown);
    tick();
}

// UI 辅助
function showLoading() {
    contentSection.innerHTML = `
        <div class="loading">
            <div class="spinner"></div>
            <p>正在加载搜索结果...</p>
        </div>
    `;
}
function showMessage(message) {
    contentSection.innerHTML = `<div class="loading"><p>${message}</p></div>`;
}
function updateProgress() {
    const total = (currentKeywords && currentKeywords.length) ? currentKeywords.length : presetKeywords.length;
    progressSpan.textContent = `${(currentIndex + 1)}/${total}`;
}

document.addEventListener('DOMContentLoaded', init);

window.addEventListener('beforeunload', () => {
    if (countdown) clearTimeout(countdown);
}); 