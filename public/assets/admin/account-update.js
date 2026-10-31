(function (root, factory) {
    'use strict';
    var api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root && root.document) {
        root.V2boardAccountUpdate = {
            handle: function (result) { return api.handle(result, root); }
        };
        api.showNotice(root);
    }
}(typeof window === 'undefined' ? null : window, function () {
    'use strict';
    var noticeKey = 'v2board.account-update-notice';
    var notice = '账号信息已保存，请使用更新后的凭据重新登录。';

    function handle(result, browser) {
        if (!result || result.code !== 200 || result.reauthentication_required !== true) return false;
        // The server has already revoked this session. Do not issue a list refresh
        // with its old credential, or report that successful save as a failure.
        browser.localStorage.removeItem('authorization');
        try { browser.sessionStorage.setItem(noticeKey, notice); } catch (_) {}
        browser.location.replace(browser.location.origin + browser.location.pathname);
        return true;
    }

    function showNotice(browser) {
        var saved;
        try {
            saved = browser.sessionStorage.getItem(noticeKey);
            browser.sessionStorage.removeItem(noticeKey);
        } catch (_) { return; }
        if (saved !== notice) return;
        var element = browser.document.createElement('div');
        element.setAttribute('role', 'status');
        element.textContent = notice;
        element.style.cssText = 'position:fixed;top:24px;left:50%;transform:translateX(-50%);z-index:10000;padding:14px 20px;background:#176b43;color:#fff;border-radius:6px;max-width:90%;box-shadow:0 4px 16px #0002';
        browser.document.body.appendChild(element);
        browser.setTimeout(function () { if (element.parentNode) element.parentNode.removeChild(element); }, 10000);
    }
    return { handle: handle, showNotice: showNotice };
}));
