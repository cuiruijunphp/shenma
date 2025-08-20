// 兜底关键词（接口/缓存失败时）
const fallbackKeywords = [
	'女装','男装','连衣裙','重庆森林公园徒步旅行多少钱','意大利购物中心节假日','上海泰山500元以内租车哪家好','泰国世界遗产旅游自驾游三日游','上海古镇自由行','加拿大博物馆户外探险春季500元以内','深圳温泉冬季性价比','深圳海岛旅游最佳拍摄点','加拿大潜水点蜜月旅行春季优惠冷门','法国海滩旅游背包客接送机','美国国家公园秋季必去度假村','澳大利亚地标建筑周末游冷门民宿','法国滑雪胜地旅游冬季','上海湖泊背包客折扣','重庆博物馆旅游徒步旅行一日游','北京周庄徒步旅行500元以内','马尔代夫地标建筑旅游自驾游性价比火车票','意大利世界遗产周末游5000元以上民宿','上海古镇旅游经济型开放时间'
];

const LS_KEYS = {
	keywords: 'shenma_keywords',
	expireAt: 'shenma_keywords_expire_at',
	index: 'shenma_keywords_index',
};

let currentIndex = 0;
let countdown = null;
let keywords = [];

const contentSection = document.getElementById('contentSection');

function getTodayEndTimestamp() {
	const d = new Date();
	d.setHours(23, 59, 59, 999);
	return d.getTime();
}

function loadFromLocal() {
	try {
		const expireAt = parseInt(localStorage.getItem(LS_KEYS.expireAt) || '0', 10);
		if (!expireAt || Date.now() > expireAt) {
			clearLocal();
			return null;
		}
		const raw = localStorage.getItem(LS_KEYS.keywords);
		if (!raw) return null;
		const arr = JSON.parse(raw);
		if (!Array.isArray(arr) || arr.length === 0) return null;
		const idx = parseInt(localStorage.getItem(LS_KEYS.index) || '0', 10);
		return { list: arr, index: isNaN(idx) ? 0 : idx };
	} catch (e) {
		return null;
	}
}

function saveToLocal(list, index=0) {
	localStorage.setItem(LS_KEYS.keywords, JSON.stringify(list));
	localStorage.setItem(LS_KEYS.expireAt, String(getTodayEndTimestamp()));
	localStorage.setItem(LS_KEYS.index, String(index));
}

function clearLocal() {
	localStorage.removeItem(LS_KEYS.keywords);
	localStorage.removeItem(LS_KEYS.expireAt);
	localStorage.removeItem(LS_KEYS.index);
}

async function fetchKeywords() {
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

async function initKeywords() {
	const local = loadFromLocal();
	if (local) {
		keywords = local.list;
		currentIndex = local.index >= 0 && local.index < keywords.length ? local.index : 0;
		return;
	}
	let list = await fetchKeywords();
	if (!list || list.length === 0) list = [...fallbackKeywords];
	keywords = list;
	currentIndex = 0;
	saveToLocal(keywords, currentIndex);
}

function persistIndex() {
	localStorage.setItem(LS_KEYS.index, String(currentIndex));
}

async function init() {
	await initKeywords();
	startCycle();
}

function startCycle() {
	if (!keywords || keywords.length === 0) {
		keywords = [...fallbackKeywords];
	}
	performSearch(keywords[currentIndex]);
	startCountdown(12);
}

function performSearch(keyword) {
	showLoading();
	const url = `https://wm.m.sm.cn/s?from=wm745640&q=${encodeURIComponent(keyword)}`;
	const iframe = document.createElement('iframe');
	iframe.src = url;
	contentSection.innerHTML = '';
	contentSection.appendChild(iframe);
	iframe.onerror = () => { showMessage('搜索结果加载失败，请检查网络连接'); };
}

function startCountdown(seconds) {
	let remaining = seconds;
	const tick = () => {
		remaining--;
		if (remaining < 0) {
			currentIndex = (currentIndex + 1) % keywords.length;
			persistIndex();
			performSearch(keywords[currentIndex]);
			remaining = seconds;
		}
		countdown = setTimeout(tick, 1000);
	};
	if (countdown) clearTimeout(countdown);
	tick();
}

function showLoading() {
	contentSection.innerHTML = `
		<div class="loading">
			<div class="spinner"></div>
			<p>正在加载搜索结果...</p>
		</div>
	`;
}

function showMessage(msg) {
	contentSection.innerHTML = `<div class="loading"><p>${msg}</p></div>`;
}

document.addEventListener('DOMContentLoaded', init);

window.addEventListener('beforeunload', () => {
	if (countdown) clearTimeout(countdown);
}); 