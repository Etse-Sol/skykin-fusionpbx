(function () {
	var mins = (window.SKYKIN && Number(SKYKIN.idleTimeoutMinutes)) || 0;
	if (mins <= 0) {
		return;
	}
	var pingUrl = (window.SKYKIN && SKYKIN.idlePingUrl) || 'session_ping.php';
	var last = Date.now();
	var lastPing = 0;
	var leaving = false;

	function logoutIdle() {
		if (leaving) {
			return;
		}
		leaving = true;
		window.location = '/logout.php';
	}

	function onCall() {
		try {
			if (typeof currentAgentStatus !== 'undefined' && currentAgentStatus === 'incall') {
				return true;
			}
			if (typeof session !== 'undefined' && session) {
				return true;
			}
		} catch (e) {}
		return false;
	}

	function ping() {
		if (Date.now() - lastPing < 25000) {
			return;
		}
		lastPing = Date.now();
		fetch(pingUrl, { credentials: 'same-origin' }).then(function (res) {
			if (res.status === 401) {
				logoutIdle();
			}
		}).catch(function () {});
	}

	function mark() {
		last = Date.now();
		ping();
	}

	['click', 'keydown', 'mousemove', 'scroll', 'touchstart'].forEach(function (ev) {
		document.addEventListener(ev, mark, { passive: true });
	});

	setInterval(function () {
		if (onCall()) {
			mark();
			return;
		}
		if (Date.now() - last > mins * 60 * 1000) {
			logoutIdle();
		}
	}, 10000);
})();
