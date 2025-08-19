// 预设关键词列表
const presetKeywords = [
    '女装',
    '男装',
    '连衣裙',
    '重庆森林公园徒步旅行多少钱',
    '意大利购物中心节假日',
    '上海泰山500元以内租车哪家好',
    '泰国世界遗产旅游自驾游三日游',
    '上海古镇自由行',
    '加拿大博物馆户外探险春季500元以内',
    '深圳温泉冬季性价比',
    '深圳海岛旅游最佳拍摄点',
    '加拿大潜水点蜜月旅行春季优惠冷门',
    '法国海滩旅游背包客接送机',
    '美国国家公园秋季必去度假村',
    '澳大利亚地标建筑周末游冷门民宿',
    '法国滑雪胜地旅游冬季',
    '上海湖泊背包客折扣',
    '重庆博物馆旅游徒步旅行一日游',
    '北京周庄徒步旅行500元以内',
    '马尔代夫地标建筑旅游自驾游性价比火车票',
    '意大利世界遗产周末游5000元以上民宿',
    '上海古镇旅游经济型开放时间'
];

// 全局变量
let currentIndex = 0;
let timer = null;
let countdown = null;
let isRunning = false;
let isAutoMode = true;
let currentKeywords = [...presetKeywords]; // 复制预设关键词数组

// DOM元素
const searchInput = document.getElementById('searchInput');
const searchBtn = document.getElementById('searchBtn');
const currentKeywordSpan = document.getElementById('currentKeyword');
const timerSpan = document.getElementById('timer');
const progressSpan = document.getElementById('progress');
const contentSection = document.getElementById('contentSection');
const autoModeCheckbox = document.getElementById('autoMode');

// 初始化
function init() {
    bindEvents();
    updateProgress();
}

// 绑定事件
function bindEvents() {
    // 搜索按钮点击事件
    searchBtn.addEventListener('click', () => {
        const keyword = searchInput.value.trim();
        if (keyword) {
            // 手动搜索
            performSearch(keyword);
            // 如果自动模式开启，将用户输入的关键词添加到当前关键词列表
            if (isAutoMode) {
                addUserKeyword(keyword);
            }
        } else {
            // 空输入时，如果自动模式开启，开始自动搜索
            if (isAutoMode && !isRunning) {
                startAutoSearch();
            }
        }
    });

    // 回车键搜索
    searchInput.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
            const keyword = searchInput.value.trim();
            if (keyword) {
                performSearch(keyword);
                if (isAutoMode) {
                    addUserKeyword(keyword);
                }
            } else {
                if (isAutoMode && !isRunning) {
                    startAutoSearch();
                }
            }
        }
    });

    // 自动模式切换
    autoModeCheckbox.addEventListener('change', (e) => {
        isAutoMode = e.target.checked;
        if (isAutoMode && !isRunning) {
            // 开启自动模式时，如果还没运行，可以开始自动搜索
            showMessage('自动模式已开启，点击搜索按钮开始自动搜索');
        } else if (!isAutoMode) {
            // 关闭自动模式时，停止自动搜索
            stopAutoSearch();
            showMessage('自动模式已关闭');
        }
    });

    // 输入框获得焦点时清空
    searchInput.addEventListener('focus', () => {
        if (searchInput.value === '') {
            searchInput.placeholder = '请输入搜索关键词...';
        }
    });
}

// 添加用户输入的关键词到列表
function addUserKeyword(keyword) {
    // 避免重复添加
    if (!currentKeywords.includes(keyword)) {
        currentKeywords.push(keyword);
        updateProgress();
    }
}

// 开始自动搜索
function startAutoSearch() {
    if (isRunning) return;
    
    isRunning = true;
    currentIndex = 0;
    currentKeywords = [...presetKeywords]; // 重置为预设关键词
    updateProgress();
    startNextSearch();
}

