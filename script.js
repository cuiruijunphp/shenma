// 无 UI 输入/状态，自动请求关键词并轮播展示

const fallbackKeywords = [
	'女装','男装','连衣裙','重庆森林公园徒步旅行多少钱','意大利购物中心节假日','上海泰山500元以内租车哪家好','泰国世界遗产旅游自驾游三日游','上海古镇自由行','加拿大博物馆户外探险春季500元以内','深圳温泉冬季性价比','深圳海岛旅游最佳拍摄点','加拿大潜水点蜜月旅行春季优惠冷门','法国海滩旅游背包客接送机','美国国家公园秋季必去度假村','澳大利亚地标建筑周末游冷门民宿','法国滑雪胜地旅游冬季','上海湖泊背包客折扣','重庆博物馆旅游徒步旅行一日游','北京周庄徒步旅行500元以内','马尔代夫地标建筑旅游自驾游性价比火车票','意大利世界遗产周末游5000元以上民宿','上海古镇旅游经济型开放时间'
];

const INTERVAL_SECONDS = 12;
const contentSection = document.getElementById('contentSection');

let bufferKeywords = [];
let cycleTimer = null;

async function fetchKeywordsFromServer() {
	try {
		const res = await fetch('keywords.php', { cache: 'no-store' });
		const json = await res.json();
		if (json && json.code === 0 && json.data && Array.isArray(json.data.keywords)) {
			return json.data.keywords.filter(v => typeof v === 'string' && v.trim() !== '');
		}
		return [];
	} catch (e) {
		return [];
	}
}

async function getNextKeyword() {
	if (bufferKeywords.length > 0) {
		return bufferKeywords.shift();
	}
	const list = await fetchKeywordsFromServer();
	if (list.length === 0) return null;
	// 如果返回1个，直接用；如果返回多条，放入内存队列
	if (list.length === 1) {
		return list[0];
	} else {
		bufferKeywords = list;
		return bufferKeywords.shift();
	}
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

async function cycleStep() {
	const kw = await getNextKeyword();
	if (!kw) {
		showMessage('暂无可用关键词，10秒后重试...');
		cycleTimer = setTimeout(cycleStep, 10000);
		return;
	}
	performSearch(kw);
	cycleTimer = setTimeout(cycleStep, INTERVAL_SECONDS * 1000);
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

document.addEventListener('DOMContentLoaded', () => {
	cycleStep();
});

window.addEventListener('beforeunload', () => {
	if (cycleTimer) clearTimeout(cycleTimer);
}); 