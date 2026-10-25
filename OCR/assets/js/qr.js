/**
 * The setup QR code ISK prints on scorecards (Api/ISK-NG/DrawQRCode.php): a JSON text
 * {"u": server, "c": competition code [|pin], "s": session, "d": distance,
 *  "t": target (four digits), "st": stage}. For the whole sheet of a target it tells
 * the session, the distance and the target number; the letter of each card comes
 * from the card itself.
 *
 * Read by the browser's own reader where there is one (phones), otherwise by jsQR
 * (assets/js/vendor/, Apache License 2.0) on a copy of the picture no larger than
 * the code needs.
 *
 * Browser and Node alike (parse and readRGBA); read() needs a canvas.
 */
(function (root, factory) {
    var api = factory(root);
    if (typeof module === 'object' && module.exports) module.exports = api;
    else root.OcrQR = api;
})(typeof self !== 'undefined' ? self : this, function (root) {
    'use strict';

    var MAX_SIDE = 1600;   // a 20 mm code still has 4 pixels per module on a whole sheet

    /** The ISK text -> {code, session, dist, target, stage}, or null for any other code. */
    function parse(text) {
        var j;
        try {
            j = JSON.parse(text);
        } catch (e) {
            return null;
        }
        if (!j || typeof j !== 'object' || !j.c) return null;
        var t = parseInt(j.t, 10);
        return {code: String(j.c).split('|')[0], session: parseInt(j.s, 10) || 0, dist: parseInt(j.d, 10) || 0,
            target: isNaN(t) ? 0 : t, stage: j.st || 'Q'};
    }

    function jsQR() {
        if (root && root.jsQR) return root.jsQR;
        if (typeof require === 'function') return require('./vendor/jsQR.js');
        return null;
    }

    /** jsQR on canvas pixels (RGBA). -> {text, info} or null. */
    function readRGBA(data, w, h) {
        var decode = jsQR();
        if (!decode) return null;
        var r = decode(data, w, h, {inversionAttempts: 'dontInvert'});
        var info = r && parse(r.data);
        return info ? {text: r.data, info: info} : null;
    }

    /**
     * The ISK code of a picture on a canvas. -> Promise of {text, info} or null.
     *
     * @param {Function} [far] (data, w, h) -> a promise of readRGBA's result, read elsewhere
     *                         (a worker); its pixels are its own then. Rejected: read here.
     */
    function read(canvas, far) {
        var native = root && 'BarcodeDetector' in root ? root.BarcodeDetector.getSupportedFormats().then(function (f) {
            return f.indexOf('qr_code') >= 0 ? new root.BarcodeDetector({formats: ['qr_code']}).detect(canvas) : [];
        }).catch(function () { return []; }) : Promise.resolve([]);
        return native.then(function (found) {
            for (var i = 0; i < (found || []).length; i++) {
                var info = parse(found[i].rawValue);
                if (info) return {text: found[i].rawValue, info: info};
            }
            var s = Math.min(1, MAX_SIDE / Math.max(canvas.width, canvas.height)), c = canvas;
            if (s < 1) {
                c = document.createElement('canvas');
                c.width = Math.round(canvas.width * s);
                c.height = Math.round(canvas.height * s);
                c.getContext('2d').drawImage(canvas, 0, 0, c.width, c.height);
            }
            var d = c.getContext('2d').getImageData(0, 0, c.width, c.height);
            if (!far) return readRGBA(d.data, d.width, d.height);
            var copy = d.data.slice();
            return far(d.data, d.width, d.height).then(null, function () { return readRGBA(copy, d.width, d.height); });
        });
    }

    return {parse: parse, read: read, readRGBA: readRGBA};
});