// 停止自动搜索
function stopAutoSearch() {
    isRunning = false;
    if (countdown) {
        clearTimeout(countdown);
        countdown = null;
    }
    timerSpan.textContent = '-';
    currentKeywordSpan.textContent = '-';
}

// 开始下一个搜索
function startNextSearch() {
    if (!isRunning || currentIndex >= currentKeywords.length) {
        // 所有关键词搜索完成或自动模式已关闭
        if (isRunning) {
            showMessage('所有关键词搜索完成！');
            isRunning = false;
        }
        return;
    }

    const keyword = currentKeywords[currentIndex];
    currentKeywordSpan.textContent = keyword;
    searchInput.value = keyword;
    
    // 执行搜索
    performSearch(keyword);
    
    // 设置12秒停留时间
    startCountdown(12);
    
    // 更新进度
    updateProgress();
}

// 执行搜索
async function performSearch(keyword) {
    try {
        showLoading();
        
        // 构建搜索URL
        const searchUrl = `https://wm.m.sm.cn/s?from=wm745640&q=${encodeURIComponent(keyword)}`;
        
        // 创建iframe来展示搜索结果
        const iframe = document.createElement('iframe');
        iframe.src = searchUrl;
        iframe.style.width = '100%';
        iframe.style.height = '800px';
        iframe.style.border = 'none';
        iframe.style.borderRadius = '8px';
        iframe.style.boxShadow = '0 2px 10px rgba(0,0,0,0.1)';
        
        // 清空内容区域并添加iframe
        contentSection.innerHTML = '';
        contentSection.appendChild(iframe);
        
        // 监听iframe加载完成
        iframe.onload = () => {
            hideLoading();
            console.log('搜索结果加载完成');
        };
        
        // 监听iframe加载错误
        iframe.onerror = () => {
            showMessage('搜索结果加载失败，请检查网络连接');
        };
        
        // 添加加载超时处理
        setTimeout(() => {
            if (contentSection.querySelector('iframe')) {
                hideLoading();
            }
        }, 10000); // 10秒超时
        
    } catch (error) {
        console.error('搜索失败:', error);
        showMessage('搜索失败: ' + error.message);
    }
}

// 开始倒计时
function startCountdown(seconds) {
    let remaining = seconds;
    
    // 更新倒计时显示
    const updateTimer = () => {
        if (!isRunning) return; // 如果自动模式已关闭，停止倒计时
        
        timerSpan.textContent = `${remaining}秒`;
        remaining--;
        
        if (remaining < 0) {
            // 倒计时结束，切换到下一个关键词
            currentIndex++;
            startNextSearch();
        } else {
            // 继续倒计时
            countdown = setTimeout(updateTimer, 1000);
        }
    };
    
    // 清除之前的倒计时
    if (countdown) {
        clearTimeout(countdown);
    }
    
    // 开始倒计时
    updateTimer();
}

// 显示加载状态
function showLoading() {
    const loadingDiv = document.createElement('div');
    loadingDiv.className = 'loading';
    loadingDiv.innerHTML = `
        <div class="spinner"></div>
        <p>正在加载搜索结果...</p>
    `;
    
    contentSection.innerHTML = '';
    contentSection.appendChild(loadingDiv);
}

// 隐藏加载状态
function hideLoading() {
    // 加载状态会在iframe加载完成后自动隐藏
}

// 显示消息
function showMessage(message) {
    const messageDiv = document.createElement('div');
    messageDiv.className = 'loading';
    messageDiv.innerHTML = `<p>${message}</p>`;
    
    contentSection.innerHTML = '';
    contentSection.appendChild(messageDiv);
}

// 更新进度
function updateProgress() {
    progressSpan.textContent = `${currentIndex + 1}/${currentKeywords.length}`;
}

// 页面加载完成后初始化
document.addEventListener('DOMContentLoaded', init);

// 页面卸载时清理资源
window.addEventListener('beforeunload', () => {
    if (timer) {
        clearTimeout(timer);
    }
    if (countdown) {
        clearTimeout(countdown);
    }
}); 