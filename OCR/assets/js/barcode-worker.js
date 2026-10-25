/**
 * Background reader of the series capture: finds the barcode of a photo just taken,
 * away from the page, which goes on watching the camera meanwhile.
 *
 * Message in: {id, bitmap}. Message out: {id, text, turn}; text is '' when no code
 * was read. The browser's own reader is used when it reads Code 39, ours otherwise.
 */
// With this worker's version (the newest of the module's scripts): never an older copy.
importScripts.apply(self, ['vision.js', 'barcode.js'].map(function (f) { return f + self.location.search; }));

self.onmessage = function (e) {
    var id = e.data.id, bmp = e.data.bitmap;
    function reply(text, turn) {
        self.postMessage({id: id, text: text || '', turn: turn === undefined ? null : turn});
    }
    var native = 'BarcodeDetector' in self
        ? self.BarcodeDetector.getSupportedFormats().then(function (f) {
            return f.indexOf('code_39') >= 0 ? new self.BarcodeDetector({formats: ['code_39']}).detect(bmp) : [];
        }).catch(function () { return []; })
        : Promise.resolve([]);
    native.then(function (found) {
        for (var i = 0; i < found.length; i++) {
            var text = String(found[i].rawValue || '').replace(/\*/g, '');
            if (text.indexOf('-') > 0) return reply(text, null);
        }
        var s = Math.min(1, 4000 / Math.max(bmp.width, bmp.height));
        var c = new OffscreenCanvas(Math.round(bmp.width * s), Math.round(bmp.height * s));
        var ctx = c.getContext('2d', {willReadFrequently: true});
        ctx.drawImage(bmp, 0, 0, c.width, c.height);
        var g = self.OcrVision.fromRGBA(ctx.getImageData(0, 0, c.width, c.height).data, c.width, c.height);
        var a = self.OcrVision.analyse(g);
        var r = a.ok ? self.OcrBarcode.find(self.OcrVision, g, a) : null;
        reply(r ? r.text : '', r ? r.turn : null);
    }).catch(function () { reply('', null); });
};
